=== AI Translation Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.508
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portable workflow layer for AI-assisted multilingual WordPress content.

== Description ==

AI Translation Workflow helps site operators manage AI-assisted page and post translations without turning translated content into a separate content system.

The plugin keeps translations as ordinary WordPress content, so it can be removed later without rebuilding the site from a proprietary translation store. Around that native content model, it adds workflow support for localized URLs, source mapping, stale-source detection, hreflang output, language-menu sync, QA guardrails, review evidence, frontend copy editing, reviewer learning, repair tools, runtime text, and publish checks.

It is designed for controlled translation workflows where an AI assistant, automation client, or editor creates draft translations and the site owner still wants clear review gates before publishing.

= Features =

* Create and update translated pages and posts as normal WordPress content.
* Track source-to-translation relationships and stale source content.
* Generate localized URL metadata for supported languages.
* Output hreflang data for mapped translations.
* Keep language menus in sync with available translations.
* Run QA checks for source-language carryover, terminology, structure, script issues, and link integrity.
* Require linguistic, quality, and final review evidence before publishing.
* Integrate with Frontend Text Edit for supported rendered frontend text fixes.
* Capture human edits and reviewer feedback as learning that can become style guidance or QA rules.
* Support authored-original intake when content starts in a non-source language.
* Repair localized internal links, URL hierarchy, and featured images where possible.
* Manage shared runtime text for language-aware labels and fallback copy.
* Provide optional theme or builder integrations through addon files.
* Keep translated content portable by storing it as normal WordPress content.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/devenia-ai-translations/` directory, or install the plugin through the WordPress plugins screen.
2. Activate "AI Translation Workflow" through the Plugins screen in WordPress.
3. Configure the supported language registry and review workflow for your site.
4. Use the plugin's workflow abilities from your AI or automation client to read sources, create drafts, run QA, record review evidence, and publish approved translations.

== Frequently Asked Questions ==

= Is this a replacement for WPML or Polylang? =

No. AI Translation Workflow focuses on controlled AI-assisted translation workflow, QA, review evidence, localized URLs, and publishing checks. It keeps translated content in WordPress instead of replacing WordPress content management.

= Does the plugin translate content by itself? =

No. The plugin provides the workflow, metadata, guardrails, and review gates around translation work. Translation text is expected to come from an AI assistant, automation client, or editor.

= How is this different from automatic translation plugins? =

Most automatic translation plugins focus on generating translated text or proxying translated pages. AI Translation Workflow focuses on the controlled publishing layer around that work: WordPress-native translated posts and pages, localized URLs, source mapping, stale-source detection, hreflang, QA checks, review evidence, runtime text, and repair operations before publishing.

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

= 0.1.508 =
* Derives source-work reservation schema guidance and legacy fallback handling from the work item catalog.

= 0.1.507 =
* Derives queue status schema and filters from the work item catalog so source-work states stay aligned with registered definitions.

= 0.1.506 =
* Lets source-work definitions own heartbeat action metadata for source-scoped work, keeping queue and assignment behavior aligned.

= 0.1.505 =
* Extends source-work definitions to own queue totals and source-state actions, reducing duplicated work-type registration.

= 0.1.504 =
* Deepens source-work definitions so each work type owns its queue builder, removing the parallel source-work switch.

= 0.1.503 =
* Makes the heartbeat action map the source of truth for supported obligations, ordering, coverage, and draft-work identity.

= 0.1.502 =
* Deepens heartbeat assignment policy so source/draft work identity and same-actor repeat protection share explicit workflow seams.

= 0.1.501 =
* Prevents the same heartbeat actor from being assigned the same source-scoped work item again after it just handled that source action.

= 0.1.500 =
* Deepens source-work candidate discovery so content integrity, design, and taxonomy queues share one work-type definition seam.

= 0.1.499 =
* Deepens the source-work queue seam so source content, design, and taxonomy work share one reservation-aware queue entry builder.

= 0.1.498 =
* Moves source taxonomy review policy into a dedicated module so category/tag review evidence, hashes, and term-sprawl decisions have one workflow seam.

= 0.1.497 =
* Adds explicit source-design completion policy metadata so contributors can choose a no-rewrite review completion when the rendered source page is already suitable.

= 0.1.496 =
* Moves publication-experience readiness into a dedicated module so review and publish gates share one publication surface seam.

= 0.1.495 =
* Deepens the source-design review policy seam so queue, source inheritance, publication experience, source publish guard, and translation fitness share one gate state.

= 0.1.494 =
* Lets the source-design review completion path satisfy source inheritance, publication experience, and translation fitness gates for the current source hash.

= 0.1.493 =
* Adds a hash-bound source-design review completion path so contributors can mark already-good source posts reviewed without rewriting them.

= 0.1.492 =
* Carries presentation contract metadata on source taxonomy review work items so release/status notes keep their release-note contract instead of falling back to the editorial-post default during taxonomy review.

= 0.1.491 =
* Deepens the internal workflow architecture with dedicated ability dispatch, assignment authority, quality snapshot, workflow read-model, and presentation adapter modules.
* Keeps existing ability names and response contracts unchanged while concentrating claim identity, QA input, queue snapshot, and presentation resolution logic behind narrower module interfaces.

= 0.1.490 =
* Preserves the last concrete heartbeat work item when a session receives wait or escalation state, preventing the same actor from being steered back to a source item it just released after a blocker or source-side fix.

= 0.1.489 =
* Hardens source work reservations against concurrent heartbeat claims so a failed atomic add returns a conflict instead of updating another contributor's source claim.
* Adds a heartbeat claim identity check so next-heartbeat-action will not hand a contributor a local claim when the returned reservation belongs to another session or actor.

= 0.1.488 =
* Adds a first-class source taxonomy review queue item and mark-source-taxonomy-reviewed ability so source post categories/tags are checked for topical fit, singleton terms, existing-term reuse, and avoidable term sprawl before translations mirror them.
* Blocks translation taxonomy mirroring when the source taxonomy review is missing or stale, and requires concrete keep/replace/remove evidence for assigned singleton terms before the source review can be marked complete.

= 0.1.487 =
* Fixes source-design fragment role guardrails so Chinese, Japanese, and Korean localized fragments are measured by CJK text units instead of whitespace-delimited words, preventing false short-fragment blockers on valid translations.

= 0.1.486 =
* Adds a read-only translation-aware taxonomy term listing ability for source categories, tags, localized mappings, expected slugs, and archive descriptions, and points draft-write assignments to it before taxonomy mirroring.

= 0.1.485 =
* Marks successful source-design reprojection as needs review after reconciling stale source state, even when projected block content is already identical.

= 0.1.484 =
* Uses full runtime mailto href overrides before falling back to subject/body replacements so localized footer contact links do not keep English payload text.

= 0.1.483 =
* Requires workers to confirm source category fit before mirroring categories to translated posts, and broadens runtime mailto localization beyond share buttons.

= 0.1.482 =
* Requires an explicit reader-value decision for translated category and tag archive descriptions: provide useful localized descriptions, or explain why a term should intentionally remain undescribed.

= 0.1.481 =
* Fixes URL-hierarchy repair coverage for older translated posts and localizes split Akismet comment notices as one runtime-text surface.

= 0.1.480 =
* Broadens heartbeat assignment scans and adds an assignment-coverage audit so visible workflow obligations cannot silently fall out of the assignment path.

= 0.1.479 =
* Lets presentation-surface labels use runtime source-label overrides such as `Published` when explicit label keys are missing.

= 0.1.478 =
* Narrows injected comment-notice localization to phrase-level comment-form overrides so short field labels do not rewrite prose.

= 0.1.477 =
* Applies runtime `comment_form_text` overrides during frontend text replacement so injected comment notices can use operator-provided localized text.

= 0.1.476 =
* Carries reader-action candidates from the presentation contract into source-design and work-item payloads so workers can choose useful destinations with less friction.

= 0.1.475 =
* Carries presentation contract worker decision briefs into source-design and work-item payloads so workers get better guidance before choosing release-note or editorial actions.

= 0.1.474 =
* Adds a content-hash-bound source publish gate handoff so validated Site Presentation pattern repairs can save without weakening the public source design gate.

= 0.1.473 =
* Adds a required Devenia design-source gate for source-design repair: workers must inspect the live Gutenberg style guide, available patterns, GenerateBlocks pattern libraries, and a comparable live Devenia page before rebuilding article designs.

= 0.1.472 =
* Adds presentation-contract metadata to source-design work items so release notes, editorial articles, and future templates are repaired against the right design contract instead of one generic article shape.

= 0.1.471 =
* Fixes translated paginated blog archive SEO metadata so canonical URLs and Rank Math titles use the real archive page number.

= 0.1.469 =
* Consolidates work-item planning, queue/status read models, translation payload reads, and provenance helpers behind the shared Work Item Catalog seam.
