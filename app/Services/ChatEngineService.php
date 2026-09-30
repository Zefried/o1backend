<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\Traits\ChatMethodHandler;

class ChatEngineService
{
    use ChatMethodHandler;

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
        'infoHistory' => [],
        'casualChatHistory' => [],
        'abusiveChatHistory' => []
    ];

    public function handle(string $message, array $chat = [], array $context = [])
    {
        $this->setState($context);
        $this->initializeLeadQualificationState();

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

            if (($intent['abusiveOrStupid'] ?? false) === true) {
                $intentName = "abusiveOrStupid";
                $result = $this->abusiveOrStupidHandler($message, $this->chatContext['abusiveChatHistory'] ?? []);
                
                $this->chatContext['abusiveChatHistory'][] = ['role' => 'user', 'content' => $message];
                if (isset($result['data']['reply'])) {
                    $this->chatContext['abusiveChatHistory'][] = ['role' => 'assistant', 'content' => $result['data']['reply']];
                }
                if (count($this->chatContext['abusiveChatHistory']) > 6) {
                    $this->chatContext['abusiveChatHistory'] = array_slice($this->chatContext['abusiveChatHistory'], -6);
                }

            } elseif (($intent['casualChat'] ?? false) === true) {
                $intentName = "casualChat";
                $result = $this->casualChat($message, $this->chatContext['casualChatHistory'] ?? []);

                $this->chatContext['casualChatHistory'][] = ['role' => 'user', 'content' => $message];
                if (isset($result['data']['reply'])) {
                    $this->chatContext['casualChatHistory'][] = ['role' => 'assistant', 'content' => $result['data']['reply']];
                }
                if (count($this->chatContext['casualChatHistory']) > 6) {
                    $this->chatContext['casualChatHistory'] = array_slice($this->chatContext['casualChatHistory'], -6);
                }

            } elseif (($intent['userRequestInfo'] ?? false) === true) {
                $intentName = "userRequestInfo";
                $result = $this->handleUserRequestInfo($message, $this->chatContext['infoHistory'] ?? []);

                $this->chatContext['infoHistory'][] = ['role' => 'user', 'content' => $message];
                if (isset($result['data']['reply'])) {
                    $this->chatContext['infoHistory'][] = ['role' => 'assistant', 'content' => $result['data']['reply']];
                }
                if (count($this->chatContext['infoHistory']) > 6) {
                    $this->chatContext['infoHistory'] = array_slice($this->chatContext['infoHistory'], -6);
                }

            } else {
                $intentName = "fallback"; // Improved fallback handler
                $result = $this->handleFallback($message, $this->chatContext['infoHistory'] ?? []);

                $this->chatContext['infoHistory'][] = ['role' => 'user', 'content' => $message];
                if (isset($result['data']['reply'])) {
                    $this->chatContext['infoHistory'][] = ['role' => 'assistant', 'content' => $result['data']['reply']];
                }
                if (count($this->chatContext['infoHistory']) > 6) {
                    $this->chatContext['infoHistory'] = array_slice($this->chatContext['infoHistory'], -6);
                }
            }
        }

        if (isset($result['data'])) {
            $result['data']['intent'] = $intentName;
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
        if ($activeService && $activeServiceId) {
            if (!isset($this->chatContext['leadQualificationState'][$activeService])) {
                $qual = \DB::table('lead_qualifications')
                    ->where('business_id', $businessId)
                    ->where('service_id', $activeServiceId)
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

// class ChatEngineService_Old
// {
//     use ChatMethodHandler;
    
//     private string $businessBIO = '';
//     private string $businessNiche = '';
//     private array $businessServices = [];
//     private array $businessAttributes = [];
//     private ?string $businessId = null;
    
//     // Dynamic tracking during conversation
//     private array $serviceRequest = [];
//     private array $attributeRequest = [];
//     private array $infoHistory = [];
//     private ?string $activeService = null;
//     private ?string $activeAttribute = null;

//     public function handle(string $message, array $chat = [], array $context = [])
//     {
//         $this->setState($context);

//         // DEBUG: Return state directly to test if setState works
//         /*
//         return [
//             'status' => true,
//             'data' => [
//                 'reply' => "Debug State -> Niche: {$this->businessNiche} | Bio: {$this->businessBIO} | Services: " . count($this->businessServices) . " | ServiceRequest: " . json_encode($this->serviceRequest)
//             ],
//             'code' => 200,
//         ];
//         */

//         // Intercept initial "Hello" greeting (empty chat history)
//         if (trim(strtolower($message)) === 'hello' && empty($chat)) {
//             $result = $this->handleWelcome();
//         } else {
//             $intent = $this->checkIntent($message, $chat);

//             if (($intent['abusiveOrStupid'] ?? false) === true) {
//                 $result = $this->abusiveOrStupidHandler($message, $chat);
//             } elseif (($intent['casualChat'] ?? false) === true) {
//                 $result = $this->casualChat($message, $chat);
//             } elseif (($intent['userRequestInfo'] ?? false) === true) {
//                 $result = $this->handleUserRequest($message, $chat, $context);
                
//                 $this->infoHistory[] = ['role' => 'user', 'content' => $message];
//                 if (isset($result['data']['reply'])) {
//                     $this->infoHistory[] = ['role' => 'assistant', 'content' => $result['data']['reply']];
//                 }
//                 if (count($this->infoHistory) > 4) {
//                     $this->infoHistory = array_slice($this->infoHistory, -4);
//                 }
//             } else {
//                 // Fallback: If AI fails and returns all false, default to casualChat
//                 $result = $this->casualChat($message, $chat);
//             }
//         }

//         // Inject the context_state so frontend can persist it
//         if (isset($result['status']) && $result['status'] === true && isset($result['data'])) {
//             $result['data']['context_state'] = [
//                 'businessBIO' => $this->businessBIO,
//                 'businessNiche' => $this->businessNiche,
//                 'businessServices' => $this->businessServices,
//                 'businessAttributes' => $this->businessAttributes,
//                 'serviceRequest' => $this->serviceRequest,
//                 'attributeRequest' => $this->attributeRequest,
//                 'infoHistory' => $this->infoHistory,
//                 'activeService' => $this->activeService,
//                 'activeAttribute' => $this->activeAttribute,
//             ];
//         }

//         return $result;
//     }

//     // private function setState(array $context = [])
//     // {
//     //     $this->businessId = $context['business_id'] ?? null;

//     //     $state = $context['context_state'] ?? null;
//     //     if ($state && is_array($state) && !empty($state)) {
//     //         // Frontend provided the state, use it (skip DB queries)
//     //         $this->businessBIO = $state['businessBIO'] ?? 'our services';
//     //         $this->businessNiche = $state['businessNiche'] ?? 'general';
//     //         $this->businessServices = $state['businessServices'] ?? [];
//     //         $this->businessAttributes = $state['businessAttributes'] ?? [];
//     //         $this->serviceRequest = $state['serviceRequest'] ?? [];
//     //         $this->attributeRequest = $state['attributeRequest'] ?? [];
//     //         $this->infoHistory = $state['infoHistory'] ?? [];
//     //         $this->activeService = $state['activeService'] ?? null;
//     //         $this->activeAttribute = $state['activeAttribute'] ?? null;
//     //         return;
//     //     }

//     //     $businessId = $context['business_id'] ?? null;

//     //     if (!$businessId) {
//     //         $this->businessBIO = 'our services';
//     //         $this->businessNiche = 'general';
//     //         $this->businessServices = [];
//     //         $this->businessAttributes = [];
//     //         $this->serviceRequest = [];
//     //         $this->attributeRequest = [];
//     //         $this->infoHistory = [];
//     //         $this->activeService = null;
//     //         $this->activeAttribute = null;
//     //         return;
//     //     }

//     //     $business = \App\Models\User::with('category')->where('role', 'business')->where('business_id', $businessId)->first();

//     //     if ($business) {
//     //         $this->businessBIO = $business->bio ?? 'our services';
//     //         $this->businessNiche = $business->category ? $business->category->name : 'general';
            
//     //         $this->businessServices = \App\Models\Service::where('business_id', $businessId)
//     //             ->where('status', 'active')
//     //             ->pluck('name')
//     //             ->toArray();
                
//     //         $this->businessAttributes = \App\Models\AttributeDefinition::where('business_id', $businessId)
//     //             ->where('status', 'active')
//     //             ->pluck('name')
//     //             ->toArray();

//     //         // Check if there is a target service requested via Campaign Information
//     //         $this->serviceRequest = [];
            
//     //         // Get the most recent campaign for this business that has a service
//     //         $campaign = \App\Models\CampaignInformation::with('service')
//     //             ->where('business_id', $businessId)
//     //             ->whereNotNull('service_id')
//     //             ->latest()
//     //             ->first();

//     //         if ($campaign && $campaign->service) {
//     //             $this->serviceRequest[] = $campaign->service->name;
//     //         }

//     //     } else {
//     //         $this->businessBIO = 'our services';
//     //         $this->businessNiche = 'general';
//     //         $this->businessServices = [];
//     //         $this->businessAttributes = [];
//     //         $this->serviceRequest = [];
//     //         $this->attributeRequest = [];
//     //         $this->infoHistory = [];
//     //         $this->activeService = null;
//     //         $this->activeAttribute = null;
//     //     }
//     // }


// }
