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
- `standard`: scores `5`, `4`, `3`, unlimited evidences, watermark disabled.
- `pro`: all scores `5` to `1`, unlimited evidences, watermark disabled.

The plan review and PDF already consume the v23 catalog with basic support for multiple departments, ODS, impact areas, triple balance axes and prioritized verification sources. The advanced export flows are still placeholders.

## Blocked features

The UI may show disabled placeholders for features that are not yet implemented:

- departmental PDF
- advanced exports
- custom comments
- internal notes
- responsibles
- checklist
- custom measures
- branding

## Payment

- Payment is not implemented yet.
- PayPal, Stripe, invoicing and checkout remain for a later phase.
