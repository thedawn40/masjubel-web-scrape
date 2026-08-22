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

        $targetUrl = 'https://www.logammulia.com/id/harga-emas-hari-ini';
        $apiKey = env('SCRAPER_API_KEY');

        $url = "http://api.scraperapi.com?api_key={$apiKey}&url=".urlencode($targetUrl);

        $response = Http::timeout(60)->retry(3, 2000)->get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web Antam! Status Code: '.$response->status());

            return;
        }

        $crawler = new Crawler($response->body());
        $results = [];

        // Flag untuk menandai apakah baris saat ini adalah kategori "Emas Batangan" reguler
        $isRegularGold = false;

        $crawler->filter('.table-bordered tr')->each(function (Crawler $node) use (&$results, &$isRegularGold, $buybackPrice) {
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
        $targetUrl = 'https://www.logammulia.com/id/sell/gold';
        $apiKey = env('SCRAPER_API_KEY');

        $url = "http://api.scraperapi.com?api_key={$apiKey}&url=".urlencode($targetUrl);

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

        $crawler = new Crawler($response->body());

        // Sumber utama: hidden input <input id="valBasePrice" value="2585000.00">
        if ($crawler->filter('#valBasePrice')->count() > 0) {
            $raw = $crawler->filter('#valBasePrice')->attr('value');
            $price = (int) round((float) str_replace(',', '', $raw));

            if ($price > 0) {
                return $price;
            }
        }

        // Fallback: cari angka setelah teks "Harga Buyback:" (format "Rp 2,585,000")
        if (preg_match('/Harga\s+Buyback\s*:<\/span>.*?Rp\s*([\d.,]+)/is', strip_tags($response->body(), '<span><b>'), $m)) {
            $price = (int) round((float) preg_replace('/[^\d.]/', '', $m[1]));

            if ($price > 0) {
                return $price;
            }
        }

        $this->warn('Elemen harga buyback tidak ditemukan di halaman.');

        return null;
    }
}
