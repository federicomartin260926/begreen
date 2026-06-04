# AGENTS.md — begreenmyfriend

This repository is Docker-first. For Symfony app work, prefer the `app/` workflow and run commands inside Docker containers. Do not install project dependencies on the host.

## Working area

* The Symfony application lives in `app/`.
* The repository root is the deployment wrapper and docs layer.
* Prefer `make -C app ...` for app tasks unless you are explicitly changing the root deployment setup.

## Environment separation

* Keep local dev, Docker dev, and production separate and explicit.
* Do not mix host-only hacks into production config.
* Use the documented Docker compose flows.
* Keep production image-based, not bind-mount based.
* Do not change production deployment wiring unless explicitly requested.

## Frontend assets

* Source frontend code lives in `app/assets/`.
* The build system is Webpack Encore.
* Use the Docker/Makefile workflow for frontend builds.
* Internally, the canonical npm workflow is `npm ci` and `npm run build`, but project tasks should normally be executed through `make -C app assets-build`.
* `app/package-lock.json` is the authoritative lockfile for the active workflow.
* Avoid introducing Yarn-based commands or workflows unless the repo is intentionally migrated.
* Treat `app/public/build/` as generated output. Do not edit compiled assets by hand.
* After changing anything under `app/assets/`, or Twig/layouts that affect Encore entrypoints, rebuild assets in Docker with `make -C app assets-build` before validating the UI.
* If a browser shows stale frontend behavior after a Twig or asset change, assume the bundle is out of date first and rebuild before debugging application code.
* Do not load the same JS bundle twice in Twig. Backend layouts must use Encore tags once, not a mix of manual `<script src="...">` plus Encore.
* Keep shared JS initialization in `app/assets/app.js`.
* Keep page-specific behavior in Stimulus controllers under `app/assets/controllers/`.

## Twig and Stimulus

* Prefer thin Twig templates that only render markup and pass data to Stimulus through `data-*` attributes.
* Keep controller logic in Stimulus controllers, not inline script blocks.
* Make controllers idempotent on `connect()` and safe to initialize more than once.
* Reuse the shared DataTables controller for admin tables instead of creating one-off initializers.
* When styling UI changes, prefer Bootstrap utilities and components first; use custom CSS only when Bootstrap cannot express the needed layout or state clearly.

## DataTables

* The shared initializer is `app/assets/controllers/datatable_controller.js`.
* Use the local i18n JSON files in `app/assets/datatables/i18n/`.
* Prefer local bundled i18n over remote `language.url` loading.
* Keep table initialization centralized.
* Avoid duplicate DataTables bootstraps in templates.

## Doctrine

* In this phase, the official schema workflow is `doctrine:schema:update`.
* Do not introduce migrations as the required workflow unless explicitly requested.
* If `doctrine:schema:validate` fails on mapping, fix the entity mapping first.
* Do not spend time cleaning residual `dump-sql` drift unless it corresponds to a real missing column, broken mapping, or functional regression.

## Testing

* Add or adjust tests when business logic changes.
* Run PHPUnit inside Docker.
* Prefer a focused test subset first.
* Use `make -C app test` for the full suite only when broad validation is needed.
* Avoid adding broad or secondary tests that are not essential to the changed behavior.

## Documentation

* Update the closest README or docs file when workflows, assets, deployment behavior or functional contracts change.
* Keep docs aligned with the actual Docker and build flow used by the repo.
* Avoid documentation churn for small internal refactors that do not affect usage, operations or contracts.

## Sustainability domain boundary

* Keep the plan sustainability measures domain separate from the emission-factor / CO2 calculation domain.
* The measure catalog, measure admin UI, parser, importer and exporter must be generic across protocols.
* Treat the standard measure Excel template as the import/export format for any protocol.
* Do not expose client-side file version names such as `v23`, `PLANTILLA_PS_v23`, `BGFM` or `BGMF` as functional concepts in the application.
* If a legacy internal label appears in class names, commands or services, treat it as technical debt to remove or neutralize when touching that area.
* Protocol-specific names such as `Rodaje` may exist as protocol data, fixtures or user-visible protocol names, but not as infrastructure names for the generic measures import/export flow.
* Do not introduce protocol editions, catalog editions, plan snapshots or measure historical versioning unless explicitly requested later.

## CodeGraph

* CodeGraph is available for this repository and should be used for structural analysis before cross-file changes.
* Use CodeGraph especially when working on measures, protocols, importers, exporters, fixtures, forms, controllers and tests.
* Before refactors touching multiple files, check `codegraph_status`.
* If the index is stale or relevant files were added, renamed or deleted, run `codegraph init -i`.
* Prefer CodeGraph over broad grep/read loops for callers, callees, dependencies, impact analysis and flow tracing.

## Command execution policy

Codex is allowed to execute normal project-related commands without asking for confirmation each time.

This includes:

* Docker and Docker Compose commands.
* `make -C app ...` commands.
* Project scripts.
* Symfony console commands inside Docker.
* Doctrine schema validation/update commands inside Docker.
* PHPUnit commands inside Docker.
* Frontend build commands through the Makefile/Docker workflow.
* HTTP requests for local testing.
* File operations inside this repository.
* Git inspection commands such as `git status`, `git diff` and `git log`.

Conditions:

* Commands must stay within this repository and its services.
* Do not execute destructive operations unless explicitly required.
* Avoid commands affecting the global host system.
* Do not install system-wide dependencies on the host.
* Prefer reproducible commands defined in the project through Docker, Makefile or scripts.

If a command is potentially destructive, such as deleting data or resetting the database, explain it briefly before executing.

## Project-specific rules

* Always use Docker/Makefile flows from the project root or `app/` as appropriate.
* Prefer `make -C app ...` over raw container commands when a target exists.
* Do not assume services run on default ports without checking configuration.
* Do not modify compiled frontend assets manually.
* Do not introduce Yarn unless the project is intentionally migrated away from npm/package-lock.
* Do not change the Doctrine schema workflow from `schema:update` to migrations unless explicitly requested.
* Do not generalize sustainability/measure concepts into versioned catalogs unless explicitly requested.
* Keep changes small, focused and aligned with the existing Symfony structure.

## Definition of done

A change is complete when:

* The implementation is minimal and aligned with the existing architecture.
* Relevant syntax/configuration has been validated.
* Focused tests have been run when business logic changed.
* Frontend assets have been rebuilt when assets, Encore entrypoints or relevant Twig layouts changed.
* `git diff` has been reviewed.
* Documentation has been updated only if workflows, deployment behavior, assets or functional contracts changed.
* Any skipped validation is reported explicitly with the reason.
