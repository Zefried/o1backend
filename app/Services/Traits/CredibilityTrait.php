<?php

namespace App\Services\Traits;

trait CredibilityTrait
{
    public function handleCredibilityRequest(string $message, array $chat = []): array
    {
        return [
            'status' => true,
            'data' => [
                'reply' => 'TEST: Credibility request successfully routed!'
            ],
            'code' => 200
        ];
    }
}
