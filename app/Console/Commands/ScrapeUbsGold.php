<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeUbsGold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:ubs-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari website UBS Lifestyle';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        info('Mengambil data dari UBS Lifestyle...');

        $source = Source::where('slug', 'ubs')->first();

        if (! $source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        $url = 'https://ubslifestyle.com/harga-buyback-hari-ini/';

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0',
        ])->get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web UBS! Status Code: '.$response->status());

            return;
        }

        $crawler = new Crawler($response->body());
        $results = [];

        $crawler->filter('table tbody tr.table-price')->each(function (Crawler $node) use (&$results) {
            $tds = $node->filter('td');

            if ($tds->count() >= 3) {
                $weightRaw = $tds->eq(0)->text();
                $baseRaw = $tds->eq(1)->text();
                $buybackRaw = $tds->eq(2)->text();

                $weight = (float) preg_replace('/[^0-9.]/', '', $weightRaw);

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
            $this->error('Gagal parsing data. Pastikan struktur HTML tidak berubah.');

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
            ['Pecahan (gr)', 'Harga Beli (Dasar)', 'Harga Jual (Buyback)'],
            $results
        );

        $this->info('Scraping UBS selesai!');
    }
}
