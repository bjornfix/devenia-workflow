=== Devenia Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.623
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

= 0.1.623 =
* Align staged presentation authority with the deterministic target-language design hash so RTL translations publish against the same mirrored design contract that WordPress stores and verifies.

= 0.1.622 =

* Made translated frontend link rewriting consume persisted Translation Index routes directly, avoiding per-post permalink and metadata reads across the complete translation registry on cache-cold requests.

= 0.1.621 =

* Moved exact reader-cache invalidation out of content publication and into the separate live-verification operation. Global Open Graph fallback images are no longer treated as translation-owned featured media when a page has no featured image.

= 0.1.620 =

* Decoupled routine translation publication from Public Header Projection. Page and post translations now publish, invalidate, and verify only their own reader surfaces; menus change only through the explicit sync-menu operation.

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
