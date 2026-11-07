<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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
            'scrape:galeri24-gold',
        ];

        $failed = [];

        foreach ($scrapers as $command) {
            try {
                $exitCode = Artisan::call($command);

                if ($exitCode !== 0) {
                    $failed[] = [
                        'command' => $command,
                        'reason' => trim(Artisan::output()) ?: 'Command returned a failure status.',
                    ];
                }
            } catch (\Throwable $exception) {
                Log::error('Scraper command failed.', [
                    'command' => $command,
                    'exception' => $exception,
                ]);

                $failed[] = [
                    'command' => $command,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        try {
            $syncExitCode = Artisan::call('sync:product-prices');

            if ($syncExitCode !== 0) {
                $failed[] = [
                    'command' => 'sync:product-prices',
                    'reason' => trim(Artisan::output()) ?: 'Command returned a failure status.',
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('Product price sync failed.', ['exception' => $exception]);

            $failed[] = [
                'command' => 'sync:product-prices',
                'reason' => $exception->getMessage(),
            ];
        }

        $hasFailures = $failed !== [];

        return response()->json([
            'status' => $hasFailures ? 'partial_success' : 'success',
            'message' => $hasFailures
                ? 'Scraping completed with some failures'
                : 'All scrapers executed and product prices synced',
            'failed' => $failed,
        ], 200);
    }
}
