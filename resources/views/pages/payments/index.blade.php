<div dir="rtl" class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    @php
        $paymentStatusLabels = [
            'pending' => 'في انتظار الدفع',
            'processing' => 'قيد المعالجة',
            'paid' => 'مدفوع',
            'failed' => 'فشل الدفع',
            'cancelled' => 'ملغي',
            'partially_refunded' => 'مسترد جزئيًا',
            'refunded' => 'مسترد بالكامل',
        ];
        $paymentStatusClasses = [
            'pending' => 'bg-amber-100 text-amber-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'paid' => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-700',
            'partially_refunded' => 'bg-orange-100 text-orange-800',
            'refunded' => 'bg-purple-100 text-purple-800',
        ];
        $refundStatusLabels = [
            'pending' => 'في الانتظار',
            'processing' => 'قيد المعالجة',
            'completed' => 'مكتمل',
            'failed' => 'فشل',
        ];
        $refundStatusClasses = [
            'pending' => 'bg-amber-100 text-amber-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'completed' => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
        ];
    @endphp

    <x-ui.page-header
        title="المدفوعات"
        description="متابعة مدفوعات الحجوزات وسجل الاسترداد لكل عملية."
    />

    <div class="space-y-3">
        <x-ui.alert type="success" :message="session('message')" />
        <x-ui.alert type="danger" :message="session('error')" />
    </div>

    <x-ui.card title="البحث والتصفية" description="ابحث ببيانات العميل أو رقم العملية، ثم صفِّ النتائج حسب الحالة أو مزود الدفع.">
        <div class="grid grid-cols-1 items-end gap-4 md:grid-cols-4">
            <x-ui.input
                label="بحث"
                name="search"
                type="search"
                placeholder="اسم العميل أو رقم العملية..."
                wire:model.live.debounce.300ms="search"
            />

            <x-ui.select label="حالة الدفع" name="status" wire:model.live="status">
                <option value="all">كل الحالات</option>
                @foreach ($paymentStatusLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select label="مزود الدفع" name="provider" wire:model.live="provider">
                <option value="all">كل المزودين</option>
                <option value="paypal">PayPal</option>
                <option value="stripe">Stripe</option>
            </x-ui.select>

            <div class="flex flex-wrap items-end gap-2">
                <x-ui.select label="عدد النتائج" name="perPage" wire:model.live="perPage">
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </x-ui.select>
                <x-ui.action variant="secondary" wire:click="resetFilters">
                    إعادة ضبط
                </x-ui.action>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-right text-gray-700">
                    <tr>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">العميل والحجز</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">مزود الدفع</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">المبلغ</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">الحالة</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">المسترد / المتبقي</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">تاريخ العملية</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($this->payments as $payment)
                        @php
                            $paymentStatus = $payment->status->value;
                            $canRefund = in_array($paymentStatus, ['paid', 'partially_refunded'], true)
                                && $payment->refundable_amount !== '0.00';
                        @endphp
                        <tr wire:key="booking-payment-{{ $payment->id }}" class="align-top">
                            <td class="px-4 py-4">
                                <div class="font-semibold text-gray-900">{{ $payment->booking?->user?->name ?? 'عميل محذوف' }}</div>
                                <div class="mt-1 text-xs text-gray-500">حجز #{{ $payment->booking_id }}</div>
                                @if ($payment->booking?->branch)
                                    <div class="mt-1 text-xs text-gray-500">{{ $payment->booking->branch->name }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <span class="font-medium uppercase text-gray-800">{{ $payment->provider->value }}</span>
                                @if ($payment->provider_reference)
                                    <div class="mt-1 max-w-48 truncate text-xs text-gray-500" title="{{ $payment->provider_reference }}">
                                        {{ $payment->provider_reference }}
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <div class="font-semibold tabular-nums text-gray-900">{{ $payment->amount }} {{ $payment->currency }}</div>
                                <div class="mt-1 text-xs text-gray-500">المسترد: {{ $payment->completed_refund_total }} {{ $payment->currency }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $paymentStatusClasses[$paymentStatus] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ $paymentStatusLabels[$paymentStatus] ?? $paymentStatus }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 tabular-nums">
                                <div class="font-semibold text-gray-900">{{ $payment->refundable_amount }} {{ $payment->currency }}</div>
                                <div class="mt-1 text-xs text-gray-500">متاح للاسترداد</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-gray-600">
                                {{ $payment->created_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="min-w-44 px-4 py-4">
                                @if ($canRefund)
                                    <button
                                        type="button"
                                        wire:click="openRefund({{ $payment->id }})"
                                        class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                                        wire:loading.attr="disabled"
                                    >
                                        إنشاء استرداد
                                    </button>
                                @else
                                    <span class="text-xs text-gray-400">لا يوجد استرداد متاح</span>
                                @endif

                                @if ($payment->refunds->isNotEmpty())
                                    <details class="mt-3 text-xs">
                                        <summary class="cursor-pointer font-semibold text-indigo-700">سجل الاسترداد ({{ $payment->refunds->count() }})</summary>
                                        <div class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3">
                                            @foreach ($payment->refunds->sortByDesc('id') as $refund)
                                                @php $refundStatus = $refund->status->value; @endphp
                                                <div wire:key="refund-{{ $refund->id }}" class="border-b border-gray-200 pb-2 last:border-0 last:pb-0">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <span class="font-semibold text-gray-800">{{ $refund->amount }} {{ $refund->currency }}</span>
                                                        <span class="rounded-full px-2 py-1 {{ $refundStatusClasses[$refundStatus] ?? 'bg-gray-100 text-gray-700' }}">
                                                            {{ $refundStatusLabels[$refundStatus] ?? $refundStatus }}
                                                        </span>
                                                    </div>
                                                    @if ($refund->reason)
                                                        <p class="mt-1 text-gray-500">{{ $refund->reason }}</p>
                                                    @endif
                                                    @if ($refund->failure_message)
                                                        <p class="mt-1 break-words text-red-700">{{ $refund->failure_message }}</p>
                                                    @endif
                                                    @if (in_array($refundStatus, ['processing', 'failed'], true))
                                                        <button
                                                            type="button"
                                                            wire:click="retryRefund({{ $refund->id }})"
                                                            wire:loading.attr="disabled"
                                                            class="mt-2 font-semibold text-indigo-700 hover:text-indigo-900 disabled:opacity-50"
                                                        >
                                                            إعادة التحقق بنفس رقم العملية
                                                        </button>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-gray-500">
                                لا توجد مدفوعات تطابق معايير البحث.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->payments->hasPages())
            <div class="mt-4 border-t border-gray-100 pt-4">
                {{ $this->payments->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showRefundModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="refund-modal-title">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="refund-modal-title" class="text-lg font-bold text-gray-900">إنشاء طلب استرداد</h2>
                        <p class="mt-1 text-sm text-gray-500">الحد الأقصى المتاح للاسترداد: {{ $refundableBalance }}</p>
                    </div>
                    <button type="button" wire:click="closeRefundModal" class="text-2xl leading-none text-gray-400 hover:text-gray-700" aria-label="إغلاق">&times;</button>
                </div>

                <form wire:submit="submitRefund" class="mt-6 space-y-4">
                    <x-ui.input
                        label="مبلغ الاسترداد"
                        name="refundAmount"
                        type="number"
                        min="0.01"
                        :max="$refundableBalance"
                        step="0.01"
                        wire:model="refundAmount"
                        :disabled="$refundAttemptStarted"
                    />
                    <div>
                        <label for="refundReason" class="mb-1 block text-sm font-medium text-gray-700">سبب الاسترداد (اختياري)</label>
                        <textarea
                            id="refundReason"
                            wire:model="refundReason"
                            @disabled($refundAttemptStarted)
                            rows="3"
                            maxlength="255"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                            placeholder="مثال: إلغاء خدمة من الحجز"
                        ></textarea>
                    </div>

                    <p class="rounded-lg bg-amber-50 p-3 text-xs leading-6 text-amber-800">
                        عند تعذر معرفة نتيجة طلب الاسترداد، سيظل المبلغ محجوزًا حتى تتم إعادة التحقق بنفس رقم العملية. لا تنشئ طلبًا جديدًا لنفس الاسترداد.
                    </p>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
                        <button type="button" wire:click="closeRefundModal" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            إلغاء
                        </button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                            <span wire:loading.remove wire:target="submitRefund">تأكيد الاسترداد</span>
                            <span wire:loading wire:target="submitRefund">جارٍ إرسال الطلب...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
