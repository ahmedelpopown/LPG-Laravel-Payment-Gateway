<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Payments;

use Ahmed\LaravelPayment\Actions\Payments\CancelBookingPayment;
use Ahmed\LaravelPayment\Actions\Payments\ConfirmPayPalPayment;
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

final class PayPalReturnController extends Controller
{
    public function complete(
        Request $request,
        BookingPayment $payment,
        ConfirmPayPalPayment $confirmPayPalPayment,
    ): RedirectResponse {
        $customer = Auth::guard((string) config('payments.auth.customer_guard', 'customer'))->user();

        abort_unless($customer instanceof Model, 403);

        $orderId = $request->query('token');

        if (! is_string($orderId) || trim($orderId) === '') {
            return $this->bookingRedirect('error', 'لم يرجع PayPal رقم الطلب. لم يتم تأكيد الدفع.');
        }

        try {
            $payment = $confirmPayPalPayment->handle($payment, $customer, $orderId);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (PaymentGatewayException $exception) {
            report($exception);

            return $this->bookingRedirect('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingRedirect('error', 'تعذر تأكيد الدفع الآن. حالة العملية قيد المراجعة، لا تحاول الدفع مرة أخرى قبل تحديث الحجوزات.');
        }

        return match ($payment->status) {
            PaymentStatus::Paid => $this->bookingRedirect('success', 'تم تأكيد الدفع بنجاح. احتفظ بتفاصيل الحجز من صفحة حجوزاتي. '),
            PaymentStatus::Processing => $this->bookingRedirect('warning', 'PayPal استلم العملية وما زال يؤكدها. لن نعتبر الحجز مدفوعًا حتى يصل التأكيد النهائي.'),
            default => $this->bookingRedirect('error', 'لم يتم تأكيد الدفع. يمكنك مراجعة تفاصيل المحاولة من صفحة حجوزاتي.'),
        };
    }

    public function cancel(
        Request $request,
        BookingPayment $payment,
        CancelBookingPayment $cancelBookingPayment,
    ): RedirectResponse {
        $customer = Auth::guard((string) config('payments.auth.customer_guard', 'customer'))->user();

        abort_unless($customer instanceof Model, 403);

        $orderId = $request->query('token');

        if (! is_string($orderId) || trim($orderId) === '') {
            return $this->bookingRedirect('error', 'تعذر مطابقة طلب الإلغاء مع عملية PayPal.');
        }

        try {
            $payment = $cancelBookingPayment->handle($payment, $customer, $orderId);
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
