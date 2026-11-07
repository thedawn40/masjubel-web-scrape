<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScrapeLotusArchiGold extends Command
{
    protected $signature = 'scrape:lotus-archi-gold';

    protected $description = 'Scrape data harga emas dari Lotus Archi';

    public function handle(): int
    {
        $this->info('Mengambil data harga emas dari Lotus Archi...');

        $source = Source::where('slug', 'lotus-archi')->first();

        if (! $source) {
            $this->error('Data sumber Lotus Archi belum ada di database!');

            return self::FAILURE;
        }

        $response = Http::timeout(60)->retry(3, 2000)->get('https://lotusarchi.com/pricing/');

        if (! $response->successful()) {
            $this->error('Gagal mengakses halaman pricing Lotus Archi! Status Code: '.$response->status());

            return self::FAILURE;
        }

        $results = $this->parsePrices($response->body());

        if (empty($results)) {
            $this->error('Data harga emas Lotus Archi tidak ditemukan atau struktur halaman berubah.');

            return self::FAILURE;
        }

        $this->info('Menyimpan data ke database...');

        foreach ($results as $item) {
            GoldPrice::create([
                'source_id' => $source->id,
                'weight' => $item['weight'],
                'base_price' => $item['base_price'],
                'tax_price' => null,
                'buyback_price' => $item['buyback_price'],
                'recorded_at' => now(),
            ]);
        }

        $this->table(
            ['Berat (gr)', 'Harga Jual', 'Harga Buyback'],
            $results
        );

        $this->info('Scraping Lotus Archi selesai!');

        return self::SUCCESS;
    }

    /**
     * Parse tabel harga emas dan tarif buyback dari halaman pricing.
     *
     * @return array<int, array{weight: float, base_price: int, buyback_price: int}>
     */
    private function parsePrices(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        preg_match_all('/HARGA EMAS(.*?)(?:HARGA PERAK|PRODUK DAN LAYANAN)/is', $text, $sectionMatches);

        $section = collect($sectionMatches[1] ?? [])
            ->first(fn (string $candidate): bool => str_contains($candidate, 'Buyback Price'));

        if ($section === null) {
            return [];
        }

        if (! preg_match('/Buyback\s+Price\s*:\s*Rp\.?\s*([\d.]+)/i', $section, $buybackMatch)) {
            return [];
        }

        $buybackPerGram = $this->parsePrice($buybackMatch[1]);
        $results = [];

        preg_match_all(
            '/(?:Paper\s+Gold\s+)?(\d+(?:[.,]\d+)?)\s*gr(?:\s*\(WB\))?\s*([\d.]+)/i',
            $section,
            $priceMatches,
            PREG_SET_ORDER
        );

        foreach ($priceMatches as $match) {
            $weight = (float) str_replace(',', '.', $match[1]);
            $weightKey = str_replace(',', '.', $match[1]);

            if ($weight <= 0 || isset($results[$weightKey])) {
                continue;
            }

            $basePrice = $this->parsePrice($match[2]);

            if ($basePrice <= 0) {
                continue;
            }

            $results[$weightKey] = [
                'weight' => $weight,
                'base_price' => $basePrice,
                'buyback_price' => (int) round($buybackPerGram * $weight),
            ];
        }

        ksort($results);

        return array_values($results);
    }

    private function parsePrice(string $value): int
    {
        return (int) preg_replace('/[^0-9]/', '', $value);
    }
}
