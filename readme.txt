=== AI Translation Workflow ===
Contributors: basicus
Tags: translations, multilingual, ai, workflow, hreflang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.262
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Controlled workflow tools for AI-assisted WordPress content translation.

== Description ==

AI Translation Workflow helps site operators manage AI-assisted page and post translations without turning translated content into a separate content system.

The plugin keeps translations as ordinary WordPress content while adding workflow support for localized URLs, source mapping, stale-source detection, hreflang output, language-menu sync, QA guardrails, review evidence, and publish checks.

It is designed for controlled translation workflows where an AI assistant or automation client creates draft translations and the site owner still wants clear review gates before publishing.

= Features =

* Create and update translated pages and posts as normal WordPress content.
* Track source-to-translation relationships and stale source content.
* Generate localized URL metadata for supported languages.
* Output hreflang data for mapped translations.
* Keep language menus in sync with available translations.
* Run QA checks for source-language carryover, terminology, structure, script issues, and link integrity.
* Require linguistic and quality review evidence before publishing.
* Support authored-original intake when content starts in a non-source language.
* Repair localized internal links and featured images where possible.
* Provide optional theme or builder integrations through addon files.

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

= Can translated content be edited in WordPress? =

Yes. Translated pages and posts are ordinary WordPress content. Existing translated page text, menu labels, slugs, URLs, runtime text, and page-specific QA options should be changed in WordPress or through the relevant workflow ability, not by editing packaged language defaults.

= Does the plugin support posts and pages? =

Yes. The workflow supports both posts and pages, including localized URLs, mapping metadata, QA checks, review state, and publish checks.

= Can a non-English page become the starting point? =

Yes. A post or page can be authored first in another configured language. The plugin can place it in an authored-original intake workflow so an English technical source can be created and reviewed before downstream translations continue.

= Are theme integrations required? =

No. The core workflow is theme-neutral. Optional theme and builder integrations live in `addons/` and load only when the matching surface is present.

== Changelog ==

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

= 0.1.262 =
Moves GeneratePress presentation-meta sync behind the optional GeneratePress addon.

= 0.1.261 =
Fixes WordPress.org package checks for the public plugin slug and optional vendor addon hooks.
