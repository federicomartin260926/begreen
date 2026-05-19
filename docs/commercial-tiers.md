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

The plan review and PDF already consume the v23 catalog with support for multiple departments, ODS, impact areas, triple balance axes and prioritized verification sources. The export flows now expose a practical MVP:

- Basic keeps only the unified PDF.
- Standard adds grouped PDF by departments.
- Pro adds grouped PDF/Excel by categories, departments, impact areas, triple balance and ODS.

## Blocked features

The UI may show disabled placeholders for features that are not yet implemented:

- grouped exports for plans outside the allowed tier
- custom comments
- internal notes
- responsibles
- checklist
- custom measures
- branding

## Payment

- Payment is not implemented yet.
- PayPal, Stripe, invoicing and checkout remain for a later phase.
