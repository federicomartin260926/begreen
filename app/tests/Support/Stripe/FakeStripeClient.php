<?php

namespace App\Tests\Support\Stripe;

use Stripe\StripeClient;

final class FakeStripeClient extends StripeClient
{
    public FakeStripeCheckoutFacade $checkout;
    public FakeStripeInvoicesFacade $invoices;

    public function __construct(?FakeStripeCheckoutFacade $checkout = null, ?FakeStripeInvoicesFacade $invoices = null)
    {
        $this->checkout = $checkout ?? new FakeStripeCheckoutFacade();
        $this->invoices = $invoices ?? new FakeStripeInvoicesFacade();
    }

    public function __get($name)
    {
        return match ($name) {
            'checkout' => $this->checkout,
            'invoices' => $this->invoices,
            default => null,
        };
    }
}
