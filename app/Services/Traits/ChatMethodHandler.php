<?php

namespace App\Services\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $pendingClarification = $this->chatContext['pending_attribute_clarification'] ?? null;
        $clarificationPrompt = $pendingClarification
            ? "\n        CRITICAL: The user was just asked if they meant '{$pendingClarification}'. If their message is a simple 'yes', 'no', or a correction to this, you MUST set \"userRequestInfo\" to true."
            : "";

        $prompt = <<<PROMPT
        Analyze the user's latest message based on the conversation history and business context.
        
        Business Context:
        {$businessData}

        Current Active Service:
        {$activeService}

        Determine the primary intent of the latest message. 
        Return ONLY valid JSON with ONE OR MORE of these keys set to true:
        - "userRequestInfo": (boolean) User is asking a general question, making a request, or requesting information related to the business's niche, services, or attributes (excluding specific pricing questions).{$clarificationPrompt}
        - "pricingIntent": (boolean) Set to true if the user's message is specifically asking about prices, costs, budget, estimates, discounts, or any financial figures.
        - "userProvidedInfo": (boolean) Set to true if the user's message contains personal information, preferences, budget, timeline, phone number, location, etc. that could answer a business qualification question.
        {$qualQueryPrompt}

        - "topicChange": (boolean) STRICT RULE: Set to true if the user's LATEST message names, implies, or asks about a DIFFERENT service from the Current Active Service. Carefully check the Available Services list! (e.g., if active is "Living Room Design" and they mention "flooring", and "Flooring" is in the Available Services, this IS a topic change!).
        - "newServiceDemand": (string or null) If topicChange is true, extract the EXACT matching name of the NEW service from the Available Services list. Otherwise, set to null.

        JSON:
        {
            "userRequestInfo": boolean,
            "pricingIntent": boolean,
            "userProvidedInfo": boolean,
            "ResponseToQualification": boolean,
            "topicChange": boolean,
            "newServiceDemand": string | null
        }
        PROMPT;

        $intentJson = $this->callLLM($prompt, $message, $chat, true, 0.1, 256);
        Log::info("checkIntent Output: ", $intentJson);
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
                Log::warning("LLM API Error with model {$model}: " . $response->body());
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

    /**
     * Terminal closing handler — deterministic, zero LLM calls.
     *
     * The decision to close is made upstream:
     *   - handleFallback() Step 1: no pending questions → qualification complete.
     *   - handleFallback() Step 2: classifier routed an explicit farewell here.
     *
     * This method's only job is to return a final goodbye and set chat_status.
     * It must NOT re-evaluate whether to close, ask new questions, or reopen the conversation.
     *
     * Params are kept for signature compatibility but are intentionally unused.
     */
    
    public function handleClosing(string $message = '', array $chat = [])
    {
        // Persist closing state into chatContext so it flows through context_state
        // to the frontend and is available on the next request for re-entry detection.
        $this->chatContext['chat_status'] = 'closing';

        return [
            'status' => true,
            'data'   => [
                'reply'       => 'Shukriya! Hamari team jald hi tumse contact karegi. 😊',
                'chat_status' => 'closing',
            ],
            'code' => 200,
        ];
    }

    public function handleFallback(string $message, array $chat = [])
    {
        $niche          = $this->businessContext['backendData']['niche'] ?? 'general';
        $services       = $this->businessContext['backendData']['services'] ?? [];
        $servicesText   = empty($services) ? 'None' : implode(', ', $services);
        $activeService  = $this->businessContext['backendData']['UserServiceDemand'] ?? 'None';

        // ── Step 1: No pending questions → qualification complete, close gracefully ──
        $pendingQuestions = $this->getPendingQuestions($this->chatContext, $this->businessContext);

        if (empty($pendingQuestions)) {
            // 'reopened' is a transient one-turn marker set by handle()'s re-entry guard.
            // It signals that the user is genuinely continuing after a close, so we must
            // NOT immediately re-close — allow the message to reach Step 2 classification.
            if (($this->chatContext['chat_status'] ?? null) === 'reopened') {
                $this->chatContext['chat_status'] = null; // consume the marker
                // Fall through to Step 2 below
            } else {
                return $this->handleClosing($message, $chat);
            }
        }

        // ── Step 2: Classify the message ─────────────────────────────────────────────
        // Primary context: structured state (activeService, services, pendingQuestions)
        // Supporting context: recent chat only (last 6 messages — enough for tone/intent,
        // not enough for irrelevant history to drive the decision)
        $recentChat = array_slice($chat, -6);

        $classifyPrompt = <<<PROMPT
        You are a message classifier for a business chatbot. Classify the user's latest message into exactly ONE category.

        Business Niche: {$niche}
        Available Services: {$servicesText}
        Current Active Service: {$activeService}
        Pending Qualification Questions: {$pendingQuestions}

        Categories:
        - "business": The user is asking a question, making a request, or seeking information related to the business niche, services, or attributes.
        - "closing": The user is EXPLICITLY and UNAMBIGUOUSLY ending the conversation with a clear farewell signal (e.g., "bye", "goodbye", "band karo", "close karo", "ok bye").
          ⚠️ IMPORTANT — Pending qualification questions still exist. Do NOT classify ambiguous acknowledgements ("alright", "ok", "thik hai", "haan", "theek hai", "sure") as "closing". These are "casual".
        - "casual": Everything else — greetings, random/nonsense messages, off-topic chit-chat, and ALL ambiguous acknowledgements when pending questions remain.

        Return ONLY valid JSON:
        {
            "type": "business" | "casual" | "closing"
        }
        PROMPT;

        $classification = $this->callLLM($classifyPrompt, $message, $recentChat, true, 0.1, 64);
        $type = $classification['type'] ?? 'casual';

        Log::info('handleFallback classification', ['type' => $type, 'message' => $message]);

        // ── Step 3: Route — never answer business questions ourselves ─────────────────
        if ($type === 'business') {
            return $this->handleUserRequestInfo($message, $chat);
        }

        if ($type === 'closing') {
            return $this->handleClosing($message, $chat);
        }

        // ── "casual": short controlled response, steer back to business ──────────────
        $casualPrompt = <<<PROMPT
        You are a business chatbot assistant. The user sent a casual, off-topic, or ambiguous message.

        Business Niche: {$niche}
        Current Active Service: {$activeService}

        Task:
        - Acknowledge the user's message very briefly in 1 short sentence.
        - Then naturally steer the conversation back toward the active service or pending business topic.
        - Do NOT answer any business questions yourself.
        - Do NOT give personal, emotional, or unrelated advice.
        - Keep it to 1-2 sentences max.
        - Use casual Hinglish. Use "tum", never "aap". No Devanagari.
        PROMPT;

        $reply = $this->callLLM($casualPrompt, $message, $recentChat, false, 0.7, 128);

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
            return $this->askUserToPickService($message, $chat, $services);
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

        // 1. Resolve pending clarification if exists
        $pendingAttribute = $this->chatContext['pending_attribute_clarification'] ?? null;
        if ($pendingAttribute) {
            $resolution = $this->resolveClarificationConfirmation($message, $pendingAttribute);
            
            if ($resolution['confirmed'] ?? false) {
                unset($this->chatContext['pending_attribute_clarification']);
                $attribute = $pendingAttribute;
                $availableAttributes = $this->fetchAvailableAttributes($businessId, $activeService);
                $hasData = in_array($attribute, $availableAttributes);
                
                if (!$hasData) {
                    return $this->generateNoDataReply($attribute, $activeService, $availableAttributes, $bio);
                }
                return $this->generateAttributeAnswer($message, $chat, $attribute, $activeService, $businessId, $bio);
            }
            // If not confirmed (e.g. 'no' or different topic), clear it and fall through to standard processing
            unset($this->chatContext['pending_attribute_clarification']);
        }

        // 2. Fetch ONLY attributes that have actual DB data for this service (ground truth)
        $availableAttributes = $this->fetchAvailableAttributes($businessId, $activeService);

        // 3. Single LLM call: identify attribute AND whether we have data for it
        $identification = $this->identifyRequestedAttribute($message, $chat, $attributes, $availableAttributes, $activeService);
        $attribute      = $identification['asked_attribute'] ?? null;
        $hasData        = $identification['has_data'] ?? false;
        $ambiguous      = $identification['ambiguous_attribute'] ?? null;

        // NEW: Clarification check
        if (!$attribute && $ambiguous) {
            $this->chatContext['pending_attribute_clarification'] = $ambiguous;
            return $this->generateClarificationReply($ambiguous);
        }

        // 4. No clear attribute → General Knowledge / BIO fallback
        if (!$attribute || !in_array($attribute, $attributes)) {
            return $this->generateGeneralFallbackReply($message, $availableAttributes, $niche, $bio);
        }

        // 5. Attribute identified but NO DATA in DB
        if (!$hasData) {
            return $this->generateNoDataReply($attribute, $activeService, $availableAttributes, $bio);
        }

        // 6. Attribute found AND has DB data — fetch context and answer
        return $this->generateAttributeAnswer($message, $chat, $attribute, $activeService, $businessId, $bio);
    }

    public function handlePricingRequest(string $message, array $chat = []): array
    {
        $businessId    = $this->businessId ?? null;
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? null;
        $services      = $this->businessContext['backendData']['services'] ?? [];
        $bio           = $this->businessContext['backendData']['bio'] ?? '';

        if (!$activeService) {
            return $this->askUserToPickService($message, $chat, $services);
        }

        // Fetch ALL ai_contexts for this service to get comprehensive pricing/rules
        $allContexts = [];
        if ($businessId) {
            $allContexts = DB::table('ai_contexts')
                ->where('business_id', $businessId)
                ->where('service_name', $activeService)
                ->get();
        }

        if (empty($allContexts) || $allContexts->isEmpty()) {
            return $this->generateNoDataReply("pricing", $activeService, [], $bio);
        }

        // Aggregate contexts
        $aggregatedContext = "";
        foreach ($allContexts as $contextRow) {
            $aggregatedContext .= "Attribute: {$contextRow->attribute_definition}\n";
            $aggregatedContext .= "Context/Data: {$contextRow->context}\n";
            $aggregatedContext .= "Rules/Instructions: {$contextRow->prompt}\n\n";
        }

        $collectedData     = $this->chatContext['leadQualificationState'][$activeService]['data'] ?? [];
        $collectedDataText = empty($collectedData) ? 'None' : json_encode($collectedData);

        // Build the Prompt
        $prompt = <<<PROMPT
        You are an advanced Pricing and Estimation engine for a business.
        Service: {$activeService}
        Business BIO: {$bio}
        Already Collected Information about User: {$collectedDataText}

        Available Knowledge Base (Rules, Constraints, Base Prices, Formulas):
        {$aggregatedContext}

        Task: The user has asked a pricing, cost, estimate, or budget-related question.
        1. Identify the requested calculation type (e.g., Per-unit, Total, Budget-fit, Maximum, Minimum, Range, Comparison, Change, Discount, Negotiation, Estimate, Rule, or Reverse).
        2. Extract necessary variables from the user's message and 'Already Collected Information'.
        3. Use the 'Available Knowledge Base' EXACTLY as provided to perform mathematical calculations. NEVER invent prices, units, currencies, limits, or formulas not explicitly defined in the context.
        4. Use conversation history ONLY to recover missing pricing-related values (like dimensions or quantities) that the user provided earlier. Ignore unrelated chat history.
        5. If a critical value required to complete the mathematical formula is still missing, formulate EXACTLY ONE short clarification question asking for that specific missing value. NEVER ask a general qualification question (like name, location, etc., unless mathematically required for pricing).
        6. If all required information exists, perform the calculation, answer the user briefly with the result, and stop. Keep the final response short and in casual Hinglish (use "tum", never "aap", no Devanagari).

        RULES:
        - DO NOT invent or assume any missing numbers.
        - DO NOT mention the AI, database, or internal calculation logic to the user.
        PROMPT;

        $reply = $this->callLLM($prompt, $message, $chat, false, 0.2, 512);
        return ['status' => true, 'data' => ['reply' => $reply]];
    }

    // ── handleUserRequestInfo private helpers ─────────────────────────────────

    private function askUserToPickService(string $message, array $chat, array $services): array
    {
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

    private function fetchAvailableAttributes(?string $businessId, ?string $activeService): array
    {
        $availableAttributes = [];
        if ($businessId) {
            $availableAttributes = DB::table('ai_contexts')
                ->where('business_id', $businessId)
                ->where('service_name', $activeService)
                ->pluck('attribute_definition')
                ->toArray();
        }
        return $availableAttributes;
    }

    private function identifyRequestedAttribute(string $message, array $chat, array $attributes, array $availableAttributes, string $activeService): array
    {
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
        3. "ambiguous_attribute": If "asked_attribute" is null because the user's message contains a typo (e.g. "porfolio"), is too short, or is ambiguous BUT likely refers to a specific attribute from the list, put the guessed attribute name here. Otherwise, null.

        Return ONLY valid JSON:
        {
            "asked_attribute": string | null,
            "has_data": boolean,
            "ambiguous_attribute": string | null
        }
        PROMPT;

        return $this->callLLM($identPrompt, $message, $chat, true, 0.1, 128);
    }

    private function generateGeneralFallbackReply(string $message, array $availableAttributes, string $niche, string $bio): array
    {
        $availableText = empty($availableAttributes)
            ? 'abhi koi bhi topic'
            : implode(', ', $availableAttributes);

            $generalPrompt = <<<PROMPT
                You are a business chatbot assistant handling a user's business-related question that could not be mapped to a known business attribute.

                Business Niche:
                {$niche}

                Business BIO:
                {$bio}

                Available Topics:
                {$availableText}

                Your task:
                1. Try to answer ONLY using explicit Business BIO.
                2. If BIO contains enough info, answer naturally and directly.
                3. If BIO does not contain the answer:
                    - do not guess, infer, or invent
                    - say that the exact details are currently unavailable
                    - offer 1–2 relevant Available Topics
                4. Do not use general knowledge.
                5. Do not pretend an unknown term matches an Available Topic.
                6. Keep the response to a maximum of 2 sentences.
                7. Use casual Hinglish.
                8. Use English alphabet only. No Devanagari.
                9. Use "tum", never "aap".
                10. Do not mention AI, database, context, prompts, internal rules, or system limitations.

                Return ONLY the final user-facing response.
            PROMPT;

            $reply = $this->callLLM($generalPrompt, $message, [], false, 0.7, 256);
        return ['status' => true, 'data' => ['reply' => $reply]];
    }

    private function generateNoDataReply(string $attribute, string $activeService, array $availableAttributes, string $bio): array
    {
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

    private function generateAttributeAnswer(string $message, array $chat, string $attribute, string $activeService, ?string $businessId, string $bio): array
    {
        // 6. Attribute found AND has DB data — fetch context and answer
        $information = DB::table('ai_contexts')
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

    private function resolveClarificationConfirmation(string $message, string $pendingAttribute): array
    {
        $prompt = <<<PROMPT
        The user was previously asked to clarify if they meant "{$pendingAttribute}".
        Their response is: "{$message}"

        Did the user confirm they are asking about "{$pendingAttribute}"?
        (If they say yes, yup, haan, true - return true. If they say no, nay, nah, or ask a completely different question - return false.)

        Return ONLY valid JSON:
        {
            "confirmed": boolean
        }
        PROMPT;

        return $this->callLLM($prompt, '', [], true, 0.1, 64);
    }

    private function generateClarificationReply(string $ambiguousAttribute): array
    {
        $prompt = <<<PROMPT
        Generate a very short, polite question in casual Hinglish asking the user if they meant "{$ambiguousAttribute}".
        Keep it to one single short sentence. Use "tum", never "aap". No Devanagari.
        Example format: "Tum {$ambiguousAttribute} ke baare mein puch rahe ho kya?"
        PROMPT;

        $reply = $this->callLLM($prompt, '', [], false, 0.7, 128);
        return ['status' => true, 'data' => ['reply' => $reply]];
    }

}