# Devenia Workflow

Run controlled AI-assisted content improvement and multilingual publishing workflows inside WordPress.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/devenia-workflow)](https://github.com/bjornfix/devenia-workflow/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0

**Stable tag:** 0.1.638

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

Additional abilities cover source inspection, workflow mode, language configuration, QA, localized routes, taxonomy, internal links, review evidence, and frontend verification.

## Storage and Portability

- translated pages and posts remain normal WordPress content
- source and language relationships use WordPress metadata
- workflow options and evidence stay in the WordPress installation
- disabling the plugin does not delete translated content
- uninstall removes plugin-owned workflow data but not ordinary posts, pages, terms, menus, media, or users

Back up WordPress before uninstalling if workflow history or audit evidence must be retained.

## Release Notes

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
