<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeLogamMuliaGold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:logam-mulia-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari website LogamMulia';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mengambil data dari Antam via ScraperAPI...');

        $source = Source::where('slug', 'antam')->first();

        if (!$source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        $targetUrl = 'https://www.logammulia.com/id/harga-emas-hari-ini';
        $apiKey = env('SCRAPER_API_KEY');

        $url = "http://api.scraperapi.com?api_key={$apiKey}&url=" . urlencode($targetUrl);

        $response = Http::timeout(60)->retry(3, 2000)->get($url);

        if (!$response->successful()) {
            $this->error('Gagal mengakses web Antam! Status Code: ' . $response->status());

            return;
        }

        $crawler = new Crawler($response->body());
        $results = [];

        // Flag untuk menandai apakah baris saat ini adalah kategori "Emas Batangan" reguler
        $isRegularGold = false;

        $crawler->filter('.table-bordered tr')->each(function (Crawler $node) use (&$results, &$isRegularGold) {
            // Cek apakah ini baris pemisah kategori (menggunakan tag )
            $th = $node->filter('th');
            if ($th->count() > 0) {
                $categoryText = trim($th->text());

                // Kalau ketemu "Emas Batangan", nyalakan flag.
                // Kalau ketemu seri lain (seperti "Gift Series"), matikan flag.
                if ($categoryText === 'Emas Batangan') {
                    $isRegularGold = true;
                } elseif ($categoryText !== 'Berat' && $categoryText !== 'Harga Dasar') {
                    // Mematikan flag untuk "Emas Batangan Gift Series", "Imlek", dll
                    $isRegularGold = false;
                }

                return; // Skip ke baris selanjutnya
            }

            // Jika sedang berada di bawah kategori Emas Batangan Reguler
            if ($isRegularGold) {
                $tds = $node->filter('td');

                if ($tds->count() >= 3) {
                    $weightRaw = $tds->eq(0)->text();
                    $baseRaw = $tds->eq(1)->text();
                    $taxRaw = $tds->eq(2)->text(); // Kolom ketiga adalah Harga + Pajak

                    // Bersihkan "gr" dan parse ke float
                    $weight = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $weightRaw));

                    // Bersihkan koma ribuan dan parse ke integer
                    $basePrice = (int) preg_replace('/[^0-9]/', '', $baseRaw);
                    $taxPrice = (int) preg_replace('/[^0-9]/', '', $taxRaw);

                    if ($weight > 0) {
                        $results[] = [
                            'weight' => $weight,
                            'base_price' => $basePrice,
                            'tax_price' => $taxPrice,
                        ];
                    }
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
            ['Berat (gr)', 'Harga Dasar', 'Harga (+Pajak)'],
            $results
        );

        $this->info('Scraping Antam selesai!');
    }
}
