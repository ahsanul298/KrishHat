<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'unit' => $this->unit,
            'stock' => (int) $this->stock,
            'min_order_quantity' => $this->min_order_quantity,
            'category' => $this->whenLoaded('categoryRecord', fn () => $this->categoryRecord ? [
                'id' => $this->categoryRecord->id,
                'name' => $this->categoryRecord->name,
                'slug' => $this->categoryRecord->slug,
            ] : null),
            'location' => [
                'division_id' => $this->division_id,
                'district_id' => $this->district_id,
                'upazila_id' => $this->upazila_id,
                'district' => $this->district,
                'upazila' => $this->upazila,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],
            'image_url' => $this->imageUrl(),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos
                ->map(fn ($photo) => asset('storage/' . $photo->path))->values()),
            'farmer' => $this->whenLoaded('farmer', fn () => $this->farmer ? [
                'id' => $this->farmer->id,
                'name' => $this->farmer->name,
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function imageUrl(): ?string
    {
        if ($this->relationLoaded('photos') && $this->photos->isNotEmpty()) {
            return asset('storage/' . $this->photos->first()->path);
        }

        if ($this->image) {
            if (Str::startsWith($this->image, ['http://', 'https://'])) {
                return $this->image;
            }

            if (Str::startsWith($this->image, ['images/', 'products/'])) {
                return asset($this->image);
            }

            return asset('storage/' . $this->image);
        }

        return $this->reference_image ? asset($this->reference_image) : null;
    }
}
