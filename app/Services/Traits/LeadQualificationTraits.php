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
            
            // Background observation block
            // This will show up in the Network tab for you to inspect
            $result['data']['eye_monitor'] = [
                'status' => 'monitoring',
                'has_query' => $hasQuery,
                'nextQuery_calculated' => $nextQuery,
                'cached_value_test' => $cachedValue
            ];
        }

        return $result;
    }
}
