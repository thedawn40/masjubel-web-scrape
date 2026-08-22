<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_commodity' => 'boolean',
    ];

    public function goldPrices(): HasMany
    {
        return $this->hasMany(GoldPrice::class);
    }

    public function latestPrice(): HasOne
    {
        return $this->hasOne(GoldPrice::class)->latestOfMany();
    }
}
