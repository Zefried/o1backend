<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\Traits\ChatMethodHandler;
use App\Services\Traits\LeadQualificationTraits;

class ChatEngineService
{
    use ChatMethodHandler, LeadQualificationTraits;

    private ?string $businessId = null;

    private array $businessContext = [
        'contextKey' => [], // Yahan prompts aur AI instructions ayenge
        'backendData' => [
            'bio' => '',
            'niche' => 'general',
            'services' => [],
            'attributes' => [],
            'UserServiceDemand' => null,
            'UserServiceDemandId' => null
        ]
    ];

    private array $chatContext = [
        'infoHistory' => []
    ];

    public function handle(string $message, array $chat = [], array $context = [])
    {
        $this->setState($context);
        $this->initializeLeadQualificationState();

        // ── Re-entry guard: detect messages sent after the conversation was closed ────────
        //
        // chat_status = 'closing' is written into chatContext by handleClosing() and
        // persists through context_state → frontend → next request → setState().
        // When detected, we run one tiny LLM call to decide:
        //   • Genuine continuation → set transient 'reopened' marker, fall through to pipeline.
        //   • Mere acknowledgement  → return closing response immediately, skip pipeline.
        // ────────────────────────────────────────────────────────────────────────────
        if (($this->chatContext['chat_status'] ?? null) === 'closing') {
            // Retrieve last AI message to give the LLM enough conversational context.
            $lastAiMessage = '';
            foreach (array_reverse($this->chatContext['infoHistory'] ?? []) as $msg) {
                if (($msg['role'] ?? '') === 'assistant') {
                    $lastAiMessage = $msg['content'] ?? '';
                    break;
                }
            }

            $reentryPrompt = <<<PROMPT
            A chatbot just closed a conversation with a goodbye message. The user has sent a new message.

            AI's closing message: "{$lastAiMessage}"
            User's new message: "{$message}"

            Determine: Is the user GENUINELY trying to continue or restart the conversation,
            or are they merely acknowledging the goodbye?

            - true  = genuine continuation: they have a question, request, new topic, or are
                      signaling they want to keep talking (even if phrased casually or ambiguously).
            - false = mere acknowledgement: a simple reaction to the goodbye with no intent
                      to continue (e.g., "haan", "ok", "thik hai", "accha", "bye", "ok bye").

            Return ONLY valid JSON:
            {
                "is_continuation": true | false
            }
            PROMPT;

            $decision = $this->callLLM($reentryPrompt, '', [
                ['role' => 'assistant', 'content' => $lastAiMessage],
                ['role' => 'user',      'content' => $message],
            ], true, 0.1, 64);

            \Log::info('Re-entry guard', [
                'is_continuation' => $decision['is_continuation'] ?? null,
                'message'         => $message,
            ]);

            if (($decision['is_continuation'] ?? false) !== true) {
                // Mere acknowledgement — stay closed, skip the pipeline entirely.
                $this->chatContext['chat_status'] = 'closing';
                return [
                    'status' => true,
                    'data'   => [
                        'reply'         => 'Shukriya! Hamari team jald hi tumse contact karegi. 😊',
                        'chat_status'   => 'closing',
                        'intent'        => 'closingAcknowledgement',
                        'context_state' => [
                            'businessContext' => $this->businessContext,
                            'chatContext'     => $this->chatContext,
                        ],
                    ],
                    'code' => 200,
                ];
            }

            // Genuine continuation — set transient marker and fall through to normal pipeline.
            // handleFallback() Step 1 reads this marker to skip the immediate re-close.
            // handle() clears it before serializing context_state so it never persists.
            $this->chatContext['chat_status'] = 'reopened';
        }
        // ────────────────────────────────────────────────────────────────────────────

        // --------------------------

        $intentName = "Unknown";
        
        if (trim(strtolower($message)) === 'hello' && empty($chat)) {
            $intentName = "casualChat";
            $result = $this->handleWelcome();
        } else {
            $intent = $this->checkIntent($message, $chat);

            // Handle Topic Change inside the same business niche
            if (($intent['topicChange'] ?? false) === true && !empty($intent['newServiceDemand'])) {
                $newService = $intent['newServiceDemand'];
                $this->businessContext['backendData']['UserServiceDemand'] = $newService;

                // Fetch new service ID to keep it in sync
                $serviceRow = \DB::table('services')->where('name', $newService)->first();
                if ($serviceRow) {
                    $this->businessContext['backendData']['UserServiceDemandId'] = $serviceRow->id;
                }
                
                $this->initializeLeadQualificationState();
            }

            // Retroactive Bubble 2 Push
            $cachedQuery = \Illuminate\Support\Facades\Cache::get('qual_query_' . request()->ip());
            if ($cachedQuery && !empty($cachedQuery['question'])) {
                $this->chatContext['infoHistory'][] = ['role' => 'assistant', 'content' => $cachedQuery['question']];
                if (($intent['ResponseToQualification'] ?? false) !== true) {
                    \Illuminate\Support\Facades\Cache::forget('qual_query_' . request()->ip());
                }
            }

            // 1. Dynamic Extraction: If the user provided info, extract it FIRST (so state is updated)
            if (($intent['userProvidedInfo'] ?? false) === true) {
                $this->extractDynamicInformation($message, $this->chatContext, $this->businessContext);
            }

            // 2. Handle the AI's Reply based on the intent
            if (($intent['ResponseToQualification'] ?? false) === true) {
                $intentName = "ResponseToQualification";
                $result = $this->handleQualificationReply($message, $this->chatContext['infoHistory'] ?? []);
            } elseif (($intent['userRequestInfo'] ?? false) === true || ($intent['topicChange'] ?? false) === true) {
                $intentName = "userRequestInfo";
                $result = $this->handleUserRequestInfo($message, $this->chatContext['infoHistory'] ?? [], $intent);
            } else {
                // If they ONLY provided info and didn't ask anything, acknowledge it!
                if (($intent['userProvidedInfo'] ?? false) === true && ($intent['ResponseToQualification'] ?? false) !== true) {
                    $intentName = "infoAcknowledgement";
                    $result = ['status' => true, 'data' => ['reply' => "Okay, note kar liya!"]];
                } else {
                    $intentName = "fallback"; // Improved fallback handler
                    $result = $this->handleFallback($message, $this->chatContext['infoHistory'] ?? []);
                }
            }

            $this->chatContext['infoHistory'][] = ['role' => 'user', 'content' => $message];
            if (isset($result['data']['reply'])) {
                $this->chatContext['infoHistory'][] = ['role' => 'assistant', 'content' => $result['data']['reply']];
            }

        }

        if (isset($result['data'])) {
            $result['data']['intent'] = $intentName;
            // Clean up the transient 'reopened' marker before serializing context_state.
            // 'reopened' is an in-memory execution signal only — only 'closing' or null
            // should ever survive into the persisted state the frontend stores.
            if (($this->chatContext['chat_status'] ?? null) === 'reopened') {
                $this->chatContext['chat_status'] = null;
            }
            $result['data']['context_state'] = [
                'businessContext' => $this->businessContext,
                'chatContext' => $this->chatContext
            ];
        }

        return $result;
    }

    private function setState(array $context)
    {
        $this->businessId = $context['business_id'] ?? null;
        $state = $context['context_state'] ?? null;

        // 1. Sync dynamic chat history from frontend
        if (isset($state['chatContext']) && is_array($state['chatContext'])) {
            $this->chatContext = array_merge($this->chatContext, $state['chatContext']);
            unset($this->chatContext['casualChatHistory']);
            unset($this->chatContext['abusiveChatHistory']);
        }

        // 2. Sync static business context from frontend OR fetch from DB if missing
        // Temporarily forcing DB load to avoid localStorage cache issues during testing
        $this->loadBusinessContextFromDB($context);
        
        if (isset($state['businessContext']) && is_array($state['businessContext'])) {
            // Merge manually to prevent overwriting the entire backendData array
            if (isset($state['businessContext']['contextKey'])) {
                $this->businessContext['contextKey'] = $state['businessContext']['contextKey'];
            }
            // Keep the user's selected service from the frontend state instead of resetting to the DB default
            if (!empty($state['businessContext']['backendData']['UserServiceDemand'])) {
                $this->businessContext['backendData']['UserServiceDemand'] = $state['businessContext']['backendData']['UserServiceDemand'];
            }
            if (!empty($state['businessContext']['backendData']['UserServiceDemandId'])) {
                $this->businessContext['backendData']['UserServiceDemandId'] = $state['businessContext']['backendData']['UserServiceDemandId'];
            }
        }
    }

    private function loadBusinessContextFromDB(array $context)
    {
        if (!$this->businessId) return;

        $business = \App\Models\User::with('category')->where('role', 'business')->where('business_id', $this->businessId)->first();

        if ($business) {
            $this->businessContext['backendData']['bio'] = $business->bio ?? 'our services';
            $this->businessContext['backendData']['niche'] = $business->category ? $business->category->name : 'general';

            $this->businessContext['backendData']['services'] = \App\Models\Service::where('business_id', $this->businessId)
                ->where('status', 'active')
                ->pluck('name')
                ->toArray();

            $this->businessContext['backendData']['attributes'] = \App\Models\AttributeDefinition::where('business_id', $this->businessId)
                ->where('status', 'active')
                ->pluck('name')
                ->toArray();

            // Check for target service (UserServiceDemand) via Campaign Information
            $campaignLink = $context['campaign_link'] ?? null;
            $campaign = null;
            
            if ($campaignLink) {
                $campaign = \DB::table('campaign_information')
                    ->where('business_id', $this->businessId)
                    ->where('campaign_link', $campaignLink)
                    ->first();
            }

            if (!$campaign) {
                $campaign = \DB::table('campaign_information')
                    ->where('business_id', $this->businessId)
                    ->whereNotNull('service_id')
                    ->latest('created_at')
                    ->first();
            }

            if ($campaign && !empty($campaign->service_id)) {
                $service = \DB::table('services')->where('id', $campaign->service_id)->first();
                if ($service) {
                    $this->businessContext['backendData']['UserServiceDemand'] = $service->name;
                    $this->businessContext['backendData']['UserServiceDemandId'] = $campaign->service_id;
                }
            }
        }
    }

    private function initializeLeadQualificationState()
    {
        if (!isset($this->chatContext['leadQualificationState'])) {
            $this->chatContext['leadQualificationState'] = [];
        }

        $businessId = $this->businessId;
        $activeService = $this->businessContext['backendData']['UserServiceDemand'] ?? null;
        $activeServiceId = $this->businessContext['backendData']['UserServiceDemandId'] ?? null;

        // Ensure "Global Lead Qualification" exists
        if (!isset($this->chatContext['leadQualificationState']['Global'])) {
            $globalService = \DB::table('services')->where('name', 'Global Lead Qualification')->first();
            if ($globalService) {
                $qual = \DB::table('lead_qualifications')
                    ->where('business_id', $businessId)
                    ->where('service_id', $globalService->id)
                    ->first();
                
                if ($qual) {
                    $this->chatContext['leadQualificationState']['Global'] = [
                        'questions' => $qual->questions,
                        'data' => []
                    ];
                }
            }
        }

        // Ensure active service exists
        if ($activeService && $activeService !== 'None') {
            if (!isset($this->chatContext['leadQualificationState'][$activeService])) {
                // Try by ID first, fall back to name lookup
                $serviceId = $activeServiceId;
                if (!$serviceId) {
                    $svc = \DB::table('services')
                        ->where('business_id', $businessId)
                        ->where('name', $activeService)
                        ->first();
                    $serviceId = $svc->id ?? null;
                    if ($serviceId) {
                        $this->businessContext['backendData']['UserServiceDemandId'] = $serviceId;
                    }
                }

                if ($serviceId) {
                    $qual = \DB::table('lead_qualifications')
                        ->where('business_id', $businessId)
                        ->where('service_id', $serviceId)
                        ->first();

                    if ($qual) {
                        $this->chatContext['leadQualificationState'][$activeService] = [
                            'questions' => $qual->questions,
                            'data' => []
                        ];
                    }
                }
            }
        }
    }

    /**
     * Independent background extraction method.
     * Called from frontend after shadow question is answered.
     * Scans full infoHistory and extracts any answers to pending qualification questions.
     */
    public function extractLeadDataFromHistory(array $infoHistory, array $chatContext, array $businessContext): array
    {
        $this->chatContext    = $chatContext;
        $this->businessContext = $businessContext;

        $demandService = $businessContext['backendData']['UserServiceDemand'] ?? null;
        if (!$demandService || $demandService === 'None') {
            return $chatContext['leadQualificationState'] ?? [];
        }

        $leadState = $chatContext['leadQualificationState'] ?? [];
        $globalQuestions  = $leadState['Global']['questions'] ?? '';
        $serviceQuestions = $leadState[$demandService]['questions'] ?? '';

        $allQuestionsList = [];
        if (!empty($globalQuestions))  $allQuestionsList[] = $globalQuestions;
        if (!empty($serviceQuestions)) $allQuestionsList[] = $serviceQuestions;
        $allQuestions = implode(', ', $allQuestionsList);

        if (empty($allQuestions)) {
            return $chatContext['leadQualificationState'] ?? [];
        }

        $historyText = '';
        foreach ($infoHistory as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'AI';
            $historyText .= "{$role}: " . $msg['content'] . "\n";
        }

        $prompt = <<<PROMPT
        You are a data extraction assistant. Read the full chat history and extract answers to the pending qualification questions.

        Pending Questions (field=priority): {$allQuestions}

        Chat History:
        {$historyText}

        Task:
        1. Go through the chat history carefully.
        2. For each pending question field, check if the user has explicitly provided an answer at any point.
        3. DO NOT extract or return fields that haven't been discussed yet.
        4. ONLY if the user was asked about a field and they explicitly said they don't know, haven't decided, or denied/skipped — treat that as "Not decided yet" for that specific field.
        5. Only extract fields that are in the Pending Questions list.

        Return ONLY valid JSON:
        {
            "extractions": [
                {"field": "Budget", "value": "80k"}
            ]
        }
        If nothing found, return: {"extractions": []}
        PROMPT;

        $response = $this->callLLM($prompt, '', [], true, 0.1, 512);
        $extractions = $response['extractions'] ?? [];

        foreach ($extractions as $item) {
            if (!isset($item['field']) || !isset($item['value'])) continue;

            $field = $item['field'];
            $value = $item['value'];

            if (str_contains($globalQuestions, $field)) {
                $this->chatContext['leadQualificationState']['Global']['data'][] = [$field => $value];
                $this->chatContext['leadQualificationState']['Global']['questions'] =
                    trim(preg_replace('/' . preg_quote($field, '/') . '=\d+(,\s*)?/', '', $this->chatContext['leadQualificationState']['Global']['questions']), ', ');
            } elseif (isset($this->chatContext['leadQualificationState'][$demandService])) {
                $this->chatContext['leadQualificationState'][$demandService]['data'][] = [$field => $value];
                $this->chatContext['leadQualificationState'][$demandService]['questions'] =
                    trim(preg_replace('/' . preg_quote($field, '/') . '=\d+(,\s*)?/', '', $this->chatContext['leadQualificationState'][$demandService]['questions']), ', ');
            }
        }

        return $this->chatContext['leadQualificationState'];
    }
}
