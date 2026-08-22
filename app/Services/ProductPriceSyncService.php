<?php

namespace App\Services;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

class ProductPriceSyncService
{
    /**
     * Sinkronkan harga products (DB sekunder) dari gold_prices (DB utama).
     *
     * Pencocokan berdasarkan source_id + weight. Untuk source komoditas
     * (is_commodity = true), harga diambil dari baris weight=1 (harga per gram)
     * lalu dikalikan weight produk.
     *
     * @return array{updated: int, skipped: int}
     */
    public function sync(): array
    {
        $products = DB::connection('mysql_secondary')
            ->table('products')
            ->select('id', 'source_id', 'weight')
            ->get();

        $commoditySourceIds = Source::where('is_commodity', true)
            ->pluck('id')
            ->flip();

        $updated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $isCommodity = $commoditySourceIds->has($product->source_id);
            $weight = (float) $product->weight;

            if ($weight <= 0) {
                $skipped++;

                continue;
            }

            // Komoditas: tarif per gram tersimpan di baris weight=1
            $query = GoldPrice::where('source_id', $product->source_id)
                ->where('weight', $isCommodity ? 1 : $weight);

            $basePrice = $query->orderByDesc('recorded_at')->value('base_price');

            if ($basePrice === null) {
                $skipped++;

                continue;
            }

            $price = $isCommodity ? (int) round($basePrice * $weight) : (int) $basePrice;

            DB::connection('mysql_secondary')
                ->table('products')
                ->where('id', $product->id)
                ->update([
                    'price' => $price,
                    'updated_at' => now(),
                ]);

            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }
}
