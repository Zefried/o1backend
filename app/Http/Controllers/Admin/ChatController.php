<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ChatEngineService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private ChatEngineService $chatEngine;

    public function __construct(ChatEngineService $chatEngine)
    {
        $this->chatEngine = $chatEngine;
    }

    /** POST /api/admin/chat */
    public function handleChat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'chat'    => 'nullable|array',
            'context' => 'nullable|array',
        ]);

        $message = $request->input('message');
        $chat = $request->input('chat', []);
        $context = $request->input('context', []);

        $result = $this->chatEngine->handle($message, $chat, $context);

        return response()->json($result, $result['code'] ?? 200);
    }
}
