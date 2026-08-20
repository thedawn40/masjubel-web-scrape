<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeSampoernaGold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:sampoerna-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari Sampoerna Gold';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        info('Mengambil data dari Sampoerna Gold...');

        $source = Source::where('slug', 'sampoerna')->first();

        if (! $source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        $url = 'https://sampoernagold.com/';

        $response = Http::get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web Sampoerna Gold!');

            return;
        }
        $crawler = new Crawler($response->body());
        $results = [];

        if (! str_contains($response->body(), '0,5')) {
            $this->warn('Data tidak ditemukan di HTML mentah. Coba cek Network tab di browser, kemungkinan pakai API!');

            return;
        }

        $crawler->filter('table.table-emas tr.table-text')->each(function (Crawler $node, $i) use (&$results) {
            // Ekstrak teks dari kolom <td>
            $tds = $node->filter('td');

            if ($tds->count() >= 3) {
                $weightRaw = $tds->eq(0)->text();
                $baseRaw = $tds->eq(1)->text();
                $buybackRaw = $tds->eq(2)->text();

                // Pembersihan Berat
                $weightClean = str_replace(',', '.', preg_replace('/[^0-9,]/', '', $weightRaw));
                $weight = (float) $weightClean;

                // Pembersihan Harga: Hilangkan "Rp" dan titik
                $basePrice = (int) preg_replace('/[^0-9]/', '', $baseRaw);
                $buybackPrice = (int) preg_replace('/[^0-9]/', '', $buybackRaw);

                if ($weight > 0) {
                    $results[] = [
                        'weight' => $weight,
                        'base_price' => $basePrice,
                        'buyback_price' => $buybackPrice,
                    ];
                }
            }
        });

        if (empty($results)) {
            $this->error('Tabel tidak ditemukan atau struktur HTML berbeda.');

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

        $this->table(
            ['Emas (gr)', 'Harga Jual', 'Harga Buyback'],
            $results
        );

        $this->info('Scraping selesai!');
    }
}
