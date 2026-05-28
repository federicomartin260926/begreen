<?php

namespace App\Tests\Support\Stripe;

final class FakeStripeInvoicesFacade
{
    /**
     * @var array<int, string>
     */
    public array $retrieveCalls = [];

    public ?object $retrieveReturn = null;

    public function retrieve(string $invoiceId): object
    {
        $this->retrieveCalls[] = $invoiceId;

        if ($this->retrieveReturn instanceof \stdClass) {
            return $this->retrieveReturn;
        }

        return (object) [
            'id' => $invoiceId,
        ];
    }
}
