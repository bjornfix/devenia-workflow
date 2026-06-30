=== AI Translation Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.321
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
* Require linguistic and quality review evidence before publishing.
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

= 0.1.321 =
* Adds a Gutenberg guardrail that blocks escaped HTML markup literals such as u003c and \u003c before they can be saved as visible text.

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
