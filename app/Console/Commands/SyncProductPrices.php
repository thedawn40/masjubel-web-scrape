<?php

namespace App\Console\Commands;

use App\Services\ProductPriceSyncService;
use Illuminate\Console\Command;

class SyncProductPrices extends Command
{
    protected $signature = 'sync:product-prices';

    protected $description = 'Sync harga dari gold_prices ke products berdasarkan source_id dan weight (komoditas dikalikan weight dari tarif per gram)';

    public function handle(ProductPriceSyncService $service)
    {
        $this->info('Syncing product prices...');

        $result = $service->sync();

        $this->info("Selesai. Updated: {$result['updated']}, Skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
