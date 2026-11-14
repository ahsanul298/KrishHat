<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketBenchmarkPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    /** Gives a user an API token and returns the plain token. */
    private function tokenFor(User $user): string
    {
        $plain = Str::random(64);
        $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

        return $plain;
    }

    /**
     * Sends the next request with the given Bearer token. The auth guards are reset
     * first, because several requests in one test share one application instance.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function farmer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'farmer',
            'verification_status' => 'verified',
        ], $attrs));
    }

    private function product(User $farmer, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'farmer_id' => $farmer->id,
            'name' => 'Potato',
            'price' => 30,
            'unit' => 'kg',
            'stock' => 100,
        ], $attrs));
    }

    public function test_public_product_list_supports_search_filter_and_sort(): void
    {
        $farmer = $this->farmer();
        $veg = Category::create(['name' => 'Vegetables', 'slug' => 'vegetables']);
        $this->product($farmer, ['name' => 'Potato', 'price' => 30, 'category_id' => $veg->id]);
        $this->product($farmer, ['name' => 'Tomato', 'price' => 80, 'category_id' => $veg->id]);
        $this->product($farmer, ['name' => 'Rice', 'price' => 60, 'stock' => 0]);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/products?q=tom')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Tomato');

        $this->getJson('/api/v1/products?category=vegetables')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products?min_price=50&max_price=70')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rice');
        $this->getJson('/api/v1/products?in_stock=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products?sort=price_desc')->assertOk()
            ->assertJsonPath('data.0.name', 'Tomato');
    }

    public function test_product_list_rejects_invalid_filters(): void
    {
        $this->getJson('/api/v1/products?sort=hack&per_page=9999')
            ->assertStatus(422)->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_product_detail_and_categories_are_public(): void
    {
        $farmer = $this->farmer();
        $product = $this->product($farmer);
        Category::create(['name' => 'Fruits', 'slug' => 'fruits']);

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonPath('data.name', 'Potato');
        $this->getJson('/api/v1/products/99999')->assertNotFound();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.slug', 'fruits');
    }

    public function test_market_prices_are_listed(): void
    {
        MarketBenchmarkPrice::create([
            'crop_name_en' => 'Potato', 'crop_name_bn' => 'আলু', 'price' => 28,
            'observation_date' => '2026-10-01', 'retrieval_date' => '2026-10-02', 'is_verified' => true,
        ]);

        $this->getJson('/api/v1/market-prices?crop=potato&verified=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.crop_name_en', 'Potato');
    }

    public function test_register_login_me_and_logout_flow(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rahim', 'email' => 'rahim@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'buyer',
        ])->assertCreated()->assertJsonStructure(['user', 'token']);

        $this->getJson('/api/v1/me')->assertUnauthorized();

        $token = $register->json('token');
        $this->as($token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('user.email', 'rahim@example.com')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.api_token');

        // admin role cannot be self-assigned
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Evil', 'email' => 'evil@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->postJson('/api/v1/auth/login', ['email' => 'rahim@example.com', 'password' => 'wrong'])
            ->assertStatus(422);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rahim@example.com', 'password' => 'secret123'])
            ->assertOk();
        $newToken = $login->json('token');

        // a new login replaces the old token
        $this->as($token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->as($newToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->as($newToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])
            ->assertStatus(429);
    }

    public function test_only_farmers_can_use_farmer_endpoints(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->getJson('/api/v1/farmer/products')->assertUnauthorized();
        $this->as($this->tokenFor($buyer))->getJson('/api/v1/farmer/products')->assertForbidden();
    }

    public function test_verified_farmer_can_create_update_and_delete_own_product(): void
    {
        $farmer = $this->farmer();
        $category = Category::create(['name' => 'Vegetables', 'slug' => 'vegetables']);
        $token = $this->tokenFor($farmer);

        $created = $this->as($token)->postJson('/api/v1/farmer/products', [
            'name' => 'Brinjal', 'category_id' => $category->id,
            'price' => 45, 'unit' => 'kg', 'stock' => 20,
        ])->assertCreated()->assertJsonPath('data.name', 'Brinjal');

        $id = $created->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'farmer_id' => $farmer->id, 'category' => 'Vegetables']);

        $this->as($token)->patchJson("/api/v1/farmer/products/{$id}", ['price' => 50])
            ->assertOk()->assertJsonPath('data.price', 50);

        $this->as($token)->postJson('/api/v1/farmer/products', ['name' => ''])
            ->assertStatus(422);

        $this->as($token)->deleteJson("/api/v1/farmer/products/{$id}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_unverified_farmer_cannot_create_product(): void
    {
        $farmer = $this->farmer(['verification_status' => 'unverified', 'farmer_verified_at' => null]);
        $category = Category::create(['name' => 'Vegetables', 'slug' => 'vegetables']);

        $this->as($this->tokenFor($farmer))->postJson('/api/v1/farmer/products', [
            'name' => 'Brinjal', 'category_id' => $category->id,
            'price' => 45, 'unit' => 'kg', 'stock' => 20,
        ])->assertForbidden();
    }

    public function test_farmer_cannot_change_another_farmers_product(): void
    {
        $owner = $this->farmer();
        $other = $this->farmer();
        $product = $this->product($owner);

        $token = $this->tokenFor($other);
        $this->as($token)->patchJson("/api/v1/farmer/products/{$product->id}", ['price' => 1])
            ->assertForbidden();
        $this->as($token)->deleteJson("/api/v1/farmer/products/{$product->id}")->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => 30]);
    }

    public function test_orders_are_visible_only_to_their_owners(): void
    {
        $farmer = $this->farmer();
        $buyerA = User::factory()->create(['role' => 'buyer']);
        $buyerB = User::factory()->create(['role' => 'buyer']);
        $product = $this->product($farmer);

        $order = Order::create([
            'buyer_id' => $buyerA->id, 'total_amount' => 60, 'status' => 'pending',
            'delivery_address' => 'Dhaka', 'phone' => '01700000000',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'farmer_id' => $farmer->id,
            'quantity' => 2, 'unit_price' => 30, 'subtotal' => 60,
        ]);

        $this->as($this->tokenFor($buyerA))->getJson('/api/v1/orders')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->tokenFor($buyerA))->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.items.0.product_name', 'Potato');

        $this->as($this->tokenFor($buyerB))->getJson('/api/v1/orders')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->tokenFor($buyerB))->getJson("/api/v1/orders/{$order->id}")->assertForbidden();

        $this->as($this->tokenFor($farmer))->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->as($this->tokenFor($this->farmer()))->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }
}
