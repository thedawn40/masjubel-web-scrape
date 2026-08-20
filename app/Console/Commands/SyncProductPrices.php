<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncProductPrices extends Command
{
    protected $signature = 'sync:product-prices';

    protected $description = 'Sync harga dari gold_prices ke products berdasarkan source_id dan weight';

    public function handle()
    {
        $this->info('Syncing product prices...');

        $products = DB::connection('mysql_secondary')
            ->table('products')
            ->select('id', 'source_id', 'weight')
            ->get();

        $updated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $latestPrice = DB::connection('mysql')
                ->table('gold_prices')
                ->where('source_id', $product->source_id)
                ->where('weight', $product->weight)
                ->orderByDesc('recorded_at')
                ->value('base_price');

            if (is_null($latestPrice)) {
                $skipped++;

                continue;
            }

            DB::connection('mysql_secondary')
                ->table('products')
                ->where('id', $product->id)
                ->update([
                    'price' => $latestPrice,
                    'updated_at' => now(),
                ]);

            $updated++;
        }

        $this->info("Selesai. Updated: {$updated}, Skipped: {$skipped}");
    }
}
