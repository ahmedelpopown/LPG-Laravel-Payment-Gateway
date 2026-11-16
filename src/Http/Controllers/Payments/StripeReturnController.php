<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Payments;

use Ahmed\LaravelPayment\Actions\Payments\CancelStripePayment;
use Ahmed\LaravelPayment\Actions\Payments\ConfirmStripePayment;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Routing\Controller;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class StripeReturnController extends Controller
{
    public function complete(
        Request $request,
        BookingPayment $payment,
        ConfirmStripePayment $confirmStripePayment,
    ): RedirectResponse {
        $customer = Auth::guard((string) config('payments.auth.customer_guard', 'customer'))->user();

        abort_unless($customer instanceof Model, 403);

        $sessionId = $request->query('session_id');

        if (! is_string($sessionId) || trim($sessionId) === '') {
            return $this->bookingRedirect('error', 'لم يرجع Stripe رقم جلسة الدفع. لم يتم تأكيد الدفع.');
        }

        try {
            $payment = $confirmStripePayment->handle($payment, $customer, $sessionId);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (PaymentGatewayException $exception) {
            report($exception);

            return $this->bookingRedirect('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingRedirect('error', 'تعذر تأكيد الدفع الآن. حالة العملية قيد المراجعة، راجع حجوزاتك قبل بدء محاولة جديدة.');
        }

        return match ($payment->status) {
            PaymentStatus::Paid => $this->bookingRedirect('success', 'تم تأكيد الدفع عبر Stripe بنجاح. احتفظ بتفاصيل الحجز من صفحة حجوزاتي.'),
            PaymentStatus::Processing => $this->bookingRedirect('warning', 'Stripe استلم العملية وما زال يؤكدها. لن نعتبر الحجز مدفوعًا حتى يصل التأكيد النهائي.'),
            default => $this->bookingRedirect('error', 'لم يتم تأكيد الدفع. يمكنك مراجعة تفاصيل المحاولة من صفحة حجوزاتي.'),
        };
    }

    public function cancel(
        BookingPayment $payment,
        CancelStripePayment $cancelStripePayment,
    ): RedirectResponse {
        $customer = Auth::guard((string) config('payments.auth.customer_guard', 'customer'))->user();

        abort_unless($customer instanceof Model, 403);

        try {
            $payment = $cancelStripePayment->handle($payment, $customer);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (PaymentGatewayException $exception) {
            report($exception);

            return $this->bookingRedirect('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingRedirect('error', 'تعذر تحديث حالة محاولة الدفع. راجع صفحة حجوزاتي.');
        }

        if ($payment->status === PaymentStatus::Cancelled) {
            return $this->bookingRedirect('warning', 'تم إلغاء محاولة الدفع، والحجز ما زال موجودًا ويمكنك المحاولة مرة أخرى.');
        }

        if ($payment->status === PaymentStatus::Paid) {
            return $this->bookingRedirect('success', 'تم تأكيد الدفع بالفعل. لم يتم إلغاء أي مبلغ.');
        }

        return $this->bookingRedirect('warning', 'حالة الدفع قيد التأكيد. لن نلغي الحجز حتى نتحقق من نتيجة العملية.');
    }

    private function bookingRedirect(string $flashType, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.profile.bookings')
            ->with($flashType, $message);
    }
}
