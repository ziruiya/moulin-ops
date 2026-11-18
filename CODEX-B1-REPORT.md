# MoulinOps Batch 1 report

## Delivered

- Created a Laravel 13.11 project at the repository root while preserving the original `README.md` unchanged.
- Added PHP 8.5-FPM, Nginx, and MariaDB 11 services under the fixed Compose project name `moulinops`. The app is reachable at `http://localhost:8080`.
- Added GitHub Actions CI for install, migration, tests, PHPStan, Pint, and Composer audit.
- Added one business-schema migration and the requested four schema tests.

## Business table inventory (21)

`users`, `roles`, `role_user`; `assets`, `asset_components`, `asset_relationships`, `component_lifecycle_records`; `vulnerability_records`, `vulnerability_affected_ranges`, `vulnerability_component_assessments`; `update_plans`, `update_plan_assets`, `update_plan_components`, `update_checklist_items`, `change_approvals`; `incidents`, `incident_assets`, `incident_components`, `incident_update_plans`, `work_records`, `audit_events`.

No prohibited business tables were added. Laravel's three standard infrastructure tables remain in place.

## Implemented schema constraints

- `users.username` is unique. All user foreign keys use `ON DELETE RESTRICT`.
- `change_approvals` has `initiated_by`, `approver_id`, `executed_by` and a MariaDB CHECK preventing approver self-approval and approval by the executor.
- Component references in assessments, update plans, and incidents use composite foreign keys to `(asset_components.id, asset_id)`; `asset_components` has the matching unique key.
- Lifecycle records have `UNIQUE(component_id, verified_at)` and no `is_current` column.
- `audit_events` has `INDEX(auditable_type, auditable_id, created_at)`.
- `affected_version_expression` is `TEXT`.
- Unique business codes exist for assets, vulnerability records, update plans, incidents, and work records.
- The v2 fields `blocked_from_status`, `waiver_scope`, `resolution_note`, `resolution_verified_by`, and `resolution_verified_at` are present.
- CHECK constraints cover the implemented support, lifecycle, vulnerability match/handling/exploitation, incident category/severity/priority/status, checklist, work-result, and update-plan status values.

## Commands and results

Executed inside the `moulinops` Compose app container:

- `docker compose -p moulinops up -d --build` — app, web, and database started; DB healthy.
- `php artisan migrate:fresh --force` — passed from an empty MariaDB database.
- `php artisan test` — **6 passed, 27 assertions** (Laravel defaults and the new schema tests).
- `vendor/bin/phpstan analyse --memory-limit=1G` — passed, no errors.
- `vendor/bin/pint --test` — passed, 28 files.
- `composer audit` — passed, no security vulnerability advisories.
- HTTP request to `http://localhost:8080` — status 200.

Migration and test command output is preserved in `CODEX-B1-MIGRATION-OUTPUT.txt` and `CODEX-B1-TEST-OUTPUT.txt`.

## Not completed / review notes

- This batch is limited to schema and runtime scaffolding. It does not add models, policies, business workflows, seed data, or UI.
- Draftable UpdatePlan fields remain nullable so a plan can be completed progressively; workflow transition validation will need to enforce required values in the later business-logic batch.
- Enum fields in this batch have database CHECK constraints for their frozen values. Date precision, match/handling/exploitation, support/lifecycle, update-plan, checklist, incident, and work-record value sets are represented in the migration.
- No push was performed. Local commit status is recorded by the commit itself; deployment remains with the coordinator.

## Open questions

- None requiring a specification decision for this batch.
