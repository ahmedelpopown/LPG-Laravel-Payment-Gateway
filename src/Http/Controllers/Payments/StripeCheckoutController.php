<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Http\Controllers\Payments;

use Ahmed\LaravelPayment\Actions\Payments\StartBookingPayment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Illuminate\Routing\Controller;
use Illuminate\Database\Eloquent\Model;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Throwable;

final class StripeCheckoutController extends Controller
{
    public function __invoke(string $booking, StartBookingPayment $startBookingPayment): RedirectResponse
    {
        $customer = Auth::guard((string) config('payments.auth.customer_guard', 'customer'))->user();

        abort_unless($customer instanceof Model, 403);

        $bookingModel = config('payments.booking.booking_model') ?: config('payments.booking_model');
        $booking = $bookingModel::query()->findOrFail($booking);

        try {
            $payment = $startBookingPayment->handle(
                $booking,
                $customer,
                PaymentProvider::Stripe,
            );
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (PaymentGatewayException|DomainException|LogicException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'تعذر بدء عملية الدفع عبر Stripe حاليًا. لم يتم تأكيد أي مبلغ. حاول مرة أخرى.');
        }

        if (! is_string($payment->checkout_url) || $payment->checkout_url === '') {
            return back()->with('error', 'لم يتم إنشاء رابط دفع Stripe صالح. حاول مرة أخرى.');
        }

        return redirect()->away($payment->checkout_url);
    }
}
