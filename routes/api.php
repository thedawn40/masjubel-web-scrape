<?php

use App\Http\Controllers\Api\V1\GoldPriceController;
use App\Http\Controllers\Api\V1\ScrapeController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/gold-prices/all', [GoldPriceController::class, 'index']);
    Route::get('/gold-prices/price-table', [GoldPriceController::class, 'priceTable']);
    Route::get('/gold-prices/buyback-simulation', [GoldPriceController::class, 'buybackSimulation']);
    Route::get('/gold-prices/highlight', [GoldPriceController::class, 'highlight']);
    Route::get('/gold-prices/chart-data', [GoldPriceController::class, 'history']);

    Route::post('/gold-prices', [GoldPriceController::class, 'store']);

    Route::get('/sources', [SourceController::class, 'index']);
    Route::get('/sources/{source}', [SourceController::class, 'show']);
    Route::post('/sources', [SourceController::class, 'store'])
        ->middleware('verify-scrape-key');
    Route::match(['put', 'patch'], '/sources/{source}', [SourceController::class, 'update'])
        ->middleware('verify-scrape-key');
    Route::delete('/sources/{source}', [SourceController::class, 'destroy'])
        ->middleware('verify-scrape-key');

    Route::post('/scrape/run', [ScrapeController::class, 'run'])
        ->middleware('verify-scrape-key');

    Route::post('/sync/product-prices', [SyncController::class, 'productPrices']);
});
