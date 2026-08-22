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
        // Tipe source kini ditentukan sources.is_commodity, bukan flag per baris
        Schema::table('gold_prices', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn('is_commodity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gold_prices', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->boolean('is_commodity')->default(false)->after('buyback_price');
        });
    }
};
