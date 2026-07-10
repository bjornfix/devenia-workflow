# ADR 0001: Server-Owned Assignment Lifecycle

## Status

Accepted

## Context

Heartbeat work currently distributes one logical assignment across recomputed
Work Items, WordPress Reservation options, Heartbeat history, a local
`claim.json`, session lease state, and free-form coordinator reports. Selection
and coverage repeat policy loops. A process failure between server Reservation
and local file creation can orphan the Reservation, while a missing local file
can remove the contributor's only release token. Release does not state whether
work completed, blocked, or was abandoned.

This produced observed duplicate claims, stale local claims, lost concurrent
Heartbeat updates, false `wait`, false route-repair Work Items, and alternating
reassignment loops.

## Decision

Introduce a server-owned Assignment Lifecycle Module and a single Work Item
Planner Module.

- Planner is the only Module that derives actor-eligible ordered Work Items.
- Coverage aggregates the same Planner result used by assignment acceptance.
- Assignment Lifecycle owns `accept`, `current`, `renew`, `complete`, `block`,
  `abandon`, and `expire`.
- Acceptance is idempotent: a session with an active Assignment receives that
  Assignment again instead of a second Work Item.
- The Assignment record stores the Work Item snapshot, revision, owner identity,
  expiry, Reservation authority, and outcome.
- Local `claim.json` is a regenerable Claim Cache.
- Completed outcomes are accepted only when the assigned Work Item revision is
  no longer current.
- Blocked outcomes are structured and bind blocker evidence to the Work Item
  revision. The exact revision remains suppressed until a coordinator records
  a verified resolution. Heartbeat history and report prose do not control
  reassignment.
- Existing reservation abilities remain compatibility adapters while clients
  migrate to Assignment Lifecycle.

## Consequences

- Assignment and recovery invariants gain one Interface and one executable test
  surface.
- Reservation storage remains internal and may continue using WordPress options
  initially.
- Existing clients can migrate without a flag day through compatibility ability
  responses.
- The implementation is larger than another repeat guard, but removes the need
  for local stale-claim heuristics and Heartbeat-based assignment suppression.

## Alternatives Considered

### Keep Recent Assignment Keys In Heartbeat

Rejected. It treats observability as workflow authority, has arbitrary history
length, and cannot recover a missing local claim or validate completion.

### Increase Queue Scan Limits

Rejected. It does not fix duplicated planning, fairness, assignment recovery, or
outcome semantics.

### Keep Local Claim Files Authoritative

Rejected. A local file cannot provide server-side idempotency or survive process
failure between Reservation and file creation.
