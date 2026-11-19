# Ahmed Laravel Payment

**Version:** 3.0.0  
**Package:** `ahmedelpopown/lpg-laravel-payment-gateway`

حزمة Laravel موحّدة للتعامل مع الدفع الإلكتروني باستخدام **Stripe** و **PayPal**. توفر الحزمة إنشاء عمليات الدفع، وإدارة حالات الدفع، وتنفيذ عمليات الاسترداد الكلي والجزئي، واستقبال Webhooks، ومراجعة العمليات من خلال Reconciliation.

الحزمة لا تفترض نوع المشروع أو أسماء جداول الـ Business Logic. يمكن ربط أي Eloquent Model محفوظ بعملية الدفع باستخدام علاقة `payable`، أو إنشاء Payment مستقلة بدون ربطها بموديل آخر.

---

## 1. ماذا تقدم الحزمة؟

| الجزء | الوظيفة |
|---|---|
| Payment Core | إنشاء Payment موحدة لأي Provider |
| Stripe Gateway | إنشاء Stripe Checkout والتحقق من الدفع والاسترداد |
| PayPal Gateway | إنشاء PayPal Order والتحقق من Capture والاسترداد |
| Idempotency | منع إنشاء Payment أو Refund مكرر لنفس الطلب |
| Payable Relation | ربط Payment بأي Eloquent Model من خلال Polymorphic Relation |
| Refunds | تنفيذ Full Refund وPartial Refund وتسجيل كل محاولة |
| Webhooks | التحقق من توقيع الأحداث الواردة ومنع معالجة نفس الحدث أكثر من مرة |
| Payment Observer | مراقبة تغيّر حالة Payment وإطلاق Event يمكن للتطبيق الاستماع إليه |
| Reconciliation | مقارنة السجلات المحلية بالبيانات المتاحة لدى Stripe أو PayPal |

## 2. كيف تعمل الحزمة؟

مسار إنشاء الدفع:

```text
Laravel Application
        |
        v
CreatePayment
        |
        v
PaymentGatewayManager
        |
        +------ StripeGateway ------> Stripe API
        |
        +------ PayPalGateway ------> PayPal API
        |
        v
Payment Model + Checkout URL
```

مسار تأكيد الدفع:

```text
Provider Return / Verified Webhook
        |
        v
ConfirmPayment / Webhook Processor
        |
        v
FinalizePayment
        |
        v
Update Payment Status
        |
        v
PaymentObserver
        |
        v
PaymentStatusChanged Event
        |
        v
Application Listener (optional)
```

مسار الاسترداد:

```text
RefundPayment
      |
      v
Create local Refund record
      |
      v
Stripe / PayPal Refund API
      |
      v
Update Refund + Payment refunded_amount/status
```

**قاعدة التصميم:** الحزمة تدير بيانات الدفع ومخاطبة مزود الخدمة. أما تحديث حالة Order أو Invoice أو أي Model خاص بالمشروع فيتم في التطبيق المستهلك عن طريق Event Listener.

## 3. المتطلبات

- PHP `^8.3`.
- Laravel 11 أو 12 أو 13.
- Database مدعومة من Laravel وتدعم أنواع الحقول والـ Indexes المستخدمة في Migrations.
- حساب Stripe مع API Keys.
- PayPal REST App مع Client ID وClient Secret.

`stripe/stripe-php` يتم تثبيتها تلقائيًا من Composer. تكامل PayPal يستخدم Laravel HTTP Client.

## 4. تثبيت الحزمة

### 4.1 من Git Repository

أضف الـ Repository إلى `composer.json` في مشروع Laravel:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ahmedelpopown/LPG-Laravel-Payment-Gateway.git"
        }
    ]
}
```

بعد نشر Tag للإصدار `3.0.0` على Git، نفّذ:

```bash
composer require ahmedelpopown/lpg-laravel-payment-gateway:^3.0
```

### 4.2 من Local Path أثناء التطوير

لو مجلد الحزمة ومجلد التطبيق بجانب بعض:

```text
workspace/
├── laravel-payment/
└── mini-store/
```

أضف إلى `composer.json` داخل `mini-store`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-payment",
            "options": {
                "symlink": false,
                "versions": {
                    "ahmedelpopown/lpg-laravel-payment-gateway": "3.0.0"
                }
            }
        }
    ]
}
```

ثم من مجلد `mini-store`:

```bash
composer require ahmedelpopown/lpg-laravel-payment-gateway:3.0.0 -W
```

مع `symlink: false` ينسخ Composer ملفات الحزمة إلى `vendor`. بعد تعديل Source Code الخاص بالحزمة، حدّث النسخة داخل التطبيق بالأمر:

```bash
composer update ahmedelpopown/lpg-laravel-payment-gateway -W
```

يمكن استخدام `symlink: true` أثناء التطوير حتى تنعكس تعديلات ملفات الحزمة مباشرة داخل `vendor`، بشرط أن تسمح بيئة Windows بإنشاء Symlinks.

### 4.3 نشر الإعدادات وإنشاء الجداول

نفّذ من جذر التطبيق المستهلك:

```bash
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=laravel-payment-reconciliation-config
php artisan payments:install
php artisan optimize:clear
```

الأمر `payments:install` ينفذ Migrations الخاصة بالجداول الأساسية على اتصال `PAYMENTS_CONNECTION`، وينفذ جداول Reconciliation على اتصال `PAYMENT_RECONCILIATION_DB_CONNECTION`. إذا لم تحدد اتصال Reconciliation، يستخدم نفس اتصال Payment. وإذا لم تحدد `PAYMENTS_CONNECTION` أيضًا، يستخدم اتصال Laravel الافتراضي.

### 4.4 التحقق من التثبيت

```bash
php artisan migrate:status
php artisan list payments
```

الجداول الأساسية التي تنشئها الحزمة:

- `payments`
- `payment_refunds`
- `payment_webhook_events`
- `payment_reconciliation_runs`
- `payment_reconciliation_items`
- `payment_reconciliation_resolutions`

## 5. إعداد Stripe وPayPal

أضف البيانات إلى `.env` في التطبيق المستهلك.

### Stripe

```dotenv
STRIPE_SECRET=sk_test_xxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxx
STRIPE_PRODUCT_NAME="My Store"
```

### PayPal Sandbox

```dotenv
PAYPAL_CLIENT_ID=xxxxxxxxx
PAYPAL_CLIENT_SECRET=xxxxxxxxx
PAYPAL_WEBHOOK_ID=xxxxxxxxx
PAYPAL_MODE=sandbox
PAYPAL_TIMEOUT=20
PAYPAL_CONNECT_TIMEOUT=10
PAYPAL_BRAND_NAME="My Store"
```

### إعدادات عامة اختيارية

```dotenv
PAYMENTS_CONNECTION=mysql
PAYMENT_RECONCILIATION_DB_CONNECTION=mysql
PAYMENTS_SUCCESS_URL=https://example.com/payment/success
PAYMENTS_CANCEL_URL=https://example.com/payment/cancel
```

- `PAYMENTS_CONNECTION`: اتصال قاعدة البيانات الذي ستستخدمه جداول Payment وRefund وWebhook. عند تركه فارغًا، يستخدم التطبيق الاتصال الافتراضي.
- `PAYMENT_RECONCILIATION_DB_CONNECTION`: اتصال جداول Reconciliation. عند تركه فارغًا، يستخدم نفس اتصال Payment.
- `PAYMENTS_SUCCESS_URL`: رابط التطبيق الذي يتم تحويل المستخدم إليه بعد Callback ناجح أو بعد محاولة التحقق.
- `PAYMENTS_CANCEL_URL`: رابط التطبيق الذي يتم تحويل المستخدم إليه عند الرجوع من صفحة الدفع دون إكمالها.

بعد تعديل `.env`:

```bash
php artisan optimize:clear
```

ابدأ دائمًا باستخدام Stripe Test Keys وPayPal Sandbox. تأكد أيضًا أن العملة المطلوبة موجودة في `supported_currencies` الخاصة بالـ Provider داخل `config/payments.php`.

## 6. إنشاء Payment

الـ Action الأساسي هو:

```php
Ahmed\LaravelPayment\Core\Actions\CreatePayment
```

### 6.1 إنشاء Payment بدون ربطها بموديل

```php
use Ahmed\LaravelPayment\Core\Actions\CreatePayment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use Illuminate\Support\Str;

$payment = app(CreatePayment::class)->handle(
    provider: PaymentProvider::Stripe,
    amount: '125.00',
    currency: 'USD',
    idempotencyKey: (string) Str::uuid(),
    metadata: [
        'purpose' => 'test-payment',
        'source' => 'website',
    ],
);

return redirect()->away($payment->checkout_url);
```

لاستخدام PayPal غيّر الـ Provider فقط:

```php
use Ahmed\LaravelPayment\Enums\PaymentProvider;

$payment = app(CreatePayment::class)->handle(
    provider: PaymentProvider::PayPal,
    amount: '125.00',
    currency: 'USD',
    idempotencyKey: (string) Str::uuid(),
    metadata: ['purpose' => 'paypal-checkout'],
);
```

`CreatePayment` يستقبل:

| Parameter | النوع | المعنى |
|---|---|---|
| `provider` | `PaymentProvider|string` | `Stripe` أو `PayPal` |
| `amount` | `string` | المبلغ بصيغة Decimal مثل `125.00`، بدون Float |
| `currency` | `string` | كود العملة من 3 أحرف مثل `USD` أو `EGP` |
| `idempotencyKey` | `string` | مفتاح فريد لنفس محاولة الدفع |
| `metadata` | `array` | بيانات إضافية اختيارية |
| `payable` | `?Model` | Eloquent Model محفوظ، اختياري |

الـ Action ينشئ السجل المحلي، ثم يطلب Checkout من الـ Provider ويحفظ `provider_reference` و`checkout_url`. الناتج هو `Payment` Model.

### 6.2 ربط Payment بأي Model

مثال على Order:

```php
$payment = app(CreatePayment::class)->handle(
    provider: PaymentProvider::Stripe,
    amount: (string) $order->total_amount,
    currency: $order->currency,
    idempotencyKey: $order->payment_attempt_key,
    metadata: [
        'order_number' => $order->number,
    ],
    payable: $order,
);
```

يجب حفظ `$order` في قاعدة البيانات قبل تمريره إلى `payable`.

لإحضار عمليات الدفع من داخل `Order` Model:

```php
use Ahmed\LaravelPayment\Core\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function payments(): MorphMany
{
    return $this->morphMany(Payment::class, 'payable');
}
```

بعدها:

```php
$order->payments;
$order->payments()->latest()->first();
```

### 6.3 Idempotency Key

يمنع `idempotencyKey` إنشاء عملية دفع جديدة عند إعادة نفس الطلب بنفس المفتاح والبيانات. لو استُخدم المفتاح نفسه مع Provider أو مبلغ أو عملة أو Payable مختلف، ترجع الحزمة خطأ تعارض.

في التطبيق الحقيقي، خزّن مفتاح المحاولة في قاعدة بيانات المشروع وأعد استخدامه عند Retry لنفس المحاولة. لا تنشئ UUID جديدًا لمجرد أن Request انتهى بـ Timeout؛ فقد تكون بوابة الدفع أنشأت العملية بالفعل.

مثال مبسط لتوليد المفتاح مرة واحدة:

```php
$order->payment_attempt_key ??= (string) Str::uuid();
$order->save();
```

## 7. دورة حياة Payment

### 7.1 قراءة Payment

```php
use Ahmed\LaravelPayment\Core\Models\Payment;

$payment = Payment::query()
    ->where('public_id', $publicId)
    ->firstOrFail();

$status = $payment->status->value;
$provider = $payment->provider->value;
```

`public_id` هو UUID عام تستخدمه الحزمة كـ Route Key. تجنب كشف رقم قاعدة البيانات الداخلي في الروابط العامة.

### 7.2 أهم خصائص Payment

```php
$payment->id;
$payment->public_id;
$payment->payable;
$payment->provider;
$payment->provider_reference;
$payment->provider_transaction_id;
$payment->idempotency_key;
$payment->amount;
$payment->refunded_amount;
$payment->currency;
$payment->status;
$payment->checkout_url;
$payment->metadata;
$payment->paid_at;
$payment->refunded_at;
$payment->refunds;
```

### 7.3 حالات Payment

| Enum | القيمة | المعنى |
|---|---|---|
| `PaymentStatus::Pending` | `pending` | تم إنشاء العملية ولم يتم تأكيد التحصيل |
| `PaymentStatus::Processing` | `processing` | العملية قيد المعالجة لدى Provider |
| `PaymentStatus::Paid` | `paid` | تم تأكيد الدفع |
| `PaymentStatus::Failed` | `failed` | فشل الدفع |
| `PaymentStatus::Cancelled` | `cancelled` | تم تأكيد الإلغاء |
| `PaymentStatus::PartiallyRefunded` | `partially_refunded` | تم استرداد جزء من المبلغ |
| `PaymentStatus::Refunded` | `refunded` | تم استرداد المبلغ بالكامل |

للقراءة استخدم `$payment->status->value` و`$payment->provider->value`.

### 7.4 تأكيد الدفع يدويًا من السيرفر

في العادة يتم تأكيد الدفع من خلال Return Callback أو Webhook. عند الحاجة إلى الاستعلام من السيرفر مباشرة:

```php
use Ahmed\LaravelPayment\Core\Actions\ConfirmPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;

$payment = Payment::query()->findOrFail($paymentId);
$payment = app(ConfirmPayment::class)->handle($payment);
```

الـ Action يستدعي الـ Gateway المناسب، ثم يمرر النتيجة إلى `FinalizePayment` للتحقق من المبلغ والعملة والمراجع وتحديث السجل المحلي.

### 7.5 إلغاء Checkout

```php
use Ahmed\LaravelPayment\Core\Actions\CancelPayment;

$payment = app(CancelPayment::class)->handle($payment);
```

الإلغاء الخارجي المدعوم حاليًا من خلال هذا الـ Action هو Stripe Checkout. لا يغيّر الـ Action حالة العملية بناءً على مجرد مغادرة المستخدم للصفحة؛ يجب أن تكون نتيجة الإلغاء مؤكدة من Provider.

## 8. ربط حالة Payment بمنطق التطبيق

الحزمة لا تعدّل حالة Order أو Invoice تلقائيًا؛ لأنها لا تعرف Models التطبيق. بدلًا من ذلك، تسجل الحزمة `PaymentObserver` تلقائيًا داخل Service Provider الخاص بها.

عندما تتغير قيمة `status` في Payment، يطلق الـ Observer الحدث:

```php
Ahmed\LaravelPayment\Events\PaymentStatusChanged
```

يتم Dispatch للحدث بعد Commit الخاص بالـ Transaction. لا تحتاج إلى تسجيل Observer مرة أخرى في التطبيق المستهلك.

### مثال Listener داخل التطبيق

أنشئ `app/Listeners/SyncOrderPaymentStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use Ahmed\LaravelPayment\Enums\PaymentStatus;
use Ahmed\LaravelPayment\Events\PaymentStatusChanged;
use App\Models\Order;

final class SyncOrderPaymentStatus
{
    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;
        $order = $payment->payable;

        if (! $order instanceof Order) {
            return;
        }

        $status = match ($payment->status) {
            PaymentStatus::Paid => 'paid',
            PaymentStatus::PartiallyRefunded => 'partially_refunded',
            PaymentStatus::Refunded => 'refunded',
            PaymentStatus::Failed => 'payment_failed',
            PaymentStatus::Cancelled => 'cancelled',
            default => null,
        };

        if ($status !== null && $order->payment_status !== $status) {
            $order->forceFill(['payment_status' => $status])->save();
        }
    }
}
```

سجّل الـ Listener داخل `app/Providers/AppServiceProvider.php`:

```php
use Ahmed\LaravelPayment\Events\PaymentStatusChanged;
use App\Listeners\SyncOrderPaymentStatus;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(
        PaymentStatusChanged::class,
        SyncOrderPaymentStatus::class,
    );
}
```

عدّل قيم حالات Order لتطابق مشروعك. اجعل تنفيذ الـ Business Logic الذي يحدث بعد الدفع Idempotent حتى لا يؤدي وصول Return Callback وWebhook لنفس العملية إلى تنفيذ الطلب مرتين.

**ملاحظة:** `PaymentObserver` يعتمد على Eloquent Model Events؛ تحديث السجلات باستخدام Mass Update مثل `Payment::where(...)->update(...)` لا يطلق أحداث الموديل.

## 9. Return URLs وWebhooks

تسجل الحزمة المسارات التالية تلقائيًا عند تفعيل `payments.routes.core_auto_register`:

| Method | URL | الوظيفة |
|---|---|---|
| `GET` | `/payments/return/{provider}/{payment}` | التحقق من حالة الدفع بعد الرجوع من Provider |
| `GET` | `/payments/cancel/{provider}/{payment}` | التعامل مع رجوع المستخدم دون إكمال Checkout |
| `POST` | `/payments/webhooks/stripe` | استقبال Stripe Webhooks والتحقق من التوقيع |
| `POST` | `/payments/webhooks/paypal` | استقبال PayPal Webhooks والتحقق من التوقيع |

أضف عناوين Webhook نفسها في لوحات Stripe وPayPal. تأكد من استخدام Webhook Secret أو Webhook ID الخاص بنفس Endpoint ونفس بيئة Test/Live.

### ما الفرق بين Return وWebhook؟

- Return هو رجوع متصفح المستخدم إلى التطبيق. الحزمة لا تعتبر مغادرة صفحة الدفع أو الرجوع منها دليلًا كافيًا على نجاح أو فشل الدفع.
- Webhook هو Server-to-Server Event من Provider. الحزمة تتحقق من التوقيع، ثم تعالج الحدث وتحفظه في `payment_webhook_events`.
- تكرار نفس Webhook Event ID لا يؤدي إلى تطبيق نفس الحدث أكثر من مرة. الأحداث التي لم تصل إلى حالة نهائية تظل قابلة لإعادة المحاولة.

### أنواع أحداث Stripe المستخدمة

تدعم معالجة أحداث Checkout الأساسية، بالإضافة إلى أحداث Refund مثل:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `refund.created`
- `refund.updated`
- `refund.failed`
- `charge.refund.updated`
- `charge.refunded`

أما PayPal، فتتعامل الحزمة مع الموافقة على Order وأحداث Capture الأساسية. الأحداث غير المدعومة يتم تسجيلها كـ `ignored` بدلًا من تطبيق تغيير غير معروف.

## 10. Refunds

الـ Action الموحد للاسترداد:

```php
Ahmed\LaravelPayment\Core\Actions\RefundPayment
```

يسمح بإنشاء Full Refund أو Partial Refund، بشرط أن تكون Payment محصلة وقابلة للاسترداد.

### 10.1 Partial Refund

```php
use Ahmed\LaravelPayment\Core\Actions\RefundPayment;
use Illuminate\Support\Str;

$refund = app(RefundPayment::class)->handle(
    payment: $payment,
    amount: '25.00',
    idempotencyKey: (string) Str::uuid(),
    requestedBy: auth()->user(),
    reason: 'Customer requested a partial refund',
);
```

| Parameter | المعنى |
|---|---|
| `payment` | Payment المراد استرداد مبلغ منها |
| `amount` | المبلغ بصيغة Decimal string مثل `25.00` |
| `idempotencyKey` | مفتاح فريد لطلب Refund نفسه |
| `requestedBy` | Eloquent Model محفوظ، اختياري مثل User أو Admin |
| `reason` | سبب الاسترداد، اختياري |

في Production، خزّن مفتاح Refund مع طلب الاسترداد في قاعدة بيانات التطبيق وأعد استخدامه عند Retry لنفس الطلب. لا تنشئ مفتاحًا جديدًا بعد Timeout قبل التحقق من نتيجة الطلب لدى Provider.

### 10.2 Full Refund

احسب المبلغ المتبقي باستخدام Decimal أو Money Library، وليس Float:

```php
$remainingAmount = bcsub(
    (string) $payment->amount,
    (string) $payment->refunded_amount,
    2,
);

$refund = app(RefundPayment::class)->handle(
    payment: $payment,
    amount: $remainingAmount,
    idempotencyKey: $storedRefundIdempotencyKey,
    requestedBy: auth()->user(),
    reason: 'Full refund',
);
```

المثال يستخدم PHP BCMath؛ تأكد من تفعيل امتداد `bcmath` أو استخدم مكتبة Money مناسبة.

### 10.3 قراءة Refunds

```php
$refunds = $payment->refunds()->latest()->get();

foreach ($refunds as $refund) {
    dump([
        'id' => $refund->id,
        'provider_refund_id' => $refund->provider_refund_id,
        'amount' => $refund->amount,
        'currency' => $refund->currency,
        'status' => $refund->status->value,
        'reason' => $refund->reason,
        'failure_message' => $refund->failure_message,
        'processed_at' => $refund->processed_at,
    ]);
}
```

### 10.4 حالات Refund

- `RefundStatus::Pending`: لم يبدأ التنفيذ النهائي بعد.
- `RefundStatus::Processing`: الطلب قيد التنفيذ أو يحتاج إلى متابعة.
- `RefundStatus::Completed`: تم تأكيد الاسترداد.
- `RefundStatus::Failed`: فشل الطلب بشكل مؤكد.

الحزمة تحجز المبلغ أثناء المعالجة وتمنع أن يتجاوز مجموع الاستردادات المبلغ الأصلي. وعند اكتمال الاسترداد تحدث `refunded_amount` وحالة Payment إلى `partially_refunded` أو `refunded`.

## 11. Reconciliation

تطابق Reconciliation سجلات الدفع المحلية مع البيانات التي يعرضها Provider. تستخدم لاكتشاف اختلافات الحالة والمبلغ والعملة والـ References والاستردادات. لا تقوم تلقائيًا بتعديل Order أو بيانات المشروع.

### 11.1 الإعداد

انشر الإعدادات إذا لم تنشرها في خطوة التثبيت:

```bash
php artisan vendor:publish --tag=laravel-payment-reconciliation-config
```

الإعداد الافتراضي يستخدم `Ahmed\LaravelPayment\Core\Models\Payment` وجدول `payments`.

### 11.2 تشغيل الأمر

```bash
php artisan payment:reconcile stripe --from="2026-11-15T00:00:00Z" --to="2026-11-16T00:00:00Z" --chunk=100
```

ولـ PayPal:

```bash
php artisan payment:reconcile paypal --from="2026-11-15T00:00:00Z" --to="2026-11-16T00:00:00Z" --chunk=100
```

| Option | الوظيفة |
|---|---|
| `gateway` | `stripe` أو `paypal` |
| `--from` | بداية الفترة بصيغة ISO-8601 |
| `--to` | نهاية الفترة بصيغة ISO-8601 |
| `--chunk` | عدد السجلات المحلية في كل دفعة |

استخدم UTC، واجعل تاريخ البداية قبل تاريخ النهاية. عند عدم تحديد الفترة يستخدم الأمر آخر يوم حتى الوقت الحالي.

### 11.3 نتائج التشغيل

يتم حفظ التشغيل في `payment_reconciliation_runs`، وتفاصيل المقارنة في `payment_reconciliation_items`.

| النتيجة | المعنى |
|---|---|
| `matched` | البيانات متطابقة |
| `status_mismatch` | اختلاف حالة الدفع |
| `amount_mismatch` | اختلاف المبلغ |
| `currency_mismatch` | اختلاف العملة |
| `refund_mismatch` | اختلاف قيمة الاسترداد |
| `provider_not_found` | Payment محلية لم يتم العثور على مقابل لها لدى Provider |
| `local_not_found` | عملية لدى Provider لا يوجد لها سجل محلي مطابق |
| `duplicate_reference` | أكثر من سجل محلي يستخدم نفس المرجع |
| `manual_review` | البيانات غير كافية لإصدار نتيجة آمنة |
| `provider_error` | حدث خطأ أثناء التواصل مع Provider |

حالات الـ Run الأساسية: `running` و`completed` و`completed_with_errors` و`failed`.

### 11.4 قراءة آخر Run

```php
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationRun;

$run = ReconciliationRun::query()
    ->where('gateway', 'stripe')
    ->latest('id')
    ->firstOrFail();

$summary = $run->summary;
$items = $run->items()->latest()->get();

$unmatchedItems = $run->items()
    ->where('result', '!=', 'matched')
    ->get();
```

### 11.5 تسجيل Resolution

تسجيل Resolution يحفظ قرار المراجعة فقط، ولا يعدّل Payment أو بيانات المشروع تلقائيًا:

```php
use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationResolver;
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationItem;

$item = ReconciliationItem::query()->findOrFail($itemId);

app(ReconciliationResolver::class)->resolve(
    item: $item,
    resolution: 'Verified provider payment and reviewed the local record.',
    notes: 'Support reference ABC-123',
    resolvedBy: auth()->id(),
);
```

### 11.6 تخصيص مصدر البيانات المحلي

لو أردت استخدام Eloquent Payment Model آخر في Reconciliation، عدّل `local_payments.model` وخرائط الأعمدة داخل `config/laravel-payment-reconciliation.php` لتطابق جدولك. القيم `amount_unit` و`refunded_amount_unit` تقبل `major` أو `minor`. ويمكن تحديد Repository مخصص من خلال `local_payments.repository` إذا كان استعلام المشروع يحتاج إلى منطق مختلف.

## 12. أهم Models وRelations

### Payment

```php
use Ahmed\LaravelPayment\Core\Models\Payment;

$payment->payable;       // الموديل المرتبط اختياريًا
$payment->refunds;       // Refund records
$payment->webhookEvents; // Webhook events المرتبطة
```

### PaymentRefund

```php
use Ahmed\LaravelPayment\Core\Models\PaymentRefund;

$refund->payment;
$refund->requestedBy;
```

### PaymentWebhookEvent

يسجل أحداث Webhook التي تم استقبالها، مع Provider Event ID وحالة المعالجة والـ Payment المرتبطة عند العثور عليها.

## 13. الأمان وملاحظات Production

1. احسب المبلغ والعملة على السيرفر من بيانات Order أو Invoice الموثوقة. لا تعتمد على قيم قادمة من Browser.
2. استخدم HTTPS لمسارات Return وWebhook.
3. لا تعتبر Return Callback وحده دليلًا على التحصيل؛ تأكيد الدفع يتم بالتحقق من Provider.
4. لا تقبل Webhooks دون التحقق من التوقيع. استخدم القيم الصحيحة الخاصة ببيئة Test أو Live.
5. خزّن Idempotency Keys لعمليات Payment وRefund وأعد استخدامها عند Retry لنفس الطلب.
6. لا تستخدم Float في أي حساب مالي. استخدم Decimal strings أو Money Library.
7. احمِ Endpoints الخاصة ببدء الدفع والاسترداد بصلاحيات التطبيق وPolicies المناسبة.
8. اجعل العمليات التي ينفذها التطبيق بعد الدفع Idempotent؛ قد يصل Callback وWebhook لنفس Payment.
9. لا تفترض فشل Payment أو Refund بعد Timeout. قد يكون Provider قد نفذ العملية قبل انقطاع الاتصال.
10. Reconciliation أداة كشف ومراجعة، وليست بديلًا عن قواعد العمل داخل التطبيق.
11. اختبر النجاح والفشل والـ Timeout وتكرار Webhook وPartial Refund قبل تفعيل Live Credentials.

## 14. استكشاف الأخطاء

### Stripe credentials missing

تحقق من `STRIPE_SECRET` و`STRIPE_WEBHOOK_SECRET`، ثم نفّذ:

```bash
php artisan optimize:clear
```

### PayPal credentials missing أو mode invalid

تأكد من وجود `PAYPAL_CLIENT_ID` و`PAYPAL_CLIENT_SECRET`، وأن `PAYPAL_MODE` تساوي `sandbox` أو `live`، وأن بيانات الاعتماد من نفس البيئة.

### Webhook signature invalid

- Stripe: استخدم `STRIPE_WEBHOOK_SECRET` الخاص بالـ Endpoint الذي يستقبل الحدث.
- PayPal: استخدم `PAYPAL_WEBHOOK_ID` الخاص بالـ Webhook في نفس Sandbox أو Live App.

### payment_currency_not_supported

راجع العملة المطلوبة داخل `supported_currencies` الخاصة بالـ Provider في `config/payments.php`.

### payment_idempotency_conflict أو refund_idempotency_conflict

المفتاح نفسه استُخدم لطلب مختلف في Provider أو المبلغ أو العملة أو Payment. أنشئ مفتاحًا جديدًا فقط عند بدء عملية منطقية جديدة، وليس عند Retry لنفس الطلب.

### Reconciliation يعرض provider_error أو manual_review

راجع `last_error` و`summary` و`differences` و`provider_snapshot` في عناصر الـ Run. لا تغيّر الحالة محليًا قبل التحقق من بيانات Provider.

## 15. تشغيل الاختبارات

من مجلد الحزمة:

```bash
composer install
composer validate --strict
composer test
```

الاختبارات تستخدم Pest وLaravel Testbench وSQLite in-memory. تأكد من توفر امتداد `pdo_sqlite` في بيئة الاختبار.

---

**License:** MIT
