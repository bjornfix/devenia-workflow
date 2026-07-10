# Translation Job V2 Ability Disposition

## Purpose

Translation Runs must not choose among the deployed plugin's 73 abilities. The
Translation Job Module exposes seven operations: discover, claim, fetch packet,
submit artifact, submit Quality Decision, publish, and inspect status/cost.

The current abilities are classified below for migration. Every ability appears
exactly once. `tools/check-translation-job-v2-ability-surface.mjs` verifies that
this classification still covers the deployed catalogue.

## Internal Adapters (19)

These remain useful behind the Translation Job Interface. They are not exposed
as nineteen separate choices to a translation or quality Run.

- `list-languages`
- `translation-fitness-status`
- `translation-index-status`
- `get-quality-profile`
- `agency-copy-brief`
- `list-taxonomy-terms`
- `update-source-qa-options`
- `get-source`
- `reserve-work`
- `release-reservation`
- `list-reservations`
- `upsert-page`
- `list-translations`
- `qa-translation`
- `publish-translation`
- `verify-live-translation`
- `workflow-status`
- `queue`
- `quality-verdict`

## Separate Owning Modules (37)

These may remain operator abilities, but they do not belong in a Translation
Run packet or model-facing translation Interface.

### Presentation And Runtime

- `get-presentation-surface`
- `update-runtime-text`
- `update-featured-image-alt`
- `author-archive-queue`
- `update-author-archive-translation`
- `sync-menu`

### Quality Governance And Learning

- `translation-fitness-scan`
- `update-quality-profile`
- `record-language-rule-event`
- `list-language-rule-events`
- `learning-inbox`
- `review-learning-event`
- `language-policy-status`
- `record-copy-feedback`
- `get-reviewer-style-profile`
- `record-reviewer-style-edit`
- `quality-review-queue`

### Source And Content Production

- `mark-source-content-integrity-reviewed`
- `authored-original-intake-queue`
- `update-authored-original-intake`
- `create-source-from-authored-original`
- `mark-source-generation-reviewed`
- `mark-source-taxonomy-reviewed`
- `mark-source-design-reviewed`
- `internal-link-opportunities`

### Maintenance And Repair

- `language-packs-status`
- `gutenberg-content-safety-scan`
- `frontend-performance-status`
- `frontend-integrity-status`
- `warm-cache`
- `repair-term-archive-self-redirects`
- `repair-translation-author`
- `reproject-source-design`
- `migrate-source-design-fragments`
- `repair-url-hierarchy`
- `repair-internal-links`
- `repair-featured-images`

## Retire From The Model-Facing Interface (17)

### Replaced By Job Status And Quality Decision

- `lifecycle-regression-status`
- `wrong-language-carryover-scan`
- `mark-reviewed`
- `mark-linguistic-reviewed`
- `workflow-obligations`
- `production-flow`
- `review-queue`
- `mark-quality-reviewed`
- `mark-final-reviewed`

Wrong-language carryover becomes deterministic validation. Legacy review marker
writes may remain inside a temporary migration Adapter, but the model does not
select or sequence them.

### Retired V1 Orchestration

- `next-heartbeat-action`
- `accept-assignment`
- `current-assignment`
- `renew-assignment`
- `complete-assignment`
- `resolve-assignment-block`
- `heartbeat-assignment-coverage`
- `heartbeat-status`

These operations protect the long-lived persona/Heartbeat design. They do not
improve the localized page and have no place in the v2 model Interface.
