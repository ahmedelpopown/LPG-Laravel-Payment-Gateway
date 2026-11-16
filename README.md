# Ahmed Laravel Payment

حزمة Laravel قابلة لإعادة الاستخدام لإدارة عمليات الدفع من خلال **Stripe** و **PayPal**، مع فصل منطق الدفع عن منطق المشروع نفسه.

بدل ما كل مشروع يكتب تكاملًا منفصلًا مع كل بوابة دفع، المشروع يتعامل مع واجهة موحدة اسمها **Payment Core**، والحزمة تتولى إنشاء Checkout، وتأكيد الدفع، وإدارة Refunds، واستقبال Webhooks، ومقارنة العمليات المحلية ببيانات مزود الدفع من خلال Reconciliation.

> **مهم:** هذه الحزمة لا تفترض أن المشروع يستخدم Booking أو E-commerce أو Barber System. يمكن ربط أي Eloquent Model محفوظ في قاعدة البيانات بعملية الدفع من خلال علاقة `payable`.

---

## المحتويات

1. [المميزات](#1-المميزات)
2. [كيف تعمل الحزمة؟](#2-كيف-تعمل-الحزمة)
3. [المتطلبات](#3-المتطلبات)
4. [تثبيت الحزمة](#4-تثبيت-الحزمة)
5. [إعداد بيانات الدفع](#5-إعداد-بيانات-الدفع)
6. [إنشاء Payment وربطها بأي Model](#6-إنشاء-payment-وربطها-بأي-model)
7. [تطبيق Checkout داخل مشروع Laravel](#7-تطبيق-checkout-داخل-مشروع-laravel)
8. [متابعة حالة Payment](#8-متابعة-حالة-payment)
9. [تأكيد الدفع يدويًا من السيرفر](#9-تأكيد-الدفع-يدويًا-من-السيرفر)
10. [Webhooks وReturn URLs](#10-webhooks-وreturn-urls)
11. [Refunds](#11-refunds)
12. [Reconciliation](#12-reconciliation)
13. [استخدام جدول Payment خاص بالمشروع في Reconciliation](#13-استخدام-جدول-payment-خاص-بالمشروع-في-reconciliation)
14. [التعامل مع Stancl Tenancy](#14-التعامل-مع-stancl-tenancy)
15. [تكامل Booking الاختياري](#15-تكامل-booking-الاختياري)
16. [Enums والحالات](#16-enums-والحالات)
17. [أسماء الجداول والحقول الأساسية](#17-أسماء-الجداول-والحقول-الأساسية)
18. [الأمان وملاحظات Production](#18-الأمان-وملاحظات-production)
19. [استكشاف الأخطاء](#19-استكشاف-الأخطاء)

---

## 1. المميزات

- Payment Core مستقل عن الـ Business Logic.
- دعم Stripe Checkout وPayPal Orders Checkout.
- Provider موحد من خلال `PaymentGatewayManager`.
- ربط Payment اختياريًا بأي Eloquent Model من خلال Polymorphic Relation.
- Idempotency لمنع إنشاء نفس Payment أكثر من مرة عند إعادة الطلب.
- تأكيد حالة الدفع من خلال Return Callback أو Webhook موثوق.
- حفظ Provider References مثل Checkout Session ID وPaymentIntent ID وPayPal Order ID وCapture ID.
- Full Refund وPartial Refund من خلال Action موحد.
- Refund Ledger محلي.
- Webhook Event Ledger مع منع معالجة نفس Event ID أكثر من مرة.
- Stripe Refund Webhook Synchronization.
- Reconciliation لمقارنة Payment Records المحلية ببيانات Stripe أو PayPal.
- تكامل اختياري مع Stancl Tenancy.
- تكامل Booking قديم واختياري، ولا يدخل في تثبيت Payment Core الافتراضي.

## 2. كيف تعمل الحزمة؟

المسار الطبيعي لعملية الدفع:

```text
Laravel Application
       |
       | CreatePayment Action
       v
Payment Core
       |
       | PaymentGatewayManager
       v
Stripe Gateway  <------> Stripe API
       |
       +---------------- PayPal Gateway <------> PayPal API

Provider Return / Webhook
       |
       v
Confirm / Finalize Payment
       |
       v
Local Payment Record
```

### مسؤولية كل جزء

| الجزء | المسؤولية |
|---|---|
| `CreatePayment` | إنشاء Payment محلية، ثم طلب Checkout من الـ Provider |
| `PaymentGatewayManager` | اختيار الـ Gateway المطلوب |
| `StripeGateway` | تنفيذ عمليات Stripe وتحويل ردوده إلى Data Objects موحدة |
| `PayPalGateway` | تنفيذ عمليات PayPal وتحويل ردوده إلى Data Objects موحدة |
| `ConfirmPayment` | طلب تأكيد/التقاط الدفع من الـ Provider عند الحاجة |
| `FinalizePayment` | التحقق من المبلغ والعملة والـ References وحفظ الحالة النهائية |
| `RefundPayment` | حجز Refund محليًا، وتنفيذ Refund عند الـ Provider، وتحديث السجل |
| Webhook Processors | التحقق من الأحداث الواردة ومعالجتها ومنع التكرار |
| Reconciliation | مقارنة السجلات المحلية بالعمليات التي يعرضها الـ Provider |

**الفكرة الأساسية:** الـ Controller في المشروع لا يكتب Stripe HTTP Requests أو PayPal HTTP Requests. هو يستدعي الـ Core Actions فقط.

---

## 3. المتطلبات

- PHP `^8.3`.
- Laravel 11 أو 12 أو 13.
- MySQL 8+ أو MariaDB متوافقة، أو Database يدعمها Laravel وتناسب migrations المستخدمة.
- Stripe Test API Keys للاختبار.
- PayPal Sandbox REST App للاختبار.
- لا يحتاج Payment Core إلى Livewire.
- لا يحتاج Payment Core إلى Stancl Tenancy.
- Livewire مطلوب فقط عند استخدام شاشات Booking Livewire الاختيارية القديمة.
- Stancl Tenancy مطلوب فقط عند تفعيل تكامل Tenancy الاختياري.

حزمة `stripe/stripe-php` يتم تثبيتها تلقائيًا من خلال Composer. تكامل PayPal يستخدم Laravel HTTP Client.

---

## 4. تثبيت الحزمة

### 4.1 التثبيت من Git Repository

إذا كانت الحزمة مستضافة في Git Repository خاص أو عام، أضف Repository إلى `composer.json` في مشروع Laravel المستهلك:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ahmedelpopown/package-payment.git",
            "no-api": true
        }
    ]
}
```

ثم من مجلد مشروع Laravel:

```bash
composer require ahmed/laravel-payment:^2.0
```

> لو الحزمة منشورة لاحقًا على Packagist، لن تحتاج إلى تعريف `repositories`.

### 4.2 التثبيت من Local Path أثناء التطوير

لو مجلد الحزمة ومجلد المشروع المستهلك بجانب بعض:

```text
workspace/
├── laravel-payment/
└── my-laravel-app/
```

أضف إلى `composer.json` في `my-laravel-app`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-payment",
            "options": {
                "symlink": true,
                "versions": {
                    "ahmed/laravel-payment": "2.0.2"
                }
            }
        }
    ]
}
```

ثم نفّذ من جذر التطبيق المستهلك:

```bash
composer require ahmed/laravel-payment:^2.0 -W
```

`symlink: true` يجعل Composer يربط التطبيق بمصدر الحزمة المحلي، وبالتالي تظهر تغييرات المصدر مباشرة أثناء التطوير. هذا الأسلوب مناسب للتطوير المحلي، وليس طريقة نشر Production.

### 4.3 نشر ملفات الإعدادات وتثبيت الجداول

نفّذ من جذر مشروع Laravel:

```bash
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=laravel-payment-reconciliation-config
php artisan payments:install
php artisan optimize:clear
```

الأمر `payments:install` يثبت جداول Payment Core وReconciliation على اتصال قاعدة البيانات المحدد في إعدادات الحزمة، أو اتصال Laravel الافتراضي عند عدم تحديد اتصال آخر.

في Production استخدم:

```bash
php artisan payments:install --force
```

الأمر لا يثبت Booking Integration افتراضيًا. لا تستخدم `--with-booking` إلا إذا كنت مجهزًا جداول `bookings` و`employees` وإعدادات التكامل المطلوبة.

### 4.4 التحقق من التثبيت

```bash
php artisan migrate:status
php artisan route:list --path=payments
```

المفروض تظهر مسارات Core مثل:

```text
POST  payments/webhooks/stripe
POST  payments/webhooks/paypal
GET   payments/return/{provider}/{payment}
GET   payments/cancel/{provider}/{payment}
```

---

## 5. إعداد بيانات الدفع

بعد نشر الإعدادات، أضف بيانات الـ Providers التي ستستخدمها في ملف `.env`.

### 5.1 Stripe Test Mode

```dotenv
STRIPE_SECRET=sk_test_REPLACE_ME
STRIPE_WEBHOOK_SECRET=whsec_REPLACE_ME
STRIPE_PRODUCT_NAME="My Laravel App"
```

- `STRIPE_SECRET`: مفتاح Stripe Secret Key.
- `STRIPE_WEBHOOK_SECRET`: Signing Secret الخاص بالـ Webhook Endpoint أو Stripe CLI Listener.
- `STRIPE_PRODUCT_NAME`: اسم اختياري يظهر في Checkout.

### 5.2 PayPal Sandbox

```dotenv
PAYPAL_CLIENT_ID=REPLACE_ME
PAYPAL_CLIENT_SECRET=REPLACE_ME
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=REPLACE_ME
PAYPAL_BRAND_NAME="My Laravel App"
PAYPAL_TIMEOUT=20
PAYPAL_CONNECT_TIMEOUT=10
```

- `PAYPAL_CLIENT_ID` و`PAYPAL_CLIENT_SECRET`: بيانات REST App من PayPal Developer Dashboard.
- `PAYPAL_MODE`: استخدم `sandbox` للاختبار و`live` بعد تجهيز Live App.
- `PAYPAL_WEBHOOK_ID`: رقم تعريف Webhook Endpoint في PayPal، وليس Client Secret.
- `PAYPAL_BRAND_NAME`: اسم المتجر الظاهر في تجربة الدفع.
- `PAYPAL_TIMEOUT` و`PAYPAL_CONNECT_TIMEOUT`: مهلة طلبات HTTP بالثواني.

استخدم Sandbox Business Account كتاجر وSandbox Personal Account كمشتري أثناء الاختبار.

### 5.3 إعدادات Return Redirect اختيارية

```dotenv
PAYMENTS_SUCCESS_URL=https://your-domain.com/payment/success
PAYMENTS_CANCEL_URL=https://your-domain.com/payment/cancelled
```

إذا لم تحدد القيمتين، فإن Return Callback المدمج يرجع JSON يوضح `payment_id` والحالة.

إذا حددت `PAYMENTS_SUCCESS_URL`، فبعد التحقق من الدفع سيتم تحويل المستخدم إلى هذا العنوان الثابت. لا تعتمد على أن الحزمة ستضيف `payment_id` تلقائيًا إلى هذا الرابط.

بعد تعديل `.env`:

```bash
php artisan optimize:clear
```

لا تضع مفاتيح Live داخل مشروع اختبار، ولا ترفع ملف `.env` إلى Git.

---

## 6. إنشاء Payment وربطها بأي Model

### 6.1 أبسط استخدام بدون Payable Model

يمكن إنشاء Payment مستقلة بدون Order أو Booking:

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
        'purpose' => 'standalone-payment-test',
        'source' => 'website',
    ],
);

$checkoutUrl = $payment->checkout_url;
```

لاختيار PayPal بدل Stripe:

```php
$payment = app(CreatePayment::class)->handle(
    provider: PaymentProvider::PayPal,
    amount: '125.00',
    currency: 'USD',
    idempotencyKey: (string) Str::uuid(),
    metadata: ['purpose' => 'paypal-checkout-test'],
);
```

الـ Action ينفذ بالترتيب:

1. يتحقق من Provider والمبلغ والعملة وIdempotency Key.
2. ينشئ سجل Payment محليًا بحالة `pending`.
3. يطلب Checkout Session أو PayPal Order من الـ Provider.
4. يحفظ `provider_reference` و`checkout_url`.
5. يرجع Payment Model إلى التطبيق.

### 6.2 ربط Payment بأي Eloquent Model

مثلًا لو المشروع عنده `Order` محفوظ في قاعدة البيانات:

```php
$payment = app(CreatePayment::class)->handle(
    provider: PaymentProvider::Stripe,
    amount: (string) $order->total_amount,
    currency: $order->currency,
    idempotencyKey: (string) Str::uuid(),
    metadata: [
        'order_number' => $order->number,
    ],
    payable: $order,
);
```

الـ `payable` لازم يكون Model محفوظًا بالفعل، ولا يكفي إنشاء Model في الذاكرة بدون `save()`.

الحزمة تستخدم علاقة Polymorphic، ولذلك لا تحتاج إلى إضافة `order_id` أو `booking_id` داخل جدول `payments`.

لإظهار العلاقة من داخل `Order` Model أضف:

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

### 6.3 فهم Idempotency Key

`idempotencyKey` يمنع إنشاء عمليتين منطقيتين بنفس المفتاح عند إعادة إرسال نفس الطلب. الحزمة تتحقق أيضًا أن المفتاح لم يُستخدم مع Provider أو مبلغ أو عملة أو Payable مختلف.

**في Production لا تنشئ UUID جديدًا عند كل Retry.** خزّن مفتاح المحاولة في قاعدة بيانات مشروعك، وأعد استخدامه عند إعادة نفس عملية الدفع.

مثال عملي: أضف عمودًا لمحاولة الدفع الحالية إلى جدول Orders:

```php
Schema::table('orders', function (Blueprint $table): void {
    $table->string('payment_attempt_key', 100)->nullable()->unique();
});
```

عند أول محاولة:

```php
$order->payment_attempt_key ??= (string) Str::uuid();
$order->save();
```

ثم استخدم نفس القيمة في `CreatePayment` عند إعادة إرسال نفس المحاولة:

```php
idempotencyKey: $order->payment_attempt_key,
```

عند بدء محاولة دفع جديدة فعلًا بعد إغلاق المحاولة القديمة ومعرفة حالتها النهائية، أنشئ مفتاحًا جديدًا مرة واحدة واحفظه. لا تغيّر المفتاح لمجرد أن HTTP Request اتعاد.

---

## 7. تطبيق Checkout داخل مشروع Laravel

المثال التالي يوضح دمج الحزمة في مشروع E-commerce. افترض أن المشروع لديه `Order` Model يحتوي على:

- `user_id`
- `total_amount` كقيمة Decimal محفوظة في قاعدة البيانات
- `currency`
- `payment_status`
- `payment_attempt_key`

عدّل أسماء الحقول لتناسب مشروعك. لا تأخذ قيمة المبلغ من Request المستخدم؛ احسبها من بيانات Order الموثوقة على السيرفر.

### 7.1 Controller

أنشئ:

`app/Http/Controllers/OrderPaymentController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Ahmed\LaravelPayment\Core\Actions\CreatePayment;
use Ahmed\LaravelPayment\Enums\PaymentProvider;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

final class OrderPaymentController
{
    public function checkout(
        Request $request,
        Order $order,
        CreatePayment $createPayment,
    ): RedirectResponse {
        // Replace this check with your Order Policy if your authorization rules differ.
        abort_unless((string) $order->user_id === (string) $request->user()->getKey(), 403);

        abort_if($order->payment_status === 'paid', 409, 'This order is already paid.');

        $data = $request->validate([
            'provider' => ['required', Rule::in(['stripe', 'paypal'])],
        ]);

        // Keep this key stable for retries of the same logical payment attempt.
        $order->payment_attempt_key ??= (string) Str::uuid();
        $order->save();

        $payment = $createPayment->handle(
            provider: PaymentProvider::from($data['provider']),
            amount: (string) $order->total_amount,
            currency: strtoupper((string) $order->currency),
            idempotencyKey: (string) $order->payment_attempt_key,
            metadata: [
                'order_id' => (string) $order->getKey(),
                'order_number' => (string) $order->number,
            ],
            payable: $order,
        );

        if (! is_string($payment->checkout_url) || $payment->checkout_url === '') {
            abort(502, 'The payment provider did not return a checkout URL.');
        }

        return redirect()->away($payment->checkout_url);
    }
}
```

> في حالة تطبيقك يسمح بمحاولات دفع متعددة لنفس Order، خزّن كل محاولة في جدول مستقل مثل `payment_attempts` بدل الاكتفاء بحقل واحد على Order. كل محاولة جديدة لها مفتاح مستقل، وكل Retry لنفس المحاولة يستخدم المفتاح نفسه.

### 7.2 Route

في `routes/web.php`:

```php
use App\Http\Controllers\OrderPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('/orders/{order}/checkout', [OrderPaymentController::class, 'checkout'])
        ->name('orders.checkout');
});
```

### 7.3 Blade Form

```blade
<form method="POST" action="{{ route('orders.checkout', $order) }}">
    @csrf

    <label for="provider">Payment Provider</label>
    <select name="provider" id="provider" required>
        <option value="stripe">Stripe</option>
        <option value="paypal">PayPal</option>
    </select>

    <button type="submit">Continue to Payment</button>
</form>
```

بعد إرسال النموذج، يختار الـ Controller الـ Provider من خلال `PaymentProvider` ويحوّل العميل إلى `checkout_url`.

---

## 8. متابعة حالة Payment

### 8.1 جلب Payment باستخدام Public ID

الحزمة تنشئ `public_id` من نوع UUID لكل Payment، وتستخدمه كـ Route Key بدل رقم قاعدة البيانات المتسلسل.

```php
use Ahmed\LaravelPayment\Core\Models\Payment;

$payment = Payment::query()
    ->where('public_id', $publicId)
    ->firstOrFail();

$status = $payment->status->value;
```

### 8.2 أهم الحقول

```php
$payment->id;
$payment->public_id;
$payment->provider->value;
$payment->provider_reference;
$payment->provider_transaction_id;
$payment->amount;
$payment->currency;
$payment->status->value;
$payment->checkout_url;
$payment->metadata;
$payment->paid_at;
$payment->refunded_amount;
$payment->refunded_at;
```

الـ Provider References تختلف حسب بوابة الدفع:

| Provider | `provider_reference` | `provider_transaction_id` |
|---|---|---|
| Stripe | Checkout Session ID | PaymentIntent ID بعد التأكيد |
| PayPal | Order ID | Capture ID بعد التأكيد |

### 8.3 ربط حالة Payment بحالة Order

الحزمة مسؤولة عن سجل Payment فقط. هي لا تعرف هل المشروع عنده Order أو Booking أو Invoice، ولذلك لا تغيّر حالة الـ Business Model تلقائيًا.

يمكن للمشروع استخدام Eloquent Observer لمزامنة حالة Order عندما تتغير حالة Payment. المثال التالي يفترض أن Order لديه عمود `payment_status`:

أنشئ:

`app/Observers/PaymentObserver.php`

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use Ahmed\LaravelPayment\Core\Models\Payment;
use Ahmed\LaravelPayment\Enums\PaymentStatus;

final class PaymentObserver
{
    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        $order = $payment->payable;

        if (! $order) {
            return;
        }

        $orderPaymentStatus = match ($payment->status) {
            PaymentStatus::Paid => 'paid',
            PaymentStatus::PartiallyRefunded => 'partially_refunded',
            PaymentStatus::Refunded => 'refunded',
            PaymentStatus::Failed => 'payment_failed',
            default => null,
        };

        if ($orderPaymentStatus !== null
            && $order->payment_status !== $orderPaymentStatus) {
            $order->forceFill([
                'payment_status' => $orderPaymentStatus,
            ])->save();
        }
    }
}
```

سجّل الـ Observer في `app/Providers/AppServiceProvider.php`:

```php
use Ahmed\LaravelPayment\Core\Models\Payment;
use App\Observers\PaymentObserver;

public function boot(): void
{
    Payment::observe(PaymentObserver::class);
}
```

هذا مثال توضيحي: عدّل أسماء حالات Order وسياسة تنفيذ الطلب حسب مشروعك. اجعل تنفيذ الطلب نفسه Idempotent حتى لا يؤدي وصول Return Callback وWebhook لنفس الدفع إلى تنفيذ الطلب مرتين.

---

## 9. تأكيد الدفع يدويًا من السيرفر

في التدفق الطبيعي، الحزمة تستخدم Return Callback أو Webhook لتأكيد الدفع. لو عندك Service موثوق على السيرفر يحتاج إعادة الاستعلام عن حالة Payment من الـ Provider، يمكنك استخدام `ConfirmPayment`:

```php
use Ahmed\LaravelPayment\Core\Actions\ConfirmPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;

$payment = Payment::query()->findOrFail($paymentId);

$confirmedPayment = app(ConfirmPayment::class)->handle($payment);

$status = $confirmedPayment->status->value;
```

هذا الـ Action يستدعي الـ Gateway المناسب، ثم يمرر نتيجة الـ Provider إلى `FinalizePayment` الذي يتحقق من المبلغ والعملة والمراجع قبل تحديث السجل المحلي.

**لا تجعل Endpoint عامًا يسمح للعميل بإرسال Payment ID وطلب تأكيدها دون صلاحيات.** استخدم Return Callback المدمج أو استدعِ Action من Service محمي على السيرفر.

---

## 10. Webhooks وReturn URLs

### 10.1 المسارات التي تسجلها الحزمة

```text
POST /payments/webhooks/stripe
POST /payments/webhooks/paypal
GET  /payments/return/{provider}/{payment}
GET  /payments/cancel/{provider}/{payment}
```

لا تحتاج إلى إعادة تعريف هذه المسارات داخل المشروع طالما `payments.routes.core_auto_register` مفعّل.

### 10.2 ماذا يحدث عند Return؟

- Stripe يرجع إلى المسار ومعه `session_id`.
- PayPal يرجع إلى المسار ومعه `token` الذي يمثل PayPal Order ID.
- الحزمة تتحقق أن المعرف المستلم يطابق `provider_reference` المحفوظ محليًا.
- بعد ذلك تستعلم عن حالة العملية من الـ Provider وتتحقق من المبلغ والعملة قبل تحديث Payment.
- عند عدم إعداد Success URL، ترجع الحزمة JSON.

مثال Response عند نجاح الدفع:

```json
{
    "payment_id": "PUBLIC-PAYMENT-UUID",
    "status": "paid",
    "message": "Payment confirmed."
}
```

مسار Cancel لا يعتبر رجوع العميل دليلًا قاطعًا أن الـ Provider ألغى العملية؛ لذلك لا يغيّر حالة Payment وحده. الحالة النهائية تعتمد على Provider Confirmation أو Webhook موثوق.

### 10.3 Stripe Webhooks

في Stripe Dashboard أضف Endpoint:

```text
https://your-domain.com/payments/webhooks/stripe
```

واختر أحداث Checkout وRefund التي يدعمها Core:

```text
checkout.session.completed
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
checkout.session.expired
refund.created
refund.updated
refund.failed
charge.refund.updated
charge.refunded
```

للاختبار المحلي باستخدام Stripe CLI:

```bash
stripe listen --events=checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,refund.created,refund.updated,refund.failed,charge.refund.updated,charge.refunded --forward-to http://127.0.0.1:8000/payments/webhooks/stripe
```

انسخ `whsec_...` الذي يظهر من Stripe CLI إلى:

```dotenv
STRIPE_WEBHOOK_SECRET=whsec_REPLACE_ME
```

ثم:

```bash
php artisan optimize:clear
```

### 10.4 PayPal Webhooks

في PayPal Developer Dashboard، افتح نفس Sandbox REST App وأنشئ Webhook يشير إلى عنوان HTTPS عام:

```text
https://your-public-test-url/payments/webhooks/paypal
```

PayPal لا يستطيع الوصول إلى `localhost` أو `127.0.0.1` مباشرة. للاختبار المحلي استخدم HTTPS Tunnel مثل ngrok أو Cloudflare Tunnel، مثلًا:

```bash
ngrok http 8000
```

اشترك في الأحداث التالية التي يعالجها PayPal Payment Core حاليًا:

```text
CHECKOUT.ORDER.APPROVED
PAYMENT.CAPTURE.COMPLETED
PAYMENT.CAPTURE.PENDING
PAYMENT.CAPTURE.DENIED
```

انسخ Webhook ID الخاص بالـ Sandbox Endpoint إلى:

```dotenv
PAYPAL_WEBHOOK_ID=YOUR_SANDBOX_WEBHOOK_ID
```

ثم:

```bash
php artisan optimize:clear
```

عند استقبال `CHECKOUT.ORDER.APPROVED`، يمكن للحزمة تنفيذ Capture للـ Order وتأكيد Payment. أحداث Capture التالية تحدّث حالة Payment وفق حالة العملية لدى PayPal.

> **حدود PayPal Webhooks في هذا الإصدار:** معالج PayPal الحالي يعالج أحداث الموافقة على Order وأحداث Capture المذكورة أعلاه. مزامنة Refund Webhooks القادمة من PayPal ليست ضمن الأحداث المدعومة حاليًا. عمليات Refund التي تتم من خلال `RefundPayment` نفسها تُحفظ في Refund Ledger المحلي.

### 10.5 منع تكرار Webhooks

تتحقق الحزمة من التوقيع قبل معالجة Webhook، وتخزن Event ID في `payment_webhook_events` مع Unique Key مركب من Provider وEvent ID. إعادة إرسال نفس الحدث لا يفترض أن تنفذ نفس المعالجة مرتين.

يمكن مراجعة الأحداث من خلال:

```php
use Ahmed\LaravelPayment\Models\PaymentWebhookEvent;

$events = PaymentWebhookEvent::query()
    ->where('provider', 'stripe')
    ->latest()
    ->limit(50)
    ->get();
```

أهم حقول سجل الحدث:

```php
$event->provider;
$event->event_id;
$event->event_type;
$event->payment_id;
$event->status;
$event->error_code;
$event->payload;
$event->processed_at;
```

---

## 11. Refunds

يمكن إنشاء Refund كامل أو جزئي من خلال Action واحد بغض النظر عن كون Payment تابعة لـ Stripe أو PayPal.

### 11.1 إنشاء Partial Refund

```php
use Ahmed\LaravelPayment\Core\Actions\RefundPayment;
use Ahmed\LaravelPayment\Core\Models\Payment;
use Illuminate\Support\Str;

$payment = Payment::query()
    ->where('public_id', $publicId)
    ->firstOrFail();

$refundIdempotencyKey = (string) Str::uuid();

$refund = app(RefundPayment::class)->handle(
    payment: $payment,
    amount: '25.00',
    idempotencyKey: $refundIdempotencyKey,
    requestedBy: auth()->user(),
    reason: 'Customer requested a partial refund',
);
```

- `payment`: سجل Payment الذي تم تحصيله.
- `amount`: المبلغ المطلوب إرجاعه كسلسلة Decimal مثل `25.00`.
- `idempotencyKey`: مفتاح فريد لطلب Refund المنطقي.
- `requestedBy`: اختياري، ويقبل أي Eloquent Model محفوظ مثل User أو Admin.
- `reason`: سبب اختياري.

في Production خزّن مفتاح Refund مع طلب الاسترداد في قاعدة بيانات مشروعك قبل الاتصال بالـ Provider. عند Retry لنفس Refund بعد Timeout، أعد استخدام المفتاح نفسه. لا تنشئ مفتاحًا جديدًا لنفس الطلب؛ فقد تكون بوابة الدفع نفذت العملية رغم انقطاع الاتصال.

### 11.2 Full Refund

مرر المبلغ المتبقي القابل للاسترداد كاملًا:

```php
$remainingAmount = bcsub(
    (string) $payment->amount,
    (string) $payment->refunded_amount,
    2,
);

$refund = app(RefundPayment::class)->handle(
    payment: $payment,
    amount: $remainingAmount,
    idempotencyKey: $storedRefundRequest->idempotency_key,
    requestedBy: auth()->user(),
    reason: 'Full refund',
);
```

يتطلب المثال إضافة BC Math في PHP أو استخدام Money helper مناسب في التطبيق. لا تستخدم Float لإجراء حسابات مالية.

### 11.3 قراءة سجل Refunds

```php
use Ahmed\LaravelPayment\Core\Models\PaymentRefund;

$refunds = $payment->refunds()->latest()->get();

$refund = PaymentRefund::query()->findOrFail($refundId);

$refund->provider_refund_id;
$refund->amount;
$refund->currency;
$refund->status->value;
$refund->reason;
$refund->failure_message;
$refund->processed_at;
```

الحزمة تتحقق من أن Refund لا يتجاوز الرصيد المتبقي، وتحجز المبلغ أثناء المعالجة حتى لا تسمح بطلبات متزامنة تتجاوز المبلغ الأصلي.

### 11.4 حالات Refund

- `pending`: قيد الانتظار.
- `processing`: تم بدء الطلب أو توجد نتيجة تحتاج إلى متابعة.
- `completed`: تم الاسترداد.
- `failed`: فشل الطلب بشكل مؤكد.

حالة Payment نفسها تتغير إلى `partially_refunded` أو `refunded` عندما يتم تسجيل Refund مكتمل.

---

## 12. Reconciliation

### 12.1 ما وظيفة Reconciliation؟

Reconciliation تقارن سجلات الدفع المحلية مع ما يعرضه Stripe أو PayPal. هدفها اكتشاف الاختلافات، وليس إنشاء Payments أو تعديل حالة المشروع تلقائيًا.

الحزمة تنشئ سجلات تشغيل في:

- `payment_reconciliation_runs`
- `payment_reconciliation_items`
- `payment_reconciliation_resolutions`

### 12.2 إعدادات References الافتراضية

في إعدادات الحزمة:

- Stripe يستخدم Checkout Session ID المخزن في `payments.provider_reference`.
- PayPal يستخدم Capture ID المخزن في `payments.provider_transaction_id` عند اختيار `capture`.

الإعدادات الافتراضية في `config/laravel-payment-reconciliation.php`:

```php
'gateways' => [
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'reference_type' => env('PAYMENT_STRIPE_REFERENCE_TYPE', 'checkout_session'),
    ],
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'environment' => env('PAYPAL_MODE', 'sandbox'),
        'reference_type' => env('PAYMENT_PAYPAL_REFERENCE_TYPE', 'capture'),
    ],
],
```

وإعدادات الأعمدة:

```php
'provider_reference_by_gateway' => [
    'stripe' => 'provider_reference',
    'paypal' => 'provider_transaction_id',
],
```

لا تغيّر هذه القيم عند استخدام Core Payment Model الافتراضي إلا إذا كنت غيّرت طريقة تخزين References في مشروعك.

### 12.3 تشغيل Reconciliation من Artisan

```bash
php artisan payment:reconcile stripe --from="2026-11-15T00:00:00Z" --to="2026-11-16T00:00:00Z" --chunk=100
```

ولـ PayPal:

```bash
php artisan payment:reconcile paypal --from="2026-11-15T00:00:00Z" --to="2026-11-16T00:00:00Z" --chunk=100
```

- `gateway`: `stripe` أو `paypal`.
- `--from`: بداية الفترة بصيغة ISO-8601.
- `--to`: نهاية الفترة بصيغة ISO-8601.
- `--chunk`: عدد السجلات المحلية التي تتم قراءتها في كل دفعة.

يوصى باستخدام UTC في كل عمليات التشغيل. تاريخ البداية يجب أن يكون قبل تاريخ النهاية.

### 12.4 معنى النتائج

| النتيجة | معناها |
|---|---|
| `matched` | الحالة والمبلغ والعملة والـ Refund Totals متوافقة |
| `status_mismatch` | اختلاف في حالة الدفع |
| `amount_mismatch` | اختلاف في المبلغ |
| `currency_mismatch` | اختلاف في العملة |
| `refund_mismatch` | اختلاف في إجمالي المبالغ المستردة |
| `provider_not_found` | توجد Payment محلية لكن لم يتم العثور على العملية عند Provider |
| `local_not_found` | توجد عملية لدى Provider ولا يوجد لها سجل محلي مطابق |
| `duplicate_reference` | أكثر من سجل محلي يستخدم نفس Provider Reference |
| `manual_review` | لا يمكن إصدار نتيجة آمنة، مثل سجل محلي بلا Provider Reference |
| `provider_error` | حدث خطأ أثناء التواصل أو قراءة بيانات Provider |

حالة الـ Run قد تكون:

- `completed`: اكتمل التشغيل بدون Provider Errors.
- `completed_with_errors`: اكتمل مع وجود أخطاء من Provider.
- `failed`: فشل التشغيل.
- `running`: التشغيل ما زال جاريًا.

**وجود `provider_error` لا يعني تلقائيًا أن Payment غير موجودة.** افحص `last_error` وتفاصيل العنصر قبل اتخاذ قرار.

### 12.5 قراءة نتائج آخر Run

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

لعرض التفاصيل:

```php
foreach ($unmatchedItems as $item) {
    dump([
        'local_payment_id' => $item->local_payment_id,
        'provider_reference' => $item->provider_reference,
        'result' => $item->result,
        'local_status' => $item->local_status,
        'provider_status' => $item->provider_status,
        'differences' => $item->differences,
        'provider_snapshot' => $item->provider_snapshot,
    ]);
}
```

### 12.6 تسجيل Resolution لعنصر يحتاج مراجعة

تسجيل Resolution لا يعدّل Payment أو Order تلقائيًا؛ هو يسجل قرار المراجعة فقط. تعديل الحالة الفعلية مسؤولية التطبيق بعد التحقق من قواعده.

```php
use Ahmed\LaravelPayment\Reconciliation\Contracts\ReconciliationResolver;
use Ahmed\LaravelPayment\Reconciliation\Models\ReconciliationItem;

$item = ReconciliationItem::query()->findOrFail($itemId);

app(ReconciliationResolver::class)->resolve(
    item: $item,
    resolution: 'Verified provider payment and corrected the local record.',
    notes: 'Support ticket PAY-123',
    resolvedBy: auth()->id(),
);
```

### 12.7 ملاحظات مهمة تخص PayPal Reconciliation

- يحتاج PayPal REST App إلى صلاحية **Transaction Search / Reporting** المناسبة. إذا رجع Transaction Search بـ HTTP 403، راجع تفعيل الصلاحية والـ App والـ Environment والـ Access Token.
- قد تتأخر العمليات المنفذة في الظهور في Transaction Search لمدة تصل إلى عدة ساعات. لا تعتبر العملية `local_not_found` أو `provider_not_found` بمجرد تشغيل البحث فور إتمام الدفع.
- PayPal Transaction Search يفرض حدًا زمنيًا على نطاق البحث؛ استخدم فترات لا تتجاوز 31 يومًا لكل طلب.
- عند استخدام `reference_type = capture`، يجب أن يكون Capture ID موجودًا محليًا في العمود المحدد في `provider_reference_by_gateway.paypal`.
- معلومات بعض حالات Partial Refund في PayPal قد لا تتضمن إجمالي Refund دقيقًا في أول إصدار من الـ Reconciliation Driver. القيمة غير المعروفة لا ينبغي تفسيرها على أنها صفر.

### 12.8 استخدام Reconciliation من داخل Service

```php
use Ahmed\LaravelPayment\Reconciliation\Services\ReconciliationManager;
use DateTimeImmutable;

$run = app(ReconciliationManager::class)->run(
    gateway: 'stripe',
    from: new DateTimeImmutable('2026-11-15T00:00:00+00:00'),
    to: new DateTimeImmutable('2026-11-16T00:00:00+00:00'),
    chunkSize: 100,
);

$run->summary;
$run->items;
```

---

## 13. استخدام جدول Payment خاص بالمشروع في Reconciliation

Payment Core الافتراضي يستخدم:

```php
Ahmed\LaravelPayment\Core\Models\Payment
```

لو المشروع يستخدم جدول Payment خاصًا به، يمكن ضبط `config/laravel-payment-reconciliation.php` بدلًا من تغيير Core.

مثال توضيحي لمشروع لديه `App\Models\Payment` وجدول يحتوي على أعمدة مشابهة:

```php
'local_payments' => [
    'model' => App\Models\Payment::class,
    'repository' => null,
    'columns' => [
        'id' => 'id',
        'gateway' => 'provider',
        'provider_reference' => 'provider_reference',
        'status' => 'status',
        'amount' => 'amount',
        'amount_unit' => 'major',
        'currency' => 'currency',
        'refunded_amount' => 'refunded_amount',
        'refunded_amount_unit' => 'major',
        'created_at' => 'created_at',
    ],
    'provider_reference_by_gateway' => [
        'stripe' => 'stripe_checkout_session_id',
        'paypal' => 'paypal_capture_id',
    ],
],
```

هذا مثال فقط. يجب أن تطابق أسماء الأعمدة الحقيقية في جدول المشروع. `amount_unit` و`refunded_amount_unit` يحددان هل القيم مخزنة بوحدة العملة الرئيسية `major` أو الوحدة الصغرى `minor`.

إذا لم يخزن مشروعك إجمالي Refund محليًا، اجعل:

```php
'columns' => [
    // Other column mappings...
    'refunded_amount' => null,
],
```

في هذه الحالة يتخطى Comparator مقارنة إجمالي Refund المحلي بدلًا من افتراض أن قيمته صفر.

### Custom Repository

لو جدول الدفع يحتاج Tenant Context أو Query خاص، أنشئ Class يطبق:

```php
use Ahmed\LaravelPayment\Reconciliation\Contracts\LocalPaymentRepository;
```

ثم عرّف الـ Class في:

```php
'local_payments' => [
    // ...
    'repository' => App\Payments\CustomLocalPaymentRepository::class,
],
```

الـ Repository مسؤول عن قراءة Payment Records وفق التاريخ والـ Gateway والـ Provider Reference وإرجاع DTOs المتوقعة من الحزمة. استخدمه عندما لا يناسبك الـ Eloquent Repository الافتراضي.

---

## 14. التعامل مع Stancl Tenancy

تكامل Stancl Tenancy اختياري. عند تثبيت `stancl/tenancy` وتسجيل `tenants:migrate` ووجود `tenancy.database.central_connection`:

- يكتشف الـ Installer إعداد Stancl.
- ينفذ Migration جدول بيانات اعتماد الدفع المركزي من مجلد `database/migrations/tenancy` على الاتصال المركزي.
- ينفذ Payment Core وReconciliation Migrations على قواعد بيانات الـ Tenants من خلال `tenants:migrate`.
- لا ينفذ Booking Migrations إلا عند تفعيل Booking صراحةً.

يمكن تحديد الاتصال المركزي المستخدم لتخزين بيانات اعتماد الـ Tenant:

```dotenv
PAYMENTS_CONNECTION=central
```

في التطبيق العادي، يظل Payment Core يستخدم اتصال قاعدة بيانات التطبيق الافتراضي. في Stancl، بعد تهيئة Tenant، يجب أن يكون اتصال Laravel الافتراضي موجّهًا لقاعدة بيانات الـ Tenant حتى تُحفظ Payments وRefunds في قاعدة هذا الـ Tenant.

### بيانات اعتماد كل Tenant

عند تهيئة Stancl Tenant، تحاول Stripe وPayPal Credentials Resolvers قراءة بيانات الاعتماد الخاصة به من جدول `tenant_payment_credentials`. أما عندما لا تكون Tenancy مهيأة، فيتم استخدام قيم `.env` وملفات Config العادية.

### Reconciliation في تطبيق Multi-Tenant

لا تفترض أن Reconciliation الافتراضي يعرف وحده أي Tenant يجب أن يقرأ أو أي بيانات اعتماد Tenant يستخدمها. لو كل Tenant لديه Database أو مفاتيح Provider مختلفة، اربط تنفيذ Reconciliation بسياق Tenant الصحيح، ووفّر تطبيقك الخاص من:

- `Ahmed\LaravelPayment\Reconciliation\Contracts\LocalPaymentRepository`
- `Ahmed\LaravelPayment\Reconciliation\Contracts\GatewayCredentialsResolver`

وسجّلهما في Service Provider الخاص بالتطبيق. يجب أن يقرأ Repository بيانات Tenant الحالية وأن يعيد Credentials Resolver بيانات اعتماد نفس Tenant. لا تضع مفاتيح كل العملاء في Global `.env` مشترك.

> خذ Backup لقاعدة البيانات المركزية قبل تنفيذ Migration Tenancy على تطبيق قائم؛ Migration بيانات اعتماد Tenancy قد تنقل أعمدة Credentials قديمة من جدول `tenants` ثم تحذف الأعمدة القديمة.

---

## 15. تكامل Booking الاختياري

Payment Core لا يحتاج `bookings` أو `employees`. التكامل القديم الخاص بـ Booking اختياري ويجب تشغيله فقط داخل مشروع لديه الجداول والموديلات والخدمات المطلوبة.

في `config/payments.php`:

```php
'booking' => [
    'enabled' => true,
    'booking_model' => App\Models\Booking::class,
    'employee_model' => App\Models\Employee::class,
    'tenant_resolver' => App\Services\CurrentTenantContext::class,
    'price_calculator' => App\Services\BookingPriceCalculator::class,
    'feature_gate' => App\Services\SubscriptionService::class,
],
```

ثم:

```bash
php artisan payments:install --with-booking
```

تكامل Booking يستخدم Workflow القديم وModels مستقلة مثل `BookingPayment` و`BookingPaymentRefund`. لا تخلط هذه الجداول مع Generic Core Tables مثل `payments` و`payment_refunds` بدون تنفيذ Migration/Adapter مقصود.

شاشات Booking Livewire اختيارية، وثبّت Livewire 4 فقط إذا كنت ستستخدمها.

---

## 16. Enums والحالات

### PaymentProvider

```php
use Ahmed\LaravelPayment\Enums\PaymentProvider;

PaymentProvider::Stripe; // stripe
PaymentProvider::PayPal; // paypal
```

### PaymentStatus

```php
use Ahmed\LaravelPayment\Enums\PaymentStatus;

PaymentStatus::Pending;
PaymentStatus::Processing;
PaymentStatus::Paid;
PaymentStatus::Failed;
PaymentStatus::Cancelled;
PaymentStatus::PartiallyRefunded;
PaymentStatus::Refunded;
```

القيمة النصية عند القراءة:

```php
$payment->status->value;
$payment->provider->value;
```

### RefundStatus

```php
use Ahmed\LaravelPayment\Enums\RefundStatus;

RefundStatus::Pending;
RefundStatus::Processing;
RefundStatus::Completed;
RefundStatus::Failed;
```

---

## 17. أسماء الجداول والحقول الأساسية

في التثبيت الافتراضي يتم إنشاء الجداول التالية:

| Table | الاستخدام |
|---|---|
| `payments` | سجلات الدفع العامة |
| `payment_refunds` | سجل عمليات Refund |
| `payment_webhook_events` | سجل أحداث Webhooks ومنع التكرار |
| `payment_reconciliation_runs` | كل عملية Reconciliation |
| `payment_reconciliation_items` | نتائج المقارنة لكل عملية |
| `payment_reconciliation_resolutions` | قرارات المراجعة اليدوية |

أهم حقول `payments`:

| Field | الاستخدام |
|---|---|
| `public_id` | UUID عام للتعامل مع Payment |
| `payable_type` / `payable_id` | ربط Payment بأي Eloquent Model |
| `provider` | `stripe` أو `paypal` |
| `provider_reference` | Checkout Session ID أو PayPal Order ID |
| `provider_transaction_id` | PaymentIntent ID أو PayPal Capture ID |
| `idempotency_key` | منع تكرار إنشاء Payment لنفس المحاولة |
| `amount` / `currency` | المبلغ والعملة |
| `refunded_amount` | إجمالي Refund المكتمل محليًا |
| `status` | حالة Payment |
| `checkout_url` | رابط الانتقال لصفحة الدفع |
| `metadata` | بيانات إضافية |
| `paid_at` / `refunded_at` | توقيتات الدفع والاسترداد |

---

## 18. الأمان وملاحظات Production

1. لا تثق في المبلغ أو العملة المرسلين من Browser. احسبهما من Order أو Invoice على السيرفر.
2. لا تعتبر رجوع المستخدم من صفحة الدفع دليلًا كافيًا على نجاح الدفع. الحزمة تستعلم من Provider وتتحقق من بيانات العملية.
3. لا تعتمد على Webhook غير متحقق من توقيعه. استخدم المسارات المدمجة وإعداد Signing Secret / Webhook ID الصحيح.
4. لا تستخدم Idempotency Key جديدًا عند Retry لنفس Payment أو Refund.
5. احفظ مفاتيح Live في Secret Manager أو Environment Variables آمنة، ولا تضعها في Git أو Logs.
6. استخدم HTTPS في Production، خاصة Webhook Endpoints.
7. احمِ Refund Endpoint بـ Authorization/Policy داخل التطبيق. وجود Refund Action لا يعني أن كل مستخدم مسموح له استرجاع الأموال.
8. نفّذ Business Actions مثل تجهيز Order بعد الدفع بطريقة Idempotent؛ قد يصل Return Callback وWebhook لنفس العملية.
9. Reconciliation تكشف الاختلافات وتسجلها، لكنها لا تصحح Order أو Payment Status في تطبيقك تلقائيًا.
10. عند حدوث Timeout أثناء Payment أو Refund، لا تفترض أن العملية فشلت. أعد الاستعلام من Provider أو أعد المحاولة بنفس Idempotency Key بعد مراجعة حالة العملية.
11. استخدم Test Mode وSandbox أثناء التطوير. اختبر سيناريوهات النجاح والفشل والـ Timeout والـ Partial Refund والـ Duplicate Webhooks قبل Live.
12. راجع توافق العملة مع `supported_currencies` في `config/payments.php` قبل بدء الدفع.

---

## 19. استكشاف الأخطاء

### `Stripe is not configured`

تحقق من:

```dotenv
STRIPE_SECRET=sk_test_...
```

ثم:

```bash
php artisan optimize:clear
```

### `PayPal is not configured`

تحقق من وجود القيم:

```dotenv
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_MODE=sandbox
```

وتأكد أن Client ID وSecret من نفس Environment. لا تخلط Sandbox credentials مع `PAYPAL_MODE=live`.

### Webhook Signature Verification Failed

- Stripe: تأكد أن `STRIPE_WEBHOOK_SECRET` يخص نفس الـ Endpoint أو CLI Listener الذي يستقبل الحدث.
- PayPal: تأكد أن `PAYPAL_WEBHOOK_ID` يخص Webhook Endpoint الصحيح في نفس Sandbox/Live App.
- بعد تعديل `.env` شغّل `php artisan optimize:clear`.

### PayPal Transaction Search HTTP 403

هذا خطأ من PayPal Transaction Search وليس دليلًا مباشرًا أن Payment المحلية غير صحيحة. راجع أن Sandbox/Live App لديه صلاحية Transaction Search/Reporting المطلوبة، وأن Access Token صادر من نفس App والـ Environment. قد تحتاج صلاحية/Entitlement إضافية من PayPal للحساب.

### `manual_review` بسبب `missing_provider_reference`

معناه أن السجل المحلي لا يحتوي على Provider Reference صالح يمكن البحث به عند مزود الدفع. افحص هل Checkout تم إنشاؤه أصلًا، وهل `provider_reference` تم حفظه.

### `provider_not_found` أو `local_not_found`

راجع فترة البحث والمنطقة الزمنية والـ Reference Mapping. مع PayPal، قد لا تظهر المعاملة مباشرة في Transaction Search؛ امنح بيانات التقارير وقتًا للظهور ثم أعد البحث.

### `completed_with_errors`

لا تعتبر التشغيل ناجحًا بالكامل لمجرد أن بعض العناصر `matched`. اقرأ:

```php
$run->last_error;
$run->summary;
$run->items;
```

وافحص `provider_snapshot` و`differences` لكل عنصر قبل أي تعديل يدوي.

---

## ملخص الاستخدام السريع

أغلب المشاريع ستحتاج إلى هذا التسلسل فقط:

```bash
composer require ahmed/laravel-payment:^2.0
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=laravel-payment-reconciliation-config
php artisan payments:install
php artisan optimize:clear
```

ثم في كود المشروع:

```php
$payment = app(\Ahmed\LaravelPayment\Core\Actions\CreatePayment::class)->handle(
    provider: \Ahmed\LaravelPayment\Enums\PaymentProvider::Stripe,
    amount: '100.00',
    currency: 'USD',
    idempotencyKey: $storedPaymentAttemptKey,
    payable: $order,
);

return redirect()->away($payment->checkout_url);
```

بعد التأكيد، راقب:

```php
$payment->fresh()->status->value;
$payment->fresh()->provider_reference;
$payment->fresh()->provider_transaction_id;
```

ولعمل Refund:

```php
$refund = app(\Ahmed\LaravelPayment\Core\Actions\RefundPayment::class)->handle(
    payment: $payment,
    amount: '20.00',
    idempotencyKey: $storedRefundIdempotencyKey,
    requestedBy: auth()->user(),
    reason: 'Customer request',
);
```

ولتشغيل Reconciliation:

```bash
php artisan payment:reconcile stripe --from="2026-11-15T00:00:00Z" --to="2026-11-16T00:00:00Z"
```

---

**License:** MIT


## Running the package test suite

The package has a standalone Testbench/Pest test suite. It intentionally does not require the host application's `App\Models`, Booking factories, or Stancl Tenancy setup.

```bash
composer install
composer test
```

The former Barber House application-specific tests are preserved under `integration-tests/barber-house/`; they are not part of the standalone package test command because they require the consuming application's models, factories, migrations, and tenancy configuration.

Before a release, also complete the manual checklist in `RELEASE-TEST-CHECKLIST.md` using a disposable database and provider test credentials.

Release verification limitations and the exact checks performed for this archive are recorded in `RELEASE-VERIFICATION.md`.
