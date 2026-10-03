<?php

use App\Services\ChatEngineService;
use Illuminate\Support\Facades\Http;

function fakePricingDecision(array $decision): array
{
    return [
        'choices' => [
            [
                'message' => [
                    'content' => json_encode($decision),
                ],
            ],
        ],
    ];
}

it('returns an explicitly stated price without deriving it from the service', function () {
    Http::fake([
        'https://api.groq.com/*' => Http::response(fakePricingDecision([
            'action' => 'direct',
            'answer' => 'Is project ka estimated price ₹1,80,000 hai.',
            'calculation' => null,
            'reason' => null,
        ])),
    ]);

    $result = app(ChatEngineService::class)->handlePricing(
        'Is project ka estimated price kitna hai?',
        'Modular Kitchen',
        'estimated_price',
        true,
        (object) ['context' => 'estimated_price: ₹1,80,000', 'prompt' => '']
    );

    expect($result['data']['pricing_action'])->toBe('direct')
        ->and($result['data']['reply'])->toBe('Is project ka estimated price ₹1,80,000 hai.');
});

it('performs a calculation in PHP only when the rule and operands are provided', function () {
    Http::fake([
        'https://api.groq.com/*' => Http::response(fakePricingDecision([
            'action' => 'calculate',
            'answer' => null,
            'calculation' => [
                'operation' => 'multiply',
                'operands' => [20, 500],
                'rule_quote' => 'Total = area x rate.',
                'result_unit' => '₹',
            ],
            'reason' => null,
        ])),
    ]);

    $result = app(ChatEngineService::class)->handlePricing(
        '20 sq ft ka total price batao',
        'Interior Design',
        'estimated_price',
        true,
        (object) [
            'context' => 'Rate is ₹500 per sq ft. Total = area x rate.',
            'prompt' => '',
        ]
    );

    expect($result['data']['pricing_action'])->toBe('calculate')
        ->and($result['data']['pricing_decision']['calculation']['result'])->toBe(10000.0)
        ->and($result['data']['reply'])->toBe('₹ 10,000');
});

it('declines calculations whose required rule is not present in the pricing information', function () {
    Http::fake([
        'https://api.groq.com/*' => Http::response(fakePricingDecision([
            'action' => 'calculate',
            'answer' => null,
            'calculation' => [
                'operation' => 'multiply',
                'operands' => [20, 500],
                'rule_quote' => 'Multiply area by rate.',
                'result_unit' => '₹',
            ],
            'reason' => null,
        ])),
    ]);

    $result = app(ChatEngineService::class)->handlePricing(
        '20 sq ft ka total price batao',
        'Interior Design',
        'estimated_price',
        true,
        (object) [
            'context' => 'Rate is ₹500 per sq ft.',
            'prompt' => '',
        ]
    );

    expect($result['data']['pricing_action'])->toBe('insufficient_context')
        ->and($result['data']['reply'])->toBe('Is pricing ke exact details abhi available nahi hain.');
});
