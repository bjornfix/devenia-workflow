# Domain Context

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
