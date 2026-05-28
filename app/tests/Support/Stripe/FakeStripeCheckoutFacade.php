<?php

namespace App\Tests\Support\Stripe;

final class FakeStripeCheckoutFacade
{
    public FakeStripeCheckoutSessions $sessions;

    public function __construct(?FakeStripeCheckoutSessions $sessions = null)
    {
        $this->sessions = $sessions ?? new FakeStripeCheckoutSessions();
    }
}
