<?php

namespace App\Tests\Support\Stripe;

final class FakeStripeCheckoutSessions
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $createCalls = [];

    /**
     * @var array<int, array{sessionId:string, options:array<string, mixed>}>
     */
    public array $retrieveCalls = [];

    public ?object $createReturn = null;
    public ?object $retrieveReturn = null;

    /**
     * @param array<string, mixed> $params
     */
    public function create(array $params): object
    {
        $this->createCalls[] = $params;

        if ($this->createReturn instanceof \stdClass) {
            return $this->createReturn;
        }

        return (object) [
            'id' => 'cs_test_default',
            'url' => 'https://stripe.test/checkout',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function retrieve(string $sessionId, array $options = []): object
    {
        $this->retrieveCalls[] = [
            'sessionId' => $sessionId,
            'options' => $options,
        ];

        if ($this->retrieveReturn instanceof \stdClass) {
            return $this->retrieveReturn;
        }

        return (object) [
            'id' => $sessionId,
            'url' => 'https://stripe.test/reused',
        ];
    }
}
