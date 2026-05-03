<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
