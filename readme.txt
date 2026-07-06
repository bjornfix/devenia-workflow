=== AI Translation Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.439
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

= 0.1.439 =
* Adds source editorial design-gate failures to translation fitness scans so legacy flat source posts cannot make translated pages look clean before the source design is repaired.

= 0.1.438 =
* Rewrites WordPress query-style content shortlinks to canonical localized permalinks and flags non-canonical `page_id`/`p`/`post_id` hrefs in translation QA.
* Outputs translated blog pagination as canonical `/page/n/` URLs and redirects legacy `devenia_blog_page` query requests.

= 0.1.437 =
* Keeps existing stored source-design fragments when saving a partial localized fragment update.

= 0.1.436 =
* Preserves source-design links when projecting plain localized fragments, supports partial fragment updates from stored fragments, and stores RTL-aware source-design hashes.

= 0.1.435 =
* Suppresses translated comment-feed and oEmbed discovery links when WordPress would advertise endpoints that return 404 on localized routes.

= 0.1.434 =
* Localizes Rank Math JSON-LD author archive IDs on translated author archive routes.

= 0.1.433 =
* Starts author archive URL localization on translated author paths even when WordPress does not classify the request as a native author archive.

= 0.1.432 =
* Reconstructs localized author archive context from the request path when late output callbacks no longer have query vars.

= 0.1.431 =
* Localizes source author archive JSON-LD IDs with fragment suffixes on translated author archives.

= 0.1.430 =
* Routes localized author archive pagination URLs so `/page/n/` archive links do not 404.
* Normalizes malformed source author archive head links on localized author archives without touching language-menu author links.

= 0.1.429 =
* Keeps translated internal-link repair on published frontend targets, falling back to the source URL when a localized translation is still unpublished.
* Detects stale links to draft localized translations during internal-link repair instead of treating them as safe localized URLs.

= 0.1.428 =
* Blocks language-specific supplemental source-design fragments unless exactly one target translation is selected.

= 0.1.427 =
* Adds source-design fragment support for visible static core/list item text so localized lists survive reprojection safely.
* Keeps list structure in the design signature while treating list item text as translatable content.

= 0.1.426 =
* Avoids false semantic-mismatch flags for CJK localized fragments that are long enough by character length but short by whitespace word count.

= 0.1.425 =
* Blocks stale exact-key source-design migrations when reused fragment keys appear to map to different source text.

= 0.1.424 =
* Ignores localized core/image alt text in source-design HTML signatures so translated image alt does not create false design mismatches.

= 0.1.423 =
* Keeps localized featured-image alt text in inherited core/image blocks during source-design reprojection.
* Treats core/image alt as localized media text in the source-design signature so translated image alt can differ safely.

= 0.1.422 =
* Adds source-design fragment support for visible core/table cell text so localized table copy can survive reprojection safely.
* Keeps table structure in the design signature while treating cell text as translatable content.

= 0.1.421 =
* Preserves Gutenberg block-comment JSON while normalizing stale GenerateBlocks dynamic container wrappers.

= 0.1.420 =
* Normalizes stale GenerateBlocks dynamic container wrapper markup before storage integrity checks so source-design repairs can be saved safely.

= 0.1.419 =
* Fixes localized archive HTML safety so runtime text replacement does not corrupt attributes or media URLs, and avoids nested featured-image links when Clickable Featured Image is active.

= 0.1.418 =
* Updates lifecycle regression to match the independent-review workflow: the write test now proves writer upsert, QA, publish blocking, self-review blocking, and writer publish-update blocking in one actor session.

= 0.1.417 =
* Passes codex_thread_id through lifecycle regression write tests so the regression exercises the same workflow authority seam as production upserts.

= 0.1.416 =
* Rejects source-language URL vocabulary for languages that require transliterated URLs, so drafts must use target-language transliteration instead of English/source-based ASCII slugs.
* Adds the same transliteration URL contract to route QA/readiness so existing translations with bad slugs enter the review/fix queue.

= 0.1.415 =
* Speeds up heartbeat assignment by using a compact indexed obligation model instead of expanding full translation payloads for every candidate source.
* Keeps publication out of the fast heartbeat path so speed improvements do not weaken publish gates.

= 0.1.414 =
* Ignores intentional language-switcher hreflang anchors in frontend link integrity checks so localized homepages are not blocked by their own language selector.

= 0.1.413 =
* Adds rendered frontend integrity checks for localized public surfaces so runtime widget, footer, menu, and shared chrome source-language remnants are caught by the workflow.
* Blocks publication experience readiness when published translations render source-language remnants that are not visible in stored Gutenberg content.
* Applies configured language naturalness rules to rendered frontend surfaces, starting with a Swedish guard against `inge förtroende` in public agency copy.

= 0.1.412 =
* Validates exact pending source block content during source design repairs and prefers the shared prefixed source-design validation seam.

= 0.1.411 =
* Aligns heartbeat backlog scanning with the translation queue and lets the scheduler try the next safe obligation on an item before waiting.

= 0.1.410 =
* Prevents any actor that changed visible media or localized image alt text from receiving or marking any review stage for that same translation.

= 0.1.409 =
* Adds post-localized featured-image alt text for translated posts and makes presentation/archive pagination labels read from the same runtime text contract workers can update.

= 0.1.408 =
* Broadens the default heartbeat source scan to 500 so older missing translations can be claimed without manual limit overrides.

= 0.1.407 =
* Prevents an actor that already wrote review evidence for a translation from backfilling another review stage on the same translation.

= 0.1.406 =
* Adds persistent visible-media provenance so the actor that fixed featured or hero media cannot quality-review, final-review, or publish that same visible surface.

= 0.1.405 =
* Adds explicit heartbeat review-surface guidance so draft quality reviews use the presentation surface instead of treating expected public 404s as blockers.

= 0.1.404 =
* Prevents stale linguistic, quality, and final review obligations from being reassigned to the same actor that wrote the review evidence being replaced.

= 0.1.403 =
* Makes heartbeat health collision checks use only fresh/live sessions so stale heartbeat history cannot block a renewed actor session after a stale assignment is released.

= 0.1.402 =
* Moves translation reservation schemas, reserve/release/list operations, and the shared claim write gate into a dedicated workflow module.

= 0.1.401 =
* Moves featured-image repair and canonical thumbnail reads into a dedicated module seam, and adds an all-PHP-file syntax preflight for faster local feedback before the full release gate.

= 0.1.400 =
* Makes featured-image repair use the translation index source of truth when repairing selected sources.

= 0.1.399 =
* Makes featured-image repair a workflow-controlled visible media write with actor provenance, reservation checks, and review invalidation.

= 0.1.398 =
* Requires quality reviewers to explicitly review featured/hero image suitability, including semantic fit, embedded text, currentness, crop, and functional value for the article.

= 0.1.397 =
* Makes translation creates atomic at the workflow interface: taxonomy and localized-path failures are preflighted before insert, and failed post-save creates are rolled back instead of leaving orphan drafts.

= 0.1.396 =
* Makes source-filtered translation listing use the same translation index source of truth as source payloads, so newly created drafts remain visible to workers and coordinators.

= 0.1.395 =
* Lets heartbeat workers claim production draft work for missing translations and source-design reprojection instead of only review/publish obligations.
* Includes missing-translation and source-reprojection totals in workflow obligations so production work remains visible without manual pointing.

= 0.1.394 =
* Respects existing Rank Math descriptions in presentation surfaces and avoids fallback meta snippets ending on dangling connector words.

= 0.1.393 =
* Adds a workflow-controlled translation author repair ability so visible byline mismatches can be fixed without bypassing translation storage guardrails.

= 0.1.392 =
* Tightens runtime-text provenance checks so a later edit to one shared language runtime key cannot hide another actor's still-current runtime correction during review assignment or evidence freshness checks.

= 0.1.391 =
* Requires quality reviewers and heartbeat workers to make an explicit rendered-page design judgment, consider alternative design solutions, and explain why the chosen design fits the article.

= 0.1.390 =
* Carries heartbeat design-ownership requirements into the selected work payload, making the worker contract visible as structured assignment data.

= 0.1.389 =
* Adds explicit heartbeat design-ownership requirements so workers must fetch the Site Presentation article contract and own the design judgment instead of relying on persona labels, checklists, or later reviewers.

= 0.1.388 =
* Blocks GenerateBlocks dynamic containers that store frontend wrapper markup, preventing nested-container layouts from passing QA as visually valid articles.

= 0.1.387 =
* Stops inferred diacritic-shadow checks from blocking valid French words such as `risque` and `demande` when related accented forms also appear in the same article; explicit protected shadow terms remain enforced.

= 0.1.386 =
* Adds a server-generated heartbeat independence proof to selected work items so workers can distinguish current safe assignments from stale local self-review assumptions.

= 0.1.385 =
* Adds required localized comment-form runtime text to every packaged language and fails language-file checks when that contract is incomplete.

= 0.1.384 =
* Records provenance for runtime text changes and blocks the same actor from reviewing or publishing content that uses the changed language runtime surface.

= 0.1.383 =
* Prevents quality and final review from being assigned to or accepted from actors that already wrote the current prior review evidence.

= 0.1.382 =
* Prevents a heartbeat session from being reassigned the same unchanged item after it already returned that item.

= 0.1.381 =
* Prevents heartbeat assignment from returning a stale review obligation to the same actor that already handled that review stage.

= 0.1.380 =
* Fixes singular presentation surface labels so localized runtime author and comments text is used before English fallbacks.

= 0.1.379 =
* Makes heartbeat health depend on fresh identified expected actors, while retaining stale failed attempts as diagnostic history.

= 0.1.378 =
* Adds a read-only server-side heartbeat status ability for independent session health checks.

= 0.1.377 =
* Adds a conservative heartbeat work-assignment ability that returns one safe next action for an independent Codex session.

= 0.1.376 =
* Keeps draft translations with needs-review workflow status visible in the review queue.

= 0.1.375 =
* Allows token-backed writer fixes to already published translations while keeping them in needs-review status and preserving independent review gates.

= 0.1.374 =
* Localizes the language switcher trigger and region headings instead of exposing English screen-reader text on translated pages.

= 0.1.373 =
* Removes local workflow token fields from protected write/review/publish ability schemas.
* Requires `codex_thread_id` from `CODEX_THREAD_ID` as the only normal workflow authority identity.

= 0.1.372 =
* Lets protected write/review/publish abilities use `codex_thread_id` from `CODEX_THREAD_ID` as the normal server-side workflow authority identity.
* Makes legacy `step_token` and `step_token_label` optional compatibility inputs instead of required local bearer-token fields.

= 0.1.371 =
* Allows signed independent reviewers to review legacy translations that have usable writer process, actor, or token-label provenance even when those rows predate signed writer control scopes.
* Keeps empty writer provenance and matching writer/reviewer identities blocked so agents still cannot review their own work.

= 0.1.370 =
* Paginates quality-review queue candidate scans so compact queues can be used directly without loading large candidate sets up front.
* Reports the actual inspected candidate count and candidate query page count for operator visibility.

= 0.1.369 =
* Keeps review and quality-review queue responses compact by default, with full evidence payloads available through `detail_level=full`.
* Stops quality-review queue scans early for modified-date queues once enough actionable items are found.

= 0.1.368 =
* Allows legacy fragment migration to accept supplemental localized fragments for missing current source-design keys.

= 0.1.367 =
* Keeps legacy fragment migration responses compact unless source contracts or fragment previews are explicitly requested.

= 0.1.366 =
* Keeps legacy fragment migration dry-run output compact and blocks order-fallback writes unless explicitly allowed after review.

= 0.1.365 =
* Adds a dry-run-first source-design fragment migration ability for legacy translations before reprojection.

= 0.1.364 =
* Adds explicit operator warnings to fail-closed self-review and review-independence denials.

= 0.1.363 =
* Requires signed control-scope provenance for protected translation reviews and publishing.
* Marks review evidence invalid when writer and reviewer share the same control chain or the reviewer is not an independent session.

= 0.1.362 =
* Adds a localized term archive self-redirect repair ability so SEO plugin redirects cannot mask translated category and tag archive URLs.

= 0.1.361 =
* Allows direct translated-content text and URL fixes when the translation was already source-design-stale and the save does not change its Gutenberg design signature.

= 0.1.360 =
* Fixes localized category/tag archive pagination so `/page/N/` routes resolve through the same translated term query as page one.

= 0.1.359 =
* Requires explicit writer process IDs for protected draft writes and source-design reprojection so workflow tokens cannot fall back to a generic WordPress operator identity.

= 0.1.358 =
* Makes Frontend Text Edit REST request detection robust across normal HTTP and CLI-based smoke tests.

= 0.1.357 =
* Restores authenticated Frontend Text Edit saves by treating its text-only REST endpoint as a copy-edit surface instead of a generic source-design save.
* Keeps hard Gutenberg storage and invalid-link guardrails active for frontend text edits.

= 0.1.356 =
* Ignores duplicated rendered HTML text in structured data-bearing blocks such as FAQ blocks when comparing source-design signatures.

= 0.1.355 =
* Keeps dynamic `[devenia_presentation]` blocks source-owned during source-design reprojection so localized bylines, titles, excerpts, and author links render from the shared presentation surface instead of stale stored fragments.

= 0.1.354 =
* Adds a narrow integration seam for validated Site Presentation contract stamping on translated posts without weakening generic manual-save design guardrails.

= 0.1.353 =
* Clarifies the protected workflow schema so authority-issued session tokens are described as reusable session credentials that are validated per requested workflow step.
* Keeps the external token-authority gate strict: the authority must still return the exact requested workflow step, verified process ID, and token label before protected writes, reviews, or publishing can proceed.

= 0.1.352 =
* Lets localized runtime text rewrite Scriptless Social Sharing email share subjects and bodies.

= 0.1.351 =
* Allows reviewed translations to move from draft to published status without tripping direct-save storage guardrails.

= 0.1.350 =
* Lets presentation-surface comment labels come from runtime language text instead of a hardcoded English fallback.

= 0.1.349 =
* Allows authorized translation upsert writes to store projected source-design content without being blocked by direct-save design-drift guardrails.

= 0.1.348 =
* Treats source and localized author archive URLs as valid internal archive links in translation link-integrity guardrails.

= 0.1.347 =
* Adds a dry-run mode to `ai-translations/publish-translation` so the full publish gate can be tested without changing live content.
* Blocks manual WordPress source-post publishing when the shared Devenia editorial design gate fails or is unavailable.
* Fixes source-post quality verdicts so source content is not incorrectly blocked as missing its own source.

= 0.1.346 =
* Adds a shared publication-experience readiness gate for source and translated article design.
* Requires quality reviewers to provide visual design, desktop, mobile, source-design, and visual-evidence review fields.
* Requires final review and publish gates to honor the same publication-experience readiness, so a failed source design blocks all downstream translations.

= 0.1.345 =
* Blocks source-design inheritance and reprojection when the source post fails the shared Devenia editorial source-design validation.
* Reports proposed source editorial validation in `ai-translations/production-flow` so agents see design-gate failures before writing.

= 0.1.344 =
* Stores localized source-design fragments during translated content upserts so existing translations can inherit later source design changes.
* Adds `ai-translations/reproject-source-design` to rebuild translations from the current source Gutenberg block tree without redesigning each language separately.
* Reports design inheritance state on translation payloads and blocks direct translated-content saves that would alter the source-owned design tree.

= 0.1.343 =
* Requires quality reviewers to document real-reader decision safety, current-state claims, and historical context handling before a page can pass quality review.
* Requires final reviewers to confirm reader decision-safety evidence before publishing.
* Exposes the decision-safety/currentness requirement in workflow policy output.

= 0.1.342 =
* Extends `ai-translations/production-flow` with read-only proposed source update impact analysis so agents can see when a source rewrite would require translation reprojection before it is safe to apply.
* Adds a dedicated reprojection lane to keep source-design inheritance work visible without turning open reviews into a global production stop.

= 0.1.341 =
* Adds `ai-translations/workflow-obligations`, a read-only queue summary that keeps open linguistic, quality, final-review, and publish obligations visible without blocking new draft/write production work.
* Adds `ai-translations/production-flow`, a read-only workflow dashboard that separates production, review, and publish lanes for agents.
* Clarifies the workflow policy in machine-readable output: open reviews must not be overlooked, but only publishing the specific translation is blocked until current review evidence exists.

= 0.1.340 =
* Stores JSON post meta with WordPress slashing so review evidence containing quoted text remains valid JSON when read back for workflow readiness.

= 0.1.338 =
* Requires concrete review evidence for linguistic, quality, and final review gates so checkbox-only or generic approvals cannot publish translations.
* Allows reviewers to approve already-good copy without unnecessary edits when they document what was checked and why no change is needed.

= 0.1.337 =
* Restores translated post modified timestamps after WordPress publish status updates so publish-only transitions do not invalidate current review evidence.

= 0.1.336 =
* Preserves translated post modified timestamps during publish-only transitions so freshly completed quality and final review evidence is not immediately made stale when no content changed.

= 0.1.335 =
* Stores and validates stable reviewer actor identities from the token authority, making self-review denial independent of process IDs or token-label naming.

= 0.1.334 =
* Ignores inert Gutenberg parser whitespace blocks in the source-design QA signature, so unchanged layouts are not blocked by trailing freeform serialization noise.

= 0.1.333 =
* Normalizes translated Gutenberg content before computing the source-design QA signature, avoiding false source-design mismatches after storage-format refreshes when the block tree is unchanged.

= 0.1.332 =
* Blocks source and localized taxonomy slugs that contain WordPress duplicate suffixes such as `-2`, reports the blocking term when one exists, and removes numeric fallback slugs from the translation workflow.
* Ensures existing translated taxonomy terms are realigned to the current `language-source-slug` standard when a source term slug is repaired.

= 0.1.339 =
* Allows translation quality reviews to document a draft review surface by using `review_surface=presentation_surface` with `presentation_surface_post_id`, while keeping public URL evidence for published frontend reviews.

= 0.1.331 =
* Enforces the language-prefix taxonomy slug standard, such as `it-marketing` and `nl-marketing`, so translated category and tag URLs stay consistent across languages.
* Moves source-design inheritance and taxonomy-localization workflow logic into dedicated include modules to reduce the main plugin file size and improve locality.

= 0.1.330 =
* Requires the external token authority to return verified label, process, and workflow-step identity before draft, review, or publish operations proceed.
* Records writer provenance from the verified authority decision instead of client-supplied writer fields.
* Records reviewer provenance from the verified authority decision and removes fallback to client-supplied reviewer identity for protected review gates.

= 0.1.329 =
* Passes writer/reviewer process IDs to the external token-authority gate so confirmed session tokens can be bound to the approved process/session lease.
* Updates token-gate descriptions to use authority-issued session tokens instead of external raw token sets.

= 0.1.328 =
* Adds a third independent final-review gate before publishing translations.
* Blocks the writer process, actor, or token label from reviewing or publishing its own translated content.
* Adds the `final_review` step-token gate for owner-scoped token authorities.

= 0.1.327 =
* Moves translation workflow step-token verification behind an external token-authority filter so token storage and rotation live outside this workflow plugin.

= 0.1.326 =
* Adds step-specific token gates for draft writes, linguistic review, quality review, and publishing, and rejects tokens that validate for more than one step.
* Requires separate reviewer process provenance for translated linguistic and quality review evidence.
* Stops the legacy status marker from promoting translations to reviewed or published.

= 0.1.325 =
* Adds source-design inheritance so translated posts/pages can be built from localized text fragments projected into the source block tree.
* Extracts and projects structured text attributes generically so FAQ/how-to style blocks are not tied to one SEO plugin.
* Adds a source design-signature guardrail so translators cannot alter layout, classes, block attributes, or media while translating; only data-driven RTL mirroring may differ.

= 0.1.324 =
* Adds one shared localized presentation surface for singular content, archives, comments, language links, labels, actions, media, and runtime public text.
* Keeps Gutenberg, GeneratePress, and template adapters out of the translation layer; presentation plugins consume this surface instead.

= 0.1.323 =
* Adds the Rank Math FAQ semantic link-count adapter.

= 0.1.322 =
* Adds an adapter seam so SEO add-ons can exclude non-content links from source/translation structure parity.
* Narrows escaped-markup detection to visible `u003c`/`u003e` text instead of valid Gutenberg JSON escaping.

= 0.1.321 =
* Adds a Gutenberg guardrail that blocks escaped HTML markup literals such as visible u003c before they can be saved as page text.

= 0.1.320 =
* Fixes translated post/page creation after the translation-fitness interface consolidation by passing guardrail context through the current array-based signature.

= 0.1.319 =
* Deepens the translation-fitness scan architecture by splitting filters, index health, ID selection, item evaluation, and response shaping behind the same public scan interface.
* Centralizes translation workflow post-status defaults so future/scheduled translations stay covered consistently.
* Normalizes fitness guardrail context handling and cleans up critical QA schema/regression readability.

= 0.1.318 =
* Includes scheduled/future translations in default fitness scans so the default scan covers the full active translation workflow index.

= 0.1.317 =
* Scans every post type managed by the translation workflow by default, while still allowing callers to narrow the scan explicitly.

= 0.1.316 =
* Rebuilds the translation index from actual translation metadata instead of a capped content query, so scan rebuilds do not drop valid translations.

= 0.1.315 =
* Adds translation index health reporting and optional rebuild support to the translation-fitness scanner.
* Moves regression-only language identity data into explicit runtime profile fixtures instead of generic profile patches.
* Cleans up translation fitness module locality so policy, dimensions, and guardrails stay behind one stable interface.

= 0.1.314 =
* Adds a general translation-fitness scanner so stored translations can be audited through the same QA modules used by save and review gates.
* Moves wrong-language carryover detection into a separate language-integrity dimension.
* Adds runtime quality profile fields for language identity markers, so language-specific detection data can live in WordPress options instead of plugin code.
* Adds an internal ability dispatch integrity check to catch half-registered workflow operations before release.

= 0.1.313 =
* Adds a wrong-language carryover guardrail that detects when visible copy matches another configured target-language profile.
* Adds regression coverage so target-language copy cannot pass silently inside another language's translation.

= 0.1.312 =
* Extends translation QA text extraction to Rank Math FAQ block question data so visible FAQ copy is checked by the same carryover guardrails as normal paragraphs.
* Adds a regression case for English leftovers inside localized FAQ answers.

= 0.1.311 =
* Adds a QA guardrail for short copied source-language sentences that are too small for long-fragment carryover checks.
* Adds a regression case so short English leftovers in translated body copy cannot pass silently.

= 0.1.310 =
* Moves vendor-specific SEO, sitemap, and theme hook integrations out of the core workflow file and into optional addons so the public core remains theme-neutral.

= 0.1.309 =
* Reads featured image IDs from canonical postmeta during translation sync and repair so stale meta caches cannot hide thumbnail drift.
* Adds source/language translation work reservations with queue visibility and write gates so parallel agents do not overwrite the same translation work.

= 0.1.308 =
* Applies localized Rank Math meta descriptions to translated blog and author archive SEO, Open Graph, and Twitter description surfaces.

= 0.1.307 =
* Follows Rank Math title templates and separators for localized author and translated blog archive SEO titles.

= 0.1.306 =
* Supports localized category and tag descriptions in translated post taxonomy input.

== 0.1.305 ==
* Localizes theme-rendered category and tag meta labels on translated archive and post surfaces.

== 0.1.304 ==
* Applies translated frontend language context to localized category and tag archives, including menus, locale, and HTML language attributes.

== 0.1.303 ==
* Sorts localized blog archives with local-language posts first by their own last-updated date, followed by source-language fallback posts.

== 0.1.302 ==
* Localizes Devenia single post hero author links, author names, and modified-date metadata through runtime language data.

== 0.1.301 ==
* Uses the localized author archive author ID fallback for SEO, Open Graph, Twitter, and document titles.

== 0.1.300 ==
* Restores language switcher links on localized author archives that use curated author post lists.
* Stores author archive hero and CTA button URLs in runtime WordPress data.

== 0.1.299 ==
* Orders localized author archives with local translations from that author first, followed by that author's source-language posts.
* Localizes archive read-more buttons from runtime WordPress text values.

== 0.1.298 ==
* Uses runtime author archive data for translated author archive SEO, Open Graph, Twitter, and document titles.

== 0.1.297 ==
* Fixes translated byline author-link attribute escaping and uses runtime author names inside byline links.

== 0.1.296 ==
* Localizes translated post author bylines and points byline author links at runtime-managed localized author archives.

== 0.1.295 ==
* Adds runtime-managed author archive translation queue, localized routing, language links, and presentation context filtering.

== 0.1.294 ==
* Prevents translated category/tag creation from reusing the source term when a localized slug collides with the source slug.
* Repairs source category/tag terms that were accidentally marked as translated terms by earlier collision handling.

== 0.1.293 ==
* Adds runtime-managed share text and localizes Scriptless Social Sharing screen-reader labels on translated frontend pages.

== 0.1.292 ==
* Adds partial source-language fragment detection so copied English sentence middles cannot pass translation QA inside otherwise localized copy.

== 0.1.291 ==
* Treats deliberate Rank Math SEO titles as current copy instead of requiring them to mirror the visible page title.
* Restores long source-language fragment detection so copied source paragraphs cannot pass translation QA.

== 0.1.290 ==
* Removes the old bundled Quick Copy Edit implementation while preserving active heading-fit frontend assets.

== 0.1.289 ==
* Restores the bundled inactive frontend editing helpers after the 0.1.288 cleanup caused a frontend fatal on some pages.

== 0.1.288 ==
* Removes the old bundled Quick Copy Edit implementation after extracting frontend editing to Frontend Text Edit.

== 0.1.287 ==
* Moves active frontend text editing out to the separate Frontend Text Edit plugin.

== 0.1.286 ==
* Keeps FAQ answer edit targets tight by marking the matching inner answer paragraph when available.

== 0.1.285 ==
* Keeps Quick Copy Edit active on FAQ answers rendered with nested markup.

== 0.1.284 ==
* Adds Quick Copy Edit support for linked list-item text while preserving anchor URLs.

== 0.1.283 ==
* Adds adapter-provided rendered segment selectors so Rank Math FAQ questions and answers remain editable when frontend markup differs from stored block HTML.

== 0.1.282 ==
* Marks all Quick Copy Edit text segments in the same rich HTML block together, so Rank Math FAQ questions and answers are all editable.

== 0.1.281 ==
* Registers the Rank Math FAQ Quick Copy Edit segment adapter independently of Rank Math frontend SEO hooks.

== 0.1.280 ==
* Adds adapter-based Quick Copy Edit text segments for rich stored block markup.
* Lets Quick Copy Edit reach inline rich paragraph text without flattening the whole block.
* Adds Rank Math FAQ segment support and keeps FAQ question/answer attributes synchronized after frontend edits.

== 0.1.279 ==
* Adds runtime text and language-code filters for companion plugins that need localized labels without reading internal options.

== 0.1.278 ==
* Reads localized date digit shaping from runtime language configuration instead of PHP language branches.

== 0.1.277 ==
* Requires translated blog archive metadata labels and date formats to come from runtime language configuration fields, not PHP values.
* Adds a public release gate against reintroducing translated blog archive labels or language-specific date-format maps in PHP.

= 0.1.276 =
* Reads translated blog archive metadata labels and date formats from runtime language configuration instead of PHP language maps.

= 0.1.274 =
* Localizes translated blog archive metadata labels for the newest language archives.

= 0.1.273 =
* Prevents localized blog archive date formatting from fataling when WordPress uses a numeric timezone offset.
* Adds explicit short-date formats for Portuguese, Chinese, Japanese, and Vietnamese archives.

= 0.1.272 =
* Moves vendor-specific Rank Math, Elementor, and GenerateBlocks dependencies behind optional addon seams.
* Adds a public release gate that keeps vendor-specific hooks, metadata writes, and identifiers out of theme-neutral core.

= 0.1.271 =
* Removes the obsolete slug-unlock wrapper from public core after site-specific slug-lockdown handling was moved out.
* Keeps internal write paths routed only through the guardrail suspension modules that still perform work.

= 0.1.270 =
* Prevents historical pre-public migration tasks from running on fresh installs.
* Replaces exported site-style menu names with neutral packaged language menu defaults.
* Removes the site-specific slug-lockdown integration from public core.
* Extends the public release gate to block those site-specific integrations from returning to core.

= 0.1.269 =
* Excludes GitHub/development README content from release archives.
* Extends the public release gate so WordPress release ZIPs use readme.txt as the public readme surface.

= 0.1.268 =
* Registers optional vendor addon hooks only when the matching surface is present.
* Cleans up the ability operation dispatcher indentation.

= 0.1.267 =
* Adds an administrator notice when the WordPress Abilities API is unavailable.
* Cleans up indentation around ability registration helpers.

= 0.1.266 =
* Makes the public release gate build a temporary git archive and fail if development-only paths are present.

= 0.1.265 =
* Excludes development tools from git archive release packages.
* Adds a public release gate for archive export rules.

= 0.1.264 =
* Adds explicit uninstall cleanup for plugin-owned options and custom workflow tables while preserving WordPress content.
* Adds a public release gate that requires uninstall cleanup to stay present.

= 0.1.263 =
* Moves Rank Math and Elementor-adjacent hook registration into optional addon files.
* Extends the public release gate so vendor integration hooks stay out of the theme-neutral core.

= 0.1.262 =
* Moves GeneratePress presentation-meta sync behind the optional GeneratePress addon so the core workflow stays theme-neutral.

= 0.1.261 =
* Restores the WordPress text domain to the plugin slug expected by WordPress.org package checks.
* Removes hidden development files from the distributable plugin ZIP.
* Documents intentional GeneratePress hook calls in the optional GeneratePress addon for Plugin Check.

= 0.1.260 =
* Makes the translated posts-page template use standard WordPress markup and plugin-owned hooks instead of direct GeneratePress hooks.
* Moves GeneratePress and GenerateBlocks hook registration into optional addon files.
* Keeps GeneratePress and GenerateBlocks compatibility styles out of the default frontend stylesheet.
* Allows the language selector to attach to a standard WordPress menu when a theme does not use a `primary` menu location.

= 0.1.259 =
* Moves RTL layout and translated blog archive frontend CSS out of inline PHP into versioned asset files.
* Centralizes plugin asset URL and filemtime version handling for frontend styles and scripts.

= 0.1.258 =
* Prepares public-release metadata by replacing private site-specific wording with generic plugin, ability, and documentation language.
* Adds input-normalization and translation-fitness policy seams for safer future refactors.
* Moves language-menu styling toward versioned frontend assets.

= 0.1.257 =
* Allows a small number of extra reviewed internal links in translated content when each added link resolves to known internal content and no source internal link is lost.
* Keeps malformed, external, and excessive added links blocked by the existing QA guardrails.

= Earlier versions =
Older workflow changes are kept in the project repository history.

== Upgrade Notice ==

== 0.1.278 ==
Reads localized date digit shaping from runtime language configuration.

== 0.1.277 ==
Requires translated blog archive metadata labels and date formats to be stored in runtime language configuration.

= 0.1.276 =
Moves translated blog archive labels and date formats to runtime language configuration.

= 0.1.274 =
Localizes translated blog archive metadata labels for the newest language archives.

= 0.1.273 =
Prevents a frontend fatal on localized blog archives when WordPress stores timezone as a numeric offset.

= 0.1.272 =
Keeps vendor-specific dependencies behind optional addons so core stays theme-neutral.

= 0.1.271 =
Removes an obsolete internal wrapper left after the public-core cleanup.

= 0.1.270 =
Keeps fresh installs free of historical pre-public migration side effects.

= 0.1.269 =
Keeps development README content out of WordPress release ZIPs.

= 0.1.268 =
Registers optional vendor addon hooks only when the matching surface is present.

= 0.1.267 =
Adds an administrator notice when the WordPress Abilities API is unavailable.

= 0.1.266 =
Adds an archive-level public release check for development-only paths.

= 0.1.265 =
Excludes development tools from git archive release packages.

= 0.1.264 =
Adds explicit uninstall cleanup for plugin-owned options and custom workflow tables.

= 0.1.263 =
Moves Rank Math and Elementor-adjacent hook registration into optional addon files.

= 0.1.262 =
Moves GeneratePress presentation-meta sync behind the optional GeneratePress addon.

= 0.1.261 =
Fixes WordPress.org package checks for the public plugin slug and optional vendor addon hooks.
