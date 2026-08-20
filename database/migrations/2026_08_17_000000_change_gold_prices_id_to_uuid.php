<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_prices_tmp', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->decimal('weight', 8, 2);
            $table->bigInteger('base_price')->nullable();
            $table->bigInteger('tax_price')->nullable();
            $table->bigInteger('buyback_price')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        $rows = DB::table('gold_prices')->orderBy('id')->get();

        foreach ($rows as $row) {
            DB::table('gold_prices_tmp')->insert([
                'id' => Str::uuid7(),
                'source_id' => $row->source_id,
                'weight' => $row->weight,
                'base_price' => $row->base_price,
                'tax_price' => $row->tax_price,
                'buyback_price' => $row->buyback_price,
                'recorded_at' => $row->recorded_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::dropIfExists('gold_prices');
        Schema::rename('gold_prices_tmp', 'gold_prices');
    }

    public function down(): void
    {
        Schema::create('gold_prices_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->decimal('weight', 8, 2);
            $table->bigInteger('base_price')->nullable();
            $table->bigInteger('tax_price')->nullable();
            $table->bigInteger('buyback_price')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        DB::table('gold_prices_tmp')->insertUsing(
            ['source_id', 'weight', 'base_price', 'tax_price', 'buyback_price', 'recorded_at', 'created_at', 'updated_at'],
            DB::table('gold_prices')->select(
                'source_id',
                'weight',
                'base_price',
                'tax_price',
                'buyback_price',
                'recorded_at',
                'created_at',
                'updated_at'
            )
        );

        Schema::dropIfExists('gold_prices');
        Schema::rename('gold_prices_tmp', 'gold_prices');
    }
};
