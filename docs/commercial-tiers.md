# Commercial Tiers

This project uses an internal tier model per `Project` for the sustainability plan.

## Tiers

- `basic`
- `standard`
- `pro`

## Defaults

- New projects start as `basic`.
- If a project has no subscription yet, the feature gate resolves it as `basic`.
- Cloned projects also start as `basic`.

## Sustainability plan rules

- `basic`: scores `5` and `4`, maximum `10` evidences per project, PDF watermark enabled.
- `standard`: scores `5`, `4`, `3`, unlimited evidences, watermark disabled, grouped PDF by departments.
- `pro`: all scores `5` to `1`, unlimited evidences, watermark disabled, grouped PDF/Excel by categories, departments, impact areas, triple balance and ODS.
- The effective tier and watermark of the unified PDF are resolved against the commercial phase being downloaded; they are not hardcoded to the elaboration phase.
- Observations are available in all three tiers. In the unified PDF, `basic` renders them literally; `standard` and `pro` hide them from the visual document while keeping them available to the AI context when that report is generated.
- Project-company logos shown on the unified PDF cover are independent from the commercial `sustainability_plan.branding` feature.

## Which measures belong to each plan

- The inclusion rule is score-based, not based on fixed measure IDs.
- The current commercial scope is:
  - `basic`: only measures with score `4` or `5`
  - `standard`: measures with score `3`, `4` or `5`
  - `pro`: measures with score `1`, `2`, `3`, `4` or `5`
- This is applied on top of the official catalog of the active protocol and after excluding measures that are not visible for the current project/tier or are skipped by block questions.
- If a protocol needs a different commercial inclusion, that exception must be encoded in the measure/catalog resolver or in the protocol-specific catalog logic. There is no static measure-by-measure plan map in the documentation or UI.

The plan review and PDF already consume the measure catalog with support for multiple departments, ODS, impact areas, triple balance axes and prioritized verification sources. The review flow also exposes Pro-only collaborative fields and a basic validation summary. The export flows now expose a practical MVP:

- Basic keeps only the unified PDF.
- Standard adds grouped PDF by departments.
- Pro adds grouped PDF/Excel by categories, departments, impact areas, triple balance and ODS.
- Pro also enables visible comments, internal notes, responsibles per measure, validation summary and custom measures.
- The plan review also shows commitment levels based on official measure points:
  - planned commitment from measures marked to implement;
  - real commitment from measures already implemented;
  - levels are Seed, Plant, Tree, Forest and Jungle;
  - the calculation is based on official measure points, not number of measures;
  - custom measures are excluded from this level;
  - this is a motivational indicator, not formal certification.

## Payments

- Payment is handled by Stripe Checkout and is one-time per project.
- `basic` stays free.
- `standard` is activated with the Standard Stripe price.
- `pro` is activated with the Pro Stripe price.
- `standard -> pro` uses the dedicated Stripe price for the difference.
- Begreen stores Stripe checkout, payment and invoice references in `ProjectSubscription`.
- Invoice download and hosted invoice links come from Stripe; Begreen does not generate its own invoices in this phase.
- Webhook processing activates the tier only after Stripe confirms the payment.
- The success URL is informational only; it does not activate the tier by itself.
- Implementation details live in [docs/stripe-payments.md](stripe-payments.md).

## Blocked features

The UI may show disabled placeholders for features that are not yet implemented:

- grouped exports for plans outside the allowed tier
- custom comments for non-Pro tiers
- internal notes for non-Pro tiers
- responsibles for non-Pro tiers
- validation summary for non-Pro tiers
- custom measures for non-Pro tiers
- branding
