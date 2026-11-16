<?php

declare(strict_types=1);

namespace Ahmed\LaravelPayment\Livewire;

use Closure;
use Ahmed\LaravelPayment\Models\TenantPaymentCredential;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('إعدادات وسائل الدفع')]
final class PaymentMethodSettings extends Component
{
    public string $paypalClientId = '';
    public string $paypalClientSecret = '';
    public string $paypalWebhookId = '';
    public string $paypalMode = 'sandbox';

    #[Locked]
    public bool $hasPaypalClientSecret = false;

    public bool $clearPaypalClientSecret = false;

    public string $stripePublishableKey = '';
    public string $stripeSecretKey = '';
    public string $stripeWebhookSecret = '';

    #[Locked]
    public bool $hasStripeSecretKey = false;

    #[Locked]
    public bool $hasStripeWebhookSecret = false;

    public bool $clearStripeSecretKey = false;
    public bool $clearStripeWebhookSecret = false;

    public function mount(): void
    {
        $this->ensureAdmin();

        $tenantId = $this->currentTenantId();
        $credentials = TenantPaymentCredential::query()->firstOrNew(['tenant_id' => $tenantId]);

        $this->paypalClientId = (string) ($credentials->paypal_client_id ?? '');
        $this->paypalWebhookId = (string) ($credentials->paypal_webhook_id ?? '');
        $this->paypalMode = (string) ($credentials->paypal_mode ?: 'sandbox');
        $this->hasPaypalClientSecret = $this->hasStoredCredential($credentials, 'paypal_client_secret');

        $this->stripePublishableKey = (string) ($credentials->stripe_publishable_key ?? '');
        $this->hasStripeSecretKey = $this->hasStoredCredential($credentials, 'stripe_secret_key');
        $this->hasStripeWebhookSecret = $this->hasStoredCredential($credentials, 'stripe_webhook_secret');

        // Secret values are deliberately never loaded into Livewire's public state.
    }

    public function savePaypal(): void
    {
        $this->ensureAdmin();

        $validated = $this->validate([
            'paypalClientId' => ['nullable', 'string', 'max:255'],
            'paypalClientSecret' => ['nullable', 'string', 'max:1000'],
            'paypalWebhookId' => ['nullable', 'string', 'max:120'],
            'paypalMode' => ['required', 'in:sandbox,live'],
        ]);

        $tenantId = $this->currentTenantId();
        $credentials = TenantPaymentCredential::query()->firstOrNew(['tenant_id' => $tenantId]);

        $attributes = [
            'paypal_client_id' => $this->nullableTrim($validated['paypalClientId']),
            'paypal_webhook_id' => $this->nullableTrim($validated['paypalWebhookId']),
            'paypal_mode' => $validated['paypalMode'],
        ];

        $newClientSecret = $this->nullableTrim($validated['paypalClientSecret']);

        if ($newClientSecret !== null) {
            $attributes['paypal_client_secret'] = $newClientSecret;
        } elseif ($this->clearPaypalClientSecret) {
            $attributes['paypal_client_secret'] = null;
        }

        $credentials->forceFill($attributes)->save();

        $this->paypalClientSecret = '';
        $this->clearPaypalClientSecret = false;
        $this->hasPaypalClientSecret = $this->hasStoredCredential($credentials, 'paypal_client_secret');

        $this->resetValidation();
        session()->flash('payment-settings-success', 'تم حفظ إعدادات PayPal بنجاح.');
    }

    public function saveStripe(): void
    {
        $this->ensureAdmin();

        $validated = $this->validate([
            'stripePublishableKey' => [
                'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! preg_match('/^pk_(test|live)_/', (string) $value)) {
                        $fail('يجب أن يبدأ Stripe Publishable Key بـ pk_test_ أو pk_live_.');
                    }
                },
            ],
            'stripeSecretKey' => [
                'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! preg_match('/^(sk|rk)_(test|live)_/', (string) $value)) {
                        $fail('يجب أن يبدأ Stripe Secret Key بـ sk_test_ أو sk_live_ أو rk_test_ أو rk_live_.');
                    }
                },
            ],
            'stripeWebhookSecret' => [
                'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! str_starts_with((string) $value, 'whsec_')) {
                        $fail('يجب أن يبدأ Webhook Signing Secret بـ whsec_.');
                    }
                },
            ],
        ]);

        $tenantId = $this->currentTenantId();
        $credentials = TenantPaymentCredential::query()->firstOrNew(['tenant_id' => $tenantId]);

        $attributes = [
            'stripe_publishable_key' => $this->nullableTrim($validated['stripePublishableKey']),
        ];

        $newSecretKey = $this->nullableTrim($validated['stripeSecretKey']);
        if ($newSecretKey !== null) {
            $attributes['stripe_secret_key'] = $newSecretKey;
        } elseif ($this->clearStripeSecretKey) {
            $attributes['stripe_secret_key'] = null;
        }

        $newWebhookSecret = $this->nullableTrim($validated['stripeWebhookSecret']);
        if ($newWebhookSecret !== null) {
            $attributes['stripe_webhook_secret'] = $newWebhookSecret;
        } elseif ($this->clearStripeWebhookSecret) {
            $attributes['stripe_webhook_secret'] = null;
        }

        $credentials->forceFill($attributes)->save();

        $this->stripeSecretKey = '';
        $this->stripeWebhookSecret = '';
        $this->clearStripeSecretKey = false;
        $this->clearStripeWebhookSecret = false;
        $this->hasStripeSecretKey = $this->hasStoredCredential($credentials, 'stripe_secret_key');
        $this->hasStripeWebhookSecret = $this->hasStoredCredential($credentials, 'stripe_webhook_secret');

        $this->resetValidation();
        session()->flash('payment-settings-success', 'تم حفظ إعدادات Stripe بنجاح.');
    }

    private function ensureAdmin(): void
    {
        abort_unless(auth((string) config('payments.auth.employee_guard', 'employee'))->user()?->hasRole((string) config('payments.auth.admin_role', 'admin')), 403);
    }

    private function currentTenantId(): string
    {
        $tenancy = tenancy();

        abort_unless($tenancy->initialized && $tenancy->tenant !== null, 404);

        return (string) $tenancy->tenant->getTenantKey();
    }

    private function hasStoredCredential(TenantPaymentCredential $credentials, string $column): bool
    {
        // Read the raw value so an old plaintext value created before encrypted casts
        // does not throw while the settings page is opening.
        $value = $credentials->getRawOriginal($column);

        return is_string($value) && trim($value) !== '';
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('payment::pages.settings.payment-methods.index')->layout(config('payments.layout', 'layouts::app'));
    }
}
