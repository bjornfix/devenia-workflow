# Devenia Workflow

Run controlled AI-assisted content improvement and multilingual publishing workflows inside WordPress.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/devenia-workflow)](https://github.com/bjornfix/devenia-workflow/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0

**Stable tag:** 0.1.669

**License:** GPLv2 or later

**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Devenia Workflow gives WordPress operators a controlled workflow for improving source content, creating localized pages and posts, checking quality, recording review evidence, and publishing verified results.

Translations remain ordinary WordPress content. The plugin owns workflow state, source relationships, localized routes, validation, review gates, and publication evidence without replacing WordPress as the content system.

**Example:** "Translate every public article and keep the translations current." - The workflow inventories the site, projects every source-language obligation, creates bounded jobs, validates complete artifacts, and reports whether unresolved work remains.

## The Real Workflow

The normal path is:

1. install and activate the plugin
2. choose `source_only` or `multilingual` workflow mode
3. configure the language registry when multilingual publishing is enabled
4. build the authoritative Source Inventory
5. let an MCP-capable worker discover and process bounded Translation Jobs
6. review validation and quality evidence
7. publish approved WordPress content
8. use Exhaustion Proof to see what remains

The operator describes the outcome and reviews evidence. The workflow keeps source revisions, routes, jobs, validation, and publication state consistent.

## Why This Feels Different

Most translation plugins focus on generating text or proxying translated pages. Devenia Workflow focuses on the controlled publishing layer around the work:

- WordPress-native pages and posts
- complete source inventory before prioritization
- bounded translation and quality jobs
- localized route and internal-link validation
- source revision and stale-translation detection
- review and publication evidence
- source-only operation for sites that need content optimization without translation
- optional editor, theme, SEO, and presentation Adapters

That changes the experience from:

- `Generate some translations and hope the site is complete`

to:

- `Project every obligation, process bounded jobs, and prove what remains`

## Before vs After

### Before

- translation coverage is estimated from recent content or partial queues
- published translations can silently become stale
- route and link decisions are repeated manually
- quality checks depend on reviewer memory
- an empty queue can be mistaken for a complete website

### After

- every public source is represented in one authoritative inventory
- each target-language obligation has an explicit state
- complete artifacts are validated before publication
- existing published routes remain stable
- Exhaustion Proof distinguishes an empty page from a completed site

## Who It Is For

This is a good fit for:

- agencies managing multilingual WordPress sites
- operators using MCP-capable AI workers
- publishers who need reviewable AI-assisted content workflows
- sites that want native WordPress content instead of proxy translations
- teams that need evidence for coverage, freshness, routes, and publication state
- source-only sites that want controlled content optimization without multilingual frontend behavior

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- WordPress Abilities API support
- an authenticated MCP or automation client for ability-driven workflows

The plugin remains active if the Abilities API is unavailable, but workflow abilities are registered only when an abilities provider supplies `wp_register_ability()`.

## Documentation

- [Plugin Page](https://devenia.com/plugins/devenia-workflow/)
- [GitHub Releases](https://github.com/bjornfix/devenia-workflow/releases)
- [Latest ZIP](https://downloads.devenia.com/devenia-workflow.zip)
- [Devenia Plugins](https://devenia.com/plugins/)

## Start Here

1. Download and install the latest ZIP.
2. Activate **Devenia Workflow**.
3. Confirm the Abilities API is available.
4. Read the current workflow mode with `devenia-workflow/get-mode`.
5. Build Source Inventory with `devenia-workflow/rebuild-source-inventory`.
6. Inspect `devenia-workflow/translation-exhaustion-proof`.
7. Process work through the bounded Translation Job abilities.

## Core Workflow Interfaces

- `devenia-workflow/rebuild-source-inventory`
- `devenia-workflow/source-inventory`
- `devenia-workflow/translation-obligation-queue`
- `devenia-workflow/translation-exhaustion-proof`
- `devenia-workflow/translation-job-next`
- `devenia-workflow/translation-job-discover`
- `devenia-workflow/translation-job-claim`
- `devenia-workflow/translation-job-abandon`
- `devenia-workflow/translation-job-fetch-packet`
- `devenia-workflow/translation-job-submit-artifact`
- `devenia-workflow/translation-job-submit-quality-decision`
- `devenia-workflow/translation-job-publish`
- `devenia-workflow/translation-job-status`
- `devenia-workflow/source-rewrite-discover`
- `devenia-workflow/source-rewrite-claim`
- `devenia-workflow/source-rewrite-abandon`
- `devenia-workflow/source-rewrite-fetch-packet`
- `devenia-workflow/source-rewrite-submit-artifact`
- `devenia-workflow/source-rewrite-submit-quality-decision`
- `devenia-workflow/source-rewrite-publish`
- `devenia-workflow/source-rewrite-verify-live`
- `devenia-workflow/source-rewrite-status`

Additional abilities cover source inspection, workflow mode, language configuration, QA, localized routes, taxonomy, internal links, review evidence, and frontend verification.

## Storage and Portability

- translated pages and posts remain normal WordPress content
- source and language relationships use WordPress metadata
- workflow options and evidence stay in the WordPress installation
- disabling the plugin does not delete translated content
- uninstall removes plugin-owned workflow data but not ordinary posts, pages, terms, menus, media, or users

Back up WordPress before uninstalling if workflow history or audit evidence must be retained.

## Release Notes

### 0.1.669

- Makes Translation Preview Authority fail closed on WordPress query re-entry and requires the Preview Adapter to consume its validated host receipt instead of resolving the relation again.

### 0.1.668

- Centralizes staged-preview page-cache prevention in the shared capability module while retaining the established WordPress cache interoperability signal with an explicit standards justification.

### 0.1.667

- Binds Source Rewrite Quality to a short-lived exact-artifact theme preview and four desktop/mobile render receipts without exposing claim tokens or mutating public content.
- Gives Translation Quality the same claim-bound staged theme preview, including a non-mutating canonical theme shell for translations that do not yet have a WordPress post.
- Binds each preview and immutable receipt to the exact resolved theme host; relation changes and altered Quality bytes fail closed.
- Returns stale or preflight-rejected approvals to one bounded correction generation with the exact failure context.
- Strengthens semantic and rendered-information-architecture attestations, first-publication guarding, live reader-copy verification, and canonical link/route identity handling.

### 0.1.666

- Adds an immutable Source Rewrite Artifact and independent Quality lifecycle with exact publication and separate live verification.
- Blocks ordinary public source-copy changes and downstream translation while a current source rewrite lacks verified approval.
- Primes fresh writing, translation, and Quality runs with vendor-neutral effectiveness guidance and a bounded source-scoped policy Adapter, without embedding site-specific copy in the public plugin.

### 0.1.665

- Fails closed when the private Source Design Adapter is unavailable.
- Replaces generic Left/Right key inference with a typed Core RTL projection Adapter.
- Binds no-rewrite evidence to the source's canonical reader route.

### 0.1.664

- Applies the canonical registered Source Content Design Gate to pages and posts before design inheritance, publication-experience approval, direct source saves, and translation fitness.
- Applies source-design fragment-role guardrails to translated pages as well as posts.

### 0.1.663

- Derives a missing legacy parent path from the exact signed translated-parent identity after language and hierarchy validation.
- Preserves every non-empty signed parent path as an exact fail-closed route assertion.

### 0.1.662

- Publishes pre-0.1.661 new-page artifacts by deriving their one valid path from the signed parent and slug.
- Rejects compatibility derivation when the signed parent identity or parent path no longer matches WordPress.

### 0.1.661

- Derives every new translated page path from the resolved WordPress parent and localized slug before staging.
- Applies the signed route through the publication Module and fails closed if the saved hierarchy differs.

### 0.1.660

- Adds a generic Adapter port for localized static copy inside dynamic block HTML while preserving source-owned tokens and attribute context.
- Validates Adapter-owned fragment values, bounded card summaries, and complete translated direct-child inventories before staging.

### 0.1.659

- Scopes Translation Artifact link obligations to the typed source fragments a translator can change, preserves native dynamic Query links through block projection, and rejects extra invented internal targets.

### 0.1.658

- Rejects incomplete, ambiguous, empty, and placeholder Translation Artifact fragment values before staging, even when exact fragment-key coverage passes.

### 0.1.657

- Adds one source-type scope to obligation queue reads, next-Job selection, dependency ordering, and Exhaustion Proof, so operators can complete every page before beginning posts without maintaining a shadow queue.
- Binds scoped cursors and per-type unresolved counts to the same immutable Inventory Generation and fail-closed shard receipts.

### 0.1.656

- Reopens repairable published-content or published-surface authority drift as a fresh finite translator and Quality generation during Job discovery or claim, while corrupt or incomplete immutable evidence continues to fail closed.

### 0.1.655

- Extends the resumable Inventory Generation lifecycle across both source scanning and obligation projection, keeping the initial snapshot call bounded on large sites.

### 0.1.654

- Makes complete Inventory Generation rebuilds resumable in bounded server-owned chunks so large sites cannot be blocked by an HTTP execution window.

### 0.1.653

- Keeps whole-site queue reads bounded through source/projection epochs, generation-bound shard receipts, direct source bindings, a seekable unresolved-shard directory, snapshot cursors, and one deep Job/projection commit interface; terminal pages prove completeness, and interrupted, concurrent, stale, or incomplete authority fails closed.

### 0.1.652

- Localizes legacy Scriptless Social Sharing screen-reader labels through semantic Workflow Presentation Text while preserving the third-party plugin's links, icons, attributes, and visible-label mode.

### 0.1.651

- Keeps Workflow theme-neutral by consuming the owning GP-MCP native layout projection during translated publication; canonical frontend rendering and GenerateBlocks cache regeneration no longer live in Workflow.

### 0.1.650

- Applies the global direction-aware native GenerateBlocks grid-gap projection to canonical source rendering as well as translated publication surfaces, without rewriting page content or adding CSS.
- Lets the Presentation Text registry supply content-type-neutral legacy sharing email copy for the source language as well as translations.

### 0.1.649

- Projects GenerateBlocks grid gaps through one direction-aware native layout Adapter for both LTR and RTL translations, removing negative wrapper offsets without CSS or page-specific rules.

### 0.1.648

- Connects the legacy Scriptless Social Sharing email subject and body filters to the semantic target-language runtime registry while preserving exactly one owner-appended canonical URL.

### 0.1.647

- Makes source-language carryover preservation phrase-aware: configured multiword technical names are exempt only as complete phrases, while isolated component words still trigger review.

### 0.1.646

- Advances and records a finite submission generation whenever a correctable, exactly rolled-back publication failure reopens a Translation Job, while retaining the prior artifact as the correction packet.

### 0.1.645

- Normalizes every supplementary Unicode code point at the WordPress storage boundary, preserving browser output while remaining compatible with legacy `utf8mb3` content and metadata tables.
- Reopens an exactly rolled-back content-revision mismatch for a fresh translator and independent Quality generation instead of leaving an approved but unpublishable artifact stuck.
- Gives language-selector region headings explicit data-driven language direction so localized labels stay semantically aligned without page- or locale-specific presentation rules.

### 0.1.644

- Stores both multilingual artifacts and their publication Surface Manifests in reversible ASCII-safe envelopes, preventing emoji and other 4-byte Unicode from failing on legacy `utf8mb3` WordPress option tables.

### 0.1.643

- Reopens an approved Translation Job for a fresh translator and Quality chain
  when a proven zero-mutation publication preflight rejects correctable artifact
  metadata, instead of leaving an uneditable `ready_to_publish` artifact stuck.

### 0.1.642

- Reuses the bounded Artifact View in translator correction packets, preventing a prior internal publication payload from pushing large revision jobs beyond the unchanged Run budget.
- Standardizes translator and Quality packet contract version 5 on one external Artifact boundary.

### 0.1.641

- Introduces a bounded Quality Review Projection that keeps all source and localized review content, staged metadata, exact revision binding, and server receipts while leaving internal publication payloads behind the Workflow boundary.
- Prevents large pages from failing Quality packet creation because localized content was previously serialized repeatedly in the submitted artifact, presentation manifest, and generated Gutenberg document.

### 0.1.640

- Normalizes GenerateBlocks horizontal grid gaps during RTL projection with native block width and spacing attributes, preventing desktop Arabic pages from extending past the physical left edge without adding CSS.
- Advances only the RTL publication contract so affected artifacts receive a fresh translator and independent Quality generation while approved LTR evidence remains current.

### 0.1.639

- Scopes the target-design-signature publication contract to RTL jobs, preserving already-approved LTR evidence while still reopening Arabic and other RTL artifacts staged with the former LTR hash.
- Requires the complete policy viewport tuple in Quality browser-receipt schemas and reports field-level rejection reasons, preventing a missing device-scale factor from silently appearing as four absent receipts.

### 0.1.638

- Advances the code-owned publication-surface contract after target-language design signatures became authoritative, so already staged artifacts using the former LTR-only presentation hash are reopened for a fresh translator and Quality generation instead of remaining unpublishable.

### 0.1.637

- Pins staged presentation evidence to the target language's expected design signature, allowing deterministic RTL mirroring to pass the same exact publication-surface check as LTR translations.
- Adds an asymmetric Arabic WordPress runtime regression so an untranslated LTR design hash can no longer block an otherwise approved RTL publication.

### 0.1.636

- Pins a separate code-owned publication-surface contract fingerprint to every Translation Job generation, Run, artifact, staged manifest, Quality Decision, and evidence chain; stale or legacy generations ownership-check and CAS-retire the exact active Run/claim before reopening for a fresh translator.
- Makes every mutable Run write ownership-bound through exact compare-and-swap, so stale fetch, abandon, budget, expiry, and completion paths cannot replace a terminal Run or advance its Job.
- Treats an identical CAS replacement as success only after exact current-byte verification, allowing repeated packet fetches without accepting a changed owner.
- Makes Source Inventory treat published authority under an obsolete fragment contract as unresolved, while preserving every prior artifact, Run, Quality Decision, and receipt as immutable audit evidence.
- Prunes orphaned historical localized-fragment keys against the current source contract after merging partial updates, so durable WordPress presentation state matches the complete approved artifact.

### 0.1.635

- Adds meaningful inline `core/image` alt text to the strict source-design fragment contract so localized alternatives are staged, revision-bound, projected into Gutenberg markup, and independently reviewed.
- Records hybrid contract ownership: executable safety invariants remain code-owned; mutable site, language, copy, render, and cost policy belongs in immutable runtime policy snapshots rather than a general database registry.

### 0.1.634

- Restore shared execution-provenance comparisons to the active identity module so translation status and Quality read models cannot fatal after legacy orchestration removal.
- Restore the compact queue-state contract and add a production method-portfolio check so removed modules cannot leave unresolved runtime calls behind.
- Make distinct translator and Quality subagents an explicit bounded-packet contract, backed by separate run, claim, principal, artifact, and surface-revision enforcement.

### 0.1.633

- Replace the translated-menu page/meta scan with bounded lookups through the existing translation registry index.

### 0.1.632

- Bind trusted storage-guardrail bypasses to the exact post being mutated, so nested WordPress hooks cannot disable protection for unrelated content.
- Reject off-site frontend evidence URLs, disable redirects in concurrent self-fetches, and cap every retained HTML response at 2 MiB.
- Centralize ability authorization and mark every state-changing ability as destructive for safer operator confirmation.
- Bind upgrade state and Quality evidence to the exact deployed plugin release identity, with aligned executable release checks.

### 0.1.619

- Bound every same-site Public Header verification request under one absolute concurrency limit, including canonical cacheable URLs that can miss or revalidate against WordPress, while reclaiming unused fast-group time inside the existing hard wall deadline.

### 0.1.618

- Bound cache-bypass verification concurrency separately from cached requests, preventing complete Public Header checks from exhausting the WordPress origin while retaining an exact all-language result set and cumulative runtime budget.

### 0.1.617

- Fetch complete all-language Public Header origin and canonical evidence through one bounded concurrent WordPress Requests batch, preserving the same fail-closed response shape while keeping synchronous operator calls within the transport budget.

### 0.1.616

- Parse only the owned primary menu list during Public Header verification, excluding branding, search controls, secondary menus, and presentation-injected language-selector links even when tolerant HTML parsing reparents them.

### 0.1.615

- Resolve first-enrollment menu labels by exact source-item identity before page or URL fallback, preserving intentional duplicate links to the same page at different hierarchy positions.
- Treat a supplied complete target authority set as the entire verified set for that language, so unrelated retained menus cannot create false cross-language conflicts.
- Allow legacy page/URL discovery only when stable identity metadata is truly absent; duplicate, corrupt, foreign-language, or relation-conflicting persisted identities now fail closed.
- Bind accepted labels to fresh exact page/custom relations and candidate menu revisions in a temporary pending receipt, consume that receipt during staging, revalidate it through activation, and keep it out of the active reader manifest.
- Make explicit first-enrollment and schema-1 migration authority sets all-or-nothing, including deterministic rejection of missing, managed, wrong-language, changed, or otherwise invalid members.
- Choose Public Header page relations only from canonical WordPress posts and exact source/language metadata, use Translation Index only as a fail-closed cross-check, and lock candidate menus plus canonical relation predicates through the final transactional revalidation.
- Mint a fresh complete all-language relation receipt for every ordinary Translation Job and operator restage, bind internal custom links to exact canonical source/target route revisions, strip ephemeral receipts from active reader state, and prove the final post-row and source/language absent-metadata-predicate InnoDB locks with separate before/under/after writers and exact rollback cleanup.
- Bind activation to the exact raw stored pending option, so even normalization-equivalent raw replacement invalidates the prior receipt before staging; ordinary publication rejects every occupied raw pending slot, issues authority only after its own missing-slot create succeeds, and releases that slot atomically on activation.
- Preserve exact WordPress content identity for query-style internal links, so `page_id`, `p`, and `post_id` links can never fall through to an unrelated path-based localized route.

### 0.1.614

- Make persisted Public Header menu identities read-only during ordinary staging, verification, and frontend selection; legacy discovery is available only through the explicit capability-gated migration Interface.
- Bind localized featured-image alt text into the complete publication compare-and-swap surface so an editorial media-text change reopens bounded work instead of being overwritten by a stale approved artifact.
- Require one strict Recovery COMMIT receipt grammar across header, content, staging, snapshot, and restore boundaries: only an exact null Adapter sentinel selects the default COMMIT, malformed or non-terminal receipts fail critical, and every production call site is portfolio-scanned.
- Separate forward publication phase evidence from the final reader result, classify the post-rollback surface from fresh exact receipts, and rebuild the returned translation payload after recovery so API responses cannot contradict persisted Job authority.

### 0.1.613

- Bind every Public Header Projection item to explicit editorial labels for the source and every configured target language by stable source-item identity; never substitute page titles, and leave the active all-language header untouched when any label authority is missing.
- Derive one deterministic Canonical Route Contract for legacy published translations whose route metadata is missing, require stored and canonical paths to match the observed WordPress permalink before staging and again under the publication lock, then bind storage and exact applied-surface verification to that same immutable route without changing the public URL.
- Require the derived legacy contract to be persisted exactly during publication; a missing or different stored route still fails closed and rolls the public surface back.

### 0.1.612

- Bind Translation Jobs, artifacts, Quality Decisions, publication evidence, and Source Inventory to one complete source publication surface covering content, routes, taxonomies, design, and byte-stable featured media identity.
- Fail localized publication closed unless the exact featured image, WordPress `srcset` candidates, and Open Graph image are verified on both origin and canonical public URLs; reopen stale work through bounded Translation Jobs instead of mutating public media directly.
- Publish source and localized public-header projections as one all-language transaction with durable enrollment, locked receipts, cache-coherent rollback, and exact origin/canonical menu verification.
- Resolve translated post links through the authoritative relationship index without language-specific route assumptions.
- Integrate the separate owned Devenia Social Sharing plugin only through its public manifest and filters; require every rendered semantic string from the language registry and verify exact heading cardinality and values on live public surfaces.
- Apply the complete approved SEO surface with explicit set/delete operations during Translation Job publication, while preserving genuinely absent optional fields in general updates and signing the actual final Rank Math state.

### 0.1.611

- Normalizes mixed `html` and `text` translation fragments to the deterministic storage representation before staging and applied-surface verification, preserving exact source-design hash and fragment-value checks while remaining compatible with already-approved manifests.

### 0.1.610

- Adds a private, bounded Surface Refresh transition that safely reopens the exact current Translation Job after server-proven public baseline, snapshot-to-lock surface, or canonical identity drift; every publish-time classification requires zero mutation and a completed owned rollback. A shared source/language lifecycle lease serializes claim mutation with publication through the final Job CAS. It preserves immutable artifact and Quality history, binds fresh Runs and submissions to a new generation, and removes coordinator labels from publication authority.

### 0.1.609

- Adds a fail-closed, owned transaction preflight for publication and recovery, including exact core-table engine proof, portable MySQL/MariaDB metadata, a transaction-scoped SERIALIZABLE boundary with private savepoint ownership, completion-mode-safe terminal SQL, and structured failure diagnostics.

### 0.1.608

- Makes staged publication recovery idempotent when an approved surface was fully applied before a gateway interruption, and restores failed existing candidates inside the guarded rollback boundary.

### 0.1.607

- Verifies applied localized presentation against the canonical stored fragment records instead of the metadata envelope.

### 0.1.606

- Separates the server-owned HTTP/theme-shell and exact staged-DOM receipt from unrelated localized-menu and copy-policy checks.

### 0.1.605

- Exposes fail-closed server receipt issuance errors in Quality packets and aligns translator packets with the staged Quality Authority v3 contract.

### 0.1.604

- Uses the canonical source-design contract digest when building complete staged surface revisions.

### 0.1.603

- Stages complete translation surfaces without mutating public content, binds writer and Quality Runs to server-issued principals, and requires immutable server receipts plus reviewer and browser attestations before guarded publication.

### 0.1.602

- Resolves the configured source language menu through the stable primary theme-location ID, eliminating brittle source-menu name lookup during atomic projection.

### 0.1.601

- Verifies menu labels against the effective runtime-editable language profile used by the frontend renderer, while retaining stable menu IDs and URLs.

### 0.1.600

- Compares hierarchical localized menus in WordPress walker render order so cache integrity remains exact without rejecting correct nested navigation.

### 0.1.599

- Publishes localized presentation through one fail-closed cache Adapter seam, atomically projects stable ID-based language menus, and verifies both origin and canonical cached navigation.

### 0.1.598

- Keeps expired and abandoned non-decision Translation Job Runs from consuming bounded translator or Quality attempt slots while retaining the limit for real submissions and decisions.

### 0.1.597

- Applies generic locale-aware source-carryover homograph rules consistently to bounded translation validation while retaining untranslated-prose detection.

### 0.1.596

- Raises the bounded translator input budget and safely upgrades active translator Runs so complete correction packets remain fetchable without bypassing the Translation Job lifecycle.

### 0.1.595

- Routes native source-editor status through the shared ability handler catalogue so ability integrity checks match the callable runtime.

### 0.1.594

- Preserves exact source external HTTP(S) destinations while allowing internal links to use localized routes.

### 0.1.593

- Rejects malformed translation links that concatenate multiple absolute URLs into one href.

### 0.1.592

- Keeps owned email-share links under the dedicated canonical URL renderer instead of replacing them with full-mailto runtime mappings.

### 0.1.591

- Supplies Devenia Social Sharing with the translation's Canonical Route Contract before share links are built.

### 0.1.590

- Restores the proven frontend localization path after withdrawing an incompatible whole-document mailto rewrite.

### 0.1.588

- Normalizes internal WordPress email-share paths to canonical lowercase slug casing before inserting runtime templates.

### 0.1.587

- Resolves localized email-share routes from the active Loop post before falling back to the queried object.

### 0.1.586

- Resolves localized email-share URLs through the published translation and its Canonical Route Contract for source-backed frontend queries.

### 0.1.585

- Uses the sanitized HTTP request path before rewritten WordPress route objects when canonicalizing localized email-share URLs.

### 0.1.584

- Uses WordPress's active request route for localized email-share URLs when translated content renders through a source query object.

### 0.1.583

- Uses the translation Canonical Route Contract as the authoritative email-share URL before WordPress permalink fallbacks.

### 0.1.582

- Resolves the current canonical permalink from WordPress when email-share localization receives only an article-content fragment.

### 0.1.581

- Keeps translated bylines on the working source author archive until a localized author archive is published.
- Restores the author archive localization queue and stops internal-link validation from accepting unpublished localized author paths.
- Normalizes owned email-share URLs to the rendered canonical URL when only path casing differs.

### 0.1.580

- Reopens a quality-pending Translation Job for translator correction when its saved artifact no longer covers the current generic source-design fragment contract.

### 0.1.579

- Includes visible core/details summary text in every source-design translation contract and projects its localized fragment without changing the block shell.
- Adds a bounded Translation Job abandon operation so a worker can release its own claim without fabricating an artifact or Quality Decision.

### 0.1.578

- Makes live translation verification reject an untranslated public skip-to-content link even when a runtime mapping has already been registered.
- Moves Devenia-site copy checks behind public extension filters so the Workflow core remains site-neutral.

### 0.1.577

- Records source-page whole-page quality evidence without requiring translation authority; translated content remains protected by bounded reviewer authority.

### 0.1.576

- Removes frontend heading-size overrides so GeneratePress remains the typography owner.
- Removes bundled frontend CSS and Devenia-specific presentation asset loading from the public Workflow plugin.
- Restores the canonical whole-page quality-review ability needed to record current source review evidence.

### 0.1.575

- Prioritizes unresolved internal-link targets before the pages that depend on them.
- Prevents dependency cycles from stalling the stable complete-inventory queue.
- Adds bounded pagination for legacy localized-fragment previews so reviewed translations can be reused safely after source structure changes.
- Restores the shared widget-title localization hook for runtime labels such as the popular-posts heading.

### 0.1.574

- Removes the superseded persona, Heartbeat, Assignment, Reservation, and Work Item orchestration system.
- Makes the bounded Translation Job the only translation orchestration Interface, with seven explicit operations.
- Renames current PHP, hooks, options, assets, abilities, and repository references consistently under `devenia-workflow`.
- Keeps Source Inventory, complete artifacts, Quality Decisions, publishing, localized routes, and ordinary WordPress translations intact.

### 0.1.573

- Adopts `devenia-workflow` as the public plugin slug, main filename, and text domain.
- Removes direct private presentation abilities and site-specific design assumptions from the public workflow core.
- Adds generic source-editor, source-design validation, and dynamic-presentation Adapter Interfaces.
- Publishes the plugin through the public Devenia updater channel.

## Contributing

PRs are welcome. Keep changes inside the Module that owns the behavior, preserve public routes and native WordPress content, and add contract coverage for workflow changes.

## License

GPL-2.0+

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin Page](https://devenia.com/plugins/devenia-workflow/)
- [GitHub Repository](https://github.com/bjornfix/devenia-workflow)
- [GitHub Releases](https://github.com/bjornfix/devenia-workflow/releases)
- [Direct Download](https://downloads.devenia.com/devenia-workflow.zip)
- [Devenia Plugins](https://devenia.com/plugins/)

## Star and Share

If Devenia Workflow helps you run safer WordPress content operations, star the repository and share the public plugin page with other WordPress operators.
