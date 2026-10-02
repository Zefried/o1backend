<?php

namespace App\Services\Traits;

trait LeadQualificationTraits
{
    public function getPendingQuestions(array $chatContext, array $businessContext)
    {
        $demandService = $businessContext['backendData']['UserServiceDemand'] ?? null;
        $leadState = $chatContext['leadQualificationState'] ?? [];
        
        $globalQuestions = $leadState['Global']['questions'] ?? '';
        $serviceQuestions = $demandService && $demandService !== 'None' ? ($leadState[$demandService]['questions'] ?? '') : '';
        
        $allQuestionsList = [];
        if (!empty($globalQuestions)) $allQuestionsList[] = $globalQuestions;
        if (!empty($serviceQuestions)) $allQuestionsList[] = $serviceQuestions;
        
        return implode(', ', $allQuestionsList);
    }

    public function extractDynamicInformation(string $message, array $chatContext, array $businessContext)
    {
        $demandService = $businessContext['backendData']['UserServiceDemand'] ?? null;
        if (!$demandService || $demandService === 'None') return;

        $leadState = $chatContext['leadQualificationState'] ?? [];
        $globalQuestions = $leadState['Global']['questions'] ?? '';
        $serviceQuestions = $leadState[$demandService]['questions'] ?? '';
        
        $allQuestionsList = [];
        if (!empty($globalQuestions)) $allQuestionsList[] = $globalQuestions;
        if (!empty($serviceQuestions)) $allQuestionsList[] = $serviceQuestions;
        $allQuestions = implode(', ', $allQuestionsList);

        if (empty($allQuestions)) return;

        $prompt = <<<PROMPT
        The user has provided some information in their message. We need to extract it to fulfill our pending qualification questions.
        
        Pending Questions (field=priority): {$allQuestions}
        User's Message: "{$message}"
        
        Task:
        1. Extract ONLY the fields that the user has explicitly answered or directly addressed in the message.
        2. DO NOT extract or assume values for any fields that the user has not mentioned.
        3. Use the exact field name from the list (e.g. "Running feet/layout", "Budget", "phonenumber").
        4. ONLY if the user explicitly says they don't know, haven't decided, or refuse to answer a specific thing, use "Not decided yet" for that specific field.
        
        Return ONLY valid JSON object:
        {
            "extractions": [
                {"field": "Budget", "value": "80k"}
            ]
        }
        If no info matches, return: {"extractions": []}
        PROMPT;

        $response = $this->callLLM($prompt, '', [], true, 0.1, 256);

        $extractions = $response['extractions'] ?? [];

        if (!empty($extractions)) {
            foreach ($extractions as $item) {
                if (isset($item['field']) && isset($item['value'])) {
                    $field = $item['field'];
                    $value = $item['value'];

                    // Decide if it belongs to Global or Service
                    if (str_contains($globalQuestions, $field)) {
                        $this->chatContext['leadQualificationState']['Global']['data'][] = [$field => $value];
                        // Remove from pending questions
                        $this->chatContext['leadQualificationState']['Global']['questions'] =
                            trim(preg_replace('/' . preg_quote($field, '/') . '=\d+(,\s*)?/', '', $this->chatContext['leadQualificationState']['Global']['questions']), ', ');
                    } else {
                        $this->chatContext['leadQualificationState'][$demandService]['data'][] = [$field => $value];
                        // Remove from pending questions
                        $this->chatContext['leadQualificationState'][$demandService]['questions'] =
                            trim(preg_replace('/' . preg_quote($field, '/') . '=\d+(,\s*)?/', '', $this->chatContext['leadQualificationState'][$demandService]['questions']), ', ');
                    }
                }
            }
        }
    }

    public function eyeOnResponses(array $result, string $userMessage, array $chatContext)
    {
        if (isset($result['data']['reply'])) {
            $aiReply = $result['data']['reply'];
            
            $prompt = <<<PROMPT
            Analyze the AI's reply below. Did the AI ask a question to the user at the end?
            
            AI Reply: "{$aiReply}"
            
            Return ONLY a valid JSON object: {"has_query": true} or {"has_query": false}
            PROMPT;

            // Call the LLM quietly in the background (increased maxTokens to prevent validation error)
            $llmDecision = $this->callLLM($prompt, '', [], true, 0.1, 128);
            
            $hasQuery = $llmDecision['has_query'] ?? false;
            $nextQuery = !$hasQuery;
            
            $cacheKey = 'disable_query_' . request()->ip();
            
            // 1. Save to Cache
            \Illuminate\Support\Facades\Cache::put($cacheKey, $nextQuery, 60);
            
            // 2. Fetch from Cache to verify
            $cachedValue = \Illuminate\Support\Facades\Cache::get($cacheKey);
            
            $followUpQuestion = null;
            if ($hasQuery === false) {
                $followUpQuestion = $this->generateQualifyingQuery($chatContext);
            }

            // Background observation block
            // This will show up in the Network tab for you to inspect
            $qualQueryCache = \Illuminate\Support\Facades\Cache::get('qual_query_' . request()->ip());

            $result['data']['eye_monitor'] = [
                'status' => 'monitoring',
                'has_query' => $hasQuery,
                'QualifyQueryMode' => $nextQuery,
                'cached_value_test' => $cachedValue,
                'shadow_generated_question' => $followUpQuestion,
                'live_cached_query' => $qualQueryCache
            ];
        }

        return $result;
    }

    private function generateQualifyingQuery(array $chatContext)
    {
        $actualChatContext = $chatContext['chatContext'] ?? $chatContext;
        $businessContext = $chatContext['businessContext'] ?? [];

        $demandService = $businessContext['backendData']['UserServiceDemand'] ?? null;
        if (!$demandService || $demandService === 'None') return null;

        $leadState = $actualChatContext['leadQualificationState'] ?? [];
        
        $globalQuestions = $leadState['Global']['questions'] ?? '';
        $serviceQuestions = $leadState[$demandService]['questions'] ?? '';
        $serviceInitialized = isset($leadState[$demandService]);

        // If service was never initialized, we can't qualify — return null
        if (!$serviceInitialized) return null;

        // Service questions take priority. Only fall back to Global when service is fully qualified.
        $activeQuestions = !empty($serviceQuestions) ? $serviceQuestions : $globalQuestions;
        
        $collectedDataGlobal = $leadState['Global']['data'] ?? [];
        $collectedDataService = $leadState[$demandService]['data'] ?? [];
        $collectedData = array_merge($collectedDataGlobal, $collectedDataService);
        $collectedDataText = empty($collectedData) ? 'None' : json_encode($collectedData);

        if (empty($activeQuestions)) return null;

        $niche = $businessContext['backendData']['niche'] ?? 'general';

        $prompt = <<<PROMPT
        You are a smart lead qualification assistant for the "{$niche}" industry.
        Read the chat history to understand what the user is talking about right now.
        
        Active Service: {$demandService}
        Available Questions to ask (with priority scores): {$activeQuestions}
        Already Collected Information: {$collectedDataText}
        
        Task:
        1. Look at the chat history and the "Already Collected Information".
        2. Pick exactly ONE question from the available list that makes the most sense to ask next based on the chat context.
        3. Do NOT ask anything related to the "Already Collected Information".
        4. Generate a natural, casual, short 1-sentence follow-up question in conversational Hinglish to ask the user for this specific field.
        5. Add a very brief, polite reason explaining WHY you are asking this. (e.g. "Isse hum exact plan bana payenge.")
        
        Return ONLY valid JSON:
        {
            "target_field": string,
            "question": string
        }
        PROMPT;

        $history = $actualChatContext['infoHistory'] ?? [];
        $response = $this->callLLM($prompt, '', $history, true, 0.1, 256);

        if (!empty($response['question'])) {
            $cacheKey = 'qual_query_' . request()->ip();
            $cacheData = [
                'leadQualifyingQueryMode' => true,
                'target_field' => $response['target_field'] ?? '',
                'question' => $response['question'],
                'response' => 'pending'
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $cacheData, now()->addMinutes(10));

            return $response['question'];
        }

        return null;
    }
}
