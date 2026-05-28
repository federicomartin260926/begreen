# Project AGENTS

This repository is Docker-first. For Symfony app work, prefer the `app/` workflow and run commands inside Docker containers. Do not install project dependencies on the host.

## Working area

- The Symfony application lives in `app/`.
- The repository root is the deployment wrapper and docs layer.
- Prefer `make -C app ...` for app tasks unless you are explicitly changing the root deployment setup.

## Environment separation

- Keep local dev, Docker dev, and production separate and explicit.
- Do not mix host-only hacks into production config.
- Use the documented Docker compose flows and keep prod image-based, not bind-mount based.

## Frontend assets

- Source frontend code lives in `app/assets/`.
- The build system is Webpack Encore.
- Use `npm ci` and `npm run build` as the canonical frontend workflow.
- `app/package-lock.json` is the authoritative lockfile for the active workflow.
- Avoid introducing Yarn-based commands or workflows unless the repo is intentionally migrated.
- Treat `app/public/build/` as generated output. Do not edit compiled assets by hand.
- After changing anything under `app/assets/`, or Twig/layouts that affect Encore entrypoints, rebuild assets in Docker with `make -C app assets-build` before validating the UI.
- If a browser shows stale frontend behavior after a Twig or asset change, assume the bundle is out of date first and rebuild before debugging application code.
- Do not load the same JS bundle twice in Twig. Backend layouts must use Encore tags once, not a mix of manual `<script src="...">` plus Encore.
- Keep shared JS initialization in `app/assets/app.js`.
- Keep page-specific behavior in Stimulus controllers under `app/assets/controllers/`.

## Twig and Stimulus

- Prefer thin Twig templates that only render markup and pass data to Stimulus through `data-*` attributes.
- Keep controller logic in Stimulus controllers, not inline script blocks.
- Make controllers idempotent on `connect()` and safe to initialize more than once.
- Reuse the shared DataTables controller for admin tables instead of creating one-off initializers.

## DataTables

- The shared initializer is `app/assets/controllers/datatable_controller.js`.
- Use the local i18n JSON files in `app/assets/datatables/i18n/`.
- Prefer local bundled i18n over remote `language.url` loading.
- Keep table initialization centralized; avoid duplicate DataTables bootstraps in templates.

## Doctrine

- In this phase, the official schema workflow is `doctrine:schema:update`.
- Do not introduce migrations as the required workflow unless explicitly requested.
- If `doctrine:schema:validate` fails on mapping, fix the entity mapping first.
- Do not spend time cleaning residual `dump-sql` drift unless it corresponds to a real missing column, broken mapping, or functional regression.

## Testing

- Add or adjust tests when business logic changes.
- Run PHPUnit inside Docker.
- Prefer a focused test subset first; use `make -C app test` for the full suite.

## Documentation

- Update the closest README or docs file when workflows, assets, or deployment behavior change.
- Keep docs aligned with the actual Docker and build flow used by the repo.

## Sustainability domain boundary

- Keep the plan sustainability measures domain separate from the emission-factor / CO2 calculation domain.
- Treat the standard measure Excel template as the import/export format for any protocol. If a legacy internal label appears in class names or command names, treat it as technical baggage only, not as a functional edition of the catalog.
- Do not introduce protocol editions, catalog editions, plan snapshots, or measure historical versioning unless the task explicitly asks for them later.
