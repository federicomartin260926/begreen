# Stripe Payments

This project uses Stripe Checkout for one-time upgrades per `Project`.

## Scope

- `basic` remains free.
- `standard` is purchased once per project.
- `pro` is purchased once per project.
- `standard -> pro` uses a dedicated checkout price for the difference.
- Begreen does not issue invoices itself in this phase.
- Stripe generates invoices and Begreen stores the relevant references/URLs.

## Environment variables

Required:

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_STANDARD_PRICE_ID`
- `STRIPE_PRO_PRICE_ID`
- `STRIPE_UPGRADE_STANDARD_TO_PRO_PRICE_ID`
- `STRIPE_SUCCESS_URL`
- `STRIPE_CANCEL_URL`

The success/cancel URLs should include the `{PROJECT_ID}` placeholder. Stripe will replace `{CHECKOUT_SESSION_ID}` automatically.

Example:

```env
STRIPE_SUCCESS_URL="https://example.com/backend/project/{PROJECT_ID}/subscription/success?session_id={CHECKOUT_SESSION_ID}"
STRIPE_CANCEL_URL="https://example.com/backend/project/{PROJECT_ID}/subscription/cancel?session_id={CHECKOUT_SESSION_ID}"
```

## Flow

1. The user starts the checkout from the plan review or project view.
2. Begreen creates a Stripe Checkout Session in `payment` mode.
3. The project subscription is marked as `pending_payment`.
4. Stripe sends a webhook after payment completion.
5. Begreen activates the target tier only after the webhook confirms the payment.
6. Invoice links and invoice PDF links are stored on `ProjectSubscription`.

## Routes

- `POST /backend/project/{id}/subscription/checkout/{targetTier}`
- `GET /backend/project/{id}/subscription/success`
- `GET /backend/project/{id}/subscription/cancel`
- `POST /webhooks/stripe`

## Stored references

The MVP stores, when available:

- checkout session id
- payment intent id
- invoice id
- customer id
- hosted invoice URL
- invoice PDF URL
- payment reference
- paid amount and currency
- payment date

## Notes

- The success URL is informational only.
- The webhook is the source of truth for activating the tier.
- This is a one-time payment flow, not Stripe Billing.
