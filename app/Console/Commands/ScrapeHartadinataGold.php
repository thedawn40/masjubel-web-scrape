<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScrapeHartadinataGold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:hartadinata-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari API Hartadinata';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mengambil data dari API Hartadinata...');

        $source = Source::where('slug', 'hartadinata')->first();

        if (! $source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        // Kita langsung tembak endpoint API aslinya
        $url = 'https://hrtagold.id/api/v1/brandings/price/daily';

        $response = Http::get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses API Hartadinata!');

            return;
        }

        // Konversi response JSON ke Array PHP
        $json = $response->json();
        $results = [];

        // Pastikan array 'data' ada
        if (isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $series) {

                // Filter hanya mengambil data seri "Gold" biasa sesuai request klien
                if (isset($series['series']) && $series['series'] === 'Gold') {

                    foreach ($series['prices'] as $item) {
                        $results[] = [
                            'weight' => (float) $item['gramasi'],
                            'base_price' => (int) $item['price'],
                            'buyback_price' => (int) $item['buyback_price'],
                        ];
                    }

                    // Karena data "Gold" sudah ketemu, hentikan looping untuk efisiensi
                    break;
                }
            }
        }

        if (empty($results)) {
            $this->error('Data harga (Series Gold) tidak ditemukan di API!');

            return;
        }

        $this->info('Menyimpan data ke database...');
        foreach ($results as $item) {
            GoldPrice::create([
                'source_id' => $source->id,
                'weight' => $item['weight'],
                'base_price' => $item['base_price'] ?? null,
                'tax_price' => $item['tax_price'] ?? null,
                'buyback_price' => $item['buyback_price'] ?? null,
                'recorded_at' => now(),
            ]);
        }

        // Tampilkan hasil di terminal
        $this->table(
            ['Berat (gr)', 'Harga Dasar', 'Harga Buyback'],
            $results
        );

        $this->info('Scraping API selesai dengan sukses!');
    }
}
