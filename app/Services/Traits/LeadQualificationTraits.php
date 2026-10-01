<?php

namespace App\Services\Traits;

trait LeadQualificationTraits
{
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
            $result['data']['eye_monitor'] = [
                'status' => 'monitoring',
                'has_query' => $hasQuery,
                'nextQuery_calculated' => $nextQuery,
                'cached_value_test' => $cachedValue,
                'shadow_generated_question' => $followUpQuestion
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
        $serviceQuestions = $leadState[$demandService]['questions'] ?? '';
        
        if (empty($serviceQuestions)) return null;

        $niche = $businessContext['backendData']['niche'] ?? 'general';

        $prompt = <<<PROMPT
        You are a smart lead qualification assistant for the "{$niche}" industry.
        
        Active Service: {$demandService}
        Available Questions to ask (with priority scores): {$serviceQuestions}
        
        Task:
        1. Pick exactly ONE question from the available list that has the HIGHEST priority score.
        2. Generate a natural, casual, short 1-sentence follow-up question in conversational Hinglish to ask the user for this specific field.
        3. Add a very brief, polite reason explaining WHY you are asking this. (e.g. "Waise aapka budget kya hai? Isse hum exact cost estimate kar payenge.")
        
        Return ONLY valid JSON:
        {
            "target_field": string,
            "question": string
        }
        PROMPT;

        $response = $this->callLLM($prompt, '', [], true, 0.1, 256);

        if (!empty($response['question'])) {
            return $response['question'];
        }

        return null;
    }
}
