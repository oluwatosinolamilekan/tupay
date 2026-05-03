<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('account_number', 20)->unique();
            $table->char('currency', 3);
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('idempotency_key');
            $table->string('type', 32);
            $table->string('direction', 16);
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_before_minor');
            $table->bigInteger('balance_after_minor');
            $table->string('status', 32)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at', 'id']);
            $table->unique(['idempotency_key', 'direction']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->unsignedBigInteger('rate_micro');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency']);
            $table->index(['base_currency', 'quote_currency', 'is_active']);
        });

        Schema::create('settlement_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference')->unique();
            $table->string('status', 32)->default('received');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_webhooks');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('accounts');
    }
};
