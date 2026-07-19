# Stripe Payments

This project uses Stripe Checkout for one-time upgrades per `Project` and commercial phase.

## Scope

- `basic` remains free.
- `standard` is purchased once per project and commercial phase.
- `pro` is purchased once per project and commercial phase.
- Checkout price IDs live on `CommercialPlan` and are configured from Super Admin.
- `standard -> pro` uses the `CommercialPlan` data for the target tier and the price difference is derived from plan amounts.
- Begreen does not issue its own invoices in this phase.
- Stripe is the official source of truth for payment and invoice data.
- `ProjectSubscription` stores the current plan/payment state for one `Project` + `CommercialPhase`.
- `ProjectBillingDocument` stores the billing documents associated with the project subscription, including the private local PDF copy when available.
- Checkout uses Stripe Price IDs directly. Product IDs are not stored in Begreen.

## Environment variables

Required:

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_SUCCESS_URL`
- `STRIPE_CANCEL_URL`

The success/cancel URLs should include `{PROJECT_ID}`, `{COMMERCIAL_PHASE}`, `{TARGET_TIER}` and `{CHECKOUT_SESSION_ID}` placeholders. Begreen replaces project, phase and target tier before creating the Checkout Session. Stripe replaces `{CHECKOUT_SESSION_ID}` on return.

Example:

```env
STRIPE_SUCCESS_URL="https://example.com/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/success/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}"
STRIPE_CANCEL_URL="https://example.com/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/cancel/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}"
```

## Flow

1. The user starts the checkout from the plan measures page, plan review page or billing view.
2. Begreen creates a Stripe Checkout Session in `payment` mode.
3. The selected phase subscription is marked as `pending_payment`.
4. Metadata includes `project_id`, `commercial_phase`, `current_tier`, `target_tier`, `commercial_plan_code`, and upgrade metadata when applicable.
5. The success URL and the manual verification action reconcile the checkout against Stripe using the stored Checkout Session ID and the requested commercial phase.
6. Stripe webhooks also require the phase metadata and only update the subscription for the matching project + phase.
7. When Stripe confirms the payment, Begreen activates the target tier on the matching `ProjectSubscription` and creates or updates a `ProjectBillingDocument` for that subscription.
8. If Stripe provides a PDF invoice URL, Begreen downloads a private copy under `var/private/stripe-invoices` and stores only the relative path in `ProjectBillingDocument.localPath`.
9. The billing view serves invoices and downloads only through protected Symfony routes scoped by commercial phase.
10. The target tier must have a `stripePriceId` configured on its `CommercialPlan`; the Standard -> Pro transition uses `stripeUpgradeFromStandardPriceId`.

## Routes

- `POST /backend/project/{id}/subscription/{phase}/checkout/{targetTier}`
- `POST /backend/project/{id}/subscription/{phase}/confirm-pending`
- `GET /backend/project/{id}/subscription/{phase}/success/{targetTier}`
- `GET /backend/project/{id}/subscription/{phase}/cancel/{targetTier}`
- `POST /backend/project/{id}/subscription/{phase}/cancel-pending/{targetTier}`
- `GET /backend/project/{id}/billing/{phase}`
- `GET /backend/project/{id}/billing/{phase}/document/{documentId}/view`
- `GET /backend/project/{id}/billing/{phase}/document/{documentId}/download`
- `POST /backend/project/{id}/billing/{phase}/document/{documentId}/sync`
- `POST /webhooks/stripe`

## Stored references

`ProjectSubscription` keeps the current payment state:

- tier
- phase
- status
- source
- paid amount and currency
- payment reference
- paid date
- current Stripe checkout session id
- current Stripe payment intent id
- current Stripe invoice id
- current Stripe customer id
- current hosted invoice URL
- current invoice PDF URL
- current payment status
- target tier while checkout is pending

`ProjectBillingDocument` keeps the document history/details:

- provider
- type
- status
- project subscription
- checkout session id
- payment intent id
- invoice id
- customer id
- payment reference
- amount and currency
- hosted invoice URL
- invoice PDF URL
- local relative path to the private PDF copy
- downloaded date
- issued date
- paid date

If Stripe does not create an invoice for a given `payment` checkout, Begreen keeps the payment confirmed and the billing view simply shows the document state that is available.

## Private PDF storage

- Local PDFs are stored outside `public/`.
- Base path: `%kernel.project_dir%/var/private/stripe-invoices`
- Example: `stripe-invoices/project-86/invoice-in_1TeY3xQbEObZty5pSPZt9bDw.pdf`
- The database stores only the relative path, never an absolute filesystem path.
- PDFs are served only through protected Symfony controllers.
- The private storage lives inside the `app_var` Docker volume, which persists `/app/var` across container restarts.
- The PHP container entrypoint prepares `/app/var/private/stripe-invoices` with `www-data:www-data` ownership and writable permissions on startup.
- If you need to repair permissions manually in local or production compose, run `make -C app prepare-private-storage` or `make -C app prepare-private-storage-prod`.
- Do not remove the `app_var` volume if you want to keep stored invoices and other runtime state under `var/`.
- The future Super Admin global billing view can read `ProjectBillingDocument` without changing the storage model.

## Notes

- In local development, keep the same host in the browser when you open Stripe and when you return from it. Mixing `localhost` and `127.0.0.1` can drop the session cookie and make the success return look unrelated to the active backend project.
- The success URL attempts a direct reconciliation against Stripe.
- The `Verificar pago en Stripe` / `Actualizar referencias desde Stripe` action is the manual/admin fallback when the browser return is missing or the project remains in `pending_payment`.
- The billing UI shows `Ver factura` only when a private local PDF exists, and `Descargar PDF` only when the local copy exists.
- The billing UI keeps Stripe technical identifiers collapsed by default.
- The Stripe `cancel_url` uses the GET return route `/subscription/{phase}/cancel/{targetTier}` and only informs the user that the payment was not completed.
- The local "Cancelar intento de pago" action uses the POST route `/subscription/{phase}/cancel-pending/{targetTier}` and clears only the pending subscription state for that phase.
- This is a one-time payment flow, not Stripe Billing.
