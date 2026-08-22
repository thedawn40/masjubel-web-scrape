<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commodity_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();

            $table->string('commodity'); // Emas / Perak / Tembaga
            $table->string('purity')->nullable(); // Kekemurnian (contoh: 99,99%), null jika tidak ada
            $table->bigInteger('buy_price_per_gram'); // Harga beli per gram

            $table->string('unit')->default('per gram');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commodity_prices');
    }
};
