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
        Schema::create('gold_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();

            // 8 total digit, 2 digit di belakang koma (contoh: 1000.50)
            $table->decimal('weight', 8, 2);

            // Kolom harga dibikin nullable karena format tiap web beda-beda
            $table->bigInteger('base_price')->nullable(); // Harga Jual/Dasar
            $table->bigInteger('tax_price')->nullable(); // Harga + Pajak (Khusus Antam)
            $table->bigInteger('buyback_price')->nullable(); // Harga Buyback

            // Waktu data ditarik
            $table->timestamp('recorded_at')->useCurrent();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gold_prices');
    }
};
