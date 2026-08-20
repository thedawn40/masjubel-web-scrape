<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoldPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'weight' => (float) $this->weight,
            'base_price' => (int) $this->base_price,
            'tax_price' => $this->tax_price ? (int) $this->tax_price : null,
            'buyback_price' => $this->buyback_price ? (int) $this->buyback_price : null,
            'recorded_at' => $this->recorded_at->toIso8601String(),
        ];
    }
}
