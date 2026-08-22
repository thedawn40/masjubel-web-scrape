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
        Schema::table('commodity_prices', function (Blueprint $table) {
            $table->bigInteger('buyback_price')->nullable()->after('buy_price_per_gram'); // Harga buyback per gram
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commodity_prices', function (Blueprint $table) {
            $table->dropColumn('buyback_price');
        });
    }
};
