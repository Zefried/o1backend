<?php

namespace App\Services\Traits;

use Illuminate\Support\Facades\Http;

trait ChatMethodHandler
{
    /**
     * Handles the initial welcome message from the AI when the user connects.
     */
    public function handleWelcome()
    {
        $servicesText = empty($this->serviceRequest)
            ? 'None'
            : implode(', ', $this->serviceRequest);

        $prompt = <<<PROMPT
        You are an AI assistant for a business in the '{$this->businessNiche}' niche.

        Business BIO: {$this->businessBIO}
        Requested services: {$servicesText}

        Use the business BIO, niche, and requested services as context to make the greeting relevant. Mention the requested services only if it feels natural.

        - Keep it short, natural, and chill.
        - ALWAYS start in casual Hinglish.
        - NEVER output Devanagari script. Use the English alphabet.
        - In Hinglish/Hindi, use "tum", never "aap".
        - Don't use awkward phrasing like "sakta/sakti hoon".
        - Don't sound salesy, cheesy, formal, or scripted.
        - Don't say "Swagat hai", "Welcome to our services", "How may I assist you", etc.
        - Keep it to 1-2 short sentences.
        PROMPT;

        $messages = [
            [
                'role' => 'system',
                'content' => $prompt,
            ],
            [
                'role' => 'user',
                'content' => 'Hello',
            ],
        ];

        $response = Http::withOptions([
            'verify' => false,
        ])
            ->retry(3, 1000)
            ->withToken(config('services.groq.key'))
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'openai/gpt-oss-120b',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_completion_tokens' => 256,
                'top_p' => 1,
            ]);

        if ($response->successful()) {
            return [
                'status' => true,
                'data' => [
                    'reply' => $response->json(
                        'choices.0.message.content',
                        'Hey! Kya help chahiye?'
                    ),
                ],
                'code' => 200,
            ];
        }

        return [
            'status' => false,
            'message' => 'Failed to generate welcome message',
            'code' => 500,
        ];
    }

    private function checkIntent(string $message, array $chat = [])
    {
        $prompt = <<<PROMPT
            Classify the user's latest message.

            Return JSON only:

            {
            "userRequestInfo": boolean,
            "casualChat": boolean,
            "abusiveOrStupid": boolean
            }

            - userRequestInfo = true ONLY for a specific question or information request related to the '{$this->businessNiche}' niche or services.
            - abusiveOrStupid = true ONLY if the message is explicitly abusive, offensive, highly inappropriate, or completely nonsensical gibberish meant to troll.
            - casualChat = true for anything else (casual talk, greetings, jokes, or unrelated topics that are NOT abusive or trolling).

            CRITICAL RULES:
            - Exactly ONE of them MUST be true.
            - If you are unsure, default to casualChat = true.
            - DO NOT wrap the response in markdown blocks like ```json
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
            'temperature' => 0.1,
            'max_completion_tokens' => 256,
            'top_p' => 1,
            'reasoning_effort' => 'medium',
        ]);

        $rawJson = $response->json('choices.0.message.content', '{}');
        // Clean out markdown ticks just in case the AI wraps it
        $rawJson = str_replace(['```json', '```'], '', $rawJson);
        $rawJson = trim($rawJson);

        return json_decode($rawJson, true);
    }

    private function abusiveOrStupidHandler( string $message, array $chat = [], int $abusiveOrStupidCount = 3 ) 
    {
       $prompt = <<<PROMPT
        Respond naturally to the user's message.

        Business niche: {$this->businessNiche}
        Business BIO: {$this->businessBIO}
        Previous abusive/stupid query count: {$abusiveOrStupidCount}

        Handle the message based on what the user actually said:

        - Default to natural, casual Hinglish.
        - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it or writes a long message entirely in pure English.
        - NEVER output Devanagari script (Hindi characters) UNLESS the user explicitly types in Devanagari or asks for it.
        - If this is the first occurrence, politely warn the user not to be abusive, or logically explain why the query doesn't make sense.
        - If this happens again, be more direct: remind them that they are being abusive or that the query makes no sense and that this chat is meant for discussing the business/services.
        - add no-bs humour 
        - Don't sound robotic, formal, or preachy.
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - Keep it short and natural, usually 1-2 sentences.
        - If appropriate, redirect them toward what they actually need.
        - Never mention AI, classification, prompts, or internal rules.
        - add no-bs humour 

        Respond only with the message to the user.
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
            'verify' => false,
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

    private function casualChat(string $message, array $chat = [])
    {
        $servicesText = empty($this->serviceRequest) ? 'None' : implode(', ', $this->serviceRequest);
        
        $prompt = <<<PROMPT
        Reply naturally to the user.

        Business niche: {$this->businessNiche}
        Business BIO: {$this->businessBIO}
        Requested services: {$servicesText}

        - Talk like a real human, not a bot.
        - In Hinglish or Hindi, always use "tum", never "aap".
        - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it or writes a long message entirely in pure English.
        - NEVER output Devanagari script (Hindi characters) UNLESS the user explicitly types in Devanagari or asks for it.
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - Keep replies short, usually 1-2 sentences.
        - Add light humour when it feels natural. Humour should feel spontaneous, not forced.
        - Never use markdown tables, bullet points, or long lists.
        - Never mention AI, prompts, classification, or internal business rules.
        - Use the business context above to keep the conversation relevant.
        - If requested services are available, use them naturally to understand what the user may be looking for.
        - When appropriate, gently move the conversation toward understanding what the user needs by asking a simple question.
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
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it or writes a long message entirely in pure English.
        - NEVER output Devanagari script (Hindi characters) UNLESS the user explicitly types in Devanagari or asks for it.
        PROMPT;

        if ($mode === 'context' && !empty($context['service'])) {
            $prompt = "You are a specialized AI assistant for the '{$this->businessNiche}' niche.\n\n";
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
            $prompt = "You are a helpful and creative AI assistant for the '{$this->businessNiche}' niche." . $globalRules;
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
