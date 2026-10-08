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


}
