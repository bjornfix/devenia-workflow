# ADR 0002: Cost-Bounded Stateless Translation Jobs

## Status

Superseded in part by
[ADR 0006](0006-verifiable-translation-quality-authority.md).

The bounded, stateless Translation Job and Run model remains accepted. ADR
0006 replaces the decisions that coordinator labels may act as authority, that
writer and Quality Runs may share an execution principal, and that caller
assertions alone are sufficient Quality evidence.

This is the only supported translation orchestration model. The superseded
persona, Heartbeat, Assignment, Reservation, and Work Item implementation has
been removed rather than retained behind compatibility aliases.

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

Use one bounded Translation Job Module inside Workflow Core.

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

### Coordinated Quality Passes (superseded authority rules)

This subsection records the original decision. Its authority and evidence
rules are superseded by ADR 0006. A coordinator may still orchestrate bounded
subagents, but its caller-provided label grants no authority and cannot collapse
translator and Quality provenance into one principal.

- A quality Run receives the source, submitted localized artifact, target
  language contract, and relevant checks, not the translator's accumulated
  conversation history.
- The quality packet includes every approved source fragment so factual
  accuracy and coverage can be judged against the actual source, not a summary.
- The Quality Decision binds to the exact submitted artifact revision.
- Publication requires a passing Quality Decision from a fresh Quality Run
  whose server-issued execution principal differs from the writer principal.
- Multiple passes exist only when they improve the page. The separation is an
  execution-provenance invariant, not a claim about agent motivation or an
  organizational approval hierarchy.
- Observability labels such as Ola and Kari may be displayed, but do not grant
  authority or establish quality.

Translation and Quality Runs use the current Job claim and a server-issued Run
principal. They do not revive Actor leases, policy personas, or long-lived
reviewer identities. `needs_review` may remain as a useful content state, but
it is not publication authority.

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
5. submit a Quality Decision bound to server-owned evidence receipts and any
   correction diff;
6. publish an approved artifact;
7. inspect Job and Run status, including cost.

Internal content, QA, and publication implementations remain reusable behind
the Translation Job Module, but their former orchestration abilities are not a
parallel public workflow. The publish gate checks the artifact revision,
required validation results, Quality Decision, and normal WordPress capability.

### Responsibility Split

The translation core retains:

- WordPress-native translated posts/pages;
- source mapping and revision/hash tracking;
- localized routes, canonical data, and hreflang;
- atomic Job ownership;
- complete artifact submission;
- deterministic structural, terminology, script, and link validation;
- immutable Run, quality, and cost evidence;
- guarded publication of the staged artifact only after principal separation,
  evidence receipts, and the complete Artifact Surface Revision pass.

These responsibilities must not be part of the Translation Job Interface:

- Heartbeat freshness and pacing;
- durable Actor bindings and persona leases;
- coordinator report inboxes;
- source design and presentation audits;
- taxonomy cleanup policy unrelated to the current translation packet;
- author archive, cache, performance, and general repair operations;
- reviewer-learning administration and broad site maintenance queues.

They are not part of this plugin's supported orchestration system.

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

## Replacement

The two owned installations replace the removed orchestration system directly.
The active inventory and Job/Run records may be rebuilt; ordinary WordPress
posts, translation relationships, localized routes, and published content stay
authoritative. There is no compatibility runtime or dual-write period.

## Consequences

- Model context becomes proportional to one translation instead of session age.
- Observability remains possible without identity infrastructure.
- Quality roles can be added or removed based on measured value rather than
  authorization topology.
- Cost, completion, and language quality become product metrics rather than
  incidental logs.
- The public Interface is smaller because removed orchestration operations are
  not registered alongside the Translation Job operations.
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

### Keep Long-Lived Reviewer Identities

Rejected as a product requirement. ADR 0006 requires a fresh, distinct Quality
Run principal for each artifact, but it does not reintroduce personas, accounts,
leases, or human organizational roles. Fresh subagents have no assumed motive
to game the workflow; the invariant exists so Workflow can prove what happened
without inferring motive.
