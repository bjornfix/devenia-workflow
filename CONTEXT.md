# Domain Context

## Translation Job

A bounded obligation to produce or review one target-language artifact for one
source revision. A Translation Job owns status, atomic claim, input packet,
submitted artifact, validation result, and terminal outcome. It does not own a
long-lived model conversation or persona.

## Translation Run

One short-lived model execution for exactly one Translation Job phase, such as
translation or quality critique. A Translation Run has an immutable `run_id`,
execution metadata, model usage, duration, and output. It starts with a
purpose-built input packet and exits after submission; conversation history is
never workflow state.

## Quality Decision

The pass, revise, or reject result produced by a quality Translation Run. A
Quality Decision records the checked artifact revision, concrete evidence, and
any edits. It is a quality signal, not an independent authorization act; the
same coordinator may launch the translation and quality Runs and publish after
the required checks pass.

## Token Budget

The maximum model input, cached input, output, attempts, and estimated provider
cost permitted for one Translation Run. Exceeding a Token Budget is a terminal,
structured technical outcome. It never starts an unbounded retry or wait loop.

## Observability Label

A human-readable label attached to a Translation Run for operator visibility.
It grants no authority, survives no session, and is not used to decide quality,
assignment eligibility, or ownership.

## Source Inventory

The authoritative set of WordPress source content that must be considered for
translation. Each Source Inventory row records the source identity, revision,
post type, publication state, source-language classification, and an explicit
inclusion or exclusion reason. A source with no translations still exists in
the Source Inventory. Translation applicability is limited to source content
that is publicly visible without authentication or a content password.
Published, publicly viewable source `page` and `post` objects are included by
default. Draft, pending, future, private, trashed, password-protected, deleted,
translation, and non-public post-type objects remain visible in inventory
evidence but are excluded with a structured reason. `noindex` does not make a
publicly viewable source non-public and is not an exclusion reason.

## Inventory Generation

One immutable, rebuildable snapshot of the Source Inventory. An Inventory
Generation records its generation ID, scan policy revision, start and finish
times, source counts, inclusion and exclusion totals, and scan cursor state.
Only a completed Inventory Generation may support an Exhaustion Proof.

## Translation Obligation

The current required outcome for one included Source Inventory row and one
configured target language. A Translation Obligation derives its revision from
the source revision, language-profile revision, and applicable route or
taxonomy contract. Its state includes source approval work, missing, stale,
queued, active Translation Job, published, or a structured technical outcome.

## Exhaustion Proof

Evidence that one completed Inventory Generation has been projected against
every configured target language and has no unresolved Translation
Obligations. It includes inventory and exclusion totals, target-language count,
projected obligation count, unresolved state totals, and the exact policy and
generation revisions. An empty page of queue results is never an Exhaustion
Proof.

## Legacy V1 Workflow Terms

The terms below describe the deployed 0.1.536 compatibility workflow. They are
not requirements for the Translation Job Interface proposed in ADR 0002.

## Work Item

A current editorial obligation for one source or translation. A Work Item has a
stable `work_item_id` and a `revision` derived from the evidence that makes the
obligation current. Queue, coverage, and assignment selection must use the same
Work Item representation.

## Work Item Planner

The Module that produces ordered Work Items for a verified contributor session.
It owns eligibility, independence, reservation visibility, ordering, skip
reasons, and exhaustion semantics. Coverage aggregates Planner output; it does
not reimplement planning.

## Assignment

A server-owned, time-bounded allocation of one Work Item revision to one
independent contributor session. An Assignment has an `assignment_id`, identity,
Work Item snapshot, expiry, and lifecycle state. At most one active Assignment
may exist per session and per Work Item revision.

## Assignment Lifecycle

The Module that owns Assignment transitions: `accept`, `current`, `renew`,
`complete`, `block`, `abandon`, and `expire`. The server record is authoritative
and acceptance is idempotent for the owning session.

## Reservation

The storage-level exclusion used internally by Assignment Lifecycle to prevent
two sessions from owning the same Work Item revision. A Reservation is not an
Assignment and is not exposed as the contributor's source of truth.

## Atomic Option Create

The storage Module that creates an internal option row only when its unique
`option_name` is absent. The Interface returns true only for the caller that
inserted the row and never updates an existing value. Assignment session
records, Work Item locks, Reservations, and first Heartbeat state use this
Interface instead of WordPress 6.9 `add_option()`, whose duplicate-key behavior
updates the existing row and therefore cannot provide exclusion.

## Contributor Outcome

The structured result of an Assignment: `completed`, `blocked`, `abandoned`, or
`expired`. Completed outcomes require the assigned Work Item revision to be
resolved. Blocked outcomes include a controlled blocker category and evidence.

## Work Item Cursor

The latest `completed` or `blocked` Contributor Outcome for a stable Work Item.
Planner uses the cursor to keep the same actor/session from receiving its own
successor revision; an independent contributor may continue the Work Item.

## Claim Cache

The local `claim.json` projection used by the contributor command. It is a cache
of the server Assignment and can be recreated with `current`; it is never the
only copy of release authority.

## Heartbeat

Observability for contributor freshness and latest activity. Heartbeat state
must not decide Assignment ownership, completion, or reassignment policy.

## Internal Content Link Resolver

The Module that resolves one site-internal URL to its canonical WordPress
content identity. It covers both core permalink/query routes and registered
localized translation paths that WordPress core `url_to_postid()` cannot map.
Link integrity and source-link parity consume this one Interface.

## Source Editor Adapter

The builder-aware Adapter that identifies the native editor owning one source
page or post and returns its safe read, content-write, and design-write
abilities. The default Adapter uses WordPress content abilities. The Elementor
Adapter uses native Elementor abilities and requires element IDs, responsive
settings, global Kit styles, and the established Public Route to remain under
their owning controls. A Source Editor Adapter never creates translation work.

## Public Route

The externally visible URL identity of a published WordPress object. For a post
or page it includes every route-bearing value, including `post_name`, page
hierarchy, and any registered localized path. For a taxonomy term it includes
the term slug and route-bearing hierarchy. A title or content change is not a
Public Route change.

## Canonical Route Contract

The durable evidence established when an object first becomes public. It binds
the object identity to its Public Route. Ordinary content, translation, SEO,
metadata, import, REST, MCP, and editor writes must preserve this contract. A
different current permalink is route drift until an explicit URL Migration has
completed successfully.

## URL Migration

The separate, explicit workflow for changing an established Public Route when
the existing route has a concrete defect or a change is otherwise necessary.
A URL Migration records the reason, old and proposed routes, affected child
routes, authorization, redirect outcome, dependent-data refresh, verification,
and audit evidence. Normal save and translation artifact Interfaces do not
grant URL Migration authority.
