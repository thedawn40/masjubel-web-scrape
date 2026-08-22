<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\GoldPriceResource;
use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GoldPriceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = Source::all();

        // 2. Dapatkan tanggal terbaru untuk masing-masing source_id (Menghindari N+1)
        $latestDatesQuery = GoldPrice::select('source_id', DB::raw('MAX(DATE(recorded_at)) as latest_date'))
            ->groupBy('source_id');

        // 3. Ambil SEMUA harga pada tanggal terbaru tersebut untuk setiap source menggunakan Join
        $latestPrices = GoldPrice::joinSub($latestDatesQuery, 'latest_dates', function ($join) {
            $join->on('gold_prices.source_id', '=', 'latest_dates.source_id')
                ->on(DB::raw('DATE(gold_prices.recorded_at)'), '=', 'latest_dates.latest_date');
        })
            ->orderBy('gold_prices.weight', 'asc')
            ->get()
            // Group by source_id di memori agar mudah dipetakan
            ->groupBy('source_id');

        // 4. Transformasi format ke response
        $responseRaw = $sources->map(function ($source) use ($latestPrices) {
            $prices = $latestPrices->get($source->id, collect())
                // Saring duplikasi hari yang sama: sisakan rekaman terbaru per gramasi
                ->sortByDesc('recorded_at')
                ->unique(fn($price) => (float) $price->weight)
                ->sortBy('weight')
                ->values();

            if ($prices->isEmpty()) {
                return null;
            }

            return [
                'source_name' => $source->name,
                'source_slug' => $source->slug,
                'source_url' => $source->url,
                'last_updated' => $prices->first()->recorded_at->toIso8601String(),
                'prices' => GoldPriceResource::collection($prices),
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Success fetch all latest gold prices data',
            'data' => $responseRaw,
        ], 200);
    }

    public function priceTable(Request $request): JsonResponse
    {
        // Tab virtual gabungan untuk seluruh source komoditas
        $commodityName = 'Lainnya';
        $commoditySlug = 'lainnya';

        $sourceSlug = $request->query('source', 'antam');

        // Tabs: semua source aktif yang punya data harga
        $sourcesWithPrices = Source::where('is_active', true)
            ->whereHas('goldPrices')
            ->orderBy('id')
            ->get();

        // Source emas dapat tab individual; semua source komoditas digabung satu tab
        [$goldSources, $commoditySources] = $sourcesWithPrices
            ->partition(fn($source) => $this->resolveSourceType($source) === 'gold');

        $tabs = $goldSources->map(fn($source) => [
            'name' => $source->name,
            'slug' => $source->slug,
            'type' => 'gold',
        ])->values();

        if ($commoditySources->isNotEmpty()) {
            $tabs[] = [
                'name' => $commodityName,
                'slug' => $commoditySlug,
                'type' => 'commodity',
            ];
        }

        // Respons gabungan harga semua komoditas
        if ($sourceSlug === $commoditySlug && $commoditySources->isNotEmpty()) {
            return $this->combinedCommodityTableResponse(
                $commodityName,
                $commoditySlug,
                $tabs,
                $commoditySources->pluck('id')->all(),
            );
        }

        // Source yang dipilih untuk isi tabel
        $activeSource = $sourcesWithPrices->firstWhere('slug', $sourceSlug)
            ?? Source::where('is_active', true)->where('slug', $sourceSlug)->first();

        if (!$activeSource) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gold resource not found',
            ], 404);
        }

        $type = $this->resolveSourceType($activeSource);
        $lastUpdated = null;

        if ($type === 'commodity') {
            // Rows komoditas: harga beli per gram dari baris weight=1 pada tanggal terbaru.
            // Data tersimpan di gold_prices (weight=1) per source komoditas.
            $latestDatesQuery = GoldPrice::select('source_id', DB::raw('MAX(DATE(recorded_at)) as latest_date'))
                ->where('source_id', $activeSource->id)
                ->groupBy('source_id');

            $latestPrices = GoldPrice::joinSub($latestDatesQuery, 'latest_dates', function ($join) {
                $join->on('gold_prices.source_id', '=', 'latest_dates.source_id')
                    ->on(DB::raw('DATE(gold_prices.recorded_at)'), '=', 'latest_dates.latest_date');
            })
                ->where('gold_prices.weight', 1)
                ->orderByDesc('recorded_at')
                ->get()
                // Saring duplikasi hari yang sama: sisakan rekaman terbaru
                ->unique('weight')
                ->values();

            $lastUpdated = $latestPrices->isNotEmpty()
                ? $latestPrices->first()->recorded_at->toIso8601String()
                : null;

            $rows = $latestPrices->map(fn($price) => [
                'commodity' => $activeSource->name,
                'purity' => null,
                'buy_price_per_gram' => $price->base_price !== null ? (int) $price->base_price : null,
                'buyback_price' => $price->buyback_price !== null ? (int) $price->buyback_price : null,
                'unit' => 'per gram',
            ])->values();
        } else {
            // Ambil harga pada tanggal terbaru untuk source terpilih (pola sama dengan index())
            $latestDatesQuery = GoldPrice::select('source_id', DB::raw('MAX(DATE(recorded_at)) as latest_date'))
                ->where('source_id', $activeSource->id)
                ->groupBy('source_id');

            $latestPrices = GoldPrice::joinSub($latestDatesQuery, 'latest_dates', function ($join) {
                $join->on('gold_prices.source_id', '=', 'latest_dates.source_id')
                    ->on(DB::raw('DATE(gold_prices.recorded_at)'), '=', 'latest_dates.latest_date');
            })
                ->orderBy('gold_prices.weight', 'asc')
                ->get();

            // Saring duplikasi hari yang sama: sisakan rekaman terbaru per gramasi
            $latestPrices = $latestPrices
                ->sortByDesc('recorded_at')
                ->unique(fn($price) => (float) $price->weight)
                ->sortBy('weight')
                ->values();

            $lastUpdated = $latestPrices->isNotEmpty()
                ? $latestPrices->first()->recorded_at->toIso8601String()
                : null;

            // Transformasi baris: HARGA JUAL (base), HARGA BELI (buyback), SPREAD (%)
            $rows = $latestPrices->map(function ($price) {
                $sellPrice = $price->base_price !== null ? (int) $price->base_price : null;
                $buyPrice = $price->buyback_price !== null ? (int) $price->buyback_price : null;

                $spread = null;
                if ($sellPrice && $buyPrice !== null && $sellPrice > 0) {
                    $spread = round((($sellPrice - $buyPrice) / $sellPrice) * 100, 2);
                }

                return [
                    'weight' => (float) $price->weight,
                    'label' => ((string) (float) $price->weight) . 'g',
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'spread_percentage' => $spread,
                ];
            })->values();
        }

        return response()->json([
            'status' => 'success',
            'message' => "Success fetch {$activeSource->name} gold price table data",
            'data' => [
                'tabs' => $tabs,
                'active_source' => [
                    'name' => $activeSource->name,
                    'slug' => $activeSource->slug,
                    'type' => $type,
                ],
                'last_updated' => $lastUpdated,
                'rows' => $rows,
            ],
        ], 200);
    }

    /**
     * Respons gabungan harga seluruh source komoditas (tab virtual slug "lainnya").
     * Rows diambil dari baris weight=1 pada tanggal terbaru masing-masing source
     * komoditas, diurutkan per source_id agar stabil.
     */
    private function combinedCommodityTableResponse(string $name, string $slug, Collection $tabs, array $sourceIds): JsonResponse
    {
        $latestDatesQuery = GoldPrice::select('source_id', DB::raw('MAX(DATE(recorded_at)) as latest_date'))
            ->whereIn('source_id', $sourceIds)
            ->groupBy('source_id');

        $latestPrices = GoldPrice::joinSub($latestDatesQuery, 'latest_dates', function ($join) {
            $join->on('gold_prices.source_id', '=', 'latest_dates.source_id')
                ->on(DB::raw('DATE(gold_prices.recorded_at)'), '=', 'latest_dates.latest_date');
        })
            ->where('gold_prices.weight', 1)
            ->orderBy('gold_prices.source_id')
            ->orderByDesc('gold_prices.recorded_at')
            ->with('source:id,name')
            ->get()
            // Saring duplikasi: sisakan rekaman terbaru per source (semuanya weight=1)
            ->unique(fn($price) => $price->source_id . '-' . $price->weight)
            ->values();

        $lastUpdated = $latestPrices->isNotEmpty()
            ? $latestPrices->max('recorded_at')->toIso8601String()
            : null;

        $rows = $latestPrices->map(function ($price) {
            $buyPricePerGram = $price->base_price !== null ? (int) $price->base_price : null;
            $buybackPrice = $price->buyback_price !== null ? (int) $price->buyback_price : null;

            // Spread: selisih harga beli vs buyback (%), pola sama dengan branch gold
            $spread = null;
            if ($buyPricePerGram && $buybackPrice !== null && $buyPricePerGram > 0) {
                $spread = round((($buyPricePerGram - $buybackPrice) / $buyPricePerGram) * 100, 2);
            }

            return [
                'commodity' => $price->source->name,
                'purity' => null,
                'buy_price_per_gram' => $buyPricePerGram,
                'buyback_price' => $buybackPrice,
                'spread_percentage' => $spread,
                'unit' => 'per gram',
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'message' => "Success fetch {$name} commodity price table data",
            'data' => [
                'tabs' => $tabs,
                'active_source' => [
                    'name' => $name,
                    'slug' => $slug,
                    'type' => 'commodity',
                ],
                'last_updated' => $lastUpdated,
                'rows' => $rows,
            ],
        ], 200);
    }

    /**
     * Tipe data source: 'gold' untuk pecahan emas, 'commodity' untuk Emas/Perak/Tembaga.
     * Ditentukan dari kolom is_commodity pada tabel sources.
     */
    private function resolveSourceType(Source $source): string
    {
        return $source->is_commodity
            ? 'commodity'
            : 'gold';
    }

    public function buybackSimulation(Request $request): JsonResponse
    {
        $sourceSlug = $request->query('source', 'antam');
        $weight = $request->query('weight', 1);

        if (!is_numeric($weight) || $weight <= 0 || $weight > 10000) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter weight harus berupa angka antara 0.001 dan 10000.',
            ], 422);
        }

        $source = Source::where('slug', $sourceSlug)->first();
        if (!$source) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gold resource not found',
            ], 404);
        }

        // Tarif buyback per gram diambil dari baris weight=1 (net 1g ≡ tarif,
        // karena di bawah threshold PPh dan materai)
        $rateRow = GoldPrice::where('source_id', $source->id)
            ->where('weight', 1.0)
            ->whereNotNull('buyback_price')
            ->latest('recorded_at')
            ->first();

        if (!$rateRow) {
            return response()->json([
                'status' => 'error',
                'message' => 'Buyback price data not found for this source',
            ], 404);
        }

        // Simulasi mengikuti kalkulator resmi Antam:
        // PPh 22 (1.5%) jika total > Rp10 juta, materai Rp10.000 jika total > Rp5 juta
        $pricePerGram = (int) $rateRow->buyback_price;
        $gross = $pricePerGram * (float) $weight;
        $pph22 = $gross > 10_000_000 ? round($gross * 0.015, 0, PHP_ROUND_HALF_EVEN) : 0;
        $materai = $gross <= 5_000_000 ? 0 : 10_000;
        $total = round($gross - $pph22 - $materai);

        return response()->json([
            'status' => 'success',
            'message' => "Success fetch {$source->name} buyback simulation data",
            'data' => [
                'source_name' => $source->name,
                'weight' => (float) $weight,
                'price_per_gram' => $pricePerGram,
                'estimated_amount' => (int) $total,
            ],
        ], 200);
    }

    public function highlight(): JsonResponse
    {
        $sources = Source::all();

        $highlights = $sources->map(function ($source) {
            $prices = GoldPrice::where('source_id', $source->id)
                ->where('weight', 1.0)
                ->latest('recorded_at')
                ->take(2)
                ->get();

            $latest = $prices->first();
            $previous = $prices->skip(1)->first();

            if (!$latest) {
                return null;
            }

            $trendPercentage = 0;
            $isUp = true;

            // Kalkulasi Tren
            if ($previous && $previous->base_price > 0) {
                $diff = $latest->base_price - $previous->base_price;
                $trendPercentage = round(($diff / $previous->base_price) * 100, 2);
                $isUp = $diff >= 0;
            }

            return [
                'source_name' => $source->name,
                'is_commodity' => $source->is_commodity,
                'weight' => 1,
                'current_price' => $latest->base_price,
                'previous_price' => $previous ? $previous->base_price : null,
                'trend_percentage' => abs($trendPercentage),
                'is_up' => $isUp,
                'last_updated' => $latest->recorded_at->toIso8601String(),
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Success fetch highlight 1 gram gold price data',
            'data' => $highlights,
        ], 200);
    }

    public function history(Request $request): JsonResponse
    {
        $sourceSlug = $request->query('source', 'antam');
        $days = (int) $request->query('range', 7);

        $source = Source::where('slug', $sourceSlug)->first();
        if (!$source) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gold resource not found',
            ], 404);
        }

        // Ambil data historis dari database
        $historyData = GoldPrice::where('source_id', $source->id)
            ->where('weight', 1.0)
            ->whereDate('recorded_at', '>=', now()->subDays($days))
            ->orderBy('recorded_at', 'asc')
            ->get();

        // 4. Format data khusus untuk kebutuhan Chart Frontend
        $formattedChart = $historyData->map(function ($item) {
            return [
                'date' => $item->recorded_at->format('Y-m-d'), // Format tanggal sumbu X
                'price' => $item->base_price,                   // Nilai sumbu Y
            ];
        })->unique('date')->values();

        return response()->json([
            'status' => 'success',
            'message' => "Succeed fetch history {$source->name} for {$days} last days",
            'data' => [
                'source_name' => $source->name,
                'range_days' => $days,
                'chart_data' => $formattedChart,
            ],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'exists:sources,slug'],
            'recorded_at' => ['nullable', 'date'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.weight' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'prices.*.base_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.tax_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.buyback_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = Source::where('slug', $validated['source'])->first();
        $recordedAt = isset($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'])
            : now();

        foreach ($validated['prices'] as $item) {
            GoldPrice::create([
                'source_id' => $source->id,
                'weight' => $item['weight'],
                'base_price' => $item['base_price'] ?? null,
                'tax_price' => $item['tax_price'] ?? null,
                'buyback_price' => $item['buyback_price'] ?? null,
                'recorded_at' => $recordedAt,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Success store ' . count($validated['prices']) . " gold prices for {$source->name}",
            'data' => [
                'source_slug' => $source->slug,
                'total_inserted' => count($validated['prices']),
                'recorded_at' => $recordedAt->toIso8601String(),
            ],
        ], 201);
    }
}
