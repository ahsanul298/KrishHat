<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_code' => $this->tracking_code,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'total_amount' => (float) $this->total_amount,
            'delivery_address' => $this->delivery_address,
            'phone' => $this->phone,
            'estimated_delivery_at' => optional($this->estimated_delivery_at)->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => optional($item->product)->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ])->values()),
            'tracking' => $this->whenLoaded('trackingEvents', fn () => $this->trackingEvents->map(fn ($event) => [
                'status' => $event->status,
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'at' => optional($event->created_at)->toIso8601String(),
            ])->values()),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
