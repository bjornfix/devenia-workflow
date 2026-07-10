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
