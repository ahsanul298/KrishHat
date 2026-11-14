<?php

namespace App\Console\Commands;

use App\Services\AiChatService;
use Illuminate\Console\Command;

class ChatbotTestCommand extends Command
{
    protected $signature = 'chatbot:test';

    protected $description = 'KrishiHat AI চ্যাটবটের key ও সংযোগ পরীক্ষা করে';

    public function handle(AiChatService $chat): int
    {
        $provider = (string) config('services.chatbot.provider');
        $this->info("প্রোভাইডার: {$provider}");

        $result = $chat->diagnose();

        if ($result['ok']) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
