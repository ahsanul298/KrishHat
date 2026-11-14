<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MarketBenchmarkPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    /** GET /api/v1/categories */
    public function categories(): JsonResponse
    {
        $categories = Category::query()->withCount('products')->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'icon' => $c->icon,
                'products_count' => $c->products_count,
            ]);

        return response()->json(['data' => $categories]);
    }

    /** GET /api/v1/market-prices — DAM/market benchmark prices. */
    public function marketPrices(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'crop' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'price_type' => ['nullable', Rule::in(['grower', 'wholesale', 'retail'])],
            'verified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MarketBenchmarkPrice::query();

        if (! empty($filters['crop'])) {
            $term = '%' . addcslashes($filters['crop'], '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('crop_name_en', 'like', $term)->orWhere('crop_name_bn', 'like', $term);
            });
        }
        if (! empty($filters['district'])) {
            $query->where('district', 'like', $filters['district']);
        }
        if (! empty($filters['price_type'])) {
            $query->where('price_type', $filters['price_type']);
        }
        if ($request->boolean('verified')) {
            $query->where('is_verified', true);
        }

        $page = $query->orderByDesc('observation_date')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)->withQueryString();

        $page->getCollection()->transform(fn ($p) => [
            'id' => $p->id,
            'crop_name_en' => $p->crop_name_en,
            'crop_name_bn' => $p->crop_name_bn,
            'crop_variety' => $p->crop_variety,
            'market_name' => $p->market_name,
            'district' => $p->district,
            'price_type' => $p->price_type,
            'price' => $p->price,
            'price_min' => $p->price_min,
            'price_max' => $p->price_max,
            'unit' => $p->unit,
            'observation_date' => $p->observation_date,
            'source_name' => $p->source_name,
            'is_verified' => (bool) $p->is_verified,
        ]);

        return response()->json($page);
    }
}
