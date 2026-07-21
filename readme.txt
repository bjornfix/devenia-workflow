=== Devenia Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.659
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted workflow for improving and publishing WordPress content, with optional multilingual support.

== Description ==

Devenia Workflow helps site operators improve, review, and publish WordPress pages and posts through controlled AI-assisted workflows. Multilingual publishing is an optional capability, not a requirement.

The plugin keeps translations as ordinary WordPress content, so it can be removed later without rebuilding the site from a proprietary translation store. Around that native content model, it adds workflow support for localized URLs, source mapping, stale-source detection, hreflang output, language-menu sync, QA guardrails, review evidence, frontend copy editing, reviewer learning, repair tools, runtime text, and publish checks.

It is designed for controlled translation workflows where an AI assistant, automation client, or editor creates draft translations and the site owner still wants clear review gates before publishing.

= Features =

* Create and update translated pages and posts as normal WordPress content.
* Track source-to-translation relationships and stale source content.
* Generate localized URL metadata for supported languages.
* Output hreflang data for mapped translations.
* Keep language menus in sync with available translations.
* Run QA checks for source-language carryover, terminology, structure, script issues, and link integrity.
* Require a Quality Decision bound to the exact complete artifact before publishing.
* Integrate with Frontend Text Edit for supported rendered frontend text fixes.
* Capture human edits and reviewer feedback as learning that can become style guidance or QA rules.
* Support authored-original intake when content starts in a non-source language.
* Repair localized internal links, URL hierarchy, and featured images where possible.
* Manage shared runtime text for language-aware labels and fallback copy.
* Provide optional theme or builder integrations through addon files.
* Keep translated content portable by storing it as normal WordPress content.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/devenia-workflow/` directory, or install the plugin through the WordPress plugins screen.
2. Activate "Devenia Workflow" through the Plugins screen in WordPress.
3. Configure the supported language registry for your site.
4. Use the bounded Translation Job abilities to read packets, submit complete artifacts, run QA, record Quality Decisions, and publish approved translations.

== Frequently Asked Questions ==

= Is this a replacement for WPML or Polylang? =

No. Devenia Workflow provides controlled content-quality, review, publishing, and optional multilingual workflows. It keeps content in WordPress instead of replacing WordPress content management.

= Does the plugin translate content by itself? =

No. The plugin provides the workflow, metadata, guardrails, and review gates around translation work. Translation text is expected to come from an AI assistant, automation client, or editor.

= How is this different from automatic translation plugins? =

Most automatic translation plugins focus on generating translated text or proxying translated pages. Devenia Workflow focuses on the controlled content and publishing layer: WordPress-native pages and posts, source-quality checks, review evidence, runtime text, and repair operations, with localized URLs, source mapping, stale-source detection, and hreflang available when multilingual publishing is enabled.

= Can editors fix translated copy on the frontend? =

Yes. With the companion Frontend Text Edit plugin, authorized editors can edit supported rendered text. Changes are saved back into the WordPress block content through normal permissions and storage guardrails, not into a separate translation store.

= Can manual edits improve future translations? =

Yes. Human edits and reviewer feedback can be captured as learning events. They can be kept as reviewer-style guidance, promoted into future QA rules, or ignored when they are only one-off changes.

= Will my translated content remain if I remove the plugin? =

Yes. The plugin does not delete translated posts, pages, menus, terms, media, or regular WordPress content on uninstall. Removing it also removes the workflow layer, so localized routing helpers, hreflang output, language-menu sync, QA gates, and repair abilities will no longer run. The translated content itself remains in WordPress and can be edited, migrated, or managed manually.

= Can translated content be edited in WordPress? =

Yes. Translated pages and posts are ordinary WordPress content. Existing translated page text, menu labels, slugs, URLs, runtime text, and page-specific QA options should be changed in WordPress or through the relevant workflow ability, not by editing packaged language defaults.

= Does the plugin support posts and pages? =

Yes. The workflow supports both posts and pages, including localized URLs, mapping metadata, QA checks, review state, and publish checks.

= What happens if the WordPress Abilities API is unavailable? =

The plugin stays active, but workflow abilities are not registered until WordPress or an installed abilities provider makes `wp_register_ability()` available. Administrators will see a dashboard notice in that state.

= Can a non-English page become the starting point? =

Yes. A post or page can be authored first in another configured language. The plugin can place it in an authored-original intake workflow so an English technical source can be created and reviewed before downstream translations continue.

= Are theme integrations required? =

No. The core workflow is theme-neutral. Optional theme and builder integrations live in `addons/` and load only when the matching surface is present.

= What is removed on uninstall? =

Uninstall removes plugin-owned options and custom workflow tables. It does not delete translated posts, pages, menus, terms, or regular WordPress content.

== Changelog ==

= 0.1.659 =
* Scope Translation Artifact link obligations to the typed source fragments a translator can change, preserve native dynamic Query links through block projection, and reject extra invented internal targets.

= 0.1.658 =
* Reject incomplete, ambiguous, empty, and placeholder Translation Artifact fragment values before staging, even when exact fragment-key coverage passes.

= 0.1.657 =
* Add one source-type scope to obligation queue reads, next-Job selection, dependency ordering, and Exhaustion Proof, allowing pages and posts to be completed in explicit phases without a shadow queue.
* Bind scoped cursors and per-type unresolved counts to the same immutable Inventory Generation and fail-closed shard receipts.

= 0.1.656 =
* Reopen repairable published-content or published-surface authority drift as a fresh finite translator and Quality generation during Job discovery or claim, while corrupt or incomplete immutable evidence continues to fail closed.

= 0.1.655 =
* Extend the resumable Inventory Generation lifecycle across both source scanning and obligation projection, keeping the initial snapshot call bounded on large sites.

= 0.1.654 =
* Make complete Inventory Generation rebuilds resumable in bounded server-owned chunks so large sites cannot be blocked by an HTTP execution window.

= 0.1.653 =

* Keep whole-site queue reads bounded through source/projection epochs, generation-bound shard receipts, direct source bindings, a seekable unresolved-shard directory, snapshot cursors, and one deep Job/projection commit interface; terminal pages prove completeness, and interrupted, concurrent, stale, or incomplete authority fails closed.

= 0.1.652 =

* Localize the legacy Scriptless Social Sharing screen-reader labels through semantic Workflow Presentation Text while preserving the third-party plugin's links, icons, attributes, and visible-label mode.

= 0.1.651 =

* Keep Workflow theme-neutral by consuming the owning GP-MCP native layout projection during translated publication; canonical frontend rendering and GenerateBlocks cache regeneration no longer live in Workflow.

= 0.1.650 =

* Apply the global direction-aware native GenerateBlocks grid-gap projection to canonical source rendering as well as translated publication surfaces, without rewriting page content or adding CSS.
* Let the Presentation Text registry supply content-type-neutral legacy sharing email copy for the source language as well as translations.

= 0.1.649 =
* Projects GenerateBlocks grid gaps through one direction-aware native layout Adapter for both LTR and RTL translations, removing negative wrapper offsets without CSS or page-specific rules.

= 0.1.648 =

* Connect legacy Scriptless Social Sharing email subject and body filters to the semantic target-language runtime registry, preserving exactly one owner-appended canonical URL.

= 0.1.647 =

* Make source-language carryover preservation phrase-aware so configured multiword technical names pass only as complete phrases while isolated component words remain reviewable.

= 0.1.646 =

* Advance and record a finite submission generation whenever a correctable, exactly rolled-back publication failure reopens a Translation Job, while retaining the prior artifact as the correction packet.

= 0.1.645 =

* Normalize every supplementary Unicode code point at the WordPress storage boundary and reopen an exactly rolled-back content-revision mismatch for a fresh translator and Quality generation.
* Give language-selector region headings explicit data-driven language direction so localized labels stay semantically aligned without page- or locale-specific presentation rules.

= 0.1.644 =

* Store both multilingual artifacts and their publication Surface Manifests in reversible ASCII-safe envelopes so 4-byte Unicode cannot fail on legacy utf8mb3 option tables.

= 0.1.643 =

* Reopen an approved Translation Job for a fresh translator and Quality chain when a zero-mutation publication preflight rejects correctable artifact metadata.

= 0.1.642 =

* Reuse the bounded Artifact View for translator correction packets so a prior staged publication payload cannot make large revisions exceed the unchanged Run budget.
* Standardize translator and Quality packet contract version 5 on the same external Artifact boundary.

= 0.1.641 =

* Add a bounded Quality Review Projection that retains complete source and localized review content, staged metadata, revision binding, and server receipts while keeping generated publication payloads internal.
* Prevent large pages from failing Quality packet creation because the same localized content was serialized in the artifact, staged presentation manifest, and generated Gutenberg document.

= 0.1.640 =

* Normalize GenerateBlocks horizontal grid gaps during RTL projection with native block width and spacing attributes, preventing desktop RTL overflow without CSS.
* Advance only the RTL publication contract so affected artifacts receive a fresh translator and independent Quality generation.

= 0.1.639 =

* Scope the target-design-signature publication contract to RTL jobs so existing LTR evidence remains current while affected RTL artifacts refresh.
* Require complete Quality browser viewport dimensions and return field-level receipt validation failures.

= 0.1.638 =

* Advance the code-owned publication-surface contract for target-language design signatures so artifacts staged under the former LTR-only presentation hash reopen through a fresh translator and Quality generation.

= 0.1.637 =

* Pin staged presentation evidence to the target language's expected design signature so deterministic RTL mirroring passes exact publication verification.
* Add an asymmetric Arabic WordPress runtime regression that rejects the untranslated LTR source hash at the staging boundary.

= 0.1.636 =

* Pin a separate code-owned publication-surface contract fingerprint to every Translation Job generation, Run, artifact, staged manifest, Quality Decision, and evidence chain; stale or legacy generations now ownership-check and CAS-retire the exact active Run/claim before reopening for a fresh translator.
* Make every mutable Run write ownership-bound through exact compare-and-swap, so stale fetch, abandon, budget, expiry, and completion paths cannot replace a terminal Run or advance its Job.
* Treat an identical CAS replacement as success only after exact current-byte verification, allowing repeated packet fetches without accepting a changed owner.
* Make Source Inventory treat published authority under an obsolete fragment contract as unresolved, while preserving every prior artifact, Run, Quality Decision, and receipt as immutable audit evidence.
* Prune orphaned historical localized-fragment keys against the current source contract after merging partial updates, so durable WordPress presentation state matches the complete approved artifact.

= 0.1.635 =

* Add meaningful inline `core/image` alt text to the strict source-design fragment contract so localized alternatives are staged, revision-bound, projected into Gutenberg markup, and independently reviewed.
* Record the hybrid architecture decision: executable safety invariants stay code-owned while mutable site, language, copy, render, and cost policy belongs in immutable runtime policy snapshots rather than a general database contract registry.

= 0.1.634 =

* Restore shared execution-provenance comparisons to the active identity module so translation status and Quality read models cannot fatal after legacy orchestration removal.
* Restore the compact queue-state contract and add a production method-portfolio check so removed modules cannot leave unresolved runtime calls behind.
* Make distinct translator and Quality subagents an explicit bounded-packet contract, backed by separate run, claim, principal, artifact, and surface-revision enforcement.

= 0.1.633 =

* Replace the translated-menu page/meta scan with bounded lookups through the existing translation registry index.

= 0.1.632 =

* Bind trusted storage-guardrail bypasses to the exact post being mutated, so nested WordPress hooks cannot disable protection for unrelated content.
* Reject off-site frontend evidence URLs, disable redirects in concurrent self-fetches, and cap every retained HTML response at 2 MiB.
* Centralize ability authorization and mark every state-changing ability as destructive for safer operator confirmation.
* Bind upgrade state and Quality evidence to the exact deployed plugin release identity, with aligned executable release checks.

= 0.1.619 =

* Bound every same-site Public Header verification request under one absolute concurrency limit, including canonical cacheable URLs that can miss or revalidate against WordPress, while reclaiming unused fast-group time inside the existing hard wall deadline.

= 0.1.618 =

* Bound cache-bypass verification concurrency separately from cached requests, preventing complete Public Header checks from exhausting the WordPress origin while retaining an exact all-language result set and cumulative runtime budget.

= 0.1.617 =

* Fetch complete all-language Public Header origin and canonical evidence through one bounded concurrent WordPress Requests batch, preserving the same fail-closed response shape while keeping synchronous operator calls within the transport budget.

= 0.1.616 =

* Parse only the owned primary menu list during Public Header verification, excluding branding, search controls, secondary menus, and presentation-injected language-selector links even when tolerant HTML parsing reparents them.

= 0.1.615 =

* Resolve first-enrollment menu labels by exact source-item identity before page or URL fallback, preserving intentional duplicate links to the same page at different hierarchy positions.
* Treat a supplied complete target authority set as the entire verified set for that language, so unrelated retained menus cannot create false cross-language conflicts.
* Allow legacy page/URL discovery only when stable identity metadata is truly absent; duplicate, corrupt, foreign-language, or relation-conflicting persisted identities now fail closed.
* Bind accepted labels to fresh exact page/custom relations and candidate menu revisions in a temporary pending receipt, consume that receipt during staging, revalidate it through activation, and keep it out of the active reader manifest.
* Make explicit first-enrollment and schema-1 migration authority sets all-or-nothing, including deterministic rejection of missing, managed, wrong-language, changed, or otherwise invalid members.
* Choose Public Header page relations only from canonical WordPress posts and exact source/language metadata, use Translation Index only as a fail-closed cross-check, and lock candidate menus plus canonical relation predicates through the final transactional revalidation.
* Mint a fresh complete all-language relation receipt for every ordinary Translation Job and operator restage, bind internal custom links to exact canonical source/target route revisions, strip ephemeral receipts from active reader state, and prove the final post-row and source/language absent-metadata-predicate InnoDB locks with separate before/under/after writers and exact rollback cleanup.
* Bind activation to the exact raw stored pending option, so even normalization-equivalent raw replacement invalidates the prior receipt before staging; ordinary publication rejects every occupied raw pending slot, issues authority only after its own missing-slot create succeeds, and releases that slot atomically on activation.
* Preserve exact WordPress content identity for query-style internal links, so `page_id`, `p`, and `post_id` links can never fall through to an unrelated path-based localized route.

= 0.1.614 =

* Make persisted Public Header menu identities read-only during ordinary staging, verification, and frontend selection; legacy discovery is available only through the explicit capability-gated migration Interface.
* Bind localized featured-image alt text into the complete publication compare-and-swap surface so an editorial media-text change reopens bounded work instead of being overwritten by a stale approved artifact.
* Require one strict Recovery COMMIT receipt grammar across header, content, staging, snapshot, and restore boundaries: only an exact null Adapter sentinel selects the default COMMIT, malformed or non-terminal receipts fail critical, and every production call site is portfolio-scanned.
* Separate forward publication phase evidence from the final reader result, classify the post-rollback surface from fresh exact receipts, and rebuild the returned translation payload after recovery so API responses cannot contradict persisted Job authority.

= 0.1.613 =

* Bind every Public Header Projection item to explicit editorial labels for the source and every configured target language by stable source-item identity; never substitute page titles, and leave the active all-language header untouched when any label authority is missing.
* Derive one deterministic Canonical Route Contract for legacy published translations whose route metadata is missing, require stored and canonical paths to match the observed WordPress permalink before staging and again under the publication lock, then bind storage and exact applied-surface verification to that same immutable route without changing the public URL.
* Require the derived legacy contract to be persisted exactly during publication; a missing or different stored route still fails closed and rolls the public surface back.

= Earlier releases =

* See the [complete release history](https://github.com/bjornfix/devenia-workflow/releases) for versions before 0.1.613.
