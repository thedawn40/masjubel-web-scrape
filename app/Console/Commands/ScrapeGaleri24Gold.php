<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeGaleri24Gold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:galeri24-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari website Galeri 24';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mengambil data dari Galeri 24...');

        $source = Source::where('slug', 'galeri24')->first();

        if (! $source) {
            $this->error('Data sumber belum ada di database! Jalankan SourceSeeder terlebih dahulu.');

            return;
        }

        $url = 'https://galeri24.co.id/harga-emas';

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ])->timeout(60)->retry(3, 2000)->get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web Galeri 24! Status Code: '.$response->status());

            return;
        }

        $results = $this->parsePrices($response->body());

        if (empty($results)) {
            $this->error('Tabel GALERI 24 tidak ditemukan atau struktur HTML berubah.');

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
            ['Berat (gr)', 'Harga Jual', 'Harga Buyback'],
            array_map(fn ($item) => [
                $item['weight'],
                number_format($item['base_price']),
                isset($item['buyback_price']) ? number_format($item['buyback_price']) : '-',
            ], $results)
        );

        $this->info('Scraping Galeri 24 selesai!');
    }

    /**
     * Parsing harga "GALERI 24" dari payload JSON Nuxt (__NUXT_DATA__).
     *
     * Halaman Galeri 24 adalah SPA Nuxt. Data harga lengkap dibawa dalam payload
     * JSON terkompresi pada <script id="__NUXT_DATA__">, yang lebih stabil dan
     * mencakup semua gramasi (termasuk 0.5) dibanding render DOM yang bervariasi.
     * Setiap entri memiliki field: denomination (berat), sellingPrice (harga jual),
     * buybackPrice (buyback) dan vendorName (merek).
     *
     * @return array<int, array{weight: float, base_price: int, buyback_price: int|null}>
     */
    private function parsePrices(string $html): array
    {
        $payload = $this->extractNuxtPayload($html);

        if ($payload !== null) {
            $results = $this->parseFromPayload($payload);

            if (! empty($results)) {
                return $results;
            }
        }

        return $this->parseFromDom($html);
    }

    /**
     * Ekstrak array payload Nuxt dari markup __NUXT_DATA__.
     *
     * @return array<int, mixed>|null
     */
    private function extractNuxtPayload(string $html): ?array
    {
        if (! preg_match('/<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $match)) {
            return null;
        }

        $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Buka payload Nuxt terkompresi menjadi daftar entri produk.
     *
     * Payload berupa array berisi referensi induk yang saling menunjuk lewat indeks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFromPayload(array $payload): array
    {
        $resolve = function (int $index) use (&$resolve, $payload) {
            $node = $payload[$index];

            if (is_array($node)) {
                $resolved = [];
                foreach ($node as $key => $value) {
                    if (is_int($value)) {
                        $resolved[$key] = $resolve($value);
                    } else {
                        $resolved[$key] = $value;
                    }
                }

                return $resolved;
            }

            if (is_int($node)) {
                return $resolve($node);
            }

            return $node;
        };

        // Slot ketiga payload adalah daftar indeks entri produk (goldPrice).
        $productIndexes = $payload[3] ?? [];

        if (! is_array($productIndexes)) {
            return [];
        }

        $results = [];

        foreach ($productIndexes as $index) {
            if (! is_int($index)) {
                continue;
            }

            $entry = $resolve($index);

            if (($entry['vendorName'] ?? null) !== 'GALERI 24') {
                continue;
            }

            $weight = (float) ($entry['denomination'] ?? 0);
            $basePrice = (int) ($entry['sellingPrice'] ?? 0);
            $buybackPrice = (int) ($entry['buybackPrice'] ?? 0);

            if ($weight <= 0 || $weight > 10000) {
                continue;
            }

            $results[$weight] = [
                'weight' => $weight,
                'base_price' => $basePrice,
                'buyback_price' => $buybackPrice > 0 ? $buybackPrice : null,
            ];
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Fallback: parse tabel "Harga GALERI 24" dari render DOM (SSR).
     *
     * Setiap gramasi dirender sebagai baris <div class="grid grid-cols-5"> dengan
     * tiga sel: berat, harga jual, dan harga buyback. Digunakan hanya jika payload
     * JSON gagal diparse.
     *
     * @return array<int, array{weight: float, base_price: int, buyback_price: int|null}>
     */
    private function parseFromDom(string $html): array
    {
        $crawler = new Crawler($html);

        $wrapper = $crawler->filterXPath('//div[@id="GALERI 24"]');

        if ($wrapper->count() === 0) {
            return [];
        }

        $results = [];

        $wrapper->filter('div.grid.grid-cols-5')->each(function (Crawler $row) use (&$results) {
            $cells = $row->filter('div.p-3');

            if ($cells->count() !== 3) {
                return;
            }

            $weightRaw = $cells->eq(0)->text();
            $baseRaw = $cells->eq(1)->text();
            $buybackRaw = $cells->eq(2)->text();

            if (! is_numeric(str_replace(',', '.', $weightRaw))) {
                return;
            }

            $weight = (float) str_replace(',', '.', preg_replace('/[^0-9,]/', '', $weightRaw));
            $basePrice = (int) preg_replace('/[^0-9]/', '', $baseRaw);
            $buybackPrice = (int) preg_replace('/[^0-9]/', '', $buybackRaw);

            if ($weight <= 0 || $weight > 10000) {
                return;
            }

            $results[$weight] = [
                'weight' => $weight,
                'base_price' => $basePrice,
                'buyback_price' => $buybackPrice > 0 ? $buybackPrice : null,
            ];
        });

        ksort($results);

        return array_values($results);
    }
}
