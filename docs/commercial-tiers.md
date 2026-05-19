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

The plan review and PDF already consume the v23 catalog with support for multiple departments, ODS, impact areas, triple balance axes and prioritized verification sources. The review flow also exposes Pro-only collaborative fields and a basic validation summary. The export flows now expose a practical MVP:

- Basic keeps only the unified PDF.
- Standard adds grouped PDF by departments.
- Pro adds grouped PDF/Excel by categories, departments, impact areas, triple balance and ODS.
- Pro also enables visible comments, internal notes, responsibles per measure, validation summary and custom measures.

## Blocked features

The UI may show disabled placeholders for features that are not yet implemented:

- grouped exports for plans outside the allowed tier
- custom comments for non-Pro tiers
- internal notes for non-Pro tiers
- responsibles for non-Pro tiers
- validation summary for non-Pro tiers
- custom measures for non-Pro tiers
- branding

## Payment

- Payment is not implemented yet.
- PayPal, Stripe, invoicing and checkout remain for a later phase.
