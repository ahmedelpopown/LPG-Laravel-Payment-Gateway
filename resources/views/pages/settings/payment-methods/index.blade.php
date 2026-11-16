<div dir="rtl" class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">إعدادات وسائل الدفع</h1>
            <p class="mt-1 text-sm text-slate-600">اربط حسابات الدفع الخاصة بالمحل. كل محل يحتفظ بمفاتيحه بشكل مستقل.</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
            إعدادات المحل الحالي
        </span>
    </div>

    @if (session()->has('payment-settings-success'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            {{ session('payment-settings-success') }}
        </div>
    @endif

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-xl font-bold text-blue-700">P</span>
                <div>
                    <h2 class="text-lg font-bold text-slate-900">PayPal</h2>
                    <p class="text-sm text-slate-500">PayPal REST API Credentials</p>
                </div>
            </div>
            @if (filled($paypalClientId) && $hasPaypalClientSecret)
                <span class="inline-flex w-fit items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">بيانات الحساب محفوظة</span>
            @else
                <span class="inline-flex w-fit items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">بيانات الحساب غير مكتملة</span>
            @endif
        </div>

        <form wire:submit="savePaypal" class="space-y-5 p-5">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="paypal-client-id" class="mb-1.5 block text-sm font-semibold text-slate-700">Client ID</label>
                    <input
                        id="paypal-client-id"
                        type="text"
                        wire:model="paypalClientId"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="PayPal Client ID"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    >
                    @error('paypalClientId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="paypal-client-secret" class="mb-1.5 block text-sm font-semibold text-slate-700">Client Secret</label>
                    <input
                        id="paypal-client-secret"
                        type="password"
                        wire:model="paypalClientSecret"
                        autocomplete="new-password"
                        placeholder="{{ $hasPaypalClientSecret ? 'اتركه فارغًا للاحتفاظ بالمفتاح الحالي' : 'PayPal Client Secret' }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    >
                    @error('paypalClientSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($hasPaypalClientSecret)
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700">
                            <input type="checkbox" wire:model="clearPaypalClientSecret" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                            حذف الـ Client Secret المحفوظ
                        </label>
                    @endif
                </div>

                <div>
                    <label for="paypal-webhook-id" class="mb-1.5 block text-sm font-semibold text-slate-700">Webhook ID <span class="font-normal text-slate-400">(اختياري)</span></label>
                    <input
                        id="paypal-webhook-id"
                        type="text"
                        wire:model="paypalWebhookId"
                        autocomplete="off"
                        placeholder="PayPal Webhook ID"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    >
                    @error('paypalWebhookId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="paypal-mode" class="mb-1.5 block text-sm font-semibold text-slate-700">Environment</label>
                    <select
                        id="paypal-mode"
                        wire:model="paypalMode"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    >
                        <option value="sandbox">Sandbox (Testing)</option>
                        <option value="live">Live (Production)</option>
                    </select>
                    @error('paypalMode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-6 text-slate-500">الـ Client Secret يتم تشفيره قبل تخزينه، ولا نعرض قيمته بعد الحفظ.</p>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="savePaypal"
                    class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="savePaypal">حفظ إعدادات PayPal</span>
                    <span wire:loading wire:target="savePaypal">جاري الحفظ...</span>
                </button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-xl font-bold text-violet-700">S</span>
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Stripe</h2>
                    <p class="text-sm text-slate-500">Stripe API Credentials</p>
                </div>
            </div>
            @if ($hasStripeSecretKey)
                <span class="inline-flex w-fit items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Secret Key محفوظ</span>
            @else
                <span class="inline-flex w-fit items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Stripe غير مفعّل</span>
            @endif
        </div>

        <form wire:submit="saveStripe" class="space-y-5 p-5">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="stripe-publishable-key" class="mb-1.5 block text-sm font-semibold text-slate-700">Publishable Key</label>
                    <input
                        id="stripe-publishable-key"
                        type="text"
                        wire:model="stripePublishableKey"
                        autocomplete="off"
                        spellcheck="false"
                        dir="ltr"
                        placeholder="pk_test_... أو pk_live_..."
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    >
                    @error('stripePublishableKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="stripe-secret-key" class="mb-1.5 block text-sm font-semibold text-slate-700">Secret Key</label>
                    <input
                        id="stripe-secret-key"
                        type="password"
                        wire:model="stripeSecretKey"
                        autocomplete="new-password"
                        dir="ltr"
                        placeholder="{{ $hasStripeSecretKey ? 'اتركه فارغًا للاحتفاظ بالمفتاح الحالي' : 'sk_test_... أو sk_live_...' }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    >
                    @error('stripeSecretKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($hasStripeSecretKey)
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700">
                            <input type="checkbox" wire:model="clearStripeSecretKey" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                            حذف الـ Secret Key المحفوظ
                        </label>
                    @endif
                </div>

                <div class="md:col-span-2">
                    <label for="stripe-webhook-secret" class="mb-1.5 block text-sm font-semibold text-slate-700">Webhook Signing Secret <span class="font-normal text-slate-400">(مطلوب لمعالجة Webhooks)</span></label>
                    <input
                        id="stripe-webhook-secret"
                        type="password"
                        wire:model="stripeWebhookSecret"
                        autocomplete="new-password"
                        dir="ltr"
                        placeholder="{{ $hasStripeWebhookSecret ? 'اتركه فارغًا للاحتفاظ بالمفتاح الحالي' : 'whsec_...' }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100"
                    >
                    @error('stripeWebhookSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($hasStripeWebhookSecret)
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700">
                            <input type="checkbox" wire:model="clearStripeWebhookSecret" class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                            حذف الـ Webhook Signing Secret المحفوظ
                        </label>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-violet-100 bg-violet-50 px-4 py-3 text-xs leading-6 text-violet-900">
                استخدم مفاتيح من نفس البيئة: Test مع Test أو Live مع Live. إنشاء Stripe Checkout يعتمد على Secret Key؛ أما Webhook Signing Secret فيُستخدم للتحقق من توقيع أحداث Stripe.
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-6 text-slate-500">يتم تشفير Secret Key وWebhook Signing Secret قبل تخزينهما، ولا نعرض قيمتهما بعد الحفظ.</p>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveStripe"
                    class="inline-flex items-center justify-center rounded-lg bg-violet-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveStripe">حفظ إعدادات Stripe</span>
                    <span wire:loading wire:target="saveStripe">جاري الحفظ...</span>
                </button>
            </div>
        </form>
    </section>
</div>
