=== Devenia AI Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.570
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted workflow for improving and publishing WordPress content, with optional multilingual support.

== Description ==

Devenia AI Workflow helps site operators improve, review, and publish WordPress pages and posts through controlled AI-assisted workflows. Multilingual publishing is an optional capability, not a requirement.

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
2. Activate "Devenia AI Workflow" through the Plugins screen in WordPress.
3. Configure the supported language registry and review workflow for your site.
4. Use the plugin's workflow abilities from your AI or automation client to read sources, create drafts, run QA, record review evidence, and publish approved translations.

== Frequently Asked Questions ==

= Is this a replacement for WPML or Polylang? =

No. Devenia AI Workflow provides controlled content-quality, review, publishing, and optional multilingual workflows. It keeps content in WordPress instead of replacing WordPress content management.

= Does the plugin translate content by itself? =

No. The plugin provides the workflow, metadata, guardrails, and review gates around translation work. Translation text is expected to come from an AI assistant, automation client, or editor.

= How is this different from automatic translation plugins? =

Most automatic translation plugins focus on generating translated text or proxying translated pages. Devenia AI Workflow focuses on the controlled content and publishing layer: WordPress-native pages and posts, source-quality checks, review evidence, runtime text, and repair operations, with localized URLs, source mapping, stale-source detection, and hreflang available when multilingual publishing is enabled.

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

= 0.1.570 =
* Add a typed site-level workflow mode so source-only sites retain WordPress locale and HTML language authority and generate no translation obligations while keeping native editor optimization abilities.

= 0.1.569 =
* Raises the bounded quality Run input budget to cover complete RTL review packets and safely upgrades active quality Runs in place.

= 0.1.568 =
* Safely upgrades the budget of an already-running quality Run created before the larger bounded review budget was released.

= 0.1.567 =
* Raises the bounded quality Run input budget so complete multilingual review packets can be fetched without weakening QA gates.

= 0.1.566 =
* Stores artifact payloads as backward-compatible ASCII-safe JSON so legacy options tables accept complete Unicode translations.

= 0.1.565 =
* Adds a verified WordPress option fallback when a host reports zero affected rows for a new Job-scoped artifact record.

= 0.1.564 =
* Distinguishes atomic artifact storage failures from genuine cross-Job revision conflicts and returns bounded diagnostic evidence.

= 0.1.563 =
* Scopes translation artifact revisions to their immutable Job contract so identical localized payloads cannot collide across source revisions.

= 0.1.562 =
* Allows explicit source-design approval for every configured translatable post type, including pages.

= 0.1.561 =
* Adds a builder-aware Source Editor Adapter seam for native WordPress and Elementor source optimization.
* Exposes read-only source editor status without creating translation work.
* Routes source Work Items to native Elementor abilities while preserving element IDs, global Kit styles, responsive settings, and Public Routes.

= 0.1.560 =
* Renames the user-facing product to Devenia AI Workflow so the name covers source-content optimization as well as optional multilingual publishing.
* Keeps the existing plugin slug, directory, text domain, option keys, metadata, and ability names unchanged for backward compatibility.

= 0.1.559 =
* Finalizes explicit translation URL migrations by removing SEO-plugin self-redirects at the new canonical route; a failed cleanup forces the migration owner to roll back.

= 0.1.558 =
* Treats the route of an existing published translation as immutable during ordinary v1 and v2 content updates.
* Adds the established canonical route and route-lock policy to bounded translation packets; route fields are now creation-only for published translations.
* Records translation-owned Canonical Route Contracts, reports route drift, and refreshes route evidence only after an explicit public-route migration.
* Stops publish-time hierarchy enforcement from moving an already published translation.
* Distinguishes established canonical, currently observed and historical route variants in the frontend read model.

= 0.1.557 =

* Resolve translated internal links from the current WordPress permalink instead of a stale indexed parent path.
* Preserve prior indexed paths only as routing variants when a translated parent slug changes.

= 0.1.556 =

* Count published v2 jobs through the canonical flat live-verification field in the obligation projection.

= 0.1.555 =

* Add an authoritative public source inventory and complete target-language obligation projection.
* Add stable cursor reads, v2 next-job selection, dirty invalidation, and whole-site exhaustion proof.
* Make the legacy translation queue select from unresolved projected obligations instead of a recent-content window.

= 0.1.554 =
* Reconciles duplicate featured-image metadata using the same effective value WordPress renders.
* Prevents v2 media synchronization from falsely passing while an older thumbnail remains visible.

= 0.1.553 =
* Lets a published v2 Job use a remaining bounded translator Run when browser QA finds a real copy defect.
* Requires the corrected artifact to complete a new exact quality decision and publication cycle while the WordPress post stays live.

= 0.1.552 =
* Finalizes historical non-active running Runs during idempotent v2 publication.
* Lets a verified republish repair orphaned Run history created before the reclaim fix shipped.

= 0.1.551 =
* Finalizes an expired bounded Run as completed with outcome expired before a replacement Run claims the Job.
* Keeps status and cost history free of permanently running Runs after lawful lease recovery.

= 0.1.550 =
* Makes v2 publication idempotently reconcile approved source media for already-published Jobs.
* Keeps media repair under v2 coordinator authority without reviving retired translation-session leases.

= 0.1.549 =
* Synchronizes the approved source featured image during bounded v2 artifact submission before quality review.
* Fails closed when the featured-image write cannot be verified and records v2 media provenance when the visible image changes.

= 0.1.548 =
* Recovers orphaned Quality Decisions by immutable Job, artifact, content, translation, and revision identity.
* Tolerates harmless serialization differences in already-stored review details while retaining revision binding.

= 0.1.547 =
* Recovers idempotently when a Quality Decision was stored before its Job state transition completed.
* Keeps genuinely different data on the same Quality Decision revision rejected as a conflict.

= 0.1.546 =
* Rejects artifacts whose mailto subject or body remains identical to source-language query text.
* Adds decoded source/translation contact actions to bounded quality packets for explicit offer/contact review.

= 0.1.545 =
* Generates bounded packet submission contracts from the same JSON schemas used by the live abilities.
* Adds exact payload examples that keep SEO fields nested and use real html or text fragment properties.

= 0.1.544 =
* Authorizes narrow runtime-text updates with WordPress manage_options instead of the retired translation-session lease flow.
* Preserves operator provenance for runtime-text changes without assigning a translation persona.

= 0.1.543 =
* Includes compact submit-payload contracts in bounded translator and quality packets.
* Merges source-scoped preserved product terms into packet language profiles and recognizes their capitalized component tokens during carryover QA.

= 0.1.542 =
* Normalizes complete translated fragment wrappers before source-design projection so headings, paragraphs, and buttons cannot be nested or duplicated.
* Adds runtime coverage for full-wrapper heading and button fragments.

= 0.1.541 =
* Allows a third bounded translator and quality Run so a valid second-review correction cannot leave a Job permanently unresolved.
* Keeps every Run under the existing per-Run Token Budget and preserves the finite Job attempt ceiling.
* Reuses the Translation Job's verified translation identity during corrections instead of relying on a potentially stale request-local index lookup.

= 0.1.540 =
* Adds authoritative internal-link targets and explicit source-URL fallback policy to bounded translation and quality packets.
* Rejects artifacts that invent localized routes or omit the current published destination for a source link.

= 0.1.539 =
* Includes the rejected artifact and exact Quality Decision corrections in bounded translator correction packets.
* Clears stale Quality Decision pointers when a corrected artifact is submitted for a new quality Run.

= 0.1.538 =
* Requires explicit hash-bound source quality approval before a Translation Job can be discovered, claimed, reviewed, or published.
* Includes the complete approved source fragment packet in quality Runs and adds source quality as a required Quality Decision check.

= 0.1.537 =
* Adds a finite, cost-bounded Translation Job v2 workflow with seven focused abilities.
* Replaces persona leases and independent-session review requirements in v2 with atomic job claims, exact artifact revisions, measured Token Budgets, deterministic QA, and coordinator-owned Quality Decisions.
* Preserves inline source markup in bounded translation packets and publishes approved artifacts through the existing WordPress-native route, SEO, taxonomy, menu, cache, and live-verification adapters.

= 0.1.536 =
* Restores create-only storage semantics for Assignment sessions, Work Item locks, Reservations, and concurrent Heartbeat initialization on WordPress 6.9.
* Adds a cross-process runtime contract that proves simultaneous contributors cannot receive the same Work Item.

= 0.1.535 =
* Resolves registered localized translation URLs through the canonical translation index when WordPress core cannot map the custom route.
* Uses the same internal content resolver for link-integrity QA and source-link parity.

= 0.1.534 =
* Keeps source and translation content-integrity Assignments on scope-correct abilities.
* Carries concrete translation integrity findings into the Assignment so the contributor can resolve the actual Work Item.

= 0.1.533 =
* Uses structured Assignment outcomes as a logical Work Item cursor so the same contributor cannot receive its own successor revision.
* Plans progressively to keep normal accepts fast while retaining a full-queue exhaustion fallback.

= 0.1.532 =
* Makes Work Item revisions deterministic and adds an atomic logical item lock so concurrent sessions cannot own the same Work Item.
* Reconciles orphan Assignments without deleting another session's valid Reservation.

= 0.1.531 =
* Adds a server-owned Assignment Lifecycle with idempotent accept, recovery, renewal, and structured outcomes.
* Routes assignment selection and coverage through one revision-aware Work Item Planner instead of heartbeat history.
* Aligns route work with authoritative route integrity and lets coordinators resolve exact blocked Work Item revisions.

= 0.1.530 =
* Keeps route-repair carryover and stale reprojection payload corrections with the assigned contributor instead of escalating normal writer work.

= 0.1.529 =
* Preserves concurrent heartbeat session state and prevents one session from immediately reclaiming the same assignment.

= 0.1.528 =
* Makes translation and source-work reservation creation fail closed when another session wins the atomic claim race.

= 0.1.527 =
* Clarifies that source-language carryover and locale terminology failures require completing the translation and retrying, not coordinator escalation.

= 0.1.526 =
* Uses current review evidence rather than stored timestamps when sequencing linguistic, quality, and final assignments.

= 0.1.525 =
* Enforces sequential review assignments so quality and final review cannot be offered before their prerequisite evidence exists.

= 0.1.524 =
* Deepen workflow state and the persistent translation index behind focused modules with executable contract checks.

= 0.1.523 =
* Extract ability catalogue, workflow-state, and frontend read-model composition seams with contract checks.

= 0.1.522 =
* Applies runtime mailto subject/body replacements to shared footer/widget chrome and generic frontend output.
* Decodes Cloudflare-protected email links during frontend integrity checks so weak public CTA text is still visible to QA.

= 0.1.521 =
* Keeps configured language menus on short localized labels when the language menu is already selected.
* Keeps shared runtime UI attributes data-driven so theme/presentation adapters can localize them without hardcoded labels.
* Extends frontend integrity and review mandates to cover header/footer chrome, accessibility attributes, and mailto CTA subject/body text.
* Persists approved source-slug URL exceptions so route integrity honors brand/proper-name slugs after save.

= 0.1.520 =
* Restores the source-design reprojection output contract with `translations` and `translation_count` aliases so clients can prove target selection after a successful run.

= 0.1.519 =
* Fixes source-design reprojection target selection so indexed page/post translations are not dropped by a stale postmeta-only source check.

= 0.1.518 =
* Fixes source content-integrity review evidence reads so no-rewrite completions immediately satisfy the queue gate.

= 0.1.517 =
* Fixes source content-integrity no-rewrite completion for page-based sources such as Learn and plugin pages.

= 0.1.516 =
* Adds a hash-bound source content-integrity no-op completion ability so already-clean or stale/false-positive repair assignments can be completed with evidence instead of forcing artificial content saves.

= 0.1.515 =
* Optimizes localized author archives by resolving translated post replacements from the indexed frontend rows once per language instead of running one translation lookup for every source post on each archive render.

= 0.1.514 =
* Keeps public frontend localized-link maps on the indexed read model instead of expanding historical slug and shortlink compatibility variants with per-post WordPress lookups on every translated render.

= 0.1.513 =
* Trusts the translation index localized path during frontend row shaping so public link-map reads do not run per-translation postmeta lookups on every translated origin render.

= 0.1.512 =
* Keeps glossary/review localized terms out of frontend runtime text replacement so translated page renders do not run broad QA-term regexes on every request.
* Uses the translation index for legacy language-prefixed source-path redirects instead of scanning every published row for the language.
* Lowers frontend performance logging to capture one-second translated origin renders during speed diagnosis.

= 0.1.511 =
* Reduces heartbeat assignment scan breadth and source-work scan floors so frequent assignment checks do not create unnecessary origin load.
* Keeps frontend slow-request logging focused on frontend requests by ignoring WordPress cron executions.

= 0.1.510 =
* Makes `ai-translations/warm-cache` batch-safe with `offset`, `next_offset`, and lower per-request limits so cache warming does not become a long-running frontend request.

= 0.1.509 =
* Adds a source content-integrity guard for stale year claims in evergreen posts.
* Speeds up cold translated frontend requests by resolving indexed post routes before archive scans, narrowing archive route matching to the URL language prefix, and building language alternates only when a frontend surface needs them.

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
