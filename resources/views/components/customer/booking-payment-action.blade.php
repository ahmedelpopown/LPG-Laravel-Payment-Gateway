@props(['booking'])

@php
    $latestPayment = \Ahmed\LaravelPayment\Models\BookingPayment::query()
        ->where('booking_id', $booking->getKey())
        ->latest('id')
        ->first();
@endphp

<div class="mt-3 flex flex-wrap items-center gap-2">
    @if($latestPayment?->status === \Ahmed\LaravelPayment\Enums\PaymentStatus::Paid)
        <span class="inline-flex items-center gap-1.5 rounded-xl bg-green-50 px-3 py-2 text-xs font-bold text-green-700">
            <span aria-hidden="true">✓</span>
            تم الدفع
        </span>
    @elseif($latestPayment?->status === \Ahmed\LaravelPayment\Enums\PaymentStatus::PartiallyRefunded)
        <span class="inline-flex rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">
            تم استرداد جزء من المبلغ
        </span>
    @elseif($latestPayment?->status === \Ahmed\LaravelPayment\Enums\PaymentStatus::Refunded)
        <span class="inline-flex rounded-xl bg-gray-100 px-3 py-2 text-xs font-bold text-gray-600">
            تم استرداد المبلغ
        </span>
    @elseif($latestPayment?->status === \Ahmed\LaravelPayment\Enums\PaymentStatus::Processing)
        <span class="inline-flex items-center gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">
            جاري تأكيد الدفع
        </span>
    @elseif($latestPayment?->status === \Ahmed\LaravelPayment\Enums\PaymentStatus::Pending && filled($latestPayment->checkout_url))
        <a
            href="{{ $latestPayment->checkout_url }}"
            rel="noopener noreferrer"
            class="inline-flex items-center justify-center rounded-xl bg-[#5b3025] px-4 py-2.5 text-xs font-bold text-white transition hover:bg-[#754638]"
        >
            متابعة الدفع
        </a>
    @elseif($booking->status === 'pending')
        <form method="POST" action="{{ route('customer.bookings.payments.paypal', ['booking' => $booking->getKey()]) }}">
            @csrf
            <button
                type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-[#5b3025] px-4 py-2.5 text-xs font-bold text-white transition hover:bg-[#754638]"
            >
                {{ in_array($latestPayment?->status, [\Ahmed\LaravelPayment\Enums\PaymentStatus::Failed, \Ahmed\LaravelPayment\Enums\PaymentStatus::Cancelled], true) ? 'إعادة المحاولة عبر PayPal' : 'الدفع عبر PayPal' }}
            </button>
        </form>

        <form method="POST" action="{{ route('customer.bookings.payments.stripe', ['booking' => $booking->getKey()]) }}">
            @csrf
            <button
                type="submit"
                class="inline-flex items-center justify-center rounded-xl bg-[#635bff] px-4 py-2.5 text-xs font-bold text-white transition hover:bg-[#5148e5]"
            >
                {{ in_array($latestPayment?->status, [\Ahmed\LaravelPayment\Enums\PaymentStatus::Failed, \Ahmed\LaravelPayment\Enums\PaymentStatus::Cancelled], true) ? 'إعادة المحاولة عبر Stripe' : 'الدفع بالبطاقة عبر Stripe' }}
            </button>
        </form>
    @endif

    @if($latestPayment)
        <span class="text-xs font-semibold text-gray-400">
            {{ number_format((float) $latestPayment->amount, 2) }} {{ $latestPayment->currency }}
        </span>
    @endif
</div>
