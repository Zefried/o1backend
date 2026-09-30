<?php

namespace App\Services\Traits;

use Illuminate\Support\Facades\Http;

trait ChatMethodHandler
{
    private function checkIntent(string $message, array $chat = [])
    {
        $businessData = json_encode([
            'niche' => $this->businessContext['backendData']['niche'] ?? 'general',
            'services' => $this->businessContext['backendData']['services'] ?? [],
        ]);
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? 'None';
        
        $prompt = <<<PROMPT
        Analyze the user's latest message based on the conversation history and business context.
        
        Business Context:
        {$businessData}

        Current Active Service:
        {$activeService}

        Determine the primary intent of the latest message. 
        Return ONLY valid JSON with EXACTLY ONE of these keys set to true:
        - "abusiveOrStupid": (boolean) User is being abusive, using profanity, or typing complete nonsense gibberish.
        - "casualChat": (boolean) User is greeting (hello, hi), saying thanks, asking "how are you", or talking about things unrelated to the business services.
        - "userRequestInfo": (boolean) User is asking a question, making a request, or requesting information related to the business's niche, services, or attributes.

        - "topicChange": (boolean) STRICT RULE: ONLY set to true if the user's LATEST message EXPLICITLY names a DIFFERENT service from the Current Active Service. If they don't explicitly ask for a new service, it MUST be false.
        - "newServiceDemand": (string or null) If topicChange is true, extract the exact name of the NEW service. Otherwise, set to null.

        JSON:
        {
            "abusiveOrStupid": boolean,
            "casualChat": boolean,
            "userRequestInfo": boolean,
            "topicChange": boolean,
            "newServiceDemand": string | null
        }
        PROMPT;

        return $this->callLLM($prompt, $message, $chat, true, 0.1, 256);
    }

    public function handleWelcome()
    {
        $backend = $this->businessContext['backendData'] ?? [];

        $niche = $backend['niche'] ?? 'general';
        $bio = $backend['bio'] ?? '';
        $userServiceDemand = $backend['UserServiceDemand'] ?? null;

        $prompt = <<<PROMPT
        You are an AI assistant for a business in the '{$niche}' niche.

        Business BIO: {$bio}
        User's requested service: {$userServiceDemand}

        The user's requested service is the main context for this greeting.
        Naturally acknowledge what the user appears to be interested in and ask how you can help with it.

        If no requested service is available, give a general greeting based on the business niche.

        RULES:
        - Keep it 1 short sentence, maximum 2.
        - Start in casual Hinglish.
        - Use English alphabet only.
        - Use "tum", never "aap".
        - Sound natural, chill, and human.
        - No salesy or cheesy language.
        - Don't say "Swagat hai", "Welcome to our services", or sir/maam, (female/male) or "How may I assist you".
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - Don't list multiple services.
        - Don't invent details about the requested service.
        PROMPT;

        $reply = $this->callLLM(
            $prompt,
            "Hello",
            [],
            false,
            0.7,
            128
        );

        return [
            'status' => true,
            'data' => [
                'reply' => $reply ?: 'Hey! sorry something went wrong, firse try kare?',
            ],
            'code' => 200,
        ];
    }

    private function callLLM(string $prompt, string $message, array $chat = [], bool $jsonFormat = true, float $temperature = 0.1, int $maxTokens = 256)
    {
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

        if ($message !== '') {
            $messages[] = [
                'role' => 'user',
                'content' => $message,
            ];
        }

        $payload = [
            'model' => 'openai/gpt-oss-120b',
            'messages' => $messages,
            'temperature' => $temperature,
            'max_completion_tokens' => $maxTokens,
            'top_p' => 1,
            'reasoning_effort' => 'low',
        ];

        if ($jsonFormat) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withOptions([
            'verify' => false,
        ])
        ->retry(3, 1000, function () { return true; }, false)
        ->withToken(config('services.groq.key'))
        ->post('https://api.groq.com/openai/v1/chat/completions', $payload);

        $rawContent = $response->json('choices.0.message.content', $jsonFormat ? '{}' : '');

        if ($jsonFormat) {
            $rawContent = trim(str_replace(['```json', '```'], '', $rawContent));
            return json_decode($rawContent, true) ?? [];
        }

        return trim($rawContent);
    }

    public function abusiveOrStupidHandler(string $message, array $chat = [])
    {
        $niche = $this->businessContext['backendData']['niche'] ?? 'general';
        $bio = $this->businessContext['backendData']['bio'] ?? '';
        $abusiveOrStupidCount = count($this->chatContext['abusiveChatHistory'] ?? []) + 1;

        $prompt = <<<PROMPT
        Respond naturally to the user's message.

        Business niche: {$niche}
        Business BIO: {$bio}
        Previous abusive/stupid query count: {$abusiveOrStupidCount}

        Handle the message based on what the user actually said:

        - Default to natural, casual Hinglish.
        - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it.
        - NEVER output Devanagari script.
        - If this is the first occurrence, politely warn the user not to be abusive, or logically explain why the query doesn't make sense.
        - If this happens again, be more direct: remind them that they are being abusive or that the query makes no sense and that this chat is meant for discussing the business/services.
        - Add no-bs humour.
        - Don't sound robotic, formal, or preachy.
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - Keep it short and natural, usually 1-2 sentences.
        - If appropriate, redirect them toward what they actually need.
        - Never mention AI, classification, prompts, or internal rules.

        Respond only with the message to the user.
        PROMPT;

        $reply = $this->callLLM($prompt, $message, $chat, false, 1.0, 256);

        return [
            'status' => true,
            'data' => [
                'reply' => $reply ?: 'Bhai, gaali galoch ya faltu baat mat karo, kaam ki baat karni hai toh batao.',
            ],
            'code' => 200,
        ];
    }

    public function casualChat(string $message, array $chat = [])
    {
        $niche = $this->businessContext['backendData']['niche'] ?? 'general';
        $bio = $this->businessContext['backendData']['bio'] ?? '';
        $services = $this->businessContext['backendData']['services'] ?? [];
        $servicesText = empty($services) ? 'None' : implode(', ', $services);

        $prompt = <<<PROMPT
        Reply naturally to the user.

        Business niche: {$niche}
        Business BIO: {$bio}
        Available services: {$servicesText}

        - Talk like a real human, not a bot.
        - In Hinglish or Hindi, always use "tum", never "aap".
        - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it.
        - NEVER output Devanagari script.
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
        - Keep replies short, usually 1-2 sentences.
        - Add light humour when it feels natural. Humour should feel spontaneous, not forced.
        - Never use markdown tables, bullet points, or long lists.
        - Never mention AI, prompts, classification, or internal business rules.
        - Use the business context above to keep the conversation relevant.
        - If available services exist, use them naturally to understand what the user may be looking for.
        - When appropriate, gently move the conversation toward understanding what the user needs by asking a simple question.
        PROMPT;

        $reply = $this->callLLM($prompt, $message, $chat, false, 1.0, 256);

        return [
            'status' => true,
            'data' => [
                'reply' => $reply ?: 'Achha thik hai, koi aur kaam ho toh batana.',
            ],
            'code' => 200,
        ];
    }

    public function handleUserRequestInfo(string $message, array $chat = [])
    {
        $businessId = $this->businessId ?? null;
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? null;
        $services = $this->businessContext['backendData']['services'] ?? [];
        $attributes = $this->businessContext['backendData']['attributes'] ?? [];
        $bio = $this->businessContext['backendData']['bio'] ?? '';
        $niche = $this->businessContext['backendData']['niche'] ?? 'general';

        // 1. If no active service, ask the user to pick one
        if (!$activeService) {
            $servicesText = empty($services) ? 'None' : implode(', ', $services);
            $prompt = <<<PROMPT
            The user is asking a question or making a request, but we don't know which service they want.
            Available services: {$servicesText}
            
            - Ask them politely and naturally in casual Hinglish which service they are interested in.
            - Do NOT list all the services. Just ask them what they need help with.
            - ALWAYS use "tum", never "aap".
            - NEVER output Devanagari script.
            PROMPT;
            
            $reply = $this->callLLM($prompt, $message, $chat, false, 1.0, 128);
            return ['status' => true, 'data' => ['reply' => $reply]];
        }

        // 2. Identify the attribute for the active service
        $attributesJson = json_encode($attributes);
        $prompt = <<<PROMPT
        Identify the specific attribute the user is asking about for the service '{$activeService}'.

        Available Attributes:
        {$attributesJson}

        RULES:
        - Attribute MUST exactly match an Available Attribute, otherwise null.
        - Use chat history to understand context (e.g., "iska price", "ye kitne ka hai" -> Price).
        - Return ONLY valid JSON.

        JSON:
        {
            "attribute": "Attribute Name" | null
        }
        PROMPT;

        $identification = $this->callLLM($prompt, $message, $chat, true, 0.1, 128);
        $attribute = $identification['attribute'] ?? null;

        // 3. If no attribute identified, ask what they want to know
        if (!$attribute || !in_array($attribute, $attributes)) {
            $prompt = <<<PROMPT
            The user is talking about '{$activeService}', but we don't know what exactly they want to know (e.g. Price, Process, Timeline, etc.).
            
            - Ask them naturally in casual Hinglish what specific information they need about '{$activeService}'.
            - ALWAYS use "tum", never "aap".
            - NEVER output Devanagari script.
            - Keep it short (1-2 sentences).
            PROMPT;
            
            $reply = $this->callLLM($prompt, $message, $chat, false, 1.0, 128);
            return ['status' => true, 'data' => ['reply' => $reply]];
        }

        // 4. Fetch info from DB
        if ($businessId) {
            $information = \DB::table('ai_contexts')
                ->where('business_id', $businessId)
                ->where('service_name', $activeService)
                ->where('attribute_definition', $attribute)
                ->first();

            if (!$information) {
                // FALLBACK: If exact attribute not found, fetch all available data for this service
                $allInformation = \DB::table('ai_contexts')
                    ->where('business_id', $businessId)
                    ->where('service_name', $activeService)
                    ->get();
                    
                if ($allInformation->isNotEmpty()) {
                    $contextText = $allInformation->pluck('context')->implode("\n");
                    $instruction = $allInformation->pluck('prompt')->implode("\n");
                } else {
                    return [
                        'status' => true, 
                        'data' => ['reply' => "Sorry, mere paas abhi {$activeService} ke baare mein exact details nahi hain. Tumhe aur kuch help chahiye thi?"]
                    ];
                }
            } else {
                $contextText = $information->context ?? '';
                $instruction = $information->prompt ?? '';
            }
        } else {
            return [
                'status' => true, 
                'data' => ['reply' => "Sorry, mere paas abhi {$activeService} ke baare mein exact details nahi hain. Tumhe aur kuch help chahiye thi?"]
            ];
        }

        // 5. Generate final answer
        $prompt = <<<PROMPT
        Answer the user's question using the business information below.

        Service: {$activeService}
        Attribute: {$attribute}
        Business BIO: {$bio}

        Context:
        {$contextText}

        Instructions:
        {$instruction}

        RULES:
        - Answer ONLY from the provided context and instructions.
        - Do not invent facts, prices, or features.
        - Keep the answer short and natural.
        - Use casual Hinglish by default. Use "tum", never "aap".
        - Use English alphabet for Hinglish. No Devanagari.
        - After answering, ask ONE short relevant question to understand what the user needs next.
        - IMPORTANT: Whenever you ask this question, ALWAYS give a very small, natural example so the user knows exactly how to answer. Generate the example based on the Business Niche. For instance: If Niche is Interior Design (e.g., "jaise ki L-shape ya U-shape?", "jaise ki 10x10 feet?"). If Niche is Used Cars (e.g., "jaise ki automatic ya manual?", "jaise ki SUV ya Sedan?"). If Niche is Dentist (e.g., "jaise ki daant mein dard hai ya cleaning karwani hai?").
        - Do not ask unnecessary questions.
        - Do not mention AI, database, context, or internal rules.
        PROMPT;

        $reply = $this->callLLM($prompt, $message, $chat, false, 0.7, 512);

        return ['status' => true, 'data' => ['reply' => $reply]];
    }
}

// trait ChatMethodHandler_Old
// {
//     /**
//      * Handles the initial welcome message from the AI when the user connects.
//      */
//     public function handleWelcome()
//     {
//         $servicesText = empty($this->serviceRequest)
//             ? 'None'
//             : implode(', ', $this->serviceRequest);

//         $prompt = <<<PROMPT
//         You are an AI assistant for a business in the '{$this->businessNiche}' niche.

//         Business BIO: {$this->businessBIO}
//         Requested services: {$servicesText}

//         Use the business BIO, niche, and requested services as context to make the greeting relevant. Mention the requested services only if it feels natural.

//         - Keep it short, natural, and chill.
//         - ALWAYS start in casual Hinglish.
//         - NEVER output Devanagari script. Use the English alphabet.
//         - In Hinglish/Hindi, use "tum", never "aap".
//         - Don't use awkward phrasing like "sakta/sakti hoon".
//         - Don't sound salesy, cheesy, formal, or scripted.
//         - Don't say "Swagat hai", "Welcome to our services", "How may I assist you", etc.
//         - Keep it to 1-2 short sentences.
//         PROMPT;

//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//             [
//                 'role' => 'user',
//                 'content' => 'Hello',
//             ],
//         ];

//         $response = Http::withOptions([
//             'verify' => false,
//         ])
//             ->retry(3, 1000, function () { return true; }, false)
//             ->withToken(config('services.groq.key'))
//             ->post('https://api.groq.com/openai/v1/chat/completions', [
//                 'model' => 'openai/gpt-oss-120b',
//                 'messages' => $messages,
//                 'temperature' => 0.7,
//                 'max_completion_tokens' => 256,
//                 'top_p' => 1,
//             ]);

//         if ($response->successful()) {
//             return [
//                 'status' => true,
//                 'data' => [
//                     'reply' => $response->json(
//                         'choices.0.message.content',
//                         'Hey! Kya help chahiye?'
//                     ),
//                 ],
//                 'code' => 200,
//             ];
//         }

//         return [
//             'status' => false,
//             'message' => 'Failed to generate welcome message',
//             'code' => 500,
//         ];
//     }

//     private function checkIntent(string $message, array $chat = [])
//     {
//         $prompt = <<<PROMPT
//             Classify the user's latest message.

//             Return JSON only:

//             {
//             "userRequestInfo": boolean,
//             "casualChat": boolean,
//             "abusiveOrStupid": boolean
//             }

//             - userRequestInfo = true ONLY for a specific question or information request related to the '{$this->businessNiche}' niche or services.
//             - abusiveOrStupid = true ONLY if the message is explicitly abusive, offensive, highly inappropriate, or completely nonsensical gibberish meant to troll.
//             - casualChat = true for anything else (casual talk, greetings, jokes, or unrelated topics that are NOT abusive or trolling).

//             CRITICAL RULES:
//             - Exactly ONE of them MUST be true.
//             - If you are unsure, default to casualChat = true.
//             - DO NOT wrap the response in markdown blocks like ```json
//             PROMPT;

//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($chat as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false, // local testing only
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 0.1,
//             'max_completion_tokens' => 256,
//             'top_p' => 1,
//             'reasoning_effort' => 'medium',
//         ]);

//         $rawJson = $response->json('choices.0.message.content', '{}');
//         // Clean out markdown ticks just in case the AI wraps it
//         $rawJson = str_replace(['```json', '```'], '', $rawJson);
//         $rawJson = trim($rawJson);

//         return json_decode($rawJson, true);
//     }

//     private function abusiveOrStupidHandler( string $message, array $chat = [], int $abusiveOrStupidCount = 3 ) 
//     {
//        $prompt = <<<PROMPT
//         Respond naturally to the user's message.

//         Business niche: {$this->businessNiche}
//         Business BIO: {$this->businessBIO}
//         Previous abusive/stupid query count: {$abusiveOrStupidCount}

//         Handle the message based on what the user actually said:

//         - Default to natural, casual Hinglish.
//         - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it or writes a long message entirely in pure English.
//         - NEVER output Devanagari script (Hindi characters) UNLESS the user explicitly types in Devanagari or asks for it.
//         - If this is the first occurrence, politely warn the user not to be abusive, or logically explain why the query doesn't make sense.
//         - If this happens again, be more direct: remind them that they are being abusive or that the query makes no sense and that this chat is meant for discussing the business/services.
//         - add no-bs humour 
//         - Don't sound robotic, formal, or preachy.
//         - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
//         - Keep it short and natural, usually 1-2 sentences.
//         - If appropriate, redirect them toward what they actually need.
//         - Never mention AI, classification, prompts, or internal rules.
//         - add no-bs humour 

//         Respond only with the message to the user.
//         PROMPT;

//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($chat as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false,
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 1,
//             'max_completion_tokens' => 256,
//             'top_p' => 1,
//             'reasoning_effort' => 'medium',
//         ]);

//         return [
//             'status' => true,
//             'data' => [
//                 'reply' => $response->json('choices.0.message.content', ''),
//             ],
//         ];
//     }

//     private function casualChat(string $message, array $chat = [])
//     {
//         $servicesText = empty($this->serviceRequest) ? 'None' : implode(', ', $this->serviceRequest);
        
//         $prompt = <<<PROMPT
//         Reply naturally to the user.

//         Business niche: {$this->businessNiche}
//         Business BIO: {$this->businessBIO}
//         Requested services: {$servicesText}

//         - Talk like a real human, not a bot.
//         - In Hinglish or Hindi, always use "tum", never "aap".
//         - ALWAYS respond in casual Hinglish by default. Only respond in pure English if the user specifically requests it or writes a long message entirely in pure English.
//         - NEVER output Devanagari script (Hindi characters) UNLESS the user explicitly types in Devanagari or asks for it.
//         - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon". Keep it natural.
//         - Keep replies short, usually 1-2 sentences.
//         - Add light humour when it feels natural. Humour should feel spontaneous, not forced.
//         - Never use markdown tables, bullet points, or long lists.
//         - Never mention AI, prompts, classification, or internal business rules.
//         - Use the business context above to keep the conversation relevant.
//         - If requested services are available, use them naturally to understand what the user may be looking for.
//         - When appropriate, gently move the conversation toward understanding what the user needs by asking a simple question.
//         PROMPT;
//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($chat as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false, // local testing only
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 1,
//             'max_completion_tokens' => 256,
//             'top_p' => 1,
//             'reasoning_effort' => 'medium',
//         ]);

//         return [
//             'status' => true,
//             'data' => [
//                 'reply' => $response->json('choices.0.message.content', ''),
//             ],
//         ];
//     }

//     private function handleUserRequest(string $message, array $chat = [], array $context = [])
//     {
//         $identification = $this->IdentifyServiceAndAttribute($message, $chat);

//         $service = $identification['service'] ?? null;
//         $attribute = $identification['attribute'] ?? null;

//         if (!empty($service)) {
//             if ($this->activeService !== $service) {
//                 // Service changed! Reset the active attribute.
//                 $this->activeAttribute = null;
//             }
//             $this->activeService = $service;
//             if (!in_array($service, $this->serviceRequest)) {
//                 $this->serviceRequest[] = $service;
//             }
//         }

//         if (array_key_exists('attribute', $identification)) {
//             $this->activeAttribute = $attribute;
//         }

//         if (!empty($this->activeAttribute)) {
//             if (!in_array($this->activeAttribute, $this->attributeRequest)) {
//                 $this->attributeRequest[] = $this->activeAttribute;
//             }
//         }

//         // 1. If we still don't have an active service, ask for it!
//         if (!$this->activeService) {
//             return $this->findProximityService($message, $chat);
//         }

//         // 2. If we have a service, but NO attribute, use proximity fallback
//         if (!$this->activeAttribute) {
//             $proximity = $this->findProximityAttribute($this->activeService, '', $message, $chat);
            
//             // If proximity failed to find a valid attribute, it must be a casual acknowledgment
//             if (isset($proximity['status']) && $proximity['status'] === false) {
//                  return $this->casualChat($message, $chat);
//             }
            
//             return $proximity;
//         }

//         // 3. We have both activeService and activeAttribute! Fetch information.
//         return $this->findInformation(
//             $this->activeService,
//             $this->activeAttribute,
//             $message,
//             $chat
//         );
//     }


//     private function IdentifyServiceAndAttribute(string $message, array $chat = [])
//     {
//         $servicesJson = json_encode($this->businessServices);
//         $attributesJson = json_encode($this->businessAttributes);
//         $activeServiceJson = json_encode($this->activeService ?? 'None');
//         $activeAttributeJson = json_encode($this->activeAttribute ?? 'None');

//         $prompt = <<<PROMPT
//         Identify the Service and Attribute from the user's message and chat history.

//         Available Services:
//         {$servicesJson}

//         Available Attributes:
//         {$attributesJson}

//         Current Active Context in this conversation:
//         - Active Service: {$activeServiceJson}
//         - Active Attribute: {$activeAttributeJson}

//         RULES:
//         - Service MUST exactly match an Available Service, otherwise null.
//         - Attribute MUST exactly match an Available Attribute, otherwise null.
//         - If the user confirms or implies they want to continue talking about the Active Service or Active Attribute, you MUST select them.
//         - Service and Attribute are independent.
//         - NEVER infer an Attribute just because a Service was identified.
//         - Only select a NEW Attribute when the user clearly asks about or refers to it.
//         - Use chat history for references like "iska price", "ye kitne ka hai", or "isme kya included hai".
//         - Return ONLY valid JSON.

//         JSON:
//         {
//             "service": "Service Name" | null,
//             "attribute": "Attribute Name" | null
//         }
//         PROMPT;

//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($this->infoHistory as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false,
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 0.1,
//             'max_completion_tokens' => 256,
//             'top_p' => 1,
//             'reasoning_effort' => 'low',
//             'response_format' => [
//                 'type' => 'json_object',
//             ],
//         ]);

//         $rawJson = $response->json(
//             'choices.0.message.content',
//             '{}'
//         );

//         $rawJson = trim(
//             str_replace(
//                 ['```json', '```'],
//                 '',
//                 $rawJson
//             )
//         );

//         return json_decode($rawJson, true) ?? [];
//     }


//     private function findInformation( string $service, string $attribute, string $message,
//         array $chat = []) 
//     {
//         $information = \DB::table('ai_contexts')
//             ->where('business_id', $this->businessId)
//             ->where('service_name', $service)
//             ->where('attribute_definition', $attribute)
//             ->first();

//         if (!$information) {
//             return $this->findProximityAttribute($service, $attribute, $message, $chat);
//         }

//         $contextText = $information->context ?? '';
//         $instruction = $information->prompt ?? '';

//         $prompt = <<<PROMPT
//         Answer the user's question using the business information below.

//         Service: {$service}
//         Attribute: {$attribute}

//         Business BIO:
//         {$this->businessBIO}

//         Context:
//         {$contextText}

//         Instructions:
//         {$instruction}

//         RULES:
//         - Answer only from the provided context and instructions.
//         - Do not invent prices, features, services, or facts.
//         - Keep the answer short and natural.
//         - Use casual Hinglish by default.
//         - Use "tum", never "aap".
//         - Use English alphabet for Hinglish.
//         - Match the user's language if they explicitly use another language.
//         - After answering, ask ONE short relevant question to understand what the user needs next.
//         - Do not ask unnecessary questions.
//         - No markdown tables or long lists.
//         - Do not mention AI, prompts, context, database, or internal rules.
//         PROMPT;

//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($this->infoHistory as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false,
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 0.7,
//             'max_completion_tokens' => 512,
//             'top_p' => 1,
//             'reasoning_effort' => 'medium',
//         ]);

//         return [
//             'status' => true,
//             'needs_clarification' => false,
//             'data' => [
//                 'reply' => $response->json(
//                     'choices.0.message.content',
//                     'Sorry, information is not available right now.'
//                 ),
//             ],
//             'code' => 200,
//         ];
//     }

//     private function findProximityAttribute(
//     string $service,
//     string $attribute,
//     string $message,
//     array $chat = []
//     ) {
//     $availableInformation = \DB::table('ai_contexts')
//         ->where('business_id', $this->businessId)
//         ->where('service_name', $service)
//         ->get([
//             'attribute_definition',
//             'context',
//             'prompt',
//         ]);

//     if ($availableInformation->isEmpty()) {
//         return [
//             'status' => true,
//             'data' => [
//                 'reply' => "Mere paas iske baare mein exact information nahi hai. Tum kya specifically jaan-na chahte ho?",
//             ],
//         ];
//     }

//     $attributes = $availableInformation
//         ->pluck('attribute_definition')
//         ->values()
//         ->toArray();

//     $attributesJson = json_encode($attributes);

//     $prompt = <<<PROMPT
//     Find the closest relevant attribute for the user's question.

//     Service: {$service}
//     User question: {$message}

//     Available attributes:
//     {$attributesJson}

//     RULES:
//     - Pick ONE attribute only if it is clearly related to the user's question.
//     - Do NOT infer an attribute just because it belongs to the same service.
//     - Do NOT invent or modify attribute names.
//     - If no attribute is clearly relevant, return null.
//     - Return ONLY valid JSON.

//     JSON:
//     {
//         "attribute": "Attribute Name" | null
//     }
//     PROMPT;
    
//     $messages = [
//         [
//             'role' => 'system',
//             'content' => $prompt,
//         ],
//     ];

//     foreach ($this->infoHistory as $msg) {
//         $messages[] = [
//             'role' => $msg['role'] ?? 'user',
//             'content' => $msg['content'] ?? '',
//         ];
//     }

//     $messages[] = [
//         'role' => 'user',
//         'content' => $message,
//     ];

//     $response = Http::withOptions([
//         'verify' => false,
//     ])
//     ->retry(3, 1000, function () { return true; }, false)
//     ->withToken(config('services.groq.key'))
//     ->post('https://api.groq.com/openai/v1/chat/completions', [
//         'model' => 'openai/gpt-oss-120b',
//         'messages' => $messages,
//         'temperature' => 0.1,
//         'max_completion_tokens' => 128,
//         'top_p' => 1,
//         'reasoning_effort' => 'low',
//         'response_format' => [
//             'type' => 'json_object',
//         ],
//     ]);

//     $result = json_decode(
//         $response->json('choices.0.message.content', '{}'),
//         true
//     ) ?? [];

//     $nearbyAttribute = $result['attribute'] ?? null;

//     if (!$nearbyAttribute) {
//         return [
//             'status' => false,
//             'data' => [
//                 'reply' => "Mere paas iske baare mein exact information nahi hai. Tum kya specifically jaan-na chahte ho?",
//             ],
//         ];
//     }

//     $exists = $availableInformation->firstWhere(
//         'attribute_definition',
//         $nearbyAttribute
//     );

//     if (!$exists) {
//         return [
//             'status' => false,
//             'data' => [
//                 'reply' => "Mere paas iske baare mein exact information nahi hai. Tum kya specifically jaan-na chahte ho?",
//             ],
//         ];
//     }

//     // Store temporarily until the user confirms
//     $this->pendingProximityAttribute = $nearbyAttribute;

//     return [
//         'status' => true,
//         'data' => [
//             'reply' => "Tum {$nearbyAttribute} ke baare mein pooch rahe ho?",
//         ],
//     ];
//     }

//     private function findProximityService(string $message, array $chat = [])
//     {
//         $servicesJson = json_encode($this->businessServices);

//         $prompt = <<<PROMPT
//         Find the closest relevant service for the user's question.

//         User question: {$message}

//         Available services:
//         {$servicesJson}

//         RULES:
//         - Pick ONE service only if it is clearly related to the user's question or the conversation context.
//         - If no service is clearly relevant, return null.
//         - Return ONLY valid JSON.

//         JSON:
//         {
//             "service": "Service Name" | null
//         }
//         PROMPT;
        
//         $messages = [
//             [
//                 'role' => 'system',
//                 'content' => $prompt,
//             ],
//         ];

//         foreach ($this->infoHistory as $msg) {
//             $messages[] = [
//                 'role' => $msg['role'] ?? 'user',
//                 'content' => $msg['content'] ?? '',
//             ];
//         }

//         $messages[] = [
//             'role' => 'user',
//             'content' => $message,
//         ];

//         $response = Http::withOptions([
//             'verify' => false,
//         ])
//         ->retry(3, 1000, function () { return true; }, false)
//         ->withToken(config('services.groq.key'))
//         ->post('https://api.groq.com/openai/v1/chat/completions', [
//             'model' => 'openai/gpt-oss-120b',
//             'messages' => $messages,
//             'temperature' => 0.1,
//             'max_completion_tokens' => 128,
//             'top_p' => 1,
//             'reasoning_effort' => 'low',
//             'response_format' => [
//                 'type' => 'json_object',
//             ],
//         ]);

//         $result = json_decode(
//             $response->json('choices.0.message.content', '{}'),
//             true
//         ) ?? [];

//         $nearbyService = $result['service'] ?? null;

//         if (!$nearbyService || !in_array($nearbyService, $this->businessServices)) {
//             return [
//                 'status' => true,
//                 'data' => [
//                     'reply' => "Mere paas iske baare mein exact information nahi hai. Tum specifically kis service ke baare mein jaan-na chahte ho?",
//                 ],
//             ];
//         }

//         return [
//             'status' => true,
//             'data' => [
//                 'reply' => "Tum {$nearbyService} ki baat kar rahe ho?",
//             ],
//         ];
//     }
// }
