<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class ScrapeController extends Controller
{
    public function run(): JsonResponse
    {
        set_time_limit(0);

        $scrapers = [
            'scrape:hartadinata-gold',
            'scrape:sampoerna-gold',
            'scrape:logam-mulia-gold',
            'scrape:ubs-gold',
            'scrape:kinghalim-gold',
        ];

        foreach ($scrapers as $command) {
            Artisan::call($command);
        }

        Artisan::call('sync:product-prices');

        return response()->json([
            'status' => 'success',
            'message' => 'All scrapers executed and product prices synced',
        ], 200);
    }
}
