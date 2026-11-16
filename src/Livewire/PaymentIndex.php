<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Livewire;



use Ahmed\LaravelPayment\Actions\Payments\RefundBookingPayment;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Enums\RefundStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Ahmed\LaravelPayment\Models\BookingPaymentRefund;
use Ahmed\LaravelPayment\Services\RefundBalanceCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('المدفوعات')]
final class PaymentIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = 'all';
    public string $provider = 'all';
    public int $perPage = 15;

    public bool $showRefundModal = false;
    #[Locked]
    public bool $refundAttemptStarted = false;

    #[Locked]
    public ?int $paymentToRefund = null;
    public string $refundAmount = '';
    public string $refundReason = '';
    #[Locked]
    public string $refundIdempotencyKey = '';

    #[Locked]
    public string $refundableBalance = '0.00';

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => 'all'],
        'provider' => ['except' => 'all'],
    ];

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        $this->ensureAdmin();
        $balanceCalculator = app(RefundBalanceCalculator::class);

        $validStatuses = array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases(),
        );

        $validProviders = ['paypal', 'stripe'];
        $status = in_array($this->status, $validStatuses, true) ? $this->status : 'all';
        $provider = in_array($this->provider, $validProviders, true) ? $this->provider : 'all';
        $perPage = in_array($this->perPage, [15, 25, 50], true) ? $this->perPage : 15;
        $term = trim($this->search);

        $payments = BookingPayment::query()
            ->with([
                'booking.user:id,name,email,phone',
                'booking.employee:id,name',
                'booking.branch:id,name',
                'refunds',
            ])
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $nestedQuery) use ($term): void {
                    $nestedQuery
                        ->where('provider_reference', 'like', "%{$term}%")
                        ->orWhere('provider_transaction_id', 'like', "%{$term}%")
                        ->orWhereHas('booking.user', function (Builder $userQuery) use ($term): void {
                            $userQuery->where(function (Builder $customerQuery) use ($term): void {
                                $customerQuery
                                    ->where('name', 'like', "%{$term}%")
                                    ->orWhere('email', 'like', "%{$term}%")
                                    ->orWhere('phone', 'like', "%{$term}%");
                            });
                        });
                });
            })
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($provider !== 'all', fn (Builder $query) => $query->where('provider', $provider))
            ->latest('id')
            ->paginate($perPage);

        $payments->getCollection()->each(function (BookingPayment $payment) use ($balanceCalculator): void {
            $reservedMinorUnits = $payment->refunds
                ->filter(fn (BookingPaymentRefund $refund): bool => in_array(
                    $refund->status,
                    [RefundStatus::Pending, RefundStatus::Processing, RefundStatus::Completed],
                    true,
                ))
                ->sum(fn (BookingPaymentRefund $refund): int => $balanceCalculator->toMinorUnits((string) $refund->amount));

            $completedMinorUnits = $payment->refunds
                ->filter(fn (BookingPaymentRefund $refund): bool => $refund->status === RefundStatus::Completed)
                ->sum(fn (BookingPaymentRefund $refund): int => $balanceCalculator->toMinorUnits((string) $refund->amount));

            $originalMinorUnits = $balanceCalculator->toMinorUnits((string) $payment->amount);

            $payment->setAttribute(
                'refundable_amount',
                $balanceCalculator->formatMinorUnits(max(0, $originalMinorUnits - $reservedMinorUnits)),
            );
            $payment->setAttribute(
                'completed_refund_total',
                $balanceCalculator->formatMinorUnits($completedMinorUnits),
            );
        });

        return $payments;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->payments);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        unset($this->payments);
    }

    public function updatedProvider(): void
    {
        $this->resetPage();
        unset($this->payments);
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
        unset($this->payments);
    }

    public function resetFilters(): void
    {
        $this->reset(['search']);
        $this->status = 'all';
        $this->provider = 'all';
        $this->perPage = 15;
        $this->resetPage();
        unset($this->payments);
    }

    public function openRefund(int $paymentId, RefundBalanceCalculator $balanceCalculator): void
    {
        $this->ensureAdmin();

        $payment = BookingPayment::query()->findOrFail($paymentId);

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            session()->flash('error', 'لا يمكن استرداد مبلغ عملية لم يتم تحصيلها بنجاح.');
            return;
        }

        $remainingAmount = $balanceCalculator->remainingAmount($payment);

        if ($balanceCalculator->toMinorUnits($remainingAmount) <= 0) {
            session()->flash('error', 'تم استرداد كامل مبلغ عملية الدفع بالفعل أو أن المبلغ محجوز لعملية استرداد قيد المعالجة.');
            return;
        }

        $this->paymentToRefund = (int) $payment->getKey();
        $this->refundableBalance = $remainingAmount;
        $this->refundAmount = $remainingAmount;
        $this->refundReason = '';
        $this->refundIdempotencyKey = (string) Str::uuid();
        $this->refundAttemptStarted = false;
        $this->showRefundModal = true;
    }

    public function submitRefund(RefundBookingPayment $refundAction): void
    {
        $this->ensureAdmin();

        if (! $this->paymentToRefund) {
            abort(404);
        }

        $validated = $this->validate([
            'refundAmount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'refundReason' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = BookingPayment::query()->findOrFail($this->paymentToRefund);
        $employee = auth((string) config('payments.auth.employee_guard', 'employee'))->user();

        if (! $employee) {
            abort(403);
        }

        $this->refundAttemptStarted = true;

        try {
            $refund = $refundAction->handle(
                payment: $payment,
                amount: $validated['refundAmount'],
                idempotencyKey: $this->refundIdempotencyKey,
                requestedBy: $employee,
                reason: $validated['refundReason'] !== '' ? $validated['refundReason'] : null,
            );
        } catch (PaymentGatewayException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->payments);
            return;
        } catch (Throwable $exception) {
            report($exception);
            session()->flash('error', 'تعذر تأكيد نتيجة الاسترداد حاليًا. حالة العملية محفوظة للمراجعة، استخدم إعادة التحقق قبل إنشاء طلب آخر.');
            unset($this->payments);
            return;
        }

        session()->flash(
            'message',
            $refund->status === RefundStatus::Completed
                ? 'تم تنفيذ عملية الاسترداد بنجاح.'
                : 'تم إرسال طلب الاسترداد، وحالته الآن قيد المعالجة. يمكنك إعادة التحقق من سجل الاسترداد.',
        );

        $this->closeRefundModal();
        unset($this->payments);
    }

    public function retryRefund(int $refundId, RefundBookingPayment $refundAction): void
    {
        $this->ensureAdmin();

        $refund = BookingPaymentRefund::query()
            ->with('bookingPayment')
            ->findOrFail($refundId);

        if (! in_array($refund->status, [RefundStatus::Processing, RefundStatus::Failed], true)) {
            session()->flash('error', 'لا تحتاج عملية الاسترداد هذه إلى إعادة المحاولة.');
            return;
        }

        $employee = auth((string) config('payments.auth.employee_guard', 'employee'))->user();

        if (! $employee) {
            abort(403);
        }

        try {
            $updatedRefund = $refundAction->handle(
                payment: $refund->bookingPayment,
                amount: (string) $refund->amount,
                idempotencyKey: $refund->idempotency_key,
                requestedBy: $employee,
                reason: $refund->reason,
            );
        } catch (PaymentGatewayException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->payments);
            return;
        } catch (Throwable $exception) {
            report($exception);
            session()->flash('error', 'تعذر تحديث حالة الاسترداد. لم يتم إنشاء طلب استرداد جديد.');
            unset($this->payments);
            return;
        }

        session()->flash(
            'message',
            $updatedRefund->status === RefundStatus::Completed
                ? 'تم تأكيد اكتمال الاسترداد.'
                : 'لا تزال عملية الاسترداد قيد المعالجة لدى مزود الدفع.',
        );

        unset($this->payments);
    }

    public function closeRefundModal(): void
    {
        $this->showRefundModal = false;
        $this->paymentToRefund = null;
        $this->refundAmount = '';
        $this->refundReason = '';
        $this->refundIdempotencyKey = '';
        $this->refundableBalance = '0.00';
        $this->refundAttemptStarted = false;
        $this->resetValidation();
    }

    private function ensureAdmin(): void
    {
        abort_unless(auth((string) config('payments.auth.employee_guard', 'employee'))->user()?->hasRole((string) config('payments.auth.admin_role', 'admin')), 403);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('payment::pages.payments.index')->layout(config('payments.layout', 'layouts::app'));
    }
}
