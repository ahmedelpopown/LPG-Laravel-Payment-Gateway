<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'paypal_client_id',
        'paypal_client_secret',
        'paypal_webhook_id',
        'paypal_mode',
        'stripe_secret_key',
        'stripe_publishable_key',
        'stripe_webhook_secret',
    ];

    public function up(): void
    {
        $connection = (string) (config('payments.connection') ?: config('tenancy.database.central_connection') ?: config('database.default', 'mysql'));

        if (! Schema::connection($connection)->hasTable('tenant_payment_credentials')) {
            Schema::connection($connection)->create('tenant_payment_credentials', function (Blueprint $table): void {
                $table->string('tenant_id')->primary();
                $table->string('paypal_client_id', 255)->nullable();
                $table->text('paypal_client_secret')->nullable();
                $table->string('paypal_webhook_id', 120)->nullable();
                $table->string('paypal_mode', 20)->default('sandbox');
                $table->text('stripe_secret_key')->nullable();
                $table->string('stripe_publishable_key', 255)->nullable();
                $table->text('stripe_webhook_secret')->nullable();
                $table->timestamps();
            });
        }

        $this->moveLegacyCredentials($connection);
    }

    public function down(): void
    {
        $connection = (string) (config('payments.connection') ?: config('tenancy.database.central_connection') ?: config('database.default', 'mysql'));
        $schema = Schema::connection($connection);

        if ($schema->hasTable('tenant_payment_credentials') && $schema->hasTable('tenants')) {
            $this->restoreLegacyColumns($connection);
        }

        $schema->dropIfExists('tenant_payment_credentials');
    }

    private function moveLegacyCredentials(string $connection): void
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('tenants')) {
            return;
        }

        $availableColumns = array_values(array_filter(
            self::LEGACY_COLUMNS,
            fn (string $column): bool => $schema->hasColumn('tenants', $column),
        ));

        if ($availableColumns === []) {
            return;
        }

        $columns = array_merge(['id'], $availableColumns);

        DB::connection($connection)
            ->table('tenants')
            ->select($columns)
            ->orderBy('id')
            ->chunkById(100, function ($tenants) use ($connection, $availableColumns): void {
                foreach ($tenants as $tenant) {
                    $attributes = ['tenant_id' => (string) $tenant->id];

                    foreach ($availableColumns as $column) {
                        $value = $tenant->{$column} ?? null;
                        if (in_array($column, ['paypal_client_secret', 'stripe_secret_key', 'stripe_webhook_secret'], true)) {
                            $value = $this->ensureEncrypted(is_string($value) ? $value : null);
                        }
                        $attributes[$column] = $value;
                    }

                    $attributes['updated_at'] = now();
                    $attributes['created_at'] = now();

                    DB::connection($connection)
                        ->table('tenant_payment_credentials')
                        ->updateOrInsert(
                            ['tenant_id' => $attributes['tenant_id']],
                            $attributes,
                        );
                }
            }, 'id');

        Schema::connection($connection)->table('tenants', function (Blueprint $table) use ($availableColumns): void {
            $table->dropColumn($availableColumns);
        });
    }


    private function restoreLegacyColumns(string $connection): void
    {
        $schema = Schema::connection($connection);
        $columnsToCreate = [
            'paypal_client_id' => static fn (Blueprint $table) => $table->string('paypal_client_id', 255)->nullable(),
            'paypal_client_secret' => static fn (Blueprint $table) => $table->text('paypal_client_secret')->nullable(),
            'paypal_webhook_id' => static fn (Blueprint $table) => $table->string('paypal_webhook_id', 120)->nullable(),
            'paypal_mode' => static fn (Blueprint $table) => $table->string('paypal_mode', 20)->default('sandbox'),
            'stripe_secret_key' => static fn (Blueprint $table) => $table->text('stripe_secret_key')->nullable(),
            'stripe_publishable_key' => static fn (Blueprint $table) => $table->string('stripe_publishable_key', 255)->nullable(),
            'stripe_webhook_secret' => static fn (Blueprint $table) => $table->text('stripe_webhook_secret')->nullable(),
        ];

        foreach ($columnsToCreate as $column => $definition) {
            if (! $schema->hasColumn('tenants', $column)) {
                $schema->table('tenants', $definition);
            }
        }

        DB::connection($connection)
            ->table('tenant_payment_credentials')
            ->orderBy('tenant_id')
            ->chunkById(100, function ($credentials) use ($connection): void {
                foreach ($credentials as $credential) {
                    $attributes = [];
                    foreach (self::LEGACY_COLUMNS as $column) {
                        $attributes[$column] = $credential->{$column} ?? null;
                    }

                    DB::connection($connection)
                        ->table('tenants')
                        ->where('id', $credential->tenant_id)
                        ->update($attributes);
                }
            }, 'tenant_id', 'tenant_id');
    }

    private function ensureEncrypted(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            Crypt::decryptString($value);

            return $value;
        } catch (\Throwable) {
            return Crypt::encryptString($value);
        }
    }
};
