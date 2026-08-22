<?php

namespace Database\Seeders;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Database\Seeder;

class CommodityPriceSeeder extends Seeder
{
    /**
     * Seed harga komoditas (per gram) ke gold_prices sebagai baris
     * weight=1, satu source per komoditas (flag is_commodity ada di tabel sources).
     */
    public function run(): void
    {
        $commodities = [
            ['slug' => 'emas', 'buy_price_per_gram' => 1_580_000, 'buyback_price' => 1_520_000],
            ['slug' => 'perak', 'buy_price_per_gram' => 11_500, 'buyback_price' => 10_500],
            ['slug' => 'tembaga', 'buy_price_per_gram' => 120, 'buyback_price' => 100],
        ];

        foreach ($commodities as $item) {
            $source = Source::where('slug', $item['slug'])->first();

            if (! $source || ! $source->is_commodity) {
                continue;
            }

            GoldPrice::updateOrCreate(
                [
                    'source_id' => $source->id,
                    'weight' => 1,
                ],
                [
                    'base_price' => $item['buy_price_per_gram'],
                    'tax_price' => null,
                    'buyback_price' => $item['buyback_price'],
                    'recorded_at' => now(),
                ]
            );
        }
    }
}
