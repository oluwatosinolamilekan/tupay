<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
