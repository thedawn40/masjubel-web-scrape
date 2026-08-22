<?php

namespace App\Console\Commands;

use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScrapeKingHalimGold extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:kinghalim-gold';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape data harga emas dari website King Halim';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mengambil data dari King Halim...');

        $source = Source::where('slug', 'kinghalim')->first();

        if (! $source) {
            $this->error('Data sumber belum ada di database!');

            return;
        }

        $url = 'https://kinghalim.com/goldbarwithamala';

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0',
        ])->timeout(60)->retry(3, 2000)->get($url);

        if (! $response->successful()) {
            $this->error('Gagal mengakses web King Halim! Status Code: '.$response->status());

            return;
        }

        $results = $this->parsePrices($response->body());
        $buybackRate = $this->parseBuybackRate($response->body());

        if ($buybackRate !== null) {
            $this->info("Harga buyback King Halim: Rp {$buybackRate}/gram");
        } else {
            $this->warn('Harga buyback gagal diambil, data akan disimpan tanpa buyback.');
        }

        if (empty($results)) {
            $this->error('Gagal parsing data. Pastikan struktur HTML tidak berubah.');

            return;
        }

        // Buyback dipublikasikan sebagai tarif flat per gram,
        // jadi nilai per pecahan dihitung proporsional tanpa potongan.
        foreach ($results as &$item) {
            $item['buyback_price'] = $buybackRate !== null
                ? (int) round($buybackRate * $item['weight'])
                : null;
        }
        unset($item);

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
            ['Berat (gr)', 'Harga Jual', 'Buyback'],
            array_map(fn ($item) => [
                $item['weight'],
                number_format($item['base_price']),
                isset($item['buyback_price']) ? number_format($item['buyback_price']) : '-',
            ], $results)
        );

        $this->info('Scraping King Halim selesai!');
    }

    /**
     * Parsing pasangan gramasi & harga jual dari HTML mentah.
     *
     * Markup halaman sangat "kotor" (site builder dengan font tag bertumpuk),
     * dan harga kadang terpecah oleh spasi/nbsp (mis. "Rp 254,980 ,000.00"),
     * jadi parsing dilakukan pada teks hasil strip tag dengan normalisasi angka.
     *
     * @return array<int, array{weight: float, base_price: int}>
     */
    private function parsePrices(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Rapatkan angka yang terpecah spasi/newline: "254,980 ,000.00" -> "254,980,000.00"
        $text = preg_replace('/(?<=\d)[\s\x{00A0}]+(?=\d)/u', '', $text);
        $text = preg_replace('/(?<=\d),[\s\x{00A0}]+(?=\d)/u', ',', $text);

        preg_match_all('/(\d+(?:[.,]\d+)?)\s*Gr\.?\s*Rp\s*([\d.,]+?)\.(\d{2})/u', $text, $matches, PREG_SET_ORDER);

        $results = [];
        foreach ($matches as $match) {
            $weight = (float) str_replace(',', '.', $match[1]);
            $price = (int) str_replace(',', '', $match[2]);

            // Dedupe: JSON internal site builder + DOM render menghasilkan pasangan ganda
            if ($weight <= 0 || $weight > 10000 || isset($results[$weight])) {
                continue;
            }

            $results[$weight] = [
                'weight' => $weight,
                'base_price' => $price,
            ];
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Ambil tarif buyback flat per gram dari teks "Harga Buyback : Rp x / Gr".
     */
    private function parseBuybackRate(string $html): ?int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/(?<=\d)[\s\x{00A0}]+(?=\d)/u', '', $text);

        if (! preg_match('/Harga\s*Buyback\s*:?\s*Rp\s*([\d.,]+)/iu', $text, $match)) {
            return null;
        }

        $price = (int) round((float) str_replace(',', '', $match[1]));

        return $price > 0 ? $price : null;
    }
}
