# AI Translation Workflow

Portable workflow layer for AI-assisted multilingual WordPress content.

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 0.1.418
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Tags:** translations, ai, workflow, wordpress, multilingual

## What It Does

AI Translation Workflow gives site operators a controlled way to create and
maintain multilingual content as ordinary WordPress pages and posts. The
translated content stays in WordPress, so the plugin can be removed later
without rebuilding the site from a proprietary translation store.

Around that native content model, the plugin adds localized URLs, mapping
metadata, stale-source detection, hreflang output, language-menu sync, QA
guardrails, review evidence, frontend copy editing, reviewer learning, repair
tools, and publish checks.

The plugin is a workflow and quality layer, not an automatic translation SaaS.
Translation text can come from an AI assistant, automation client, or editor;
AI Translation Workflow keeps the WordPress side reliable, reviewable,
publishable, and portable.

Logged-in editors can make supported text fixes directly on the rendered page
with the companion Frontend Text Edit plugin. Those saves go back through the stored Gutenberg content,
normal WordPress permissions, and the plugin's storage guardrails.

Human edits and reviewer feedback can also become reusable learning: keep a
manual change as reviewer-style guidance, promote it into a QA rule, or ignore
it when it is only a one-off correction.

It is not a WPML or Polylang replacement. English content remains the technical
source of truth for downstream translations, but a post or page may now be
authored first in another configured language. In that case the plugin queues
the authored original for English source generation, then requires source review
before downstream translations can use the generated English source.

The core plugin is theme-neutral. Theme or builder integrations live in optional
`addons/` files and are loaded only when the matching vendor surface is present.

## Positioning

Most AI translation plugins compete on automatic generation, number of
languages, or external translation services. AI Translation Workflow is built
for teams that want the translation work to remain ordinary WordPress content
instead of being locked into a proxy layer, external store, or replacement
content system.

The plugin can then add source mapping, localized routing, hreflang, QA checks,
review evidence, frontend copy editing, reviewer learning, language-menu sync,
runtime text, and repair operations around that portable WordPress content.

That makes it closer to a controlled multilingual publishing workflow than a
one-click translation engine.

## No Content Lock-In

Uninstalling the plugin removes plugin-owned options and workflow tables, but it
does not delete translated posts, pages, menus, terms, media, or regular
WordPress content.

If you stop using AI Translation Workflow, the workflow automation, localized
routing helpers, hreflang output, menus sync, and QA gates stop with it. The
translated content itself remains editable in WordPress, so the site can be
continued manually, migrated to another multilingual setup, or archived without
rebuilding pages from scratch.

## Frontend Editing And Learning

Frontend Text Edit lets authorized editors fix supported visible text directly on
the frontend. The edit is saved into the WordPress block tree instead of a
separate translation database, so the correction stays with the page.

Those manual edits can feed the learning workflow. Captured before/after
changes appear in a learning inbox where they can be kept as reviewer-style
guidance, promoted into a future QA rule, or ignored. Reviewer style profiles
and copy feedback then help future translation briefs reflect the way humans
actually corrected the site.

## The Real Workflow

In practice, the useful path is:

1. read the English source with `ai-translations/get-source`
2. create or update the translation with `ai-translations/upsert-page`
3. run `ai-translations/qa-translation`
4. record linguistic review evidence
5. record quality review evidence when required
6. publish with `ai-translations/publish-translation`

When a new post or page is written first in a configured non-source language,
the plugin can place it in the authored-original intake queue automatically on
save. Operators can then read `ai-translations/authored-original-intake-queue`,
create the technical English source with
`ai-translations/create-source-from-authored-original`, review it with
`ai-translations/mark-source-generation-reviewed`, and continue the normal
translation flow from that English source.

Generic WordPress or MCP content saves of registered translations are guarded by
the same translation guardrails. They must not bypass source-language carryover,
terminology, script, structure, or link-integrity checks.

Translation saves also reject numeric duplicate slug suffixes such as `-2`.
Those suffixes mean WordPress found a route collision. The collision must be
resolved directly so localized URLs stay intentional.

## Source Of Truth

WordPress owns translated content, titles, slugs, URLs, taxonomies, menu labels,
runtime text, and page-specific QA options once those entities exist.

Packaged `languages/*.json` files are install-time defaults only. Do not use
them for live copy fixes.

Use these surfaces instead:

- page or post content for public translated copy
- WordPress menu items for existing menu labels
- `ai-translations/update-runtime-text` for shared widget, 404, menu
  fallback, or custom menu text
- `ai-translations/update-source-qa-options` for page-specific preserved
  source terms
- `ai-translations/update-quality-profile` for language-wide glossary,
  terminology, review-pattern, and script-signal rules

Source-language Gutenberg/GenerateBlocks content is the design source of truth.
Translation workers provide localized fragments, and the plugin projects those
fragments into the source block tree. When source design changes later, use
`ai-translations/reproject-source-design` so translated posts/pages inherit the
same design instead of being redesigned per language.

## Main Abilities

- `ai-translations/get-source`
- `ai-translations/authored-original-intake-queue`
- `ai-translations/update-authored-original-intake`
- `ai-translations/create-source-from-authored-original`
- `ai-translations/mark-source-generation-reviewed`
- `ai-translations/upsert-page`
- `ai-translations/migrate-source-design-fragments`
- `ai-translations/reproject-source-design`
- `ai-translations/qa-translation`
- `ai-translations/mark-linguistic-reviewed`
- `ai-translations/mark-quality-reviewed`
- `ai-translations/publish-translation`
- `ai-translations/list-translations`
- `ai-translations/workflow-status`
- `ai-translations/quality-review-queue`
- `ai-translations/quality-verdict`
- `ai-translations/update-runtime-text`
- `ai-translations/update-source-qa-options`
- `ai-translations/get-quality-profile`
- `ai-translations/update-quality-profile`
- `ai-translations/record-language-rule-event`
- `ai-translations/list-language-rule-events`
- `ai-translations/learning-inbox`
- `ai-translations/review-learning-event`
- `ai-translations/agency-copy-brief`
- `ai-translations/record-copy-feedback`
- `ai-translations/get-reviewer-style-profile`
- `ai-translations/record-reviewer-style-edit`
- `ai-translations/language-policy-status`
- `ai-translations/translation-fitness-status`
- `ai-translations/translation-fitness-scan`
- `ai-translations/lifecycle-regression-status`
- `ai-translations/frontend-performance-status`
- `ai-translations/warm-cache`
- `ai-translations/gutenberg-content-safety-scan`
- `ai-translations/repair-url-hierarchy`
- `ai-translations/repair-internal-links`
- `ai-translations/repair-featured-images`

## Documentation

- [GitHub Tags](https://github.com/bjornfix/ai-translation-workflow/tags)
- [GitHub Releases](https://github.com/bjornfix/ai-translation-workflow/releases)

## Installation

1. Build or download the release ZIP.
2. Install it through WordPress or WP-CLI.
3. Activate `AI Translation Workflow`.
4. Verify the language registry, translation index, language packs, and QA
   regression status before production use.

## Development

Before changing the plugin:

1. keep fixes in the module that owns the behavior
2. do not edit `languages/*.json` for current live page text
3. run `node tools/check-php-syntax.mjs`
4. run `node tools/check-language-policy.mjs`
5. run `node tools/check-public-release.mjs`
6. run the translation fitness and lifecycle regression abilities after deploy
   to a validation site
7. run WordPress Plugin Check before production deployment

## Changelog

### 0.1.418

- Updates lifecycle regression to match the independent-review workflow: the
  write test now proves writer upsert, QA, publish blocking, self-review
  blocking, and writer publish-update blocking in one actor session.

### 0.1.417

- Passes `codex_thread_id` through lifecycle regression write tests so the
  regression exercises the same workflow authority seam as production upserts.

### 0.1.416

- Rejects source-language URL vocabulary for languages that require
  transliterated URLs, so drafts must use target-language transliteration
  instead of English/source-based ASCII slugs.
- Adds the same transliteration URL contract to route QA/readiness so existing
  translations with bad slugs enter the review/fix queue.

### 0.1.415

- Speeds up heartbeat assignment by using a compact indexed obligation model
  instead of expanding full translation payloads for every candidate source.
- Keeps publication out of the fast heartbeat path so speed improvements do not
  weaken publish gates.

### 0.1.414

- Ignores intentional language-switcher `hreflang` anchors in frontend link
  integrity checks so localized homepages are not blocked by their own language
  selector.

### 0.1.413

- Adds rendered frontend integrity checks for localized public surfaces so
  runtime widget, footer, menu, and shared chrome source-language remnants are
  caught by the workflow.
- Blocks publication experience readiness when published translations render
  source-language remnants that are not visible in stored Gutenberg content.
- Applies configured language naturalness rules to rendered frontend surfaces,
  starting with a Swedish guard against `inge förtroende` in public agency copy.

### 0.1.412

- Validates exact pending source block content during source design repairs and
  prefers the shared prefixed source-design validation seam.

### 0.1.411

- Aligns heartbeat backlog scanning with the translation queue and lets the
  scheduler try the next safe obligation on an item before waiting.

### 0.1.410

- Prevents any actor that changed visible media or localized image alt text
  from receiving or marking any review stage for that same translation.

### 0.1.409

- Adds post-localized featured-image alt text for translated posts and makes
  presentation/archive pagination labels read from the same runtime text
  contract workers can update.

### 0.1.408

- Broadens the default heartbeat source scan to 500 so older missing
  translations can be claimed without manual limit overrides.

### 0.1.407

- Prevents an actor that already wrote review evidence for a translation from
  backfilling another review stage on the same translation.

### 0.1.406

- Adds persistent visible-media provenance so the actor that fixed featured or
  hero media cannot quality-review, final-review, or publish that same visible
  surface.

### 0.1.405

- Adds explicit heartbeat review-surface guidance so draft quality reviews use
  the presentation surface instead of treating expected public 404s as blockers.

### 0.1.404

- Prevents stale linguistic, quality, and final review obligations from being
  reassigned to the same actor that wrote the review evidence being replaced.

### 0.1.403

- Makes heartbeat health collision checks use only fresh/live sessions so stale
  heartbeat history cannot block a renewed actor session after a stale
  assignment is released.

### 0.1.402

- Moves translation reservation schemas, reserve/release/list operations, and
  the shared claim write gate into a dedicated workflow module.

### 0.1.401

- Moves featured-image repair and canonical thumbnail reads into a dedicated
  module seam, and adds an all-PHP-file syntax preflight for faster local
  feedback before the full release gate.

### 0.1.400

- Makes featured-image repair use the translation index source of truth when
  repairing selected sources.

### 0.1.399

- Makes featured-image repair a workflow-controlled visible media write with
  actor provenance, reservation checks, and review invalidation.

### 0.1.398

- Requires quality reviewers to explicitly review featured/hero image
  suitability, including semantic fit, embedded text, currentness, crop, and
  functional value for the article.

### 0.1.397

- Makes translation creates atomic at the workflow interface: taxonomy and
  localized-path failures are preflighted before insert, and failed post-save
  creates are rolled back instead of leaving orphan drafts.

### 0.1.396

- Makes source-filtered translation listing use the same translation index
  source of truth as source payloads, so newly created drafts remain visible
  to workers and coordinators.

### 0.1.395

- Lets heartbeat workers claim production draft work for missing translations
  and source-design reprojection instead of only review/publish obligations.
- Includes missing-translation and source-reprojection totals in workflow
  obligations so production work remains visible without manual pointing.

### 0.1.394

- Respects existing Rank Math descriptions in presentation surfaces and avoids
  fallback meta snippets ending on dangling connector words.

### 0.1.393

- Adds a workflow-controlled translation author repair ability so visible
  byline mismatches can be fixed without bypassing translation storage
  guardrails.

### 0.1.392

- Tightens runtime-text provenance checks so a later edit to one shared
  language runtime key cannot hide another actor's still-current runtime
  correction during review assignment or evidence freshness checks.

### 0.1.391

- Requires quality reviewers and heartbeat workers to make an explicit
  rendered-page design judgment, consider alternative design solutions, and
  explain why the chosen design fits the article.

### 0.1.390

- Carries heartbeat design-ownership requirements into the selected work
  payload, making the worker contract visible as structured assignment data.

### 0.1.389

- Adds explicit heartbeat design-ownership requirements so workers must fetch
  the Site Presentation article contract and own the design judgment instead of
  relying on persona labels, checklists, or later reviewers.

### 0.1.388

- Blocks GenerateBlocks dynamic containers that store frontend wrapper markup,
  preventing nested-container layouts from passing QA as visually valid
  articles.

### 0.1.387

- Stops inferred diacritic-shadow checks from blocking valid French words such
  as `risque` and `demande` when related accented forms also appear in the same
  article; explicit protected shadow terms remain enforced.

### 0.1.386

- Adds a server-generated heartbeat independence proof to selected work items so
  workers can distinguish current safe assignments from stale local self-review
  assumptions.

### 0.1.385

- Adds required localized comment-form runtime text to every packaged language
  and fails language-file checks when that contract is incomplete.

### 0.1.384

- Records provenance for runtime text changes and blocks the same actor from
  reviewing or publishing content that uses the changed language runtime
  surface.

### 0.1.383

- Prevents quality and final review from being assigned to or accepted from
  actors that already wrote the current prior review evidence.

### 0.1.382

- Prevents a heartbeat session from being reassigned the same unchanged item
  after it already returned that item.

### 0.1.381

- Prevents heartbeat assignment from returning a stale review obligation to the
  same actor that already handled that review stage.

### 0.1.380

- Fixes singular presentation surface labels so localized runtime author and
  comments text is used before English fallbacks.

### 0.1.379

- Makes heartbeat health depend on fresh identified expected actors, while
  retaining stale failed attempts as diagnostic history.

### 0.1.378

- Adds a read-only server-side heartbeat status ability for independent session
  health checks.

### 0.1.377

- Adds a conservative heartbeat work-assignment ability that returns one safe
  next action for an independent Codex session.

### 0.1.376

- Keeps draft translations with needs-review workflow status visible in the
  review queue.

### 0.1.373

- Removes local workflow token fields from protected write, review, and publish
  ability schemas.
- Requires `codex_thread_id` from `CODEX_THREAD_ID` as the workflow authority
  identity.

### 0.1.372

- Lets protected write, review, and publish abilities use `codex_thread_id`
  from `CODEX_THREAD_ID` as the normal server-side workflow authority identity.
- Makes legacy `step_token` and `step_token_label` optional compatibility
  inputs instead of required local bearer-token fields.

### 0.1.371

- Allows signed independent reviewers to review legacy translations that have
  usable writer process, actor, or token-label provenance even when those rows
  predate signed writer control scopes.
- Keeps empty writer provenance and matching writer/reviewer identities blocked
  so agents still cannot review their own work.

### 0.1.370

- Paginates quality-review queue candidate scans so compact queues can be used
  directly without loading large candidate sets up front.
- Reports the actual inspected candidate count and candidate query page count
  for operator visibility.

### 0.1.369

- Keeps review and quality-review queue responses compact by default, with full
  evidence payloads available through `detail_level=full`.
- Stops quality-review queue scans early for modified-date queues once enough
  actionable items are found.

### 0.1.368

- Allows legacy fragment migration to accept supplemental localized fragments
  for missing current source-design keys.

### 0.1.367

- Keeps legacy fragment migration responses compact unless source contracts or
  fragment previews are explicitly requested.

### 0.1.366

- Keeps legacy fragment migration dry-run output compact and blocks
  order-fallback writes unless explicitly allowed after review.

### 0.1.365

- Adds a dry-run-first source-design fragment migration ability for legacy
  translations before reprojection.

### 0.1.364

- Adds explicit operator warnings to fail-closed self-review and
  review-independence denials.

### 0.1.363

- Requires signed control-scope provenance for protected translation reviews and
  publishing.
- Marks review evidence invalid when writer and reviewer share the same control
  chain or the reviewer is not an independent session.

### 0.1.362

- Adds a localized term archive self-redirect repair ability so SEO plugin
  redirects cannot mask translated category and tag archive URLs.

### 0.1.361

- Allows direct translated-content text and URL fixes when the translation was
  already source-design-stale and the save does not change its Gutenberg design
  signature.

### 0.1.360

- Fixes localized category/tag archive pagination so `/page/N/` routes resolve
  through the same translated term query as page one.

### 0.1.359

- Requires explicit writer process IDs for protected draft writes and
  source-design reprojection so workflow tokens cannot fall back to a generic
  WordPress operator identity.

### 0.1.358

- Makes Frontend Text Edit REST request detection robust across normal HTTP and
  CLI-based smoke tests.

### 0.1.357

- Restores authenticated Frontend Text Edit saves by treating its text-only REST
  endpoint as a copy-edit surface instead of a generic source-design save.
- Keeps hard Gutenberg storage and invalid-link guardrails active for frontend
  text edits.

### 0.1.356

- Ignores duplicated rendered HTML text in structured data-bearing blocks such
  as FAQ blocks when comparing source-design signatures.

### 0.1.355

- Keeps dynamic `[devenia_presentation]` blocks source-owned during
  source-design reprojection so localized bylines, titles, excerpts, and author
  links render from the shared presentation surface instead of stale stored
  fragments.

### 0.1.354

- Adds a narrow integration seam for validated Site Presentation contract
  stamping on translated posts without weakening generic manual-save design
  guardrails.

### 0.1.353

- Clarifies the protected workflow schema so authority-issued session tokens
  are described as reusable session credentials that are validated per requested
  workflow step.
- Keeps the external token-authority gate strict: the authority must still
  return the exact requested workflow step, verified process ID, and token label
  before protected writes, reviews, or publishing can proceed.

### 0.1.352

- Lets localized runtime text rewrite Scriptless Social Sharing email share
  subjects and bodies.

### 0.1.351

- Allows reviewed translations to move from draft to published status without
  tripping direct-save storage guardrails.

### 0.1.350

- Lets presentation-surface comment labels come from runtime language text
  instead of a hardcoded English fallback.

### 0.1.349

- Allows authorized translation upsert writes to store projected source-design
  content without being blocked by direct-save design-drift guardrails.

### 0.1.348

- Treats source and localized author archive URLs as valid internal archive
  links in translation link-integrity guardrails.

### 0.1.347

- Adds a dry-run mode to `ai-translations/publish-translation` so the full
  publish gate can be tested without changing live content.
- Blocks manual WordPress source-post publishing when the shared Devenia
  editorial design gate fails or is unavailable.
- Fixes source-post quality verdicts so source content is not incorrectly
  blocked as missing its own source.

### 0.1.346

- Adds a shared publication-experience readiness gate for source and translated
  article design.
- Requires quality reviewers to provide visual design, desktop, mobile,
  source-design, and visual-evidence review fields.
- Requires final review and publish gates to honor the same
  publication-experience readiness, so a failed source design blocks all
  downstream translations.

### 0.1.345

- Blocks source-design inheritance and reprojection when the source post fails the shared Devenia editorial source-design validation.
- Reports proposed source editorial validation in `ai-translations/production-flow` so agents see design-gate failures before writing.

### 0.1.344

- Stores localized source-design fragments during translated content upserts so
  existing translations can inherit later source design changes.
- Adds `ai-translations/reproject-source-design` to rebuild translations from
  the current source Gutenberg block tree without redesigning each language
  separately.
- Reports design inheritance state on translation payloads and blocks direct
  translated-content saves that would alter the source-owned design tree.

### 0.1.343

- Requires quality reviewers to document real-reader decision safety, current-state claims, and historical context handling before a page can pass quality review.
- Requires final reviewers to confirm reader decision-safety evidence before publishing.
- Exposes the decision-safety/currentness requirement in workflow policy output.

### 0.1.342

- Extends `ai-translations/production-flow` with read-only proposed source update impact analysis so agents can see when a source rewrite would require translation reprojection before it is safe to apply.
- Adds a dedicated reprojection lane to keep source-design inheritance work visible without turning open reviews into a global production stop.

### 0.1.341

- Adds `ai-translations/workflow-obligations`, a read-only queue summary that keeps open linguistic, quality, final-review, and publish obligations visible without blocking new draft/write production work.
- Adds `ai-translations/production-flow`, a read-only workflow dashboard that separates production, review, and publish lanes for agents.
- Clarifies the workflow policy in machine-readable output: open reviews must not be overlooked, but only publishing the specific translation is blocked until current review evidence exists.

### 0.1.340

- Stores JSON post meta with WordPress slashing so review evidence containing quoted text remains valid JSON when read back for workflow readiness.

### 0.1.339

- Allows translation quality reviews to document a draft review surface by using `review_surface=presentation_surface` with `presentation_surface_post_id`, while keeping public URL evidence for published frontend reviews.

### 0.1.335

- Stores and validates stable reviewer actor identities from the token authority, making self-review denial independent of process IDs or token-label naming.

### 0.1.334

- Ignores inert Gutenberg parser whitespace blocks in the source-design QA signature, so unchanged layouts are not blocked by trailing freeform serialization noise.

### 0.1.333

- Normalizes translated Gutenberg content before computing the source-design QA signature, avoiding false source-design mismatches after storage-format refreshes when the block tree is unchanged.

### 0.1.332

- Blocks source and localized taxonomy slugs that contain WordPress duplicate suffixes such as `-2`, reports the blocking term when one exists, and removes numeric fallback slugs from the translation workflow.
- Ensures existing translated taxonomy terms are realigned to the current `language-source-slug` standard when a source term slug is repaired.

### 0.1.331

- Enforces the language-prefix taxonomy slug standard, such as `it-marketing` and `nl-marketing`, so translated category and tag URLs stay consistent across languages.
- Moves source-design inheritance and taxonomy-localization workflow logic into dedicated include modules to reduce the main plugin file size and improve locality.

### 0.1.330

- Requires the external token authority to return verified label, process, and workflow-step identity before draft, review, or publish operations proceed.
- Records writer provenance from the verified authority decision instead of client-supplied writer fields.
- Records reviewer provenance from the verified authority decision and removes fallback to client-supplied reviewer identity for protected review gates.

### 0.1.329

- Passes writer/reviewer process IDs to the external token-authority gate so confirmed session tokens can be bound to the approved process/session lease.
- Updates token-gate descriptions to use authority-issued session tokens instead of external raw token sets.

### 0.1.328

- Adds a third independent final-review gate before publishing translations.
- Blocks the writer process, actor, or token label from reviewing or publishing its own translated content.
- Adds the `final_review` step-token gate for owner-scoped token authorities.

### 0.1.327

- Moves translation workflow step-token verification behind an external token-authority filter so token storage and rotation live outside this workflow plugin.

### 0.1.326

- Adds step-specific token gates for draft writes, linguistic review, quality review, and publishing, and rejects tokens that validate for more than one step.
- Requires separate reviewer process provenance for translated linguistic and quality review evidence.
- Stops the legacy status marker from promoting translations to reviewed or published.

### 0.1.325

- Adds source-design inheritance so translated posts/pages can be built from localized text fragments projected into the source block tree.
- Extracts and projects structured text attributes generically so FAQ/how-to style blocks are not tied to one SEO plugin.
- Adds a source design-signature guardrail so translators cannot alter layout, classes, block attributes, or media while translating; only data-driven RTL mirroring may differ.

### 0.1.324

- Adds one shared localized presentation surface for singular content, archives, comments, language links, labels, actions, media, and runtime public text.
- Keeps Gutenberg, GeneratePress, and template adapters out of the translation layer; presentation plugins consume this surface instead.

### 0.1.323

- Adds the Rank Math FAQ semantic link-count adapter.

### 0.1.322

- Adds an adapter seam so SEO add-ons can exclude non-content links from source/translation structure parity.
- Narrows escaped-markup detection to visible `u003c`/`u003e` text instead of valid Gutenberg JSON escaping.

### 0.1.321

- Adds a Gutenberg guardrail that blocks escaped HTML markup literals such as visible u003c before they can be saved as page text.

### 0.1.320

- Fixes translated post/page creation after the translation-fitness interface consolidation by passing guardrail context through the current array-based signature.

### 0.1.319

- Deepens the translation-fitness scan architecture by splitting filters, index health, ID selection, item evaluation, and response shaping behind the same public scan interface.
- Centralizes translation workflow post-status defaults so future/scheduled translations stay covered consistently.
- Normalizes fitness guardrail context handling and cleans up critical QA schema/regression readability.

### 0.1.318

- Includes scheduled/future translations in default fitness scans so the default scan covers the full active translation workflow index.

### 0.1.317

- Scans every post type managed by the translation workflow by default, while still allowing callers to narrow the scan explicitly.

### 0.1.316

- Rebuilds the translation index from actual translation metadata instead of a capped content query, so scan rebuilds do not drop valid translations.

### 0.1.315

- Adds translation index health reporting and optional rebuild support to the translation-fitness scanner.
- Moves regression-only language identity data into explicit runtime profile fixtures instead of generic profile patches.
- Cleans up translation fitness module locality so policy, dimensions, and guardrails stay behind one stable interface.

### 0.1.314

- Adds a general translation-fitness scanner so stored translations can be audited through the same QA modules used by save and review gates.
- Moves wrong-language carryover detection into a separate language-integrity dimension.
- Adds runtime quality profile fields for language identity markers, so language-specific detection data can live in WordPress options instead of plugin code.
- Adds an internal ability dispatch integrity check to catch half-registered workflow operations before release.

### 0.1.313

- Adds a wrong-language carryover guardrail that detects when visible copy matches another configured target-language profile.
- Adds regression coverage so target-language copy cannot pass silently inside another language's translation.

### 0.1.312

- Extends translation QA text extraction to Rank Math FAQ block question data so visible FAQ copy is checked by the same carryover guardrails as normal paragraphs.
- Adds a regression case for English leftovers inside localized FAQ answers.

### 0.1.311

- Adds a QA guardrail for short copied source-language sentences that are too small for long-fragment carryover checks.
- Adds a regression case so short English leftovers in translated body copy cannot pass silently.

### 0.1.310

- Moves vendor-specific SEO, sitemap, and theme hook integrations out of the core workflow file and into optional addons so the public core remains theme-neutral.

### 0.1.309

- Reads featured image IDs from canonical postmeta during translation sync and repair so stale meta caches cannot hide thumbnail drift.
- Adds source/language translation work reservations with queue visibility and write gates so parallel agents do not overwrite the same translation work.

### 0.1.308

- Applies localized Rank Math meta descriptions to translated blog and author archive SEO, Open Graph, and Twitter description surfaces.

### 0.1.307

- Follows Rank Math title templates and separators for localized author and translated blog archive SEO titles.

### 0.1.306

- Supports localized category and tag descriptions in translated post taxonomy input.

### 0.1.305

- Localizes theme-rendered category and tag meta labels on translated archive and post surfaces.

### 0.1.304

- Applies translated frontend language context to localized category and tag archives, including menus, locale, and HTML language attributes.

### 0.1.303

- Sorts localized blog archives with local-language posts first by their own last-updated date, followed by source-language fallback posts.

### 0.1.302

- Localizes Devenia single post hero author links, author names, and modified-date metadata through runtime language data.

### 0.1.301

- Uses the localized author archive author ID fallback for SEO, Open Graph, Twitter, and document titles.

### 0.1.300

- Restores language switcher links on localized author archives that use curated author post lists.
- Stores author archive hero and CTA button URLs in runtime WordPress data.

### 0.1.299

- Orders localized author archives with local translations from that author first, followed by that author's source-language posts.
- Localizes archive read-more buttons from runtime WordPress text values.

### 0.1.298

- Uses runtime author archive data for translated author archive SEO, Open Graph, Twitter, and document titles.

### 0.1.297

- Fixes translated byline author-link attribute escaping and uses runtime author names inside byline links.

### 0.1.296

- Localizes translated post author bylines and points byline author links at runtime-managed localized author archives.

### 0.1.295

- Adds runtime-managed author archive translation queue, localized routing, language links, and presentation context filtering.

### 0.1.294

- Prevents translated category/tag creation from reusing the source term when a localized slug collides with the source slug.
- Repairs source category/tag terms that were accidentally marked as translated terms by earlier collision handling.

### 0.1.293

- Adds runtime-managed share text and localizes Scriptless Social Sharing screen-reader labels on translated frontend pages.

### 0.1.291

- Treats deliberate Rank Math SEO titles as current copy instead of requiring them to mirror the visible page title.
- Restores long source-language fragment detection so copied source paragraphs cannot pass translation QA.

### 0.1.290

- Removes the old bundled Quick Copy Edit implementation while preserving active heading-fit frontend assets.

### 0.1.289

- Restores the bundled inactive frontend editing helpers after the 0.1.288 cleanup caused a frontend fatal on some pages.

### 0.1.288

- Removes the old bundled Quick Copy Edit implementation after extracting frontend editing to Frontend Text Edit.

### 0.1.287

- Moves active frontend text editing out to the separate Frontend Text Edit plugin.

### 0.1.286

- Keeps FAQ answer edit targets tight by marking the matching inner answer paragraph when available.

### 0.1.285

- Keeps Quick Copy Edit active on FAQ answers rendered with nested markup.

### 0.1.284

- Adds Quick Copy Edit support for linked list-item text while preserving anchor URLs.

### 0.1.283

- Adds adapter-provided rendered segment selectors so Rank Math FAQ questions and answers remain editable when frontend markup differs from stored block HTML.

### 0.1.282

- Marks all Quick Copy Edit text segments in the same rich HTML block together, so Rank Math FAQ questions and answers are all editable.

### 0.1.281

- Registers the Rank Math FAQ Quick Copy Edit segment adapter independently of Rank Math frontend SEO hooks.

### 0.1.280

- Adds adapter-based Quick Copy Edit text segments for rich stored block markup.
- Lets Quick Copy Edit reach inline rich paragraph text without flattening the whole block.
- Adds Rank Math FAQ segment support and keeps FAQ question/answer attributes synchronized after frontend edits.

### 0.1.272

- Moves vendor-specific Rank Math, Elementor, and GenerateBlocks dependencies behind optional addon seams.
- Adds a public release gate that keeps vendor-specific hooks, metadata writes, and identifiers out of theme-neutral core.

### 0.1.271

- Removes the obsolete slug-unlock wrapper from public core after site-specific slug-lockdown handling was moved out.
- Keeps internal write paths routed only through the guardrail suspension modules that still perform work.

### 0.1.270

- Prevents historical pre-public migration tasks from running on fresh installs.
- Replaces exported site-style menu names with neutral packaged language menu defaults.
- Removes the site-specific slug-lockdown integration from public core.
- Extends the public release gate to block those site-specific integrations from returning to core.

### 0.1.269

- Excludes GitHub/development README content from release archives.
- Extends the public release gate so WordPress release ZIPs use `readme.txt` as the public readme surface.

### 0.1.268

- Registers optional vendor addon hooks only when the matching surface is present.
- Cleans up the ability operation dispatcher indentation.

### 0.1.267

- Adds an administrator notice when the WordPress Abilities API is unavailable.
- Cleans up indentation around ability registration helpers.

### 0.1.266

- Makes the public release gate build a temporary git archive and fail if
  development-only paths are present.

### 0.1.265

- Excludes development tools from git archive release packages.
- Adds a public release gate for archive export rules.

### 0.1.264

- Adds explicit uninstall cleanup for plugin-owned options and custom workflow
  tables while preserving WordPress content.
- Adds a public release gate that requires uninstall cleanup to stay present.

### 0.1.263

- Moves Rank Math and Elementor-adjacent hook registration into optional addon
  files.
- Extends the public release gate so vendor integration hooks stay out of the
  theme-neutral core.

### 0.1.262

- Moves GeneratePress presentation-meta sync behind the optional GeneratePress
  addon so the core workflow stays theme-neutral.

### 0.1.261

- Restores the WordPress text domain to the plugin slug expected by
  WordPress.org package checks.
- Removes hidden development files from the distributable plugin ZIP.
- Documents intentional GeneratePress hook calls in the optional GeneratePress
  addon for Plugin Check.

### 0.1.260

- Makes the translated posts-page template use standard WordPress markup and
  plugin-owned hooks instead of direct GeneratePress hooks.
- Moves GeneratePress and GenerateBlocks hook registration into optional
  `addons/` files.
- Keeps GeneratePress/GenerateBlocks compatibility styles out of the default
  frontend stylesheet.
- Allows the language selector to attach to a standard WordPress menu when a
  theme does not use a `primary` menu location.

### 0.1.259

- Moves RTL layout and translated blog archive frontend CSS out of inline PHP
  into versioned asset files.
- Centralizes plugin asset URL and filemtime version handling for frontend
  styles and scripts.

### 0.1.258

- Prepares public-release metadata by replacing private site-specific wording
  with generic plugin, ability, and documentation language.
- Adds an ability input-normalization seam and a centralized translation fitness
  policy seam for safer future refactors.
- Moves language-menu styling toward versioned frontend assets.

### 0.1.257

- Allows a small number of extra reviewed internal links in translated content
  when each added link resolves to known internal content and no source
  internal link is lost.
- Keeps malformed, external, and excessive added links blocked by the existing
  QA guardrails.

### 0.1.256

- Improves Quick Copy Edit editor, toolbar, and status contrast when manually selected dark mode is selected independently of the operating-system color scheme.

### 0.1.255

- Fixes `ai-translations/repair-url-hierarchy` so translated posts can
  refresh stored localized paths instead of being skipped as missing page
  sources.

### 0.1.254

- Raises the confidence threshold for source-language fallback internal-link
  suggestions.
- Prevents weak translated-page guidance from preferring an English fallback
  URL unless the match is clearly useful.

### 0.1.253

- Tightens internal-link opportunity scoring so shared category/tag context is
  only a supporting signal, not enough by itself.
- Requires higher confidence before suggesting source-language fallback URLs for
  translated content.
- Keeps internal-link opportunities as moderated brief, QA, and review-evidence
  guidance instead of a hard publish blocker.

### 0.1.252

- Adds `ai-translations/internal-link-opportunities` for a small,
  moderated set of relevant internal pages/posts to consider.
- Includes internal-link opportunities in agency-copy briefs before
  AI-generated translation or rewrite work starts.
- Surfaces strong missing internal-link opportunities in QA and quality evidence
  without automatically overlinking content.
- Blocks direct/MCP saves of existing translations when malformed internal
  hrefs such as `href="//"` are present.

### 0.1.251

- Adds automatic frontend heading fit for localized hero headings.
- Detects real browser line wrapping and scales only headings that overflow or
  split the final word.
- Keeps the fix out of Gutenberg content so translations do not need per-block
  typography overrides.

### 0.1.250

- Redirects translated blog archive `devenia_blog_page=1` query variants to
  the clean archive URL.
- Prevents translated blog pagination from emitting duplicate page-one query
  URLs.
- Overrides translated blog archive Rank Math title, description, and canonical
  output so stale page meta cannot say no posts are present when the archive
  contains posts.

### 0.1.249

- Adds rich paragraph segment editing for Quick Copy Edit, so strong intro text
  and body text after a line break can be edited separately without losing
  Gutenberg markup.

### 0.1.248

- Adds a text-and-tag fallback for Quick Copy Edit markers, so simple rendered
  paragraphs, headings, list items, and buttons remain editable even when
  frontend markup is normalized by WordPress or the theme.

### 0.1.247

- Adds Quick Copy Edit support for simple Gutenberg list items, so plugin
  feature bullets and similar inline page copy can be edited from the frontend.

### 0.1.246

- Adds runtime context exclusions for script-signal shadow terms, so valid local
  homographs such as Norwegian `Last ned` can pass without weakening
  stripped-diacritic QA globally.

### 0.1.245

- Makes Quick Copy Edit mark dynamically rendered GenerateBlocks text by stable
  block class and visible text, so inline frontend editing reaches hero and
  section copy that no longer matches stored block HTML byte-for-byte.

### 0.1.244

- Fixes `ai-translations/agency-copy-brief` language resolution for
  source-only briefs.
- Adds explicit review language context so agency copy, copy feedback, quality
  verdicts, and quality review queue rows use the same source/target language
  resolver.

### 0.1.243

- Treats Simplified Chinese and Japanese as transliterated-URL languages.
- Blocks translated slugs and parent-path segments that are merely copied from
  the source-language route unless an explicit source-slug reason is supplied.
- Stops `localized_parent_path` from creating empty placeholder parent pages;
  translated child pages must point at an existing translated parent.
- Adds lifecycle regression coverage for transliterated-language source-slug
  copy rejection, including a Japanese fixture.

### 0.1.242

- Adds `tools/check-language-policy.mjs` so release checks can fail before
  runtime-only script-signal decisions are moved back into packaged language
  data.
- Uses a shared runtime-only script-signal option list in policy validation.

### 0.1.241

- Adds runtime support for `infer_text_shadow_terms` in script-signal quality
  profiles.
- Adds `script_signal_option` rule events so script-signal options can be stored
  in the audited rule-event table instead of packaged language rules.

### 0.1.240

- Refines Vietnamese diacritic QA with shadow-term exclusions for common valid
  words such as `dung`, `trong`, and `gian`, while keeping the stripped-
  diacritic regression active.

### 0.1.239

- Adds Vietnamese (`vi`, `vi_VN`) as a configured target language.
- Adds packaged runtime text seeds, menu labels, blog paths, taxonomy paths,
  Asia menu-region metadata, and diacritic quality-rule registry coverage for
  Vietnamese.

### 0.1.238

- Uses configured language menu regions so Portuguese stays under Europe and
  Simplified Chinese/Japanese appear under Asia when translated URLs exist.
- Adds packaged region metadata for the newly configured target languages.

### 0.1.237

- Adds Portuguese (`pt`, `pt_PT`) as a configured target language.
- Adds Simplified Chinese (`zh`, `zh_CN`, `zh-Hans`) as a configured target
  language.
- Adds Japanese (`ja`, `ja_JP`) as a configured target language.
- Adds packaged runtime text seeds, menu labels, blog paths, taxonomy paths,
  and quality-rule registry coverage for the new languages.

### 0.1.236

- Adds `ai-translations/authored-original-intake-queue` so AI/MCP
  operators can pick up newly authored non-source posts and pages.
- Adds `ai-translations/update-authored-original-intake` for marking
  intake items pending, ignored, or failed with an operator note.
- Automatically detects configured non-source language posts/pages on save and
  queues them for English source generation.
- Tracks authored-original intake status through pending, stale, source-created,
  completed, ignored, and error states.

### 0.1.235

- Adds `ai-translations/create-source-from-authored-original` for posts
  or pages first written in a configured non-source language.
- Adds `ai-translations/mark-source-generation-reviewed` so generated
  English sources must be reviewed against the authored original before
  downstream translations can use them.
- Stores authored-original provenance and hashes on both the generated English
  source and the attached original-language translation.
- Attaches the authored original as its language's translation without
  rewriting the original content.
- Marks generated English sources and downstream translations stale when the
  authored original changes.

### 0.1.234

- Marks supported rendered Gutenberg/GenerateBlocks text blocks with safe edit
  metadata for logged-in editors.
- Turns marked frontend text into inline `contenteditable` fields only while
  Quick Copy Edit is active.
- Saves inline edits through the existing REST endpoint, Gutenberg storage
  guardrails, and normal `wp_update_post()` learning path.
- Replaces the old floating list panel with a small contextual save/cancel
  toolbar.

### 0.1.233

- Adds an editor-only frontend Quick Copy Edit panel on singular pages/posts.
- Uses REST nonce and `edit_post` capability checks.
- Updates only supported plain-text Gutenberg/GenerateBlocks blocks and runs
  existing storage guardrails before saving.
- Saves through normal `wp_update_post()` so manual reviewer-style learning can
  capture the edit.

### 0.1.232

- Requires meaningful text overlap before superseding a recent human-edit
  learning item.
- Keeps separate edits on the same page and reviewer visible even when they
  happen close together.

### 0.1.231

- Supersedes quick follow-up human editor saves on the same content so
  intermediate test phrasings do not remain as separate pending learning items.
- Removes superseded examples from the reviewer style profile before merging
  the newer example.
- Adds a filterable reviewer-style refinement window for normal multi-save
  human editing.

### 0.1.230

- Queries extra learning rows before applying inbox-status filtering so ignored
  rows do not hide older pending learning events.

### 0.1.229

- Stores manual reviewer learning examples as focused before/after windows
  around the changed text.
- Avoids filling the learning inbox with the first long text fragment when the
  actual edit happened deeper in a page.

### 0.1.228

- Limits direct WordPress editor save blocking to storage-integrity failures:
  route collisions, hard invalid links, and malformed static Gutenberg markup.
- Lets normal translation and copy-quality findings be handled by post-save
  review state and learning hooks instead of preventing human editor saves.

### 0.1.227

- Adds a learning inbox for captured human editor changes.
- Adds review actions to keep a change as style guidance, promote it to a
  naturalness QA rule, or ignore it for future rule work.

### 0.1.226

- Adds every localized blog archive URL to `publish-translation` purge output
  when a post translation is published, using configured/detected blog paths
  instead of hardcoded route lists.

### 0.1.225

- Mirrors `_thumbnail_id` directly so translated content inherits the exact
  source featured image meta, including existing media that WordPress helper
  validation may not re-accept.

### 0.1.224

- Mirrors the source featured image during translation upsert and publish
  lifecycle updates.
- Adds `ai-translations/repair-featured-images` for existing translated
  pages and posts.
- Adds `featured_image_id` to source and translation payloads for workflow
  verification.

### 0.1.223

- Adds a persistent language-rule event table for human feedback, reviewer
  learning, and approved QA-rule decisions.
- Merges the effective QA profile from packaged language metadata, packaged
  quality rules, runtime overrides, and active learned rule events.
- Adds MCP abilities to record/list language-rule events and run a policy gate
  that fails language-specific QA hardcoding in PHP or packaged language files.

### 0.1.220

- Moves Spanish `donde` handling into the packaged quality-rule registry as
  `script_signals.shadow_exclusions`; no language-specific PHP exception.

### 0.1.218

- Blocks years in localized URLs unless the operator explicitly marks the year
  as the article's basis.
- Fixes duplicate-slug detection so a four-digit year such as `2026` is not
  misreported as a WordPress `-2` collision suffix.

### 0.1.217

- Adds a language-profile `source_carryover_homographs` field for true local
  words that happen to match English, while keeping avoidable loanwords flagged.

### 0.1.214

- Syncs Rank Math SEO title and description during `upsert-page` so translated
  search, social, and schema titles do not keep stale pre-rewrite wording.
- Adds a `seo_meta_not_current` quality-verdict action when stored Rank Math
  titles no longer match current visible content.
- Reduces false source-carryover failures for natural target-language words
  such as Norwegian `Start`.

### 0.1.213

- Adds safe `next_actions` to the AI-operator quality verdict surface.
- Keeps the actions operational and bounded to internal workflow steps, without
  exposing raw review evidence or premium rewrite guidance.

### 0.1.212

- Adds audience-specific presentation for `ai-translations/quality-verdict`.
- Keeps `ai_operator` as the safe default surface with actionable issue codes
  and next-action categories.
- Keeps full QA, agency-copy profiles, review questions, and raw review
  evidence behind the `internal_debug` audience.

### 0.1.211

- Adds `pre_publish`, `post_publish`, and `auto` stages to the local quality
  verdict so pre-publish checks do not require a live full-page review first.
- Includes the pre-publish quality verdict in `publish-translation` responses
  without changing publish blocking behavior yet.

### 0.1.210

- Adds `ai-translations/quality-verdict`, a read-only local quality verdict
  over QA, review evidence, copy feedback, hashes, and agency-copy state.
- Includes the local quality verdict in quality-review queue items without
  changing publish behavior yet.

### 0.1.209

- Lets parent-repair runs finish stale localized path metadata updates after a
  translated page has moved to its correct parent.

### 0.1.208

- Keeps current stored localized path metadata as a legacy repair variant even
  after the translation index has been rebuilt to canonical paths.

### 0.1.207

- Fixes translation index rows so page localized paths follow the current
  WordPress permalink instead of stale path metadata.
- Adds stale localized path variants to internal-link QA and repair maps so old
  translated URLs can be rewritten to the current translated URL.
- Lets `repair-url-hierarchy` repair stale localized path metadata and report
  it in `changed` and `changed_count`.

### 0.1.206

- Exposes translation sibling IDs to Elementor write guards through the
  shared `mcp_abilities_elementor_translation_sibling_post_ids` filter.
- Merges WPML and Polylang translation sibling IDs with this plugin's translation
  families so mixed multilingual plugin stacks can cooperate.

### 0.1.205

- Adds route-integrity checks for active Rank Math redirects whose source and
  destination both normalize to a translated page canonical URL.
- Lets `repair-url-hierarchy` clean those self-redirects so slug repairs cannot
  leave a live redirect loop behind.
- Treats a frontend redirect from a translated canonical URL as a
  live-verification failure instead of following it silently.

### 0.1.204

- Fixes `repair-url-hierarchy` response accounting so duplicate-slug repairs
  are included in `changed` and `changed_count` even when the page parent was
  already correct.

### 0.1.203

- Extends `repair-url-hierarchy` so it can remove existing WordPress duplicate
  slug suffixes from registered translations when the intended base slug is
  free.
- Keeps duplicate-slug repair inside the translation workflow so route cleanup
  does not rely on manual database edits or generic content saves.

### 0.1.202

- Rejects localized translation slugs that already end in a WordPress duplicate
  suffix such as `-2`.
- Detects slug collisions before saving translations and returns the conflicting
  posts instead of silently accepting WordPress' rewritten slug.
- Adds a shared translation route-integrity check used by QA, publish gates,
  direct WordPress/MCP saves, parent-path creation, and URL-hierarchy repair.
- Adds QA coverage for existing translated pages whose stored slug already
  contains a WordPress duplicate suffix.

### 0.1.201

- Blocks direct WordPress/MCP saves of translated content when the normal
  translation guardrails detect source-language carryover, terminology, script,
  structure, or link-integrity failures.
- Keeps trusted internal content-safety and link repairs separate from semantic
  direct-save QA so maintenance repairs do not become blocked by unrelated
  translation-review issues.

### 0.1.200

- Normalizes source hashes across Gutenberg saved-markup repairs so
  output-preserving maintenance does not create false stale translations.
- Syncs translated source-hash metadata during content-safety repair, including
  recent revision recovery for source pages already normalized by an earlier
  run.

## Contributing

Keep changes narrow, review the workflow impact, and verify against a validation
site before production deployment.
