<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('commodity_prices');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('commodity_prices', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();

            $table->string('commodity');
            $table->string('purity')->nullable();
            $table->bigInteger('buy_price_per_gram');

            $table->string('unit')->default('per gram');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }
};
