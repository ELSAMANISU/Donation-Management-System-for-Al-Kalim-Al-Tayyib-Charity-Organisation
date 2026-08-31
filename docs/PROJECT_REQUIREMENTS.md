# Project Requirements

## 1. Purpose and first-version boundary

The Donation Management System supports Al-Kalim Al-Tayyib Charity Organisation in receiving simulated donations, reviewing help applications, publishing privacy-safe fundraising campaigns, recording aid delivery, and reporting outcomes.

The first version must use Laravel 12, PHP 8.2+, MySQL/MariaDB, Blade, Bootstrap, Tailwind where already used, and vanilla JavaScript. It must be bilingual in Arabic and English, responsive, accessible, secure, and compatible with the existing interface. The primary currency is Sudanese Pound (SDG / ج.س).

Real payment processing, BigQuery, email notifications, and administrator specialization by category are not part of the first version.

## 2. Actors and permissions

### Visitor

- View the homepage, organisation information, categories, campaigns, completed campaigns, statistics, and campaign details without signing in.
- Donate as a guest through the simulated Sandbox Checkout.

### User

- Has all visitor capabilities.
- Donate and view an account donation history.
- Download receipts for account donations.
- Submit and manage a help application, provide requested information, upload additional documents, communicate with administrators within the application, and appeal a rejection.
- Have no more than one open help application at a time.

### Admin

- Review all help applications and their private documents in the first version.
- Request information, approve or reject applications, and manage the later campaign and aid-delivery workflow.
- Manage campaigns, categories, transactions, aid delivery, reports, users, internal notifications, and relevant settings.
- Search users, inspect activity, disable accounts, and reactivate accounts.
- Must not be created through public registration.

### Super admin

- Has all admin permissions.
- Exclusively creates and manages administrator accounts.
- Must not be created through public registration.

## 3. Required modules

### Public site and categories

- Public pages expose organisation information, statistics, categories, active campaigns, completed campaigns, and campaign details.
- Categories appear as cards; selecting a category displays campaign cards generated dynamically from database records.
- A campaign card displays its image, title, summary, target amount, raised amount, remaining amount, progress, status, details action, and donation action.
- Featured and urgent campaigns may appear on the homepage.
- Completed campaigns remain publicly visible for transparency.

### Donations and Sandbox Checkout

- Allow both guest and registered donations without requiring guests to register.
- Collect only the minimum guest information needed for a receipt.
- Let donors display a public name, first name only, or remain anonymous.
- Simulate `initiated`, `pending`, `completed`, `failed`, `cancelled`, and `refunded` transactions.
- Assign every transaction a unique reference and provide a receipt that clearly states it is a sandbox simulation and no real money was processed.
- Give registered donors a donation history and downloadable receipts.
- Allow administrators to perform simulated refunds.

### Help applications

- Allow a signed-in user to create and save a draft application.
- Capture the applicant's full name, email, phone, address, date of birth, identity or passport information, requested amount, private story, preferred general receiving method, data-processing consent, and preferred public identity presentation. Exact validation and encryption are implemented in later scoped increments.
- Do not ask the applicant to select, submit, or control a category. An authorized administrator assigns an appropriate active category after reviewing the private application and supporting documents; client-supplied category identifiers must never control that assignment.
- Treat `preferred_receiving_method` as free text describing a general preference, such as bank transfer, cash, electronic wallet or card, a trusted person's account, or another suitable method. The user-facing label is "Preferred way to receive assistance / الطريقة المفضلة لاستلام المساعدة".
- Do not collect a bank name, account number, card number, wallet identifier, trusted-person account details, or any other actual transfer destination at application time. The preference is not a final transfer instruction.
- Allow a draft to be saved temporarily without documents, but require at least one supporting document before submission for review. An application may contain multiple supporting documents, such as medical reports and operation estimates, tuition invoices and admission letters, or other evidence appropriate to the need.
- Keep supporting documents private and visible only to the applicant owner and authorized administrators. Documents never become public campaign files automatically.
- Permit administrators to request missing information or additional documents. Move the application to `additional_information_required`, notify the applicant internally, and allow a private response and requested-document upload within the application. After the applicant responds, return the application to `under_review` and notify administrators internally.
- After rejection, require the applicant to choose either to appeal the rejected application or to close it and submit a new application. The applicant cannot have an open appeal and a new open application at the same time, and opening a new application permanently ends the option to appeal the previous rejected application.
- Detect possible duplicate or previous applications using verified identity information such as identity or passport number. Show reviewing administrators a warning and, when authorized, links to related applications; never reject automatically and require a documented administrator decision.
- Keep application messages, information requests, responses, and files private.
- Never expose the applicant's identity, contact details, private story, supporting documents, messages, receiving preference, actual receiving details, internal notes, or decision and appeal text publicly.

#### Applicant submission requirements

- An applicant may submit a Help Application only when the actor is an authenticated, active ordinary user with no required password change pending; the actor owns the application; the application is `draft`; `open_slot` is exactly true; `category_id` remains null; all required applicant information is complete and valid; complete identity information and a consistent current blind index and version exist; explicit consent is accepted; and at least one active, eligible supporting document is present with verified private managed bytes. Applicant-controlled Category input is prohibited.
- The following fields may be incomplete or null in a draft but are required and valid at submission: `full_name`, `email`, `phone`, `address`, `date_of_birth`, `identity_document_type`, `identity_issuing_country`, `identity_document_number`, `identity_blind_index`, `identity_blind_index_version`, `requested_amount`, `private_story`, `preferred_receiving_method`, and `public_identity_preference`. Retain the approved length, enum, date, exact-money, normalization, encryption, privacy, and identity rules. Do not invent a minimum applicant age, new phone syntax, an ISO country registry beyond the approved two-letter issuing-country format, or semantic regular-expression detection for bank, card, wallet, or account identifiers inside the free-text receiving preference.
- Submission changes exactly: `status` moves from `draft` to `pending`; `open_slot` remains true; `category_id` remains null; `submitted_at` is set to the transition time; `status_changed_at` is set to that same transition time; `updated_by` is set to the locked applicant; and the server records the approved consent version and consent time. Submission must not set review, decision, Category-assignment, Campaign, aid-delivery, or public-content state.
- The approved consent version is `help_application_v1`. Changing the approved text requires a new consent version.
- The exact approved English consent text is: “I confirm that the information I provided is accurate to the best of my knowledge. I consent to the Al-Kalimah Foundation securely storing and processing my private personal information, identity information, story, and supporting documents solely to review and manage my request for assistance. I understand that submitting the application locks the current draft, does not guarantee approval, and does not automatically publish my story, identity, or documents.”
- The exact approved Arabic consent text is: “أؤكد أن المعلومات التي قدمتها صحيحة حسب علمي، وأوافق على قيام مؤسسة الكلمة بحفظ ومعالجة بياناتي الشخصية الخاصة ومعلومات هويتي وقصتي ومستنداتي الداعمة بصورة آمنة، وذلك فقط لمراجعة طلب المساعدة وإدارته. وأفهم أن إرسال الطلب يؤدي إلى قفل المسودة الحالية، ولا يضمن قبول الطلب، ولا ينشر قصتي أو هويتي أو مستنداتي تلقائيًا.”
- Display the complete bilingual consent text immediately beside an unchecked consent control. Consent requires a deliberate affirmative action, is never inferred from saving a draft, and has a server-controlled version and timestamp. Consent text, version, and time are private compliance state and must not appear in public content or audit values.
- At least one submission document must belong to the locked Help Application; have `removed_at` set to null; have a controlled, non-null purpose; have a security status allowed by validated submission-eligibility configuration; have a canonical private path owned by the application and document; exist on the dedicated private disk; and match the stored byte size and SHA-256 digest when streamed. Missing, malformed, or unsafe eligibility configuration fails closed.
- The submission service locks the application before eligible document rows, preserving the existing document-mutation lock order, and inspects eligible candidates until at least one complete metadata-and-byte match is established. It never restores a removed document and never reads a foreign, traversal, public, malformed, or unmanaged path. Document metadata and parser or storage diagnostics must not appear in errors, logs, audits, sessions, URLs, notifications, or public content. Storage presence cannot be perfectly atomic against infrastructure loss, but applicant upload and removal races must serialize through the locked application.
- At submission, recompute the identity blind index and version from the locked encrypted identity values and compare the digest in constant time. Compare possible previous applications through keyed blind indexes without decrypting unrelated applications. A possible match never automatically rejects or approves an application.
- Create durable private administrator-review warning records for matching prior applications. A warning may contain only the internal application IDs or references required for authorized review, and duplicate rows for the same application and match pair are prohibited. Warnings and matching identifiers must never appear publicly or in general audit values, logs, applicant responses, sessions, URLs, or notifications. Administrator warning review and resolution UI belongs to a later bounded workflow.
- The first valid locked submission creates exactly one pending transition, one audit entry, one duplicate check, and the required internal notifications. A repeated or concurrent request after successful submission is a harmless idempotent no-op that redirects to the private application status or index page with a generic already-submitted message. It must not change timestamps or create another audit, duplicate-warning record, or notification, and it must not reveal private state to a non-owner.
- The submission audit action is exactly `help_application.submitted`. Its exact allowed old values are `status = draft` and `open_slot = true`; its exact allowed new values are `status = pending` and `open_slot = true`. Never include applicant or identity fields, blind indexes, consent text/version/time, Category, story, amount, receiving preference, document IDs/counts/purposes/statuses/metadata/paths/checksums, duplicate matches, notification recipients or payloads, request data, or transition timestamps. Audit failure before commit rolls back submission.
- On the first successful submission, notify the applicant internally that the private application is pending review and notify eligible active administrators that a new private Help Application requires review. Internal notification payloads contain only minimal privacy-safe routing and state information and exclude identity data, story, requested amount, receiving preference, document metadata, Category assumptions, and transfer details. Notification recipients exclude inactive or otherwise ineligible accounts.
- Internal notifications are database-backed and are not dispatched before the submission transaction commits. Notification failure after commit must not roll back or corrupt the submission, and repeated or concurrent submission must not create duplicate notifications. Notification storage, recipient rules, private routes and UI, read state, and after-commit behavior must exist before submission integrates notifications. No mail notification or queued email is sent in this version.
- After submission, the applicant cannot edit draft fields, upload documents, or remove documents. The private application index displays the pending state, while edit, upload, removal, and submit controls are absent. Private values and documents remain private, and submission creates no Campaign or public story automatically.

#### Supporting-document policy

- Accept exactly PDF, JPEG, and PNG supporting documents in the first version. Store JPEG files with the canonical `.jpg` extension; do not accept SVG, WebP, HEIC, GIF, or other image formats.
- Limit each file to 10 MiB, each Help Application to 10 active documents, and the combined size of its active documents to 50 MiB.
- Use only server-generated, application-owned paths on a dedicated private disk outside the public web root. Never create a public URL or symbolic link, use an original filename as a storage path, or expose a storage path.
- Resolve documents through non-sequential references, but never treat knowledge of a reference as authorization. Validate the application, document, and path ownership before every filesystem operation.
- Encrypt original display filenames. Authorized private interfaces may display a decrypted original filename, but downloads use a generic generated filename and are attachments by default.
- Immediately before or directly beside the download control for an `accepted_unscanned` document, display this conspicuous bilingual warning: "Security notice: This document passed structural validation but has not been scanned for malware. Download and open it only on a protected, fully updated device. / تنبيه أمني: اجتاز هذا المستند التحقق البنيوي، لكنه لم يُفحص من البرمجيات الخبيثة. قم بتنزيله وفتحه فقط على جهاز محمي ومحدّث بالكامل." Download must require deliberate user action and must never start automatically. The interface must never describe the document as safe, verified clean, virus-free, or malware-free; PDFs remain attachment-only and must not open automatically or render inline.
- Keep uploads immutable. Replacement creates a new document and removes the old one through an authorized operation. Applicants may remove documents only while the application is `draft`; removal after submission is reserved for a future authorized administrator workflow with a documented reason.
- Commit logical removal before best-effort post-commit deletion of managed bytes, preserve tombstone metadata for application history, and never read or delete a foreign, traversal, public, or unmanaged path. Leftover bytes do not restore a removed document.
- Use controlled purposes containing exactly `medical_report`, `cost_estimate`, `tuition_invoice`, `admission_letter`, and `other`. Purpose may be unset in a draft but must be selected before the document is eligible for submission.
- Represent future document security state with at least `pending`, `accepted_unscanned`, `clean`, and `rejected`. The first version has no antivirus or malware scanner; a structurally validated document accepted under this limitation is `accepted_unscanned`, never `clean` or described as malware-safe.
- Under the approved first-version policy, an active, present, fully validated `accepted_unscanned` document with a selected purpose may satisfy the minimum supporting-document requirement. This policy must remain replaceable when malware scanning is introduced.
- A future scanner may move `pending` or `accepted_unscanned` to `clean` after a successful scan or to `rejected` after a failed scan. Historical `accepted_unscanned` documents must never be silently reinterpreted as `clean`.

### Application review and campaign creation

- Let administrators view all applications and private supporting documents in the first version.
- Let administrators request more information, approve an application, or reject it with a reason.
- After approval, require an administrator to prepare separate, approved, privacy-safe public campaign content and choose the category, image, target amount, priority, featured state, and whether the campaign remains `draft` or is published as `active`. The applicant's private story is not the campaign's public story.
- Publishing changes the campaign to `active`, records `published_at`, and creates the public campaign card in the selected category.
- Preserve a private link between the campaign and its source application.

### Campaign management

- Support campaign creation and management without a mandatory expiry date.
- Allow administrators to pause or cancel a campaign only with a documented reason.
- Automatically mark a campaign funded when its target is reached.
- Stop accepting donations once a campaign is fully funded.
- Retain completed campaigns publicly and allow a privacy-safe impact update after aid delivery.

### Aid delivery

- Only after a campaign reaches 100% and becomes `funded`, allow an administrator to contact the beneficiary within the private system and request the actual receiving details needed for aid delivery.
- Collect and store actual bank, wallet, cash, trusted-person, or other transfer details encrypted within the aid-delivery workflow. The beneficiary may confirm a method different from the earlier general preference.
- Never expose actual receiving details publicly, copy them into public campaign content, or include them in audit payloads.
- Allow aid to be recorded as one payment or multiple instalments.
- For each delivery, store the amount, date, receiving method, internal transfer reference, private proof document, delivering administrator, and notes.
- Track total raised, total delivered, and remaining balance.
- Keep proof documents private.
- Require recorded delivery and a published impact update before completion.

### User, category, notification, audit, and settings administration

- Let administrators list and search users, view activity, disable accounts, and reactivate accounts.
- Reserve administrator-account management for super administrators.
- Store Arabic and English category names and descriptions, icon, image, display order, active or hidden state, soft-deletion state, and restoration state.
- Prevent permanent deletion of a category that has campaigns unless those campaigns are handled safely.
- Provide internal notifications for new application submission, submission confirmation, review started, information or additional-document requests, applicant responses or document uploads, approval or rejection, appeals, campaign conversion or activation, campaign funding, requests for actual receiving details, aid delivery, and completion, as well as relevant donation events.
- Audit important user and administrator actions.
- Provide settings for organisation identity, logo, contact details, currency, minimum donation, upload limits, and whether help applications are open.

## 4. Workflows

### Donation workflow

1. A visitor or registered user selects an eligible campaign and donation amount.
2. If the requested amount exceeds the remaining target, the system offers only the remaining required amount.
3. The donor provides the required receipt information and chooses their public identity display.
4. Sandbox Checkout records one idempotent transaction with a unique reference and a simulated outcome.
5. Only a completed transaction increases the campaign total. Duplicate submission must not create a second financial effect.
6. Reaching the target changes the campaign to funded and closes it to further donations.
7. Before aid delivery begins, a simulated refund reverses the completed contribution correctly within a database transaction and returns a `funded` campaign to `active` if its raised amount falls below the target. After aid delivery begins, ordinary refunds are prohibited and any exceptional correction is processed as a separately audited administrative adjustment.

### Applicant and beneficiary workflow

1. A signed-in user starts or resumes a draft while having no other open application.
2. The applicant supplies personal and identity details, a private story, requested amount, preferred general receiving method, consent, and public-identity choice. The applicant does not choose or submit a category and does not provide actual transfer details.
3. The applicant uploads one or more private supporting documents and submits the application. A draft may temporarily have no documents, but submission requires at least one active, present, fully validated document with a selected purpose that is eligible under the approved document-security policy. In the first version, this may be an `accepted_unscanned` document and must never be represented as `clean`. The system checks verified identity information for possible duplicate or previous applications and flags possible matches for administrative review.
4. An administrator reviews the private story and documents, assigns an appropriate active category, and considers any duplicate warning and authorized links to related applications. The administrator may request information, approve the application, or reject it with a documented reason; a duplicate warning never determines the decision automatically.
5. If information or documents are requested, the application moves to `additional_information_required`. The applicant receives an internal notification and responds privately within the application, including uploading requested documents; the application then returns to `under_review`, and administrators receive an internal notification.
6. After rejection, the applicant chooses either to appeal the rejected application or to close it and submit a new application. An open appeal and a new open application cannot coexist, and opening the new application makes the previous rejection ineligible for appeal.
7. An approved application may be converted into a privately linked campaign. An administrator prepares separate privacy-safe public content using the administrator-assigned category, and the campaign may then be published.

### Campaign and delivery workflow

1. An administrator prepares a draft campaign from an approved application.
2. Publishing sets the campaign status to `active`, stores the publication time separately in `published_at`, exposes its database-generated card, and accepts donations until paused, cancelled, or fully funded.
3. Funding completion changes its status automatically.
4. Only after the campaign is 100% funded, an administrator requests actual receiving details from the beneficiary within the private system. The encrypted details may differ from the earlier general preference and remain excluded from public content and audit payloads.
5. An administrator records one or more private, auditable aid deliveries.
6. After delivery, an administrator publishes a privacy-safe impact update.
7. The campaign can be completed only after delivery is recorded and the impact update is published.

## 5. Status lifecycles

### Application statuses

`draft` → `pending` → `under_review`

The canonical help application statuses are `draft`, `pending`, `under_review`, `additional_information_required`, `approved`, `rejected`, `appealed`, `converted_to_campaign`, `campaign_active`, `aid_delivery`, `completed`, and `closed`.

From `under_review`, an application may move to `additional_information_required`, `approved`, or `rejected`. Requested information returns the application to `under_review`. After `rejected`, the applicant may move the application to `appealed`, or move it to `closed` and create a new application, but not both. An open appeal and a new open application cannot coexist. Once a new application is opened, the previous rejected application cannot move to `appealed`. An approved application may progress through `converted_to_campaign`, `campaign_active`, `aid_delivery`, and `completed`.

Transitions must be authorized and auditable. Closing a rejected application makes the applicant eligible to create a new application.

### Campaign statuses

The canonical campaign statuses are `draft`, `active`, `paused`, `funded`, `aid_delivery`, `completed`, and `cancelled`.

- Publishing moves a campaign from `draft` to `active`; publication is represented by the `active` status and its time is stored separately in `published_at`.
- An authorized administrator may pause or cancel with a documented reason.
- Reaching the target automatically moves an active campaign to funded.
- Before aid delivery begins, if a refund reduces the raised amount below the target, the campaign automatically moves from `funded` back to `active`.
- Delivery activity moves a funded campaign into `aid_delivery`.
- Completion requires recorded delivery and an impact update.

### Transaction statuses

The canonical Sandbox transaction statuses are `initiated`, `pending`, `completed`, `failed`, `cancelled`, and `refunded`. Only `completed` transactions count toward raised totals. Before aid delivery begins, refunds must correctly reverse the applicable contribution and return a `funded` campaign to `active` automatically if its raised amount falls below the target. After aid delivery begins, ordinary simulated refunds are prohibited; any exceptional financial correction must be a separately audited administrative adjustment that preserves the rule that delivered aid never exceeds valid raised funds.

## 6. Business rules

- Use MySQL/MariaDB as the operational database and SDG / ج.س as the primary currency.
- Public campaign content must be prepared separately by an administrator and remain privacy-safe. The applicant's private story is not the campaign's public story, and identity, contact details, documents, messages, receiving preference, actual receiving details, internal notes, and decision or appeal text must never appear publicly.
- Applicants do not select or submit categories. An authorized administrator assigns an active category after reviewing the private application and documents, and client-supplied category identifiers must never control assignment.
- Draft applications may temporarily have no supporting documents, but submission requires at least one active, present, fully validated document with a selected purpose that is eligible under the approved security policy. Multiple documents are permitted; all remain private and never become campaign files automatically.
- The first version explicitly accepts structurally validated supporting documents without antivirus or malware scanning and records them as `accepted_unscanned`, never `clean`. This limitation must remain visible and replaceable by future scanning.
- At application time, collect only a general free-text receiving preference, not bank, account, card, wallet, trusted-person account, or other transfer-destination details. Request and encrypt actual receiving details only after the associated campaign is fully funded.
- A user can have only one open application at a time. An open appeal and a new open application cannot coexist, and opening a new application ends eligibility to appeal the previous rejected application.
- On submission, compare verified identity information such as identity or passport number to detect possible duplicate or previous applications. A match creates an administrator-visible warning and authorized links to related applications, but never an automatic rejection; the administrator must document the decision.
- Campaigns do not require an expiry date.
- A published campaign has status `active`, with its publication time stored separately in `published_at`.
- Campaign totals derive only from completed transactions, adjusted by valid refunds.
- Each transaction reference is unique; payment and refund actions are idempotent.
- Before aid delivery begins, a refund that reduces a funded campaign below its target automatically returns it from `funded` to `active`.
- After aid delivery begins, ordinary simulated refunds are prohibited. Exceptional financial corrections require separately audited administrative adjustments, and delivered aid must never exceed valid raised funds.
- A fully funded campaign accepts no further donations.
- A donation above the outstanding target is reduced by offering only the remaining amount.
- Aid may be delivered in one or multiple instalments, while raised, delivered, and remaining totals remain consistent.
- Category deletion must preserve or safely handle associated campaigns.
- Email is not required; first-version notifications are internal.

## 7. Security and quality requirements

- Enforce role-based authorization with middleware, Policies, and server-side checks.
- Use Form Request validation and retain CSRF protection and secure password hashing.
- Restrict Help Application documents to their applicant owner and authorized administrators. Store them only on a dedicated private disk outside the public web root, without a public URL or symbolic link, and keep them completely separate from Campaign files and public content.
- Use non-sequential document references without treating them as authorization. Validate application/document ownership and strict server-generated managed-path ownership before every filesystem operation, and never expose storage paths.
- Detect MIME type server-side and require exact extension-to-detected-MIME agreement. Do not trust browser-supplied MIME types or filename extensions. Reject malformed, truncated, mismatched, polyglot, or unsupported files wherever detectable.
- Accept supporting documents only as PDF, JPEG stored canonically as `.jpg`, or PNG, subject to a 10 MiB per-file limit, 10 active documents per application, and 50 MiB combined active size.
- For JPEG and PNG, require successful decoding, positive dimensions, maximum width and height of 8,000 pixels, and no more than 40,000,000 total decoded pixels. Reject malformed images and decompression-bomb candidates.
- For PDF, require `.pdf`, server-detected `application/pdf`, a valid PDF signature, and successful structural parsing by an approved parser. Limit documents to 100 pages and reject malformed, encrypted, or password-protected PDFs, embedded files, JavaScript or actions, launch actions, active forms, and other detectable active content. If the required structural safety conditions cannot be established, fail closed. Structural validation does not replace malware scanning.
- The first version has no antivirus or malware scanner. Record a document that passes all available validation as `accepted_unscanned`; never call or mark it malware-safe or `clean`. Reserve `clean` for a future successful malware scan.
- Encrypt original filenames and never use them as storage paths. Authorized private interfaces may display them, but downloads use generic generated filenames, default to attachment delivery, do not render arbitrary PDFs inline, and apply private `no-store` caching, `nosniff`, and restrictive response headers.
- Before an authorized user deliberately downloads an `accepted_unscanned` document, place this conspicuous warning immediately before or directly beside the download control: "Security notice: This document passed structural validation but has not been scanned for malware. Download and open it only on a protected, fully updated device. / تنبيه أمني: اجتاز هذا المستند التحقق البنيوي، لكنه لم يُفحص من البرمجيات الخبيثة. قم بتنزيله وفتحه فقط على جهاز محمي ومحدّث بالكامل." Never start the download automatically or describe the file as safe, verified clean, virus-free, or malware-free. Authorization, application/document ownership checks, dedicated private storage, generic download filenames, private `no-store` caching, `nosniff`, and restrictive response headers remain mandatory; first-version PDFs remain attachment-only and must neither open automatically nor render inline.
- Never place document bytes, original filenames, paths, hashes, MIME details, purpose, size, or request payloads in audit values.
- Treat document uploads as immutable. Commit logical removal first, delete only validated managed bytes after commit using best-effort cleanup, preserve tombstone metadata, and never restore removal merely because leftover bytes remain.
- Store actual receiving details encrypted and restrict them to the private aid-delivery workflow after campaign funding. Never place them in public content or audit payloads; when an authorized interface displays an identifier, show only a masked form.
- Never store full card numbers, CVV, or real payment details.
- Rate-limit sensitive actions.
- Use database transactions for financial state changes and idempotency controls for sandbox payments and refunds.
- Audit important user and administrator actions.
- Use soft deletion wherever recovery is required.
- Provide automated feature tests for critical workflows, including authorization, financial state changes, private documents, and lifecycle rules.
- Provide seeded demonstration accounts and data for the final presentation without exposing secrets.
- Back up the operational MySQL/MariaDB database, privately stored beneficiary documents, uploaded campaign images, and other required storage files regularly through a defined, recoverable process. Protect backup files from public access, and document the recovery procedure so it can be tested.
- Keep forms accessible and the interface responsive and bilingual.
- Develop incrementally, make focused changes, and avoid unrelated refactoring.

## 8. Reports

Administrators must be able to view and download relevant reports as PDF and CSV for:

- Total donations.
- Donations by period and category.
- Active, funded, completed, paused, and cancelled campaigns.
- Successful/completed, pending, failed, cancelled, and refunded transactions.
- Applications grouped by status.
- Total raised, delivered, and remaining.

## 9. Deferred and out-of-scope features

- Real payment gateways and processing of real funds.
- BigQuery or another operational database in place of MySQL/MariaDB.
- Email notifications.
- Administrator specialization or assignment by category. The authorization design must remain extensible so this can be added later.
- Antivirus and malware scanning for Help Application documents. The first version's explicit `accepted_unscanned` state and compensating controls apply until scanning is introduced; adding a scanner must preserve historical state honestly.
- Applicant removal of supporting documents after submission. Such removal requires a future authorized administrator workflow with a documented reason.
- Inline rendering of arbitrary supporting-document PDFs.

### Approved Help Application implementation sequence

1. Record the approved submission decisions in this requirements document.
2. Implement a separate internal-notification and identity-duplicate-warning data foundation.
3. Implement the applicant `draft` to `pending` submission using those foundations.
4. Implement administrator review workflows in later bounded increments.
5. Keep email notifications deferred.

## 10. Acceptance principles

- The first version implements only the approved features above and does not invent additional product scope.
- All public browsing, donation, application, review, campaign, refund, delivery, administration, and reporting rules behave consistently with their canonical machine-readable statuses, stated permissions, and lifecycles; campaign publication uses `active` with a separate `published_at` value.
- Sensitive data never appears in public content, private files require owner or authorized-administrator access, and no real payment credentials are collected or stored. Help Application evidence remains on a dedicated private disk and never becomes Campaign content or files.
- The first version explicitly distinguishes structurally validated `accepted_unscanned` evidence from future malware-scanned `clean` evidence. It never claims unscanned files are malware-safe, and its eligibility policy can be replaced when scanning is introduced.
- Financial totals and lifecycle transitions are transactional, idempotent where required, auditable, and covered by feature tests. Refunds can reactivate an underfunded campaign only before aid delivery; after delivery begins, corrections use separately audited administrative adjustments and delivered aid never exceeds valid raised funds.
- Rejected applicants must choose between appeal and a closed rejection followed by a new application; the two paths cannot remain open together, and a new application ends the prior appeal option.
- Duplicate detection based on verified identity information warns administrators and provides authorized related-application links without automatically rejecting an applicant; every decision remains documented and administrative.
- Regular database and required-file backups are protected from public access, and their documented recovery procedure is testable.
- Demonstration data supports the final presentation without weakening production-oriented security controls.
- Changes preserve the existing public interface unless a task explicitly authorizes UI changes.
