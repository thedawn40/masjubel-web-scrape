<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\GoldPriceResource;
use App\Models\GoldPrice;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $prices = $latestPrices->get($source->id, collect());

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

            if (! $latest) {
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
        if (! $source) {
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
}
