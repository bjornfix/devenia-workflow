# ADR 0002: Cost-Bounded Stateless Translation Jobs

## Status

Accepted

For v2 model orchestration this supersedes ADR 0001's independent-contributor
session model. ADR 0001 continues to describe the deployed 0.1.536 compatibility
implementation until migration is complete.

## Context

The original product objective is to produce complete, natural, useful Devenia
pages and posts in every configured language so readers trust Devenia and make
contact, with little or no operator intervention.
The current workflow protects many useful invariants, but it couples them to
long-lived Codex sessions, reviewer-independence controls, durable Actor
identities, lease renewal, Heartbeat freshness, pacing, coordinator reports,
and a broad queue of non-translation maintenance work.

That coupling has not produced a reliable autonomous translator:

- contributors have abandoned full translations for subjective attention or
  language-capacity reasons;
- empty/wait outcomes repeatedly ended turns and required operator nudges;
- structurally green QA has not prevented user-visible unnatural copy;
- session resume replays large accumulated histories instead of starting with
  the minimum context needed for one translation;
- orchestration defects have consumed engineering effort without increasing
  language quality.

The cost evidence from 2026-07-10 makes the current direction unacceptable as a
production worker model. The Ola session reported 145,094,778 cumulative model
tokens and the Kari session 125,194,648. Most input was cached, so these totals
are not equivalent to full-price uncached tokens, but the scale and repeated
250k-plus input turns are still incompatible with a bounded translation job.

The plugin itself now contains about 43,288 code lines, a 28,085-line main PHP
file, and 73 registered abilities. Translation workflow, frontend behavior,
source design, taxonomy review, cache/performance operations, repair tools,
learning, and worker orchestration are presented through one plugin Interface.

## Decision

Introduce a small Translation Job Module alongside the existing workflow. Do
not extend Heartbeat/persona orchestration while this decision is evaluated.

### Job Lifecycle

A Translation Job represents one source revision, target language, and phase.
Its lifecycle is finite:

`queued -> claimed -> submitted -> validated -> quality_pending -> ready_to_publish -> published`

Terminal alternatives are `changes_requested`, `failed_technical`,
`budget_exceeded`, `superseded`, and `cancelled`.

The Job record, not a conversation or local file, is authoritative. Atomic
claiming remains server-owned. A crashed worker loses its time-bounded claim and
the same Job can be retried with a new Run.

A Translation Job may only be created for a source revision with explicit,
hash-bound source quality evidence. Source improvement is a separate bounded
phase: the source must be useful, current, factually defensible, structurally
sound, and commercially clear before target-language work begins. Changing the
source supersedes every artifact and Quality Decision bound to its prior hash.

### Run Model

Each translator or quality critic is a fresh Translation Run with an immutable
`run_id`.
It receives one purpose-built packet and exits after submitting one artifact or
one structured terminal failure.

The packet contains only:

- source identity, revision, title, excerpt, SEO fields, and visible fragments
  with the minimal safe inline HTML needed to preserve emphasis and links;
- target-language route and taxonomy requirements;
- the effective target-language glossary/style rules;
- source link identities and required localized destinations;
- the small validation contract relevant to this Job;
- at most a few approved examples for the target language.

It does not contain prior contributor chat, Heartbeat history, coordinator
reports, unrelated sources, the whole ability catalogue, or the workspace
ledger.

Large sources may be processed in deterministic fragment batches, but partial
batches are never saved as a public translation. The Run assembles one complete
artifact, performs one consolidation pass, and submits it atomically.

### Coordinated Quality Passes

Independent sessions are not required. One coordinator owns the reader and
business outcome and may use bounded subagents for translation, language
critique, factual checks, SEO localization, or rendered-page inspection.

- A quality Run receives the source, submitted localized artifact, target
  language contract, and relevant checks, not the translator's accumulated
  conversation history.
- The quality packet includes every approved source fragment so factual
  accuracy and coverage can be judged against the actual source, not a summary.
- The Quality Decision binds to the exact submitted artifact revision.
- The coordinator may accept quality edits and publish after the required
  checks pass.
- Multiple passes exist only when they improve the page, not to manufacture
  organizational independence.
- Observability labels such as Ola and Kari may be displayed, but do not grant
  authority or establish quality.

Translation and quality Runs use the coordinator's normal WordPress capability
and the current Job claim. They do not claim Actor leases, policy personas, or
step-specific tokens. `needs_review` may remain as a useful content state, but
it does not imply that another session or account must perform the next action.

### Cost Contract

Every Run records uncached input, cached input, output, attempts, duration, and
estimated provider cost. Provider prices are configuration, not hardcoded
policy.

The prototype ceilings are:

- translator Run: at most 60,000 total model tokens;
- quality Run: at most 30,000 total model tokens;
- at most one validation-driven correction attempt per Run;
- no sleep, polling, session resume, or automatic model retry after a terminal
  outcome.

The production ceiling will be chosen from measured canary results. A Run that
cannot finish inside its budget returns `budget_exceeded` with evidence. It does
not reinterpret size or language as permission to abandon, and it does not keep
spending while waiting for another Job.

### Minimal Interface

The target Translation Job Interface is deliberately small:

1. create or discover a current Job;
2. atomically claim a Job;
3. fetch its bounded input packet;
4. submit a complete localized artifact;
5. submit a Quality Decision and any correction diff;
6. publish an approved artifact;
7. inspect Job and Run status, including cost.

Existing abilities such as `upsert-page`, `qa-translation`, `workflow-status`,
and `publish-translation` may serve as migration adapters. The v2 Interface must
not expose the existing 73-ability surface to a Translation Run.

The migration adapter may map a passing Quality Decision to legacy review
metadata while production remains on v1. The target publish gate checks the
artifact revision, required validation results, Quality Decision, and normal
WordPress capability; it does not consult Ydepi or translation Actor leases.

### Responsibility Split

The translation core retains:

- WordPress-native translated posts/pages;
- source mapping and revision/hash tracking;
- localized routes, canonical data, and hreflang;
- atomic Job ownership;
- complete artifact submission;
- deterministic structural, terminology, script, and link validation;
- immutable Run, quality, and cost evidence;
- guarded publication.

These responsibilities must not be part of the Translation Job Interface:

- Heartbeat freshness and pacing;
- durable Actor bindings and persona leases;
- coordinator report inboxes;
- source design and presentation audits;
- taxonomy cleanup policy unrelated to the current translation packet;
- author archive, cache, performance, and general repair operations;
- reviewer-learning administration and broad site maintenance queues.

They may remain temporarily as compatibility Modules, move to owning plugins,
or be retired after migration evidence proves they are unnecessary.

## Acceptance Evidence

The prototype must process one long source with at least 100 visible fragments
into three target languages. It is accepted only when all of these are proven:

- every assigned translation Job ends in a complete submitted artifact or a concrete
  technical failure; no subjective abandon outcome exists;
- no operator nudge, `continue`, window restart, or wait loop is required;
- all source-visible fragments are represented exactly once;
- localized routes, SEO fields, required links, and taxonomy inputs validate;
- a bounded quality Run records concrete evidence and any edit diff;
- translator plus quality Runs stay below 90,000 total model tokens per language;
- each Run exposes actual usage and estimated cost;
- known regressions in the translation-fitness corpus remain blocked;
- the resulting copy is judged natural and useful by the quality pass, not
  merely structurally green;
- the localized page preserves or improves the source offer, proof, next action,
  SEO intent, and contact path;
- dev WordPress stores the artifact as `needs_review` before approval and the
  coordinator publishes only after the Quality Decision passes.

The comparison report must show completion rate, interventions, elapsed time,
model tokens, estimated cost, validation failures, quality edits, and final
state against the 2026-07-10 baseline.

## Migration

1. Freeze new Heartbeat/persona features and keep production 0.1.536 stable.
2. Implement the bounded packet builder and Job/Run contracts without writes.
3. Run local fixture translations and measure prompt/output budgets.
4. Enable draft-only submission on the validation WordPress site through
   existing guarded storage adapters.
5. Add a dev-only v2 capability adapter that accepts coordinator-owned Quality
   Decisions without Actor leases, then prove correction/publish gating.
6. Canary one source and three languages on production without auto-publish.
7. Move or retire compatibility Modules only after the canary report passes.

Rollback is immediate during phases 2-6 because the existing workflow remains
authoritative and no v2 artifact is published automatically.

## Consequences

- Model context becomes proportional to one translation instead of session age.
- Observability remains possible without identity infrastructure.
- Quality roles can be added or removed based on measured value rather than
  authorization topology.
- Cost, completion, and language quality become product metrics rather than
  incidental logs.
- The existing plugin is not made smaller immediately. Reduction follows only
  after the v2 Interface proves which compatibility Modules can be deleted.
- Some current abilities will move to other owning Modules or lose automatic
  worker exposure. That is intentional: fewer choices increase locality and
  reduce prompt/tool selection cost.

## Alternatives Considered

### Continue Hardening Long-Lived Contributor Sessions

Rejected. It improves orchestration correctness but preserves context growth,
persona/lease complexity, user-visible stopping behavior, and weak cost control.

### Keep Personas But Start Fresh Conversations

Rejected as authority design. Labels remain useful for dashboards, but fresh
Runs and immutable provenance already provide the required visibility without
durable Actor state.

### Keep Independent Reviewer Sessions

Rejected as a product requirement. A separate critical pass can improve
quality, but it may be a subagent under the same coordinator. Names, accounts,
leases, and control-chain separation do not make copy better by themselves.

### Rewrite the Production Plugin Immediately

Rejected. A flag-day rewrite would risk live routing, stored translations, and
publish gates before the smaller model has proven its quality and cost.
