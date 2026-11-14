<?php

namespace App\Services;

use App\Models\MarketBenchmarkPrice;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;

/**
 * KrishiHat chatbot knowledge: site facts, live product/price lookups for the
 * AI prompt, and a rule-based fallback answer (used when no AI key is set or
 * the AI provider is unavailable).
 *
 * Only PUBLIC marketplace data is ever read here (product listings and market
 * prices). No user, order, payment or conversation data goes to the AI.
 */
class ChatbotKnowledge
{
    private const STOP_WORDS = [
        'কত', 'কি', 'কী', 'দাম', 'দর', 'আছে', 'কোথায়', 'আমি', 'আমার', 'চাই', 'কিনতে',
        'বিক্রি', 'করব', 'করতে', 'হবে', 'এর', 'এই', 'জন্য', 'কিভাবে', 'কীভাবে', 'এবং',
        'না', 'কেন', 'কোন', 'কোনো', 'বাজার', 'বাজারদর', 'মূল্য', 'দেখাও', 'বলো', 'বলুন',
        'the', 'what', 'how', 'is', 'are', 'price', 'prices', 'of', 'in', 'to', 'for', 'and',
        'a', 'an', 'i', 'my', 'can', 'do', 'me', 'show', 'tell', 'cost', 'rate', 'today',
    ];

    private const BN_SUFFIXES = ['গুলো', 'টির', 'টি', 'টা', 'ের', 'কে', 'তে', 'র', 'য়'];

    /** Static facts about the platform (taken from the real routes of this project). */
    public function siteFacts(): string
    {
        return <<<'TXT'
- KrishiHat (কৃষি হাট) হলো কৃষক ও ক্রেতার সরাসরি কেনাবেচার মার্কেটপ্লেস।
- নিবন্ধন: /register, লগইন: /login। অ্যাকাউন্টের ধরন: কৃষক (farmer) বা ক্রেতা (buyer)।
- কৃষক: লগইন করে "ড্যাশবোর্ড" থেকে নতুন পণ্য যোগ করা যায় (/farmer/products/create)। ছবি, দাম, একক, মজুত, ন্যূনতম অর্ডার ও এলাকা দিতে হয়।
- কৃষক যাচাই: প্রোফাইল পেজ (/profile) থেকে NID ও ট্রেড লাইসেন্স জমা দিলে অ্যাডমিন যাচাই করেন।
- ক্রেতা: হোম পেজে ফসল সার্চ/ক্যাটাগরি/জেলা/সর্বোচ্চ দাম দিয়ে ফিল্টার করা যায়। পণ্যের পেজে "কার্টে যোগ", দরদাম অফার পাঠানো এবং কৃষকের সাথে চ্যাট শুরু করা যায়।
- কার্ট: /cart, চেকআউট: /checkout (লগইন লাগে)। অর্ডার ও ট্র্যাকিং: /my-orders।
- সরাসরি চ্যাট ও অফার: /conversations।
- অনলাইন পেমেন্ট SSLCommerz-এর মাধ্যমে; প্রজেক্ট টেস্ট/স্যান্ডবক্স মোডে থাকলে আসল টাকা কাটে না।
- সাবস্ক্রিপশন প্ল্যান: /plans।
- বাজার দর পূর্বাভাস পেজ: /price-forecast (সরকারি কৃষি বিপণন অধিদপ্তর DAM কাঠামোর রেফারেন্স তথ্য; ডেমো তথ্য হলে পেজে লেখা থাকে)।
TXT;
    }

    /** Builds the data block that is added to the AI prompt for this question. */
    public function contextFor(string $message): string
    {
        $keywords = $this->keywords($message);

        $parts = ["[প্ল্যাটফর্ম তথ্য]\n" . $this->siteFacts()];

        $products = $this->matchingProducts($keywords);
        $parts[] = "[ডাটাবেজ থেকে মিলে যাওয়া পণ্য]\n" . ($products ?: 'কোনো মিল পাওয়া যায়নি।');

        $prices = $this->matchingPrices($keywords);
        $parts[] = "[বাজার দরের তথ্য]\n" . ($prices ?: 'কোনো দরের তথ্য পাওয়া যায়নি।');

        return implode("\n\n", $parts);
    }

    /** Rule-based answer when no AI is available. */
    public function fallbackReply(string $message): string
    {
        $text = mb_strtolower($message);

        $faq = [
            [['রেজিস্ট', 'নিবন্ধন', 'একাউন্ট', 'অ্যাকাউন্ট', 'sign up', 'register', 'signup'],
                "নতুন অ্যাকাউন্ট খুলতে /register পেজে যান। কৃষক বা ক্রেতা — আপনার ভূমিকা বেছে নিয়ে তথ্য দিন। তারপর /login দিয়ে প্রবেশ করুন।"],
            [['পণ্য যোগ', 'বিক্রি করব', 'বিক্রি করতে', 'লিস্ট', 'পণ্য তুল', 'add product', 'sell', 'listing'],
                "পণ্য বিক্রি করতে কৃষক অ্যাকাউন্টে লগইন করে ড্যাশবোর্ড থেকে \"নতুন পণ্য\" (/farmer/products/create) চাপুন। নাম, ছবি, দাম, একক, মজুত ও এলাকা দিয়ে সেভ করুন।"],
            [['যাচাই', 'ভেরিফ', 'nid', 'এনআইডি', 'ট্রেড লাইসেন্স', 'verify', 'verification'],
                "কৃষক যাচাইয়ের জন্য প্রোফাইল (/profile) পেজে NID ও ট্রেড লাইসেন্সের তথ্য জমা দিন। অ্যাডমিন যাচাই করে অনুমোদন বা বাতিল জানাবেন।"],
            [['অর্ডার', 'ট্র্যাক', 'order', 'track'],
                "আপনার অর্ডার ও ট্র্যাকিং দেখতে লগইন করে /my-orders পেজে যান।"],
            [['কার্ট', 'চেকআউট', 'কিনব', 'কেনা', 'কিভাবে কিন', 'কীভাবে কিন', 'cart', 'checkout', 'buy'],
                "পণ্যের পেজ থেকে \"কার্টে যোগ করুন\" চাপুন, তারপর /cart থেকে /checkout এ গিয়ে ঠিকানা ও পেমেন্ট সম্পন্ন করুন। চেকআউটের জন্য লগইন লাগবে।"],
            [['দরদাম', 'অফার', 'offer', 'negotiat', 'চ্যাট', 'কথা বল', 'chat'],
                "পণ্যের পেজ থেকে কৃষককে অফার পাঠাতে বা চ্যাট শুরু করতে পারবেন। সব আলাপ /conversations পেজে দেখা যায়।"],
            [['পেমেন্ট', 'প্রেমেন্ট', 'পেমেন্', 'টাকা', 'বিকাশ', 'payment', 'sslcommerz', 'bkash'],
                "পেমেন্ট SSLCommerz এর মাধ্যমে হয়। প্রজেক্ট স্যান্ডবক্স/টেস্ট মোডে থাকলে আসল টাকা কাটা হয় না।"],
            [['প্ল্যান', 'সাবস্ক্রিপশন', 'plan', 'subscription', 'membership'],
                "সাবস্ক্রিপশন প্ল্যানগুলো /plans পেজে দেখা যায়।"],
            [['পূর্বাভাস', 'forecast', 'prediction'],
                "বাজার দরের পূর্বাভাস দেখতে /price-forecast পেজে যান। মনে রাখবেন, পেজে ডেমো তথ্য থাকলে সেটি স্পষ্ট লেখা থাকে।"],
        ];

        foreach ($faq as [$needles, $answer]) {
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower($needle))) {
                    return $answer;
                }
            }
        }

        $keywords = $this->keywords($message);
        $products = $this->matchingProducts($keywords, 4);
        $prices = $this->matchingPrices($keywords, 4);

        if ($products || $prices) {
            $reply = "আপনার প্রশ্ন অনুযায়ী যা পেয়েছি:\n";
            if ($prices) {
                $reply .= "\nবাজার দর:\n" . $prices . "\n";
            }
            if ($products) {
                $reply .= "\nমার্কেটে থাকা পণ্য:\n" . $products;
            }

            return trim($reply);
        }

        return "দুঃখিত, এই প্রশ্নের উত্তর এখন দিতে পারছি না। ফসলের নাম (যেমন: আলু, পেঁয়াজ) লিখে দাম জিজ্ঞাসা করুন, অথবা অ্যাকাউন্ট, পণ্য যোগ, অর্ডার, পেমেন্ট নিয়ে জানতে চান।";
    }

    /** @return string[] search words (with simple Bangla suffix variants) */
    public function keywords(string $message): array
    {
        $tokens = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2 || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $words[] = $token;

            foreach (self::BN_SUFFIXES as $suffix) {
                if (str_ends_with($token, $suffix) && mb_strlen($token) - mb_strlen($suffix) >= 2) {
                    $words[] = mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
                    break;
                }
            }
        }

        return array_slice(array_values(array_unique($words)), 0, 8);
    }

    private function matchingProducts(array $keywords, int $limit = 6): string
    {
        if ($keywords === [] || ! Schema::hasTable('products')) {
            return '';
        }

        $products = Product::query()
            ->where('stock', '>', 0)
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $word) {
                    $like = '%' . addcslashes($word, '%_\\') . '%';
                    $query->orWhere('name', 'like', $like);
                }
            })
            ->latest()
            ->limit($limit)
            ->get(['id', 'name', 'price', 'unit', 'stock', 'district']);

        return $products->map(fn (Product $p) => sprintf(
            '%s | ৳%s/%s | মজুত %s | %s | %s',
            $p->name,
            rtrim(rtrim(number_format((float) $p->price, 2, '.', ''), '0'), '.'),
            $p->unit,
            $p->stock,
            $p->district ?: 'এলাকা উল্লেখ নেই',
            route('products.show', $p->id)
        ))->implode("\n");
    }

    private function matchingPrices(array $keywords, int $limit = 8): string
    {
        $lines = [];

        if (Schema::hasTable('market_benchmark_prices') && MarketBenchmarkPrice::query()->exists()) {
            $query = MarketBenchmarkPrice::query()->orderByDesc('observation_date');

            if ($keywords !== []) {
                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $word) {
                        $like = '%' . addcslashes($word, '%_\\') . '%';
                        $q->orWhere('crop_name_bn', 'like', $like)
                            ->orWhere('crop_name_en', 'like', $like)
                            ->orWhere('district', 'like', $like)
                            ->orWhere('market_name', 'like', $like);
                    }
                });
            }

            foreach ($query->limit($limit)->get() as $row) {
                $lines[] = sprintf(
                    '%s | %s ৳%s/%s | %s, %s | তারিখ %s | %s',
                    $row->crop_name_bn ?: $row->crop_name_en,
                    $row->price_type,
                    rtrim(rtrim(number_format((float) $row->price, 2, '.', ''), '0'), '.'),
                    $row->unit,
                    $row->market_name,
                    $row->district,
                    optional($row->observation_date)->format('Y-m-d'),
                    $row->is_verified ? 'যাচাইকৃত' : 'অযাচাইকৃত/নমুনা তথ্য'
                );
            }
        }

        if ($lines === []) {
            foreach (BangladeshDamMarketData::getVerifiedReferenceRecords() as $record) {
                $haystack = mb_strtolower(($record['crop_name_bn'] ?? '') . ' ' . ($record['crop_name_en'] ?? '') . ' ' . ($record['district'] ?? ''));
                $matched = $keywords === [];
                foreach ($keywords as $word) {
                    if (str_contains($haystack, $word)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    continue;
                }

                $lines[] = sprintf(
                    '%s | পাইকারি ৳%s, খুচরা ৳%s / %s | %s | তারিখ %s | %s',
                    $record['crop_name_bn'],
                    $record['price_wholesale'],
                    $record['price_retail'],
                    $record['unit'],
                    $record['market_name'],
                    $record['observation_date'],
                    ! empty($record['is_verified']) ? 'যাচাইকৃত' : 'অযাচাইকৃত/ডেমো তথ্য'
                );

                if (count($lines) >= $limit) {
                    break;
                }
            }
        }

        return implode("\n", $lines);
    }
}
