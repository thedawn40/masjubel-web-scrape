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

        if (! $source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        $buybackPrice = $this->fetchBuybackPrice();

        if ($buybackPrice === null) {
            $this->warn('Harga buyback gagal diambil, data akan disimpan tanpa buyback.');
        } else {
            $this->info("Harga buyback Antam: Rp {$buybackPrice}/gram");
        }

        $targetUrl = 'https://logammulia.com/harga-emas';
        $apiKey = env('SCRAPER_API_KEY');

        $url = "http://api.scraperapi.com?api_key={$apiKey}&render=true&wait=5000&url=".urlencode($targetUrl);

        $response = Http::timeout(60)->retry(3, 2000)->get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web Antam! Status Code: '.$response->status());

            return;
        }

        $crawler = new Crawler($response->body());
        $results = [];

        $crawler->filter('.d-flex.align-center')->each(function (Crawler $node) use (&$results, $buybackPrice) {
            $texts = [];
            $node->filter('div, span')->each(function (Crawler $el) use (&$texts) {
                $t = trim($el->text());
                if ($t !== '' && !in_array($t, $texts)) {
                    $texts[] = $t;
                }
            });
            $clean = array_values(array_filter($texts, function ($t) {
                $isGr = preg_match('/^\d+([.,]\d+)?\s*gr$/i', $t);
                $isRp = strpos($t, 'Rp') === 0;
                return strlen($t) < 30 && ($isGr || $isRp);
            }));
            if (count($clean) >= 3) {
                $weight = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $clean[0]));
                $basePrice = (int) preg_replace('/[^0-9]/', '', $clean[1]);
                $taxPrice = (int) preg_replace('/[^0-9]/', '', $clean[2]);
                if ($weight > 0 && $weight <= 1000) {
                    $exists = collect($results)->contains('weight', $weight);
                    if (! $exists && count($results) < 12) {
                        $results[] = [
                            'weight' => $weight,
                            'base_price' => $basePrice,
                            'tax_price' => $taxPrice,
                            'buyback_price' => $buybackPrice,
                        ];
                    }
                }
            }
        });

        if (empty($results)) {
            $this->error('Gagal parsing data. Pastikan struktur HTML tidak berubah.');

            return;
        }

        // Hitung nilai buyback net per pecahan (mengikuti kalkulator resmi Antam):
        // PPh 22 (1.5%) jika total > Rp10 juta, materai Rp10.000 jika total > Rp5 juta
        if ($buybackPrice !== null) {
            foreach ($results as &$item) {
                $gross = $buybackPrice * $item['weight'];
                $pph22 = $gross > 10_000_000 ? round($gross * 0.015, 0, PHP_ROUND_HALF_EVEN) : 0;
                $materai = $gross <= 5_000_000 ? 0 : 10_000;

                $item['buyback_price'] = (int) round($gross - $pph22 - $materai);
            }
            unset($item);
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
            ['Berat (gr)', 'Harga Dasar', 'Harga (+Pajak)', 'Buyback (Net)'],
            $results
        );

        $this->info('Scraping Antam selesai!');
    }

    /**
     * Ambil harga buyback per gram dari halaman simulasi buyback Antam.
     */
    private function fetchBuybackPrice(): ?int
    {
        $targetUrl = 'https://logammulia.com/harga-hari-ini/emas?tab=jual';
        $apiKey = env('SCRAPER_API_KEY');

        $url = "http://api.scraperapi.com?api_key={$apiKey}&render=true&wait=5000&url=".urlencode($targetUrl);

        try {
            $response = Http::timeout(60)->retry(3, 2000)->get($url);
        } catch (\Throwable $e) {
            $this->warn('Gagal terhubung ke halaman buyback: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->warn('Gagal mengakses halaman buyback! Status Code: '.$response->status());

            return null;
        }

        $html = $response->body();
        $crawler = new Crawler($html);

        $buybackPrice = null;
        $crawler->filter('.font-weight-bold.color-grey-darker')->each(function (Crawler $node) use (&$buybackPrice) {
            $text = trim($node->text());
            if (strpos($text, 'Rp') !== false && strpos($text, '/gr') !== false) {
                $buybackPrice = (int) preg_replace('/[^0-9]/', '', $text);
            }
        });

        if ($buybackPrice !== null && $buybackPrice > 0) {
            return $buybackPrice;
        }

        // Cari teks "Harga Beli" diikuti "Rp X,XXX,XXX"
        if (preg_match('/Harga\s+Beli.*?Rp\s*([\d.,]+)/is', strip_tags($html), $m)) {
            $price = (int) round((float) preg_replace('/[^\d.]/', '', $m[1]));

            if ($price > 0) {
                return $price;
            }
        }

        // Fallback: cari angka setelah "Emas Fisik" atau format Rp
        if (preg_match('/Emas\s+Fisik.*?Rp\s*([\d.,]+)/is', strip_tags($html), $m)) {
            $price = (int) round((float) preg_replace('/[^\d.]/', '', $m[1]));

            if ($price > 0) {
                return $price;
            }
        }

        $this->warn('Elemen harga buyback tidak ditemukan di halaman.');

        return null;
    }
}
