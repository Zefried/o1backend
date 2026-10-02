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
        
        $cachedQuery = \Illuminate\Support\Facades\Cache::get('qual_query_' . request()->ip());
        $qualQueryPrompt = "";
        if ($cachedQuery && !empty($cachedQuery['leadQualifyingQueryMode'])) {
            $lastQuestion = $cachedQuery['question'] ?? '';
            $qualQueryPrompt = "- \"ResponseToQualification\": (boolean) Set to true ONLY if the user's latest message is answering or acknowledging the AI's recent qualification question: \"{$lastQuestion}\".";
        } else {
            $qualQueryPrompt = "- \"ResponseToQualification\": (boolean) Always set to false.";
        }

        $prompt = <<<PROMPT
        Analyze the user's latest message based on the conversation history and business context.
        
        Business Context:
        {$businessData}

        Current Active Service:
        {$activeService}

        Determine the primary intent of the latest message. 
        Return ONLY valid JSON with ONE OR MORE of these keys set to true:
        - "userRequestInfo": (boolean) User is asking a question, making a request, or requesting information related to the business's niche, services, or attributes.
        - "userProvidedInfo": (boolean) Set to true if the user's message contains personal information, preferences, budget, timeline, phone number, location, etc. that could answer a business qualification question.
        {$qualQueryPrompt}

        - "topicChange": (boolean) STRICT RULE: Set to true if the user's LATEST message names, implies, or asks about a DIFFERENT service from the Current Active Service. Carefully check the Available Services list! (e.g., if active is "Living Room Design" and they mention "flooring", and "Flooring" is in the Available Services, this IS a topic change!).
        - "newServiceDemand": (string or null) If topicChange is true, extract the EXACT matching name of the NEW service from the Available Services list. Otherwise, set to null.

        JSON:
        {
            "userRequestInfo": boolean,
            "userProvidedInfo": boolean,
            "ResponseToQualification": boolean,
            "topicChange": boolean,
            "newServiceDemand": string | null
        }
        PROMPT;

        $intentJson = $this->callLLM($prompt, $message, $chat, true, 0.1, 256);
        \Log::info("checkIntent Output: ", $intentJson);
        return $intentJson;
    }

    public function handleWelcome()
    {
        $backend = $this->businessContext['backendData'] ?? [];

        $niche = $backend['niche'] ?? 'general';
        $bio = $backend['bio'] ?? '';
        $userServiceDemand = $backend['UserServiceDemand'] ?? null;
        
        $infoHistory = $this->chatContext['infoHistory'] ?? [];

        if (!empty($infoHistory)) {
            $prompt = <<<PROMPT
        You are an AI assistant for a business in the '{$niche}' niche.

        Business BIO: {$bio}
        User's requested service: {$userServiceDemand}

        The user is returning to the chat. You have access to their previous conversation history.
        Welcome them back naturally (e.g., "Welcome back!" or "Hey again!").
        Briefly and warmly mention what they were last discussing based on the chat history (e.g., "Last time hum modular kitchen ke price par baat kar rahe the") and ask if they want to continue from there or if they need help with something else.

        RULES:
        - Keep it 1 short sentence, maximum 2.
        - Start in casual Hinglish.
        - Use English alphabet only.
        - Use "tum", never "aap".
        - Sound natural, chill, and human.
        - No salesy or cheesy language.
        - NEVER use awkward gender-neutral phrasing like "sakta/sakti hoon".
        PROMPT;
            $chatData = $infoHistory;
        } else {
            // CASE A: Service demand already known — jump straight to attribute qualifying question
            if (!empty($userServiceDemand) && $userServiceDemand !== 'None') {
                $qualQuery = $this->generateQualifyingQuery([
                    'chatContext' => $this->chatContext,
                    'businessContext' => $this->businessContext
                ]);
                
                if ($qualQuery) {
                    return [
                        'status' => true,
                        'data' => ['reply' => "Hey! " . $qualQuery],
                        'code' => 200,
                    ];
                }
            }

            // CASE B: No service demand — ask what service they want
            $servicesText = implode(', ', $backend['services'] ?? []);
            $prompt = <<<PROMPT
        You are an AI assistant for a business in the '{$niche}' niche.

        Business BIO: {$bio}
        Available Services: {$servicesText}

        The user just opened the chat. Ask them what service they are looking for in ONE casual Hinglish sentence.

        RULES:
        - Keep it 1 short sentence only.
        - Use casual Hinglish. Use "tum", never "aap". No Devanagari.
        - Sound natural and human. Do NOT list all services.
        - Do not say "Swagat hai", "Welcome to our services", "sir/maam", or "How may I assist you".
        - NEVER use "sakta/sakti hoon".
        PROMPT;
            $chatData = [];
        }

        $reply = $this->callLLM(
            $prompt,
            "Hello",
            $chatData,
            false,
            0.7,
            128
        );

        return [
            'status' => true,
            'data' => [
                'reply' => $reply ?: 'Hey! sorry something went wrong, firse try kare?',
                'debug_welcome' => [
                    'history_count' => count($infoHistory),
                    'history_empty' => empty($infoHistory),
                    'user_service_demand' => $userServiceDemand ?? 'null',
                    'path_taken' => !empty($infoHistory) ? 'returning_user' : (!empty($userServiceDemand) && $userServiceDemand !== 'None' ? 'fresh_with_service_qual_failed' : 'fresh_no_service'),
                ]
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

        // Auto-fallback models list
        $models = [
            'openai/gpt-oss-120b',
            'openai/gpt-oss-20b',
            'openai/gpt-oss-safeguard-20b'
        ];

        $rawContent = '';

        foreach ($models as $model) {
            $payload = [
                'model' => $model,
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

            if ($response->successful()) {
                $rawContent = $response->json('choices.0.message.content', $jsonFormat ? '{}' : '');
                break; // Stop loop, we got a successful response
            } else {
                \Log::warning("LLM API Error with model {$model}: " . $response->body());
                // Will automatically continue loop to try the next model
            }
        }

        if ($jsonFormat) {
            $rawContent = trim(str_replace(['```json', '```'], '', $rawContent));
            return json_decode($rawContent, true) ?? [];
        }

        return trim($rawContent);
    }

    public function handleQualificationReply(string $message, array $chat = [])
    {
        $cachedQuery = \Illuminate\Support\Facades\Cache::get('qual_query_' . request()->ip());
        
        if (!$cachedQuery) {
            return $this->handleFallback($message, $chat);
        }

        $targetField = $cachedQuery['target_field'] ?? '';
        $questionAsked = $cachedQuery['question'] ?? '';

        $prompt = <<<PROMPT
        The AI previously asked the user this question: "{$questionAsked}".
        The user's reply is: "{$message}"
        
        Task: 
        Generate a very short, polite 1-sentence acknowledgment in Hinglish (e.g. "Okay, note kar liya!" or "Got it, samajh gaya!").

        Return ONLY valid JSON:
        {
            "reply": string
        }
        PROMPT;

        $response = $this->callLLM($prompt, '', [], true, 0.1, 128);

        \Illuminate\Support\Facades\Cache::forget('qual_query_' . request()->ip());

        return [
            'status' => true,
            'data' => [
                'reply' => $response['reply'] ?? 'Okay, noted!'
            ]
        ];
    }

    public function handleClosing()
    {
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? null;
        $bio           = $this->businessContext['backendData']['bio'] ?? '';

        $collectedData = [];
        if ($activeService && isset($this->chatContext['leadQualificationState'][$activeService]['data'])) {
            $collectedData = $this->chatContext['leadQualificationState'][$activeService]['data'];
        }
        $globalData = $this->chatContext['leadQualificationState']['Global']['data'] ?? [];
        $allData    = array_merge($collectedData, $globalData);
        $summary    = empty($allData) ? 'None' : json_encode($allData);

        $prompt = <<<PROMPT
        The conversation is complete. We have collected all the information we need from the user.
        
        Collected Information: {$summary}
        Business BIO: {$bio}
        Service: {$activeService}

        Generate a warm, natural closing message in casual Hinglish that:
        1. Thanks the user briefly for sharing details.
        2. Tells them our team will reach out to them shortly to take it forward.
        3. Politely ask if it's okay to close the chat now, OR if they have any final question.
        4. Keep it short — 2-3 sentences max.
        5. Use "tum", never "aap". No Devanagari. Sound human, not robotic.
        PROMPT;

        $reply = $this->callLLM($prompt, '', [], false, 0.7, 256);

        return [
            'status' => true,
            'data'   => [
                'reply'       => $reply ?: 'Shukriya! Hamari team jald hi tumse contact karegi. Kya chat band kar sakte hain?',
                'chat_status' => 'closing'
            ],
            'code' => 200,
        ];
    }

    public function handleFallback(string $message, array $chat = [])
    {

        $niche = $this->businessContext['backendData']['niche'] ?? 'general';
        $bio = $this->businessContext['backendData']['bio'] ?? '';
        $services = $this->businessContext['backendData']['services'] ?? [];
        $servicesText = empty($services) ? 'None' : implode(', ', $services);
        
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? 'None';
        $attributes = $this->businessContext['backendData']['attributes'] ?? [];
        $attributesText = empty($attributes) ? 'None' : implode(', ', $attributes);
        
        $collectedData = [];
        if ($activeService !== 'None' && isset($this->chatContext['leadQualificationState'][$activeService]['data'])) {
            $collectedData = $this->chatContext['leadQualificationState'][$activeService]['data'];
        }
        $collectedDataText = empty($collectedData) ? 'None' : json_encode($collectedData);

        $pendingQuestions = $this->getPendingQuestions($this->chatContext, $this->businessContext);

        // If nothing left to qualify, close the chat gracefully
        if (empty($pendingQuestions)) {
            return $this->handleClosing();
        }
        $prompt = <<<PROMPT
        You are a helpful AI assistant for a business. Read the chat history to understand the context.

        Business Niche: {$niche}
        Business BIO: {$bio}
        Available services: {$servicesText}
        Current Active Service: {$activeService}
        Service Attributes we can discuss: {$attributesText}
        Already Collected Information: {$collectedDataText}

        INSTRUCTIONS:
        - If the user's message is a casual greeting (hello, hi) or out-of-context chit-chat, acknowledge it politely in 1 short sentence and immediately steer the conversation back to asking how you can help them with the active service.
        - If the user's message is abusive, contains profanity, or is complete nonsense, politely but firmly tell them to keep it professional and ask how you can help them with the business.
        - If the user simply states they want a service without asking a specific question: DO NOT ask multiple questions. Pick ONE question from this exact list of pending items: [{$pendingQuestions}] and ask it naturally. Do NOT invent your own questions.
        - If the user asks a specific question about the business/service and the answer is not in your Business BIO, DO NOT guess or ask unrelated questions. Simply state that you don't have the exact details right now and politely ask for their phone number so the team can contact them with the information.
        - Do not ask the user for information that is already listed in "Already Collected Information".
        - ALWAYS respond in casual Hinglish. Use "tum", never "aap". No Devanagari.
        - Do not act like a robot.
        - Do not use markdown or lists. Keep it short (1-2 sentences).
        PROMPT;

        $reply = $this->callLLM($prompt, $message, $chat, false, 0.7, 256);

        // API/Network failure hardcoded fallback
        $fallbackReply = 'Bhai lagta hai thoda network issue tha, main theek se samajh nahi paya. Ek baar phir se bataoge tum kya dhoond rahe ho?';

        return [
            'status' => true,
            'data' => [
                'reply' => empty($reply) ? $fallbackReply : $reply,
            ],
            'code' => 200,
        ];
    }

    public function handleUserRequestInfo(string $message, array $chat = [], array $intent = [])
    {
        $businessId    = $this->businessId ?? null;
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? null;
        $services      = $this->businessContext['backendData']['services'] ?? [];
        $attributes    = $this->businessContext['backendData']['attributes'] ?? [];
        $bio           = $this->businessContext['backendData']['bio'] ?? '';
        $niche         = $this->businessContext['backendData']['niche'] ?? 'general';

        // 1. If no active service, ask the user to pick one
        if (!$activeService) {
            $servicesText = empty($services) ? 'None' : implode(', ', $services);
            $prompt = <<<PROMPT
            The user is asking a question but we don't know which service they want.
            Available services: {$servicesText}
            Ask them politely in casual Hinglish which service they need help with.
            Do NOT list all services. Use "tum", never "aap". No Devanagari.
            PROMPT;
            $reply = $this->callLLM($prompt, $message, $chat, false, 1.0, 128);
            return ['status' => true, 'data' => ['reply' => $reply]];
        }

        // Handle topic change — user switched to a new service
        if (($intent['topicChange'] ?? false) === true) {
            $qualQuery = $this->generateQualifyingQuery([
                'chatContext'     => $this->chatContext,
                'businessContext' => $this->businessContext
            ]);
            if ($qualQuery) {
                return ['status' => true, 'data' => ['reply' => "Bohot badhiya! " . $qualQuery], 'code' => 200];
            }
        }

        // 2. Fetch ONLY attributes that have actual DB data for this service (ground truth)
        $availableAttributes = [];
        if ($businessId) {
            $availableAttributes = \DB::table('ai_contexts')
                ->where('business_id', $businessId)
                ->where('service_name', $activeService)
                ->pluck('attribute_definition')
                ->toArray();
        }

        // 3. Single LLM call: identify attribute AND whether we have data for it
        $attributesJson  = json_encode($attributes);
        $availableJson   = json_encode($availableAttributes);

        $identPrompt = <<<PROMPT
        User's message: "{$message}"
        Service: {$activeService}

        All business attributes (for identification): {$attributesJson}
        Attributes we ACTUALLY have database data for: {$availableJson}

        Task:
        1. "asked_attribute": Which attribute from "All business attributes" is the user asking about? Use chat history for context. Return null if not asking about any specific attribute (e.g., casual chat, greetings).
        2. "has_data": Is "asked_attribute" present in "Attributes we ACTUALLY have database data for"? true/false.

        Return ONLY valid JSON:
        {
            "asked_attribute": string | null,
            "has_data": boolean
        }
        PROMPT;

        $identification = $this->callLLM($identPrompt, $message, $chat, true, 0.1, 128);
        $attribute      = $identification['asked_attribute'] ?? null;
        $hasData        = $identification['has_data'] ?? false;

        // 4. No clear attribute → route to fallback (casual/general message)
        if (!$attribute || !in_array($attribute, $attributes)) {
            return $this->handleFallback($message, $chat);
        }

        // 5. Attribute identified but NO DATA in DB
        if (!$hasData) {
            $availableText = empty($availableAttributes)
                ? 'abhi koi bhi topic'
                : implode(', ', $availableAttributes);

            $noDataPrompt = <<<PROMPT
            The user asked about: {$attribute} for {$activeService}.
            We do NOT have this specific information in our system right now.
            We DO have data for these topics: {$availableText}.
            Business BIO: {$bio}

            Generate a short, honest, helpful response in casual Hinglish that:
            1. Clearly says in ONE sentence we don't have "{$attribute}" details right now.
            2. Naturally offers to help with 1-2 things we DO have (from the available list).
            3. Do NOT say "database", "context", or "AI". Sound natural.
            4. Use "tum", never "aap". No Devanagari. Keep it to 2 sentences max.
            PROMPT;

            $reply = $this->callLLM($noDataPrompt, '', [], false, 0.7, 256);
            return ['status' => true, 'data' => ['reply' => $reply]];
        }

        // 6. Attribute found AND has DB data — fetch context and answer
        $information = \DB::table('ai_contexts')
            ->where('business_id', $businessId)
            ->where('service_name', $activeService)
            ->where('attribute_definition', $attribute)
            ->first();

        if (!$information) {
            // Shouldn't normally happen since has_data is true, but safety net
            return ['status' => true, 'data' => ['reply' => "Sorry, abhi mere paas iski exact jaankari nahi hai. Kya tum apna phone number de sakte ho?"]];
        }

        $contextText = $information->context ?? '';
        $instruction = $information->prompt ?? '';

        // 7. Generate final answer
        $shouldAskQuery   = \Illuminate\Support\Facades\Cache::pull('disable_query_' . request()->ip(), true);
        $pendingQuestions = $this->getPendingQuestions($this->chatContext, $this->businessContext);

        if ($shouldAskQuery === false || empty($pendingQuestions)) {
            $questionRule = "- IMPORTANT: DO NOT ask any follow-up questions. Just answer and stop naturally.";
        } else {
            $questionRule = <<<RULE
        - After answering, ask ONE short question.
        - IMPORTANT RULE: Pick ONLY from this list of pending items: [{$pendingQuestions}].
        - Do NOT invent questions. Do NOT ask about anything not in the pending list.
        RULE;
        }

        $collectedData     = $this->chatContext['leadQualificationState'][$activeService]['data'] ?? [];
        $collectedDataText = empty($collectedData) ? 'None' : json_encode($collectedData);

        $answerPrompt = <<<PROMPT
        Answer the user's question using the business information below.

        Service: {$activeService}
        Attribute: {$attribute}
        Business BIO: {$bio}
        Already Collected Information about User: {$collectedDataText}

        Context:
        {$contextText}

        Instructions:
        {$instruction}

        RULES:
        - Answer ONLY from the provided context and instructions.
        - Do not invent facts, prices, or features.
        - Keep the answer short and natural.
        - Use casual Hinglish. Use "tum", never "aap". No Devanagari.
        - Do not re-ask for information already in "Already Collected Information about User".
        {$questionRule}
        - Do not mention AI, database, context, or internal rules.
        PROMPT;

        $reply = $this->callLLM($answerPrompt, $message, $chat, false, 0.7, 512);
        return ['status' => true, 'data' => ['reply' => $reply]];
    }
}
