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

    /** POST /api/public/chat */
    public function publicChat(Request $request)
    {
        $request->validate([
            'message'       => 'required|string',
            'chat'          => 'nullable|array',
            'business_id'   => 'required|string',
            'context_state' => 'nullable|array',
            'campaign_link' => 'nullable|string',
        ]);

        $message = $request->input('message');
        $chat = $request->input('chat', []);
        $businessId = $request->input('business_id');
        $contextState = $request->input('context_state', null);
        $campaignLink = $request->input('campaign_link', null);

        $context = [
            'business_id' => $businessId,
            'context_state' => $contextState,
            'campaign_link' => $campaignLink
        ];

        $result = $this->chatEngine->handle($message, $chat, $context);

        return response()->json($result, $result['code'] ?? 200);
    }
    public function publicEyeOnResponses(Request $request)
    {
        $request->validate([
            'userMessage' => 'required|string',
            'aiReply' => 'required|string',
            'chatContext' => 'nullable|array',
        ]);

        $userMessage = $request->input('userMessage');
        $aiReply = $request->input('aiReply');
        $chatContext = $request->input('chatContext', []);

        $result = [
            'data' => [
                'reply' => $aiReply
            ]
        ];

        // Call the decoupled rough engine
        $finalResult = $this->chatEngine->eyeOnResponses($result, $userMessage, $chatContext);

        return response()->json([
            'status' => true,
            'data' => $finalResult['data']['eye_monitor'] ?? null
        ]);
    }

    public function extractLeadData(Request $request)
    {
        $request->validate([
            'infoHistory' => 'required|array',
            'chatContext'  => 'required|array',
            'businessContext' => 'required|array',
        ]);

        $infoHistory    = $request->input('infoHistory', []);
        $chatContext    = $request->input('chatContext', []);
        $businessContext = $request->input('businessContext', []);

        $updatedState = $this->chatEngine->extractLeadDataFromHistory(
            $infoHistory,
            $chatContext,
            $businessContext
        );

        return response()->json([
            'status' => true,
            'data'   => ['leadQualificationState' => $updatedState]
        ]);
    }
}
