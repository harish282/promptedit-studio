<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Services\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_provider_cost_usd' => Money::toDollars($this->estimated_provider_cost_nano),
            'estimated_charge_usd' => Money::toDollars($this->estimated_charge_nano),
            'estimated_charge_display' => Money::format($this->estimated_charge_nano),
            'actual_provider_cost_usd' => $this->actual_provider_cost_nano !== null
                ? Money::toDollars($this->actual_provider_cost_nano)
                : null,
            'actual_charge_usd' => $this->actual_charge_nano !== null
                ? Money::toDollars($this->actual_charge_nano)
                : null,
            'actual_charge_display' => $this->actual_charge_nano !== null
                ? Money::format($this->actual_charge_nano)
                : null,
            'image_url' => $this->image_url,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'provider_usage' => $this->provider_usage,
            'created_at' => $this->created_at?->toISOString(),
            'completed_at' => $this->provider_completed_at?->toISOString(),
        ];
    }
}
