<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\Traits\ChatMethodHandler;

class ChatEngineService
{
    use ChatMethodHandler;
    
    private string $businessBIO = '';
    private string $businessNiche = '';
    private array $businessServices = [];
    private array $businessAttributes = [];
    
    // Dynamic tracking during conversation
    private array $serviceRequest = [];
    private array $attributeRequest = [];

    public function handle(string $message, array $chat = [], array $context = [])
    {
        $this->setState($context);

        // DEBUG: Return state directly to test if setState works
        /*
        return [
            'status' => true,
            'data' => [
                'reply' => "Debug State -> Niche: {$this->businessNiche} | Bio: {$this->businessBIO} | Services: " . count($this->businessServices) . " | ServiceRequest: " . json_encode($this->serviceRequest)
            ],
            'code' => 200,
        ];
        */

        // Intercept initial "Hello" greeting (empty chat history)
        if (trim(strtolower($message)) === 'hello' && empty($chat)) {
            return $this->handleWelcome();
        }

        $intent = $this->checkIntent($message, $chat);

        if (($intent['abusiveOrStupid'] ?? false) === true) {
            return $this->abusiveOrStupidHandler($message, $chat);
        }

        if (($intent['casualChat'] ?? false) === true) {
            return $this->casualChat($message, $chat);
        }

        if (($intent['userRequestInfo'] ?? false) === true) {
            return $this->handleUserRequest($message, $chat, $context);
        }

        // Fallback: If AI fails and returns all false, default to casualChat
        return $this->casualChat($message, $chat);
    }

    private function setState(array $context = [])
    {
        $businessId = $context['business_id'] ?? null;

        if (!$businessId) {
            $this->businessBIO = 'our services';
            $this->businessNiche = 'general';
            $this->businessServices = [];
            $this->businessAttributes = [];
            $this->serviceRequest = [];
            $this->attributeRequest = [];
            return;
        }

        $business = \App\Models\User::with('category')->where('role', 'business')->where('business_id', $businessId)->first();

        if ($business) {
            $this->businessBIO = $business->bio ?? 'our services';
            $this->businessNiche = $business->category ? $business->category->name : 'general';
            
            $this->businessServices = \App\Models\Service::where('business_id', $businessId)
                ->where('status', 'active')
                ->pluck('name')
                ->toArray();
                
            $this->businessAttributes = \App\Models\AttributeDefinition::where('business_id', $businessId)
                ->where('status', 'active')
                ->pluck('name')
                ->toArray();

            // Check if there is a target service requested via Campaign Information
            $this->serviceRequest = [];
            
            // Get the most recent campaign for this business that has a service
            $campaign = \App\Models\CampaignInformation::with('service')
                ->where('business_id', $businessId)
                ->whereNotNull('service_id')
                ->latest()
                ->first();

            if ($campaign && $campaign->service) {
                $this->serviceRequest[] = $campaign->service->name;
            }

        } else {
            $this->businessBIO = 'our services';
            $this->businessNiche = 'general';
            $this->businessServices = [];
            $this->businessAttributes = [];
            $this->serviceRequest = [];
        }
    }


}