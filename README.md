# Devenia Workflow

Run controlled AI-assisted content improvement and multilingual publishing workflows inside WordPress.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/devenia-workflow)](https://github.com/bjornfix/devenia-workflow/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0

**Stable tag:** 0.1.574

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
