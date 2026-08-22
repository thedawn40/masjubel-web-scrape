<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProductPriceSyncService;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function productPrices(ProductPriceSyncService $service): JsonResponse
    {
        $result = $service->sync();

        return response()->json([
            'status' => 'success',
            'message' => 'Success synced product prices',
            'data' => [
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ],
        ], 200);
    }
}
