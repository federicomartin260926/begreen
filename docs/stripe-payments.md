# Stripe Payments

This project uses Stripe Checkout for one-time upgrades per `Project`.

## Scope

- `basic` remains free.
- `standard` is purchased once per project.
- `pro` is purchased once per project.
- Checkout price IDs live on `CommercialPlan` and are configured from Super Admin.
- `standard -> pro` uses the `CommercialPlan` data for the target tier and the price difference is derived from plan amounts.
- Begreen does not issue invoices itself in this phase.
- Stripe generates invoices and Begreen stores the relevant references/URLs.

## Environment variables

Required:

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
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
4. The success URL and the manual verification action reconcile the checkout against Stripe using the stored Checkout Session ID.
5. Begreen activates the target tier only when Stripe reports the session as paid.
6. Stripe webhooks remain pending as the durable production fallback.
7. Invoice links and invoice PDF links are stored on `ProjectSubscription` when they are available.
8. The target tier must have a `stripePriceId` configured on its `CommercialPlan`.

## Routes

- `POST /backend/project/{id}/subscription/checkout/{targetTier}`
- `POST /backend/project/{id}/subscription/confirm-pending`
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

- In local development, keep the same host in the browser when you open Stripe and when you return from it. Mixing `localhost` and `127.0.0.1` can drop the session cookie and make the success return look unrelated to the active backend project.
- The success URL now attempts a direct reconciliation against Stripe.
- The `Verificar pago en Stripe` action is the manual/admin fallback when the browser return is missing or the project remains in `pending_payment`.
- The webhook is still the source of truth for production-grade background confirmation.
- This is a one-time payment flow, not Stripe Billing.
