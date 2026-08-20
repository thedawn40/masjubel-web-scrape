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
            ['name' => 'Sampoerna', 'slug' => 'sampoerna', 'url' => 'https://sampoernagold.com/'],
            ['name' => 'Antam', 'slug' => 'antam', 'url' => 'https://www.logammulia.com/id/harga-emas-hari-ini'],
            ['name' => 'UBS', 'slug' => 'ubs', 'url' => 'https://ubslifestyle.com/harga-buyback-hari-ini/'],
        ];

        foreach ($sources as $source) {
            Source::updateOrCreate(['slug' => $source['slug']], $source);
        }
    }
}
