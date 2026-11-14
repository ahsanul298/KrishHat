<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\District;
use App\Models\Product;
use App\Models\Upazila;
use App\Services\AddressValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const WITH = ['categoryRecord', 'photos', 'farmer:id,name'];

    /** GET /api/v1/products — public search & filter. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()->with(self::WITH);

        if (! empty($filters['q'])) {
            $term = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('description', 'like', $term);
            });
        }

        if (! empty($filters['category'])) {
            $value = $filters['category'];
            if (ctype_digit($value)) {
                $query->where('category_id', (int) $value);
            } else {
                $query->whereHas('categoryRecord', fn ($q) => $q->where('slug', $value));
            }
        }

        if (! empty($filters['district'])) {
            $value = $filters['district'];
            if (ctype_digit($value)) {
                $query->where('district_id', (int) $value);
            } else {
                $query->where(function ($q) use ($value) {
                    $q->where('district', 'like', $value)
                        ->orWhereHas('districtRecord', fn ($d) => $d
                            ->where('name_en', 'like', $value)
                            ->orWhere('name_bn', 'like', $value));
                });
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }
        if ($request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('id'),
        };

        return ProductResource::collection(
            $query->paginate($filters['per_page'] ?? 12)->withQueryString()
        );
    }

    /** GET /api/v1/products/{id} — public. */
    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(self::WITH));
    }

    /** GET /api/v1/farmer/products — the logged-in farmer's own listings. */
    public function mine(Request $request)
    {
        return ProductResource::collection(
            $request->user()->products()->with(self::WITH)->orderByDesc('id')->paginate(20)
        );
    }

    /** POST /api/v1/farmer/products */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isFarmerVerified()) {
            return response()->json([
                'message' => 'পণ্য আপলোডের আগে আপনার NID ও ট্রেড লাইসেন্স জমা দিয়ে প্রোফাইল ভেরিফাই করতে হবে।',
            ], 403);
        }

        if (! $user->canCreateMoreListings()) {
            return response()->json([
                'message' => 'আপনার বর্তমান প্ল্যানে সর্বোচ্চ ' . $user->activeListingLimit() . 'টি লিস্টিং অনুমোদিত।',
            ], 403);
        }

        $data = $request->validate($this->rules());
        $data = $this->prepareData($data);
        $product = $user->products()->create($data);

        return (new ProductResource($product->load(self::WITH)))
            ->response()->setStatusCode(201);
    }

    /** PUT/PATCH /api/v1/farmer/products/{product} */
    public function update(Request $request, Product $product): ProductResource
    {
        abort_unless($product->farmer_id === $request->user()->id, 403, 'এটি আপনার পণ্য নয়।');

        $data = $request->validate($this->rules(partial: true));
        $data = $this->prepareData($data, $product);
        $product->update($data);

        return new ProductResource($product->fresh()->load(self::WITH));
    }

    /** DELETE /api/v1/farmer/products/{product} */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->farmer_id === $request->user()->id, 403, 'এটি আপনার পণ্য নয়।');

        if ($product->orderItems()->exists() || $product->offers()->exists() || $product->conversations()->exists()) {
            return response()->json([
                'message' => 'এই পণ্যের অর্ডার/অফার/চ্যাটের ইতিহাস আছে, তাই মোছা যাবে না। স্টক ০ করে দিন।',
            ], 422);
        }

        $product->delete();

        return response()->json(['message' => 'পণ্য মুছে ফেলা হয়েছে।']);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => [$required, 'numeric', 'min:0', 'max:99999999.99'],
            'unit' => [$required, 'string', 'max:50'],
            'stock' => [$required, 'integer', 'min:0'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'upazila_id' => ['nullable', 'integer', 'exists:upazilas,id'],
            'union_id' => ['nullable', 'integer', 'exists:unions,id'],
            'urban_area_id' => ['nullable', 'integer', 'exists:urban_areas,id'],
            'post_office_id' => ['nullable', 'integer', 'exists:post_offices,id'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'image' => ['nullable', 'url', 'max:2048'],
        ];
    }

    /** Address-chain validation (division → district → upazila …) + legacy text fields. */
    private function prepareData(array $data, ?Product $existing = null): array
    {
        $addressKeys = ['division_id', 'district_id', 'upazila_id', 'union_id', 'urban_area_id', 'post_office_id'];
        $touchesAddress = (bool) array_intersect($addressKeys, array_keys($data));

        if ($touchesAddress) {
            // On update, validate the full chain together with the values already stored.
            $chain = array_merge(
                $existing ? $existing->only($addressKeys) : [],
                array_intersect_key($data, array_flip($addressKeys))
            );
            $resolved = app(AddressValidationService::class)->validateChain($chain);

            if (! empty($resolved['district'])) {
                $data['district'] = $resolved['district']->name_en;
            }
            if (! empty($resolved['upazila'])) {
                $data['upazila'] = $resolved['upazila']->name_en;
            }
            if (! empty($resolved['post_office']) && empty($data['postal_code'])) {
                $data['postal_code'] = $resolved['post_office']->post_code;
            }
        }

        if (array_key_exists('min_order_quantity', $data)) {
            $data['min_order_quantity'] = max(1, (int) ($data['min_order_quantity'] ?? 1));
        }

        if (! empty($data['category_id'])) {
            $data['category'] = Category::findOrFail($data['category_id'])->name;
        }

        return $data;
    }
}
