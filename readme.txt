=== AI Translation Workflow ===
Contributors: basicus
Tags: translations, ai, workflow, multilingual
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.260
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Workflow plugin for AI-assisted WordPress content translations.

== Description ==

AI Translation Workflow is a workflow plugin for controlled AI-assisted content translation in WordPress.

It helps AI-assisted operators create ordinary WordPress page and post translations with localized URLs, mapping metadata, stale-source detection, hreflang output, language menu sync, QA checks, and mandatory linguistic review before publishing.

== Installation ==

This plugin is being prepared for public release. Review configuration, permissions, and quality gates before production use.

1. Install the release ZIP through the WordPress plugin installer or WP-CLI.
2. Activate "AI Translation Workflow".
3. Confirm the language registry, translation index, and language packs with the plugin's MCP status abilities.

== Frequently Asked Questions ==

= Should live copy fixes be made in `languages/*.json`? =

No. Existing translated pages, menu items, slugs, URLs, runtime text, and page-specific QA options are WordPress data. Use the relevant page, menu, translation metadata, or runtime-text ability.

= What is the safe publishing flow? =

Use `ai-translations/upsert-page`, run `ai-translations/qa-translation`, record the required linguistic and quality review evidence, then publish through `ai-translations/publish-translation`.

== Upgrade Notice ==

= 0.1.260 =
Makes the frontend archive surface theme-neutral and moves GeneratePress/GenerateBlocks hooks into optional addons.

= 0.1.259 =
Moves frontend layout CSS into versioned assets and centralizes plugin asset loading.

= 0.1.258 =
Prepares public-release metadata, generic ability documentation, and architecture seams.

= 0.1.257 =
Allows reviewed translations to add moderated internal links without weakening source-structure guardrails.

= 0.1.256 =
Improves Quick Copy Edit contrast when manually selected dark mode is active.

= 0.1.255 =
Lets `repair-url-hierarchy` refresh localized URL metadata for translated posts, not only translated pages.

= 0.1.254 =
Raises the confidence bar for source-language fallback internal-link suggestions after live-page noise testing.

= 0.1.253 =
Tightens moderated internal-link opportunity scoring so broad taxonomy matches do not create noisy link suggestions.

= 0.1.252 =
Adds moderated internal-link opportunity discovery to translation briefs, QA, and quality evidence, and blocks malformed internal link saves earlier.

= 0.1.251 =
Adds automatic frontend heading fit for localized hero headings so narrow translated headings can avoid orphan-letter wrapping without Gutenberg typography overrides.

= 0.1.250 =
Normalizes translated blog archive page-one query URLs and keeps translated archive SEO metadata accurate to reduce duplicate SERP noise.

= 0.1.249 =
Adds rich paragraph segment editing for Quick Copy Edit, so strong intro text and body text after a line break can be edited separately without losing Gutenberg markup.

= 0.1.248 =
Adds a text-and-tag fallback for Quick Copy Edit markers, so simple rendered paragraphs, headings, list items, and buttons remain editable even when frontend markup is normalized by WordPress or the theme.

= 0.1.247 =
Adds Quick Copy Edit support for simple Gutenberg list items, so plugin feature bullets and similar inline page copy can be edited from the frontend.

= 0.1.246 =
Adds runtime context exclusions for script-signal shadow terms, so valid local homographs such as Norwegian "Last ned" can pass without weakening stripped-diacritic QA globally.

= 0.1.245 =
Makes Quick Copy Edit mark dynamically rendered GenerateBlocks text by stable block class and visible text, so frontend inline editing reaches hero and section copy that no longer matches stored block HTML byte-for-byte.

= 0.1.244 =
Fixes agency-copy source briefs and centralizes review language context so source-only calls resolve the configured source language instead of failing when no target language is supplied.

= 0.1.243 =
Blocks copied source-language slugs for transliterated URL languages and stops empty placeholder parent pages from being created.

= 0.1.242 =
Adds a release-time language-policy check so runtime-only script-signal decisions cannot be moved back into packaged language data.

= 0.1.241 =
Adds audited rule-event support for script-signal options, so language-specific QA modes can live in the rule table instead of packaged rule files.

= Earlier versions =
Older workflow changes are kept in the project repository history.

== Changelog ==

= 0.1.260 =
* Makes the translated posts-page template use standard WordPress markup and plugin-owned hooks instead of direct GeneratePress hooks.
* Moves GeneratePress and GenerateBlocks hook registration into optional `addons/` files.
* Keeps GeneratePress/GenerateBlocks compatibility styles out of the default frontend stylesheet.
* Allows the language selector to attach to a standard WordPress menu when a theme does not use a `primary` menu location.

= 0.1.259 =
* Moves RTL layout and translated blog archive frontend CSS out of inline PHP into versioned asset files.
* Centralizes plugin asset URL and filemtime version handling for frontend styles and scripts.

= 0.1.258 =
* Prepares public-release metadata by replacing private site-specific wording with generic plugin, ability, and documentation language.
* Adds an ability input-normalization seam and a centralized translation fitness policy seam for safer future refactors.
* Moves language-menu styling toward versioned frontend assets.

= 0.1.257 =
* Allows a small number of extra reviewed internal links in translated content when each added link resolves to known internal content and no source internal link is lost.
* Keeps malformed, external, and excessive added links blocked by the existing QA guardrails.

= 0.1.256 =
* Improves Quick Copy Edit editor, toolbar, and status contrast when manually selected dark mode is selected independently of the operating-system color scheme.

= 0.1.255 =
* Fixes `ai-translations/repair-url-hierarchy` so translated posts can refresh stored localized paths instead of being skipped as missing page sources.

= 0.1.254 =
* Raises the confidence threshold for source-language fallback internal-link suggestions.
* Prevents weak translated-page guidance from preferring an English fallback URL unless the match is clearly useful.

= 0.1.253 =
* Tightens internal-link opportunity scoring so shared category/tag context is only a supporting signal, not enough by itself.
* Requires higher confidence before suggesting source-language fallback URLs for translated content.
* Keeps internal-link opportunities as moderated brief, QA, and review-evidence guidance instead of a hard publish blocker.

= 0.1.252 =
* Adds `ai-translations/internal-link-opportunities` for a small, moderated set of relevant internal pages/posts to consider.
* Includes internal-link opportunities in agency-copy briefs before AI-generated translation or rewrite work starts.
* Surfaces strong missing internal-link opportunities in QA and quality evidence without automatically overlinking content.
* Blocks direct/MCP saves of existing translations when malformed internal hrefs such as `href="//"` are present.

= 0.1.251 =
* Adds automatic frontend heading fit for localized hero headings.
* Detects real browser line wrapping and scales only headings that overflow or split the final word.
* Keeps the fix out of Gutenberg content so translations do not need per-block typography overrides.

= 0.1.250 =
* Redirect translated blog archive `devenia_blog_page=1` query variants to the clean archive URL.
* Keep translated blog pagination from emitting duplicate page-one query URLs.
* Override translated blog archive Rank Math title, description, and canonical output so stale page meta cannot say no posts are present when the archive contains posts.

= Earlier versions =
Older workflow changes are kept in the project repository history.
