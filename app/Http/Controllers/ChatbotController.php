<?php

namespace App\Http\Controllers;

use App\Services\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    private const HISTORY_KEY = 'chatbot.history';

    /** Keep the last 4 question/answer pairs (8 items). */
    private const HISTORY_LIMIT = 8;

    public function message(Request $request, AiChatService $chat): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'message.required' => 'অনুগ্রহ করে আপনার প্রশ্নটি লিখুন।',
            'message.max' => 'বার্তাটি ১০০০ অক্ষরের মধ্যে রাখুন।',
        ]);

        $message = trim($data['message']);
        $history = $request->session()->get(self::HISTORY_KEY, []);
        $role = $request->user()?->role ?? 'guest';

        $result = $chat->reply($message, $history, $role);

        $history[] = ['role' => 'user', 'content' => mb_substr($message, 0, 1000)];
        $history[] = ['role' => 'assistant', 'content' => mb_substr($result['reply'], 0, 1000)];
        $request->session()->put(self::HISTORY_KEY, array_slice($history, -self::HISTORY_LIMIT));

        return response()->json([
            'reply' => $result['reply'],
            'source' => $result['source'],
        ]);
    }
}
