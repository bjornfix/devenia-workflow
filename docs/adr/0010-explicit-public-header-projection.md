# ADR 0010: Explicit Public Header Projection authority

## Status

Accepted.

## Context

Translation publication exposed a `sync_menu` boolean and passed it into
Localized Presentation Publication. A caller could therefore choose whether a
content mutation also ran the complete all-language Public Header Projection.
The default hid a second mutation behind ordinary publication, while `false`
allowed callers to bypass behavior that older architecture text described as a
publication invariant. Writer, Translator, and Quality Runs must not acquire
menu mutation authority merely because they participate in a content Job.

The two operations have different authority, cost, recovery scope, and cadence.
Content publication is revision-bound to one approved artifact. Public Header
Projection mutates one complete source-and-target navigation set and requires
its own all-language activation and verification evidence.

## Decision

Translation Job publication and the legacy content publish Interface expose no
`sync_menu` field. Localized Presentation Publication never invokes Public
Header Projection implicitly.

Public Header Projection remains the separate explicit
`activate-public-header-projection` mutation Interface. Only
the root coordinator may call it intentionally. Writer, Translator, and Quality
subagents never receive menu-sync authority. The explicit operation continues
to activate only a separately staged, receipt-bound complete configured-language
set atomically; no caller may request a one-language or partial projection.
Activation returns a verification-pending transition. Only the root coordinator
may resume that exact receipt by calling `verify-public-header-projection` once
per configured language until the complete set is terminally verified or its
exact prior reader state is restored and rollback verification completes.

## Consequences

- Publishing approved content cannot unexpectedly rebuild every language menu.
- Passing `sync_menu`, whether `true` or `false`, is rejected as an unknown
  publish argument by the public ability schema.
- Menu freshness is an explicit coordinator responsibility and must be invoked
  and verified separately when navigation relationships or labels require it.
- The atomic projection, durable transition, rollback, cache invalidation, and
  complete-language evidence contracts from ADR 0005 remain behind the two
  explicit root-coordinator Interfaces.
- Removing the publish-time switch passes the deletion test: no caller must
  learn a replacement flag, and authority does not reappear across subagents.
