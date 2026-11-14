<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $name = 'আলু', float $price = 28): Product
    {
        $farmer = User::factory()->create(['role' => 'farmer']);

        return Product::create([
            'farmer_id' => $farmer->id,
            'name' => $name,
            'price' => $price,
            'unit' => 'কেজি',
            'stock' => 100,
            'district' => 'বগুড়া',
        ]);
    }

    public function test_fallback_answers_without_api_key(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => null, 'app.debug' => false]);
        Http::fake();

        $this->postJson(route('chatbot.message'), ['message' => 'কিভাবে রেজিস্ট্রেশন করব?'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonFragment(['reply' => 'নতুন অ্যাকাউন্ট খুলতে /register পেজে যান। কৃষক বা ক্রেতা — আপনার ভূমিকা বেছে নিয়ে তথ্য দিন। তারপর /login দিয়ে প্রবেশ করুন।']);

        Http::assertNothingSent();
    }

    public function test_gemini_reply_is_returned_and_prompt_contains_matching_product(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => 'test-key']);
        $this->makeProduct('আলু', 28);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '**আলুর** দাম ২৮ টাকা।']]]]],
            ]),
        ]);

        $this->postJson(route('chatbot.message'), ['message' => 'আলুর দাম কত?'])
            ->assertOk()
            ->assertJsonPath('source', 'gemini')
            ->assertJsonPath('reply', 'আলুর দাম ২৮ টাকা।');

        Http::assertSent(function ($request) {
            $system = $request['systemInstruction']['parts'][0]['text'] ?? '';

            return $request->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($system, 'আলু | ৳28/কেজি')
                && ! str_contains($request->url(), 'test-key');
        });
    }

    public function test_groq_provider_uses_openai_format(): void
    {
        config(['services.chatbot.provider' => 'groq', 'services.chatbot.groq.key' => 'g-key']);

        Http::fake([
            'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'হ্যালো!']]]]),
        ]);

        $this->postJson(route('chatbot.message'), ['message' => 'হ্যালো'])
            ->assertOk()
            ->assertJsonPath('source', 'groq')
            ->assertJsonPath('reply', 'হ্যালো!');

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer g-key') && $r['messages'][0]['role'] === 'system');
    }

    public function test_provider_failure_falls_back_instead_of_breaking(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);

        $this->postJson(route('chatbot.message'), ['message' => 'অর্ডার ট্র্যাক করব কীভাবে'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback');
    }

    public function test_history_is_sent_on_the_next_message(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'উত্তর']]]]],
            ]),
        ]);

        $this->postJson(route('chatbot.message'), ['message' => 'প্রথম প্রশ্ন'])->assertOk();
        $this->postJson(route('chatbot.message'), ['message' => 'দ্বিতীয় প্রশ্ন'])->assertOk();

        Http::assertSent(fn ($r) => count($r['contents']) === 3 && $r['contents'][0]['role'] === 'user' && $r['contents'][1]['role'] === 'model');
    }

    public function test_message_is_validated(): void
    {
        $this->postJson(route('chatbot.message'), ['message' => ''])->assertStatus(422);
        $this->postJson(route('chatbot.message'), ['message' => str_repeat('ক', 1001)])->assertStatus(422);
    }

    public function test_logged_in_user_role_is_passed_but_private_data_is_not(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ঠিক আছে']]]]],
            ]),
        ]);

        $buyer = User::factory()->create(['role' => 'buyer', 'email' => 'secret-buyer@example.com']);

        $this->actingAs($buyer)->postJson(route('chatbot.message'), ['message' => 'হ্যালো'])->assertOk();

        Http::assertSent(function ($r) {
            $system = $r['systemInstruction']['parts'][0]['text'];

            return str_contains($system, 'ক্রেতা (লগইন করা)') && ! str_contains($system, 'secret-buyer@example.com');
        });
    }

    public function test_chat_widget_endpoint_is_rendered_on_the_home_page(): void
    {
        $this->withoutVite();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-kh-chat-endpoint="' . route('chatbot.message') . '"', false);
    }

    public function test_debug_mode_explains_why_fallback_was_used(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => null, 'app.debug' => true]);

        $this->postJson(route('chatbot.message'), ['message' => 'হ্যালো'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertSee('ডেভেলপার নোট', false);
    }

    public function test_chatbot_test_command_reports_missing_key_and_success(): void
    {
        config(['services.chatbot.provider' => 'gemini', 'services.chatbot.gemini.key' => null]);
        $this->artisan('chatbot:test')->expectsOutputToContain('API key পাওয়া যায়নি')->assertFailed();

        config(['services.chatbot.gemini.key' => 'k']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'হ্যালো!']]]]],
        ])]);
        $this->artisan('chatbot:test')->expectsOutputToContain('AI কাজ করছে')->assertSuccessful();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);
        $this->artisan('chatbot:test')->expectsOutputToContain('HTTP 400')->assertFailed();
    }
}
