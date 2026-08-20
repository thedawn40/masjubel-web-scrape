<?php

use App\Http\Controllers\Api\V1\GoldPriceController;
use App\Http\Controllers\Api\V1\ScrapeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/gold-prices/all', [GoldPriceController::class, 'index']);
    Route::get('/gold-prices/highlight', [GoldPriceController::class, 'highlight']);
    Route::get('/gold-prices/chart-data', [GoldPriceController::class, 'history']);

    Route::post('/scrape/run', [ScrapeController::class, 'run'])
        ->middleware('verify-scrape-key');
});
