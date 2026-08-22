<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sources = [
            ['name' => 'Hartadinata', 'slug' => 'hartadinata', 'url' => 'https://hrtagold.id/id/gold-price'],
            ['name' => 'Waris', 'slug' => 'sampoerna', 'url' => 'https://sampoernagold.com/'],
            ['name' => 'Antam', 'slug' => 'antam', 'url' => 'https://www.logammulia.com/id/harga-emas-hari-ini'],
            ['name' => 'UBS', 'slug' => 'ubs', 'url' => 'https://ubslifestyle.com/harga-buyback-hari-ini/'],
            ['name' => 'King Halim', 'slug' => 'kinghalim', 'url' => 'https://kinghalim.com/goldbarwithamala'],
            // Sumber komoditas (Emas/Perak/Tembaga) - harga diisi via CommodityPriceSeeder
            ['name' => 'Emas', 'slug' => 'emas', 'url' => '', 'is_commodity' => true],
            ['name' => 'Perak', 'slug' => 'perak', 'url' => '', 'is_commodity' => true],
            ['name' => 'Tembaga', 'slug' => 'tembaga', 'url' => '', 'is_commodity' => true],
        ];

        foreach ($sources as $source) {
            Source::updateOrCreate(['slug' => $source['slug']], $source);
        }
    }
}
