<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scrape:hartadinata-gold')->dailyAt('09:41');
Schedule::command('scrape:sampoerna-gold')->dailyAt('09:44');
Schedule::command('scrape:logam-mulia-gold')->dailyAt('09:47');
Schedule::command('scrape:ubs-gold')->dailyAt('09:50');
Schedule::command('sync:product-prices')->dailyAt('09:55');
