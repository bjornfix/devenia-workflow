# ADR 0008: Code-owned invariants and runtime policy snapshots

## Status

Accepted.

## Context

Workflow currently mixes three different kinds of “contract”:

- executable protocol and safety invariants in plugin code;
- mutable language, copy, site, and operator policy in WordPress data;
- immutable Job, Run, artifact, receipt, and Quality evidence in WordPress data.

Moving every contract into a general database registry would let mutable option
state redefine the same identity, evidence, route, publication, and recovery
rules that must fail closed. Keeping every policy choice in the public plugin
instead makes site-specific render dimensions, terminology, register, and cost
settings require a release and risks leaking Devenia policy into reusable code.

## Decision

Use hybrid ownership.

Executable protocol, schema, and safety invariants remain code-owned. This
includes distinct translator and Quality Runs and principals, exact artifact and
surface binding, immutable evidence hashing, supported publication-surface field
types, Canonical Route preservation, guarded publication, compare-and-swap,
rollback, and fail-closed validation. Database state may record evidence that an
invariant held; it cannot redefine, weaken, or disable the invariant.

Mutable site, operator, language, copy, render-review, and safely bounded cost
policy belongs behind one Translation Policy Snapshot Interface. Its effective
value is canonicalized into an immutable content-addressed revision. Jobs, Runs,
receipts, Quality Decisions, Inventory projections, and Translation Obligations
pin that revision instead of repeatedly interpreting whichever mutable values
are current.

Do not create a general contract registry. A database Adapter stores only the
narrow policy snapshots and an atomically activated pointer. Code owns allowed
fields, normalization, safe ranges, mandatory minima, authorization, migration,
and fail-closed behavior. A test Adapter supplies the same Interface without
WordPress state.

Language/copy profiles, audited language-rule events, reviewer learning, and
source-scoped exceptions remain runtime data and feed the effective Snapshot.
Changing policy creates a new revision; it never mutates a revision in place.
Existing work either remains bound to its prior compatible Snapshot or is
explicitly requeued when the change is incompatible.

Coordinator wording such as “spawn a subagent” is guidance for a coordinator
Adapter, not publication authority. The code-owned invariant is two distinct
bounded executions with a Quality principal checking the exact translator
revision. No database flag may turn that separation off.

Fragment, media, and route surface coverage remains code-owned because it
defines which public values enter the Artifact Surface Revision. Localized
values and explicit source exceptions are data; arbitrary executable fragment
definitions are not.

Each active submission generation pins a separate content-addressed
Publication Surface Contract Revision derived from the code-owned typed fragment
projection and supported surface fields. It is not the plugin version and is
not a database registry. A missing legacy pin, a changed fingerprint, or
incomplete exact fragment coverage reopens the same source-revision Job through
the lifecycle lease only after ownership-bound CAS retirement of its exact
active Run and claim. Conflicts preserve active references for retry, retain
prior evidence and completed Runs immutably, and still require a fresh
translator and Quality generation before publication.

All mutable Run-record paths use the same exact serialized-value CAS seam.
Packet receipts, budget migration, abandonment, expiry, orphan finalization,
completion, and rollback deletion therefore cannot overwrite a terminal Run
from a stale owner.
When expected and replacement bytes are identical, the CAS seam performs an
exact BINARY current-value read: it succeeds for the still-owned no-op and
fails for a changed current owner despite MariaDB reporting zero affected rows
for both ordinary no-op updates.

## Consequences

- Safety behavior changes through reviewed plugin releases and their tests.
- Site and language policy can change without forking the public plugin.
- Historical Quality evidence remains interpretable against its exact policy.
- Policy activation and rollback use one revision pointer rather than mutable
  in-place configuration.
- A stale or missing required Snapshot fails closed instead of falling through
  to unrelated live option values.
- The public plugin does not become an interpreter for arbitrary database code
  or a shallow duplicate of its PHP contracts.

## Alternatives considered

### Store every contract in the database

Rejected. It creates a shallow registry, expands the privileged mutation
surface, weakens tamper resistance, and makes stale rows capable of redefining
security-critical behavior.

### Keep every contract and policy in the plugin

Rejected. It gives poor locality for site-specific language, copy, viewport,
and cost choices and requires releases for routine policy changes.

### Keep reading mutable policy directly at every phase

Rejected. Packet construction, Quality, publication, and Inventory can then
observe different effective policies for the same Job without an explicit
revision transition.
