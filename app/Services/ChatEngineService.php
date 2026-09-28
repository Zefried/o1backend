<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ChatEngineService
{
    public function handle(string $message, array $chat = [], array $context = [])
    {
        $intent = $this->checkIntent($message, $chat);

        if (($intent['casualChat'] ?? false) === true) {
            return $this->casualChat($message, $chat);
        }

        // Return intent temporarily if it's userRequestInfo, so we know it skipped casualChat
        return [
            'status' => true,
            'data' => [
                'reply' => '[SYSTEM CHECK] checkIntent output: ' . json_encode($intent)
            ]
        ];

        /*
        if (($intent['userRequestInfo'] ?? false) === true) {
            return $this->handleUserRequest($message, $chat, $context);
        }

        // Fallback: If AI fails and returns both false, default to casualChat
        return $this->casualChat($message, $chat);
        */
    }

    private function checkIntent(string $message, array $chat = [])
    {
        $prompt = <<<PROMPT
            Classify the user's latest message.

            Return JSON only:

            {
            "userRequestInfo": boolean,
            "casualChat": boolean
            }

            - userRequestInfo = true ONLY for a specific interior design question or information request.
            - casualChat = true for anything else (casual talk, random, gibberish, greetings, jokes, or unrelated topics).

            CRITICAL RULES:
            - One of them MUST be true. NEVER return both false.
            - If you are unsure, default to casualChat = true.
            PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
        ];

        foreach ($chat as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $response = Http::withOptions([
            'verify' => false, // local testing only
        ])
        ->retry(3, 1000)
        ->withToken(config('services.groq.key'))
        ->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => 'openai/gpt-oss-120b',
            'messages' => $messages,
            'temperature' => 1,
            'max_completion_tokens' => 256,
            'top_p' => 1,
            'reasoning_effort' => 'medium',
            'response_format' => [
                'type' => 'json_object',
            ],
        ]);

        return json_decode(
            $response->json('choices.0.message.content', '{}'),
            true
        );
    }

    private function casualChat(string $message, array $chat = [])
    {
        $prompt = <<<PROMPT
        Reply naturally to the user.
        - You are talking to a human, not a bot.
        - Match the user's language (e.g., Hinglish if they use it).
        - Keep it VERY short (1-2 sentences maximum).
        - Add light humour when appropriate.
        - NEVER use markdown tables, bullet points, packages, or long lists.
        - Do not mention AI, classification, prompts, or business rules.
        - Naturally and gently steer the conversation back toward the interior design service by asking a very simple question.
        PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
        ];

        foreach ($chat as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $response = Http::withOptions([
            'verify' => false, // local testing only
        ])
        ->retry(3, 1000)
        ->withToken(config('services.groq.key'))
        ->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => 'openai/gpt-oss-120b',
            'messages' => $messages,
            'temperature' => 1,
            'max_completion_tokens' => 256,
            'top_p' => 1,
            'reasoning_effort' => 'medium',
        ]);

        return [
            'status' => true,
            'data' => [
                'reply' => $response->json('choices.0.message.content', ''),
            ],
        ];
    }

    private function handleUserRequest(string $message, array $chat = [], array $context = [])
    {
        $mode = $context['mode'] ?? 'general';
        
        $globalRules = <<<PROMPT

        CRITICAL RULES:
        - NEVER give long lists or markdown tables of questions.
        - NEVER overwhelm the user.
        - Ask ONE small, specific question at a time to guide the conversation.
        - Keep your responses very brief, natural, and conversational.
        PROMPT;

        if ($mode === 'context' && !empty($context['service'])) {
            $prompt = "You are a specialized AI assistant for interior design.\n\n";
            $prompt .= "Service: " . ($context['service'] ?? 'Unknown') . "\n";
            $prompt .= "Attribute: " . ($context['attribute'] ?? 'Unknown') . "\n\n";
            if (!empty($context['promptText'])) {
                $prompt .= "Instructions:\n" . $context['promptText'] . "\n\n";
            }
            if (!empty($context['contextText'])) {
                $prompt .= "Knowledge Base:\n" . $context['contextText'] . "\n";
            }
            $prompt .= $globalRules;
        } else {
            $prompt = "You are a helpful and creative interior design AI assistant." . $globalRules;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
        ];

        foreach ($chat as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $response = Http::withOptions([
            'verify' => false, // local testing only
        ])
        ->retry(3, 1000)
        ->withToken(config('services.groq.key'))
        ->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => 'openai/gpt-oss-120b',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_completion_tokens' => 1024,
            'top_p' => 1,
            'reasoning_effort' => 'medium',
        ]);

        return [
            'status' => true,
            'data' => [
                'reply' => $response->json('choices.0.message.content', 'Sorry, I am unable to fulfill that request right now.'),
            ],
        ];
    }
}