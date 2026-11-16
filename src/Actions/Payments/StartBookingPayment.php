<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Actions\Payments;

use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Exceptions\PaymentGatewayException;
use Ahmed\LaravelPayment\Models\BookingPayment;
use Illuminate\Database\Eloquent\Model;
use Ahmed\LaravelPayment\Payments\PaymentGatewayManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class StartBookingPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
    ) {}

    public function handle(
        Model $booking,
        Model $customer,
        PaymentProvider $provider = PaymentProvider::PayPal,
    ): BookingPayment {
        $tenantResolver = config('payments.booking.tenant_resolver');
        if (is_string($tenantResolver) && $tenantResolver !== '') {
            $tenant = app($tenantResolver)->current();
        } else {
            $tenancy = function_exists('tenancy') ? tenancy() : null;
            $tenant = $tenancy?->initialized ? $tenancy->tenant : null;
        }

        if ($tenant === null) {
            throw new LogicException('Booking payments can only start inside an initialized tenant.');
        }

        $featureGate = config('payments.booking.feature_gate');
        if (is_string($featureGate) && $featureGate !== ''
            && ! app($featureGate)->hasFeature($tenant, 'online_payment')) {
            throw new PaymentGatewayException(
                'الدفع الإلكتروني غير متاح في باقة هذا المحل حاليًا.',
                'online_payment_feature_disabled',
            );
        }

        $gateway = $this->gatewayManager->driver($provider);
        $currency = strtoupper(trim((string) config('payments.booking.currency', config('payments.booking_currency', 'EGP'))));

        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new PaymentGatewayException(
                'The booking payment currency is not configured correctly.',
                'booking_payment_currency_invalid',
            );
        }

        if (! $gateway->supportsCurrency($currency)) {
            throw new PaymentGatewayException(
                sprintf(
                    'الدفع عبر %s لا يدعم عملة %s. لم يتم إنشاء محاولة دفع. راجع إعداد العملة قبل تفعيل الدفع الإلكتروني.',
                    strtoupper($provider->value),
                    $currency,
                ),
                'payment_currency_not_supported',
            );
        }

        $payment = DB::transaction(function () use (
            $booking,
            $customer,
            $provider,
            $currency,
        ): BookingPayment {
            $bookingModel = config('payments.booking.booking_model') ?: config('payments.booking_model');
            $lockedBooking = $bookingModel::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedBooking->user_id !== (int) $customer->getKey()) {
                throw new AuthorizationException('You are not allowed to pay for this booking.');
            }

            if ($lockedBooking->status !== 'pending') {
                throw new PaymentGatewayException(
                    'لا يمكن بدء الدفع لحجز غير نشط.',
                    'booking_not_payable',
                );
            }

            $priceCalculator = config('payments.booking.price_calculator');
            if (! is_string($priceCalculator) || $priceCalculator === '') {
                throw new LogicException('Configure payments.booking.price_calculator to enable booking checkout.');
            }

            $amount = app($priceCalculator)->calculateTotal($lockedBooking);

            if ($this->amountInMinorUnits($amount) <= 0) {
                throw new PaymentGatewayException(
                    'لا يمكن بدء الدفع لحجز إجماليه صفر.',
                    'booking_payment_amount_must_be_positive',
                );
            }

            $activePayment = BookingPayment::query()
                ->where('booking_id', $lockedBooking->getKey())
                ->whereIn('status', [
                    PaymentStatus::Pending->value,
                    PaymentStatus::Processing->value,
                ])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($activePayment) {
                $sameAttempt = $activePayment->provider === $provider
                    && (string) $activePayment->amount === $amount
                    && strtoupper((string) $activePayment->currency) === $currency;

                if (! $sameAttempt) {
                    throw new PaymentGatewayException(
                        'يوجد بالفعل طلب دفع نشط لهذا الحجز. أكمل المحاولة الحالية أو ألغها قبل بدء محاولة أخرى.',
                        'booking_payment_already_active',
                    );
                }

                if ($activePayment->status === PaymentStatus::Processing) {
                    throw new PaymentGatewayException(
                        'عملية الدفع قيد التأكيد بالفعل. حدّث صفحة الحجوزات بعد لحظات.',
                        'booking_payment_processing',
                    );
                }

                return $activePayment;
            }

            return BookingPayment::query()->create([
                'booking_id' => $lockedBooking->getKey(),
                'provider' => $provider,
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $amount,
                'currency' => $currency,
                'status' => PaymentStatus::Pending,
                'metadata' => [
                    'source' => 'customer_booking_checkout',
                    'customer_id' => (int) $customer->getKey(),
                ],
            ]);
        });

        if (is_string($payment->checkout_url) && $payment->checkout_url !== '') {
            return $payment;
        }

        $providerCreatedCheckout = false;

        try {
            $checkout = $gateway->createCheckout($payment);
            $providerCreatedCheckout = true;

            return DB::transaction(function () use ($payment, $checkout): BookingPayment {
                $lockedPayment = BookingPayment::query()
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedPayment->status !== PaymentStatus::Pending) {
                    throw new PaymentGatewayException(
                        'The payment attempt changed before its checkout session could be saved.',
                        'booking_payment_state_changed',
                    );
                }

                $lockedPayment->forceFill([
                    'provider_reference' => $checkout->providerReference,
                    'checkout_url' => $checkout->checkoutUrl,
                    'metadata' => array_merge($lockedPayment->metadata ?? [], $checkout->metadata, [
                        'checkout_created_at' => now()->toIso8601String(),
                    ]),
                ])->save();

                return $lockedPayment->fresh();
            });
        } catch (Throwable $exception) {
            if (! $providerCreatedCheckout) {
                $this->recordCheckoutFailure($payment, $exception);
            }

            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }

            throw new PaymentGatewayException(
                'تعذر إنشاء جلسة الدفع حاليًا. لم يتم تأكيد أي مبلغ، حاول مرة أخرى.',
                'booking_checkout_initialization_failed',
                null,
                $exception,
            );
        }
    }

    private function recordCheckoutFailure(BookingPayment $payment, Throwable $exception): void
    {
        $lockedPayment = BookingPayment::query()->find($payment->getKey());

        if (! $lockedPayment || $lockedPayment->status !== PaymentStatus::Pending) {
            return;
        }

        $providerMayHaveCreatedOrder = $exception instanceof PaymentGatewayException
            && (in_array($exception->providerCode, ['paypal_connection_error', 'stripe_connection_error'], true)
                || $exception->httpStatus === 408
                || $exception->httpStatus === 429
                || ($exception->httpStatus !== null && $exception->httpStatus >= 500));

        $lockedPayment->forceFill([
            'status' => $providerMayHaveCreatedOrder ? PaymentStatus::Pending : PaymentStatus::Failed,
            'metadata' => array_merge($lockedPayment->metadata ?? [], [
                'checkout_error_code' => $exception instanceof PaymentGatewayException
                    ? $exception->providerCode
                    : 'checkout_initialization_failed',
                'checkout_failed_at' => now()->toIso8601String(),
                'checkout_retryable' => $providerMayHaveCreatedOrder,
            ]),
        ])->save();
    }

    private function amountInMinorUnits(string $amount): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new PaymentGatewayException(
                'The booking total is not a valid payment amount.',
                'booking_payment_amount_invalid',
            );
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
