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

## Secrets and deployment safety

* Never hard-code, commit, echo or log API keys, tokens, passwords or credentials.
* Secrets belong in the existing environment/configuration mechanism and must stay outside Git.
* When inspecting configuration, avoid printing secret values; verify presence/configuration without exposing credentials.
* DEV is the default deployment and validation target unless the task explicitly authorizes another environment.
* Never deploy to production or change production deployment/configuration implicitly.
* Treat any production-affecting action as a separate explicit authorization boundary.

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
* While the project remains in development, schema changes may assume fixtures can be regenerated and existing development data can be discarded.

## Testing and validation economy

* Treat tests as a risk-based tool, not as a mandatory step for every change.
* Add or adjust tests only when the change affects business logic, data integrity, permissions, payments, security, state transitions, calculations, persistence, imports/exports or another core contract.
* For minor presentation, copy, spacing, Bootstrap, Twig-only or purely visual changes, do not add automated tests by default. Validate manually in the affected screen when needed.
* Do not add controller/render tests merely to assert labels, translated text, CSS classes, layout details, badges, modal content or other low-risk presentation output unless the view itself contains critical branching or authorization behavior.
* Run the smallest relevant PHPUnit subset inside Docker. Prefer one focused test file or one focused test method over a broader group.
* Do not run the full suite by default. Use `make -C app test` only when the change is cross-cutting, affects shared infrastructure, alters core business rules, touches payments/security/permissions, or when focused tests cannot provide sufficient confidence.
* For very small low-risk changes, syntax/lint plus `git diff --check` may be sufficient and no PHPUnit run is required.
* Do not create tests that duplicate existing coverage without protecting a distinct regression or contract.
* Do not expand test fixtures, mocks or helper abstractions unless they materially reduce future maintenance or are necessary for the changed behavior.
* When a full suite is skipped, report that explicitly and state the focused validation performed instead.

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
* Before changing plan states, navigation, filters, charts, execution, incidents, checklist or verification behavior, consult `docs/sustainability-plan-status-model.md` and keep elaboration, implementation and verification as separate dimensions.

## AI and report-generation boundaries

* AI-generated narrative must only use data explicitly supplied by the application.
* Never invent, infer or complete sustainability metrics, emission values, states, factors or verification results that are not present in the authorized data context.
* Calculations remain deterministic application logic; do not move calculation rules into prompts.
* Manual edits such as text changes, photo metadata, selections or ordering must not call AI unless explicitly required by the feature.
* Preserve user-edited or manual content unless the user explicitly requests regeneration or replacement.
* Reuse the existing ReportDesign infrastructure for new report types. Do not create a second AI report designer, draft/approval workflow or parallel storage mechanism.
* Do not loosen ReportDesign HTML/CSS/security/geometry restrictions merely to satisfy a visual request. If an existing restriction blocks a requested capability, report it before widening the contract.
* ReportDesign must continue to protect physical A4 geometry, pagination, calculations and authorized data boundaries.
* Prefer controlled, explicit AI actions over automatic regeneration triggered by unrelated manual edits.

## CodeGraph and repository exploration

* CodeGraph is available for structural analysis, but use it only when the task genuinely spans multiple files or requires dependency/impact tracing.
* Prefer direct inspection of the named files for small, local changes.
* Use CodeGraph especially for cross-cutting work involving measures, protocols, importers, exporters, fixtures, forms, controllers, services and shared tests.
* Before substantial refactors, check `codegraph_status`.
* Run `codegraph init -i` only when a stale index would materially affect the task.
* Prefer one targeted CodeGraph query over broad grep/read loops, but do not perform exploratory analysis that is not needed to implement the requested change.

## Token and execution economy

* Optimize for the smallest reliable change and the shortest useful validation path.
* Read only the files needed for the current task. Do not inventory the repository or inspect adjacent modules without a concrete reason.
* Reuse established context, existing helpers and prior findings instead of rediscovering the same architecture.
* Prefer targeted searches with exact symbols, routes, services or file names over broad repository-wide searches.
* Do not run repeated audits, repeated full diffs, repeated test suites or repeated lints after every small edit. Validate once after the implementation stabilizes.
* Do not request, generate or summarize large outputs when a concise result is sufficient.
* Avoid speculative refactors, cleanup, renaming, abstraction or documentation outside the requested scope.
* Do not fix unrelated warnings, deprecations, schema drift or technical debt unless they block the requested task.
* Prefer existing Bootstrap, Symfony, Doctrine and project patterns over introducing new abstractions or dependencies.
* For Codex reasoning, start with the lowest effort sufficient for the task and increase only when architecture, payments, security, concurrency, data integrity or a persistent defect requires it.
* When reporting completion, summarize only changed behavior, relevant files, validation performed and remaining risks. Do not restate the full investigation.

## Scope and budget discipline

* Treat the requested task scope as a hard boundary.
* Do not turn an MVP into a generic platform, framework, editor or reusable subsystem unless explicitly requested.
* Prefer the smallest implementation that satisfies the confirmed user flow.
* Reuse existing entities, services, UI, report infrastructure and calculations before creating new abstractions.
* If a requested capability would materially increase implementation or QA effort, stop before implementing it and report:
  * why it exceeds the current scope;
  * the simplest in-scope alternative;
  * what would need separate approval.
* Do not implement "while we are here" improvements.
* When a task is split into estimated blocks, complete only the currently authorized block.
* Do not silently consume time from later blocks to absorb scope growth; simplify, defer or request approval instead.
* Existing audit inventories and technical maps are references, not automatic feature lists. Implement only the subset required by the approved MVP.

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
* Only validation proportional to the real risk has been executed.
* Syntax/lint is checked for modified code or configuration when applicable.
* Focused tests are run only when core logic or a meaningful regression risk changed.
* Frontend assets are rebuilt only when source assets or Encore entrypoints changed; Twig-only visual edits do not require an asset build unless they alter asset loading.
* `git diff --check` is run and the relevant diff is reviewed.
* Documentation is updated only if workflows, deployment behavior, assets or functional contracts changed.
* Any skipped validation is reported explicitly with the reason.

## Development-phase data compatibility

* This project is still in active development.
* Do not introduce new legacy fallbacks, compatibility aliases, transitional dual models or synchronization logic unless the task explicitly requires them.
* Existing compatibility behavior that is already intentional, tested or documented is part of the current contract and must not be removed unless the task explicitly authorizes it.
* For genuinely new development-only schema changes, prefer updating the Doctrine mapping, applying `doctrine:schema:update`, regenerating fixtures and adapting consumers rather than inventing unnecessary migration or compatibility layers.
* If it is unclear whether an existing compatibility path is intentional, preserve it and report the ambiguity rather than deleting it.
* Historical-data compatibility may be introduced when explicitly required by the task or by existing deployed/data contracts.
