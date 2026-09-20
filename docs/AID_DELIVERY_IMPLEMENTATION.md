# Sandbox aid-delivery ledger implementation report

## Result and operational boundary

Implemented the approved minimal synthetic aid-delivery ledger after confirmed coordination. There is no real transfer, provider integration, upload, applicant mutation, refund, parent completion, or public impact publication in this increment. Existing Campaign publication permissions and expiry behavior remain unchanged.

The operational database was not migrated, seeded, or modified. Tests use isolated in-memory SQLite databases. No dependencies, builds, browsers, screenshots, network actions, staging, commits, pushes, or deployment were used. The pre-existing untracked `.claude/` directory was preserved.

## Migration (added, operationally unapplied)

`database/migrations/2026_09_17_000000_create_aid_delivery_ledger.php`

- Creates normalized `aid_deliveries`, `aid_delivery_transitions`, and `aid_delivery_proofs`, with UUID references and RESTRICT ownership/actor foreign keys.
- Adds nullable `delivery_reference` to the existing internal-notification outbox.
- Uses application-assigned DATETIME for every new ledger business time, without generated defaults or automatic updates.
- MySQL/MariaDB stores positive amounts as DECIMAL(18,2), with a positive SDG CHECK constraint. SQLite test storage uses text for money, following the existing Donation test-storage approach, to prevent NUMERIC affinity converting exact amounts to floats; SQLite triggers enforce positive SDG values.
- A stored generated coordination slot plus a unique index prevents a second unfinished instalment. Terminal rows produce NULL, permitting sequential completed instalments. Coordination and parent locks serialize service mutations.
- Unique entry keys, delivery/revision pairs, and proof delivery IDs prevent duplicate entries, revisions, and proofs.

## Authorization and lock order

`AidDeliveryPolicy` delegates only the established narrow coordination participant rules: an active assigned reviewer or active super-admin without a required password change administers delivery; an eligible owning ordinary applicant may read. Unassigned administrators, other applicants, guests, disabled accounts, wrong roles, and password-change accounts receive concealed 404 responses.

Every mutation re-fetches the actor and linked records inside its transaction. Lock order:

`HelpApplication -> Users ascending by ID -> Campaign -> Category -> Coordination -> existing Deliveries/Transitions/Proofs -> Audit/Outbox`

Succeeded donations are read under the shared Application/Campaign serialization boundary already used by donation settlement. Delivery never writes Donations or PaymentAttempts.

Historical confirmed reads validate participant authorization, ownership/linkage, and compatible parent states, independently from current mutation readiness. Inactive or soft-deleted surrounding category/campaign records do not remove authorized historical access. Confirmed receiving details are queried/decrypted separately only after authorization. New delivery mutations require active coherent surroundings, confirmed coordination, valid ordered lifecycle timestamps, compatible parent states, and fully reconciled funding.

## State, finance, and idempotency

- Start creates `in_progress`.
- `in_progress -> problem -> in_progress` supports a recorded problem and resumption.
- `in_progress -> simulated_delivered` is terminal and atomically generates exactly one sandbox proof.
- Problem explanations require 1–2000 nonblank UTF-8 characters and reject invalid/control-byte input. Other transitions accept no note.
- First start alone updates Campaign `funded -> aid_delivery` and HelpApplication `campaign_active -> aid_delivery` with the same timestamp. The application's open slot stays true. Parent funding/publication/private fields and unrelated timestamps are preserved; later instalments and transitions do not rewrite the first start.
- Funding authority is the exact Brick\Math sum of `succeeded` Donations. It must equal both Campaign.raised_amount and the fully funded target. The available assistance balance subtracts only `simulated_delivered` amounts. No floating-point arithmetic or DonationMoney::remaining() is used.
- A matching authorized start entry key replays as a no-op; a changed amount, actor, or coordination is rejected. Transition replays and stale revisions are rejected before writes. The generated unfinished slot also enforces the rule at database level.
- Forms have independent cryptographically random tokens, bound to session, actor, application, coordination, action, and where applicable delivery/revision. They expire after 30 minutes and are capped at 32 per session. Raw persistence keys are never exposed. Tokens and private inputs are never flashed.

## Private routes

All parameters below are UUID-constrained. Every route uses web/session/CSRF middleware, fresh account validation, authentication, role checks, dedicated service-policy authorization, and the indicated named throttle.

| Method | Route name | Path | Throttle |
| --- | --- | --- | --- |
| GET/HEAD | admin.aid-delivery.index | /admin/aid-delivery/{helpApplication}/{coordination} | aid-delivery-read |
| GET/HEAD | admin.aid-delivery.show | /admin/aid-delivery/{helpApplication}/{coordination}/{delivery} | aid-delivery-read |
| POST | admin.aid-delivery.start | /admin/aid-delivery/{helpApplication}/{coordination}/start | aid-delivery-start |
| POST | admin.aid-delivery.problem | /admin/aid-delivery/{helpApplication}/{coordination}/{delivery}/problem | aid-delivery-problem |
| POST | admin.aid-delivery.resume | /admin/aid-delivery/{helpApplication}/{coordination}/{delivery}/resume | aid-delivery-resume |
| POST | admin.aid-delivery.success | /admin/aid-delivery/{helpApplication}/{coordination}/{delivery}/success | aid-delivery-success |
| GET/HEAD | help-applications.aid-delivery.index | /help-applications/{helpApplication}/aid-delivery/{coordination} | aid-delivery-read |
| GET/HEAD | help-applications.aid-delivery.show | /help-applications/{helpApplication}/aid-delivery/{coordination}/{delivery} | aid-delivery-read |

Read limit: 30/minute. Each mutation has an independent 6/minute limit. Applicant routes expose no mutations. Private responses/errors use no-store/private, no-cache, no-referrer, nosniff, and noindex/nofollow. Errors are generic and bilingual. Queries on mutations, uploads, extra fields, malformed scalars/arrays/UTF-8, invalid decimals/currency, expired/wrong-bound tokens, and stale revisions are rejected.

## Encryption, proof, and notifications

- Receiving method remains the existing private plaintext enum. Receiving details remain only in their existing encrypted coordination column.
- Transition problem notes are encrypted, excluded from serialization, and displayed only to eligible administrators using escaped text. Applicants see read-only states, amounts, transitions, and proof references.
- Models reject mass assignment; visible allowlists exclude private values and loaded relationships. Transition/proof model updates and deletes are blocked by immutable model events. As with the existing architecture, direct SQL bypasses model events and is not an exposed application workflow.
- Proofs store only delivery linkage, immutable UUID, opaque sandbox reference, generator/version, and explicit time. Amount is resolved from the delivery; no files, receiving destination, identity, or external transaction identifiers are stored.
- Every private delivery/proof view prominently states that no real financial transaction or aid transfer occurred. Coordination and applicant/admin navigation retain history links during aid delivery.
- Parent audits contain status only; delivery audits contain state only. Actor identity is retained through private delivery/transition foreign keys, without name/contact audit snapshots.
- Events are `aid_delivery_started`, `aid_delivery_problem_recorded`, `aid_delivery_resumed`, and `aid_delivery_simulated_delivered`. Payloads contain exactly delivery UUID and canonical action.
- Deduplication is based on the immutable transition UUID. Events/intents are written transactionally; projection happens after commit and rechecks the owning applicant's eligibility. Retries preserve the original transition time for notification creation and cannot duplicate notifications. Reads create no events or notifications.
- Existing timestamp-correction behavior is preserved. Delivery tests verify that delayed/retried delivery events are not rewritten by the older coordination repair.

## Validation

Final full suite: **1,390 passed, 13,444 assertions** (149.22 seconds). Focused delivery suite: **68 passed, 557 assertions**, including three offline schema cases. Final delivery plus coordination run: **181 passed, 1,708 assertions**. Coordination/donation/notification/audit/publication regressions: **415 passed, 3,738 assertions**. These runs overlap; their totals are not additive. Pint and its final check passed; git diff --check passed. Route inspection found exactly the eight routes listed above. Static sensitive-data searches passed. Automated coverage includes schema/constraints, authorization, first-start parent preservation, exact money, sequential instalments, state transitions, replay/stale revisions, encrypted notes, immutable proofs, audit/outbox/proof rollback, delayed/retried projection, eligibility changes, private HTTP input validation, token isolation, navigation, historical access, headers, route middleware, throttles, and public isolation.

Offline schema compilation passes for MySQL 8.0.36 and MariaDB 10.6.23 / 10.11.8. No live operational database connection is made by these compilation tests.

Limitations: real concurrent MySQL/MariaDB lock contention and live DDL application remain manually unverified. Browser/visual QA was not run, as instructed. The proof is a private database-backed page section, not a PDF/download. Completion, impact publication, expiry conflicts, refunds, real transfers/providers/uploads, and applicant acknowledgements/disputes remain deferred.

## Exact files changed or added by this task

- `app/Enums/AidDeliveryAction.php`
- `app/Enums/AidDeliveryState.php`
- `app/Enums/InternalNotificationEventType.php`
- `app/Enums/InternalNotificationType.php`
- `app/Http/Controllers/Admin/CampaignController.php`
- `app/Http/Controllers/AidDeliveryController.php`
- `app/Http/Controllers/Applicant/HelpApplicationController.php`
- `app/Http/Middleware/EnsureCoordinationAccount.php`
- `app/Http/Middleware/PrivateCoordinationResponse.php`
- `app/Http/Requests/AidDeliveryRequest.php`
- `app/Models/AidDelivery.php`
- `app/Models/AidDeliveryProof.php`
- `app/Models/AidDeliveryTransition.php`
- `app/Models/AssistanceCoordination.php`
- `app/Policies/AidDeliveryPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AidDeliveryFormTokens.php`
- `app/Services/AidDeliveryInput.php`
- `app/Services/AidDeliveryNotifications.php`
- `app/Services/AidDeliveryService.php`
- `app/Services/AssistanceCoordinationService.php`
- `app/Services/InternalNotificationPayload.php`
- `app/Services/InternalNotificationProjector.php`
- `bootstrap/app.php`
- `database/migrations/2026_09_17_000000_create_aid_delivery_ledger.php`
- `docs/AID_DELIVERY_IMPLEMENTATION.md`
- `docs/PROJECT_REQUIREMENTS.md`
- `resources/views/admin/campaigns/index.blade.php`
- `resources/views/aid-delivery/show.blade.php`
- `resources/views/applicant/help-applications/index.blade.php`
- `resources/views/coordination/show.blade.php`
- `routes/web.php`
- `tests/Feature/Admin/HelpApplicationDecisionTest.php`
- `tests/Feature/AidDelivery/AidDeliverySchemaTest.php`
- `tests/Feature/AidDelivery/AidDeliveryTest.php`
- `tests/Feature/HelpApplication/HelpApplicationDraftManagementTest.php`
- `tests/Feature/Notification/InternalNotificationDataFoundationTest.php`

## Complete Git status at handoff

```text
 M app/Enums/InternalNotificationEventType.php
 M app/Enums/InternalNotificationType.php
 M app/Http/Controllers/Admin/CampaignController.php
 M app/Http/Controllers/Applicant/HelpApplicationController.php
 M app/Http/Middleware/EnsureCoordinationAccount.php
 M app/Http/Middleware/PrivateCoordinationResponse.php
 M app/Models/AssistanceCoordination.php
 M app/Providers/AppServiceProvider.php
 M app/Services/AssistanceCoordinationService.php
 M app/Services/InternalNotificationPayload.php
 M app/Services/InternalNotificationProjector.php
 M bootstrap/app.php
 M docs/PROJECT_REQUIREMENTS.md
 M resources/views/admin/campaigns/index.blade.php
 M resources/views/applicant/help-applications/index.blade.php
 M resources/views/coordination/show.blade.php
 M routes/web.php
 M tests/Feature/Admin/HelpApplicationDecisionTest.php
 M tests/Feature/HelpApplication/HelpApplicationDraftManagementTest.php
 M tests/Feature/Notification/InternalNotificationDataFoundationTest.php
?? .claude/
?? app/Enums/AidDeliveryAction.php
?? app/Enums/AidDeliveryState.php
?? app/Http/Controllers/AidDeliveryController.php
?? app/Http/Requests/AidDeliveryRequest.php
?? app/Models/AidDelivery.php
?? app/Models/AidDeliveryProof.php
?? app/Models/AidDeliveryTransition.php
?? app/Policies/AidDeliveryPolicy.php
?? app/Services/AidDeliveryFormTokens.php
?? app/Services/AidDeliveryInput.php
?? app/Services/AidDeliveryNotifications.php
?? app/Services/AidDeliveryService.php
?? database/migrations/2026_09_17_000000_create_aid_delivery_ledger.php
?? docs/AID_DELIVERY_IMPLEMENTATION.md
?? resources/views/aid-delivery/
?? tests/Feature/AidDelivery/
```

No files are staged. The .claude/ entry was already present before this task and remains untouched.
