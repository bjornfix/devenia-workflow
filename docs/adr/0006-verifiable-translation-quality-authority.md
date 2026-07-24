# ADR 0006: Verifiable Translation Quality Authority

## Status

Accepted.

Supersedes the coordinator-authority and caller-attestation decisions in
[ADR 0002](0002-cost-bounded-stateless-translation-jobs.md). The bounded,
stateless Job and Run model remains in force.

## Context

Translation and Quality work already runs in fresh subagents. There is no
observed incentive or evidence that those agents intentionally game Quality.
The defect is architectural: Workflow cannot distinguish a real check from the
same caller submitting `true`, free text, invented browser claims, or zero token
usage. A caller-selected coordinator label is also treated as authority, so
stored provenance does not prove that the writer and reviewer were distinct
executions.

Artifact submission currently writes through to an existing published
translation before Quality passes. The Quality Decision is then bound to a
content-only revision of that live post. SEO, taxonomy, route, and visible media
can fall outside the revision even though they affect the public reader surface.

The required fix is not more ceremony around trustworthy agents. It is a deep
Quality Authority Module whose small Interface makes the relevant facts
server-verifiable and fail-closed.

## Decision

### Staged Translation Artifact

A translator submits one immutable Staged Translation Artifact. Submission may
create or replace Workflow-owned staging records, but must not mutate an
existing published WordPress post, its SEO, taxonomy, Public Route, or visible
media. For a new translation, Workflow may reserve a non-public WordPress
identity, but the reader surface remains unchanged.

The approved staged artifact is applied only inside Localized Presentation
Publication. Application and public activation are one guarded transition. A
failure before activation leaves the prior published surface authoritative.

### Artifact Surface Revision

Every Staged Translation Artifact has one content-addressed Artifact Surface
Revision over the complete publication manifest:

- source and Job revisions;
- title, excerpt, and every localized content fragment;
- SEO title, description, and other publication-relevant SEO values;
- taxonomy identities and localized term values;
- Canonical Route Contract inputs and established route identity;
- visible media identity, attachment provenance, alt text, and other
  publication-relevant media values.

Quality Evidence Receipts, Browser Render Receipts, the Quality Decision, and
publication all bind to this exact Artifact Surface Revision. A change to any
surface member creates a new revision and invalidates prior receipts.

### Translation Run Principal

The coordinator must spawn one bounded translator subagent and, only after the
translator submits its complete artifact, a different bounded Quality
subagent. The translator may not review or approve its own artifact. The
Quality subagent reviews the exact translator Artifact Surface Revision and
may return `pass`, `revise`, or `reject`; it does not silently become the
translator. Reusing one subagent for both roles, or relabeling one run as the
other role, violates the coordinator contract.

Subagent topology is an orchestrator fact, not something a caller-selected
label can prove. Workflow publishes this requirement in both bounded packets
and enforces the server-verifiable boundary with distinct run IDs, claim
tokens, and server-issued principals plus exact artifact/surface revision
binding.

Each successful claim creates a server-issued Translation Run Principal bound
to the Job, `run_id`, role, claim token, and claim lifetime. `coordinator_id`
and `observability_label` remain display and correlation data only; callers
cannot choose an authority principal.

The writer principal is recorded on the staged artifact. A Quality claim is
rejected unless it creates a fresh `run_id` and a Quality principal different
from the writer principal. This proves execution separation without asserting
that different human accounts, personas, or organizations are involved.

### Quality Single-Flight Gate

Source Rewrite and Translation share one installation-wide Quality lease. A
Quality claim atomically acquires that lease only for its exact Job, Run,
Artifact Revision, submission generation, claim secret hash, and expiry. While
the lease is active, every other Quality claim fails with
`quality_run_active`, including claims from another workflow or language.

The exact terminal owner releases the global lease before deleting its local
claim after `pass`, `revise`, `reject`, or abandon. This ordering keeps a
failed global compare-and-delete retryable while global validation prevents a
released owner from retaining useful Quality authority. Expired leases are
replaced only through compare-and-delete plus create-only storage, so a
predecessor cannot delete its successor. Ability
discovery is presentation rather than authority: a known claim ability remains
safe when called directly because the execution seam enforces the lease.

The Gate proves single-flight execution, not fresh Codex topology. The
orchestrator must still terminate one Quality subagent after its single outcome
and spawn a fresh one for the next artifact.

### Server-Owned Quality Evidence

Caller booleans and narrative evidence may supply Reviewer Attestations from a
fresh, server-authenticated Quality Run, but cannot alone authorize a passing
Quality Decision. The Quality Authority Module accepts immutable Quality
Evidence Receipt identifiers issued by Workflow-owned deterministic checks or
a validated Adapter at an explicit evidence Seam.

Each server-owned receipt binds its check kind, result, Artifact Surface
Revision, issuing Adapter, policy revision, issuance time, and evidence digest.
Required server-owned receipt kinds include deterministic structure and source
coverage, localized route and links, SEO and taxonomy, offer/contact
preservation, and built-in HTTP/live-DOM publication readiness. A passing
Quality Decision requires the complete current receipt set; missing, failed,
expired, foreign, or revision-mismatched receipts fail closed.

Natural-language and factual judgment remain model tasks. Workflow stores them
as Reviewer Attestations only for a distinct Quality Run Principal and binds
them to the Artifact Surface Revision. Workflow does not pretend that
deterministic PHP can prove linguistic taste or all semantic facts; it proves
which execution judged which immutable surface. A Reviewer Attestation is
required, but never substitutes for the mandatory server-owned receipt set.

### Browser Render Receipt

Rendered experience requires a structured Browser Render Receipt, not a text
claim that a browser was used. A receipt may be a Reviewer Attestation from the
fresh Quality Run or a stronger receipt from a validated external browser
Adapter. Each receipt binds:

- Job, artifact, and Artifact Surface Revision;
- canonical or origin URL and response identity;
- a named viewport scheme with concrete width, height, and device scale;
- a named color scheme;
- document language and direction;
- render and layout measurements;
- screenshot or trace digest when produced;
- browser Adapter and policy revisions;
- an explicit trust level.

The required viewport and color schemes are policy-owned. Quality cannot pass
by supplying arbitrary viewport names or by reusing a receipt from another
surface revision. A reviewer-produced receipt is stored as
`trust=reviewer_attested`; Workflow validates its identity, hashes, dimensions,
schemes, URL, and artifact bindings but does not claim it independently
interpreted the screenshot. The Interface also accepts receipt IDs from a
validated external browser Adapter Seam when one is available. Neither form
replaces the mandatory built-in server HTTP/live-DOM receipt.

### Measured Run Usage

Caller-supplied token and cost numbers are estimates, not measured usage.
Measured usage requires a server-owned usage receipt or a validated provider
Adapter receipt bound to the Run principal. A successful translator or Quality
Run cannot record all-zero token usage as measured usage. If the provider does
not expose measurements, Workflow stores a structured `unavailable` state and
must not label it measured or use it as evidence that the Token Budget passed.

### Publish Authority

The publish Interface accepts a Job identity, not a coordinator authority
claim. Workflow resolves the current staged artifact, writer principal, Quality
principal, Artifact Surface Revision, Quality Decision, required evidence
receipts, and usage state from its own stores. Publication fails closed if any
binding is absent or stale.

## Acceptance Evidence

The Quality Authority Module is accepted only when contract and runtime tests
prove all of the following:

- submitting an artifact for an existing published translation leaves the
  published post and complete reader surface byte-for-byte unchanged;
- caller booleans and free text cannot produce a passing Quality Decision
  without the mandatory server-owned receipt set and principal-bound Reviewer
  Attestations;
- a Quality Run cannot claim or pass with the writer Run principal;
- Source Rewrite and Translation cannot hold Quality claims concurrently, and
  a terminal exact owner releases the shared slot without deleting a successor;
- both bounded packets carry the explicit distinct translator-subagent and
  Quality-subagent coordinator contract;
- every Browser Render Receipt is bound to artifact, complete surface revision,
  approved viewport scheme, and approved color scheme; reviewer-produced
  receipts are marked `trust=reviewer_attested` rather than server-verified;
- built-in server HTTP/live-DOM evidence is mandatory even when reviewer or
  external browser receipts exist;
- the Artifact Surface Revision changes when SEO, taxonomy, route, or visible
  media changes, not only when post content changes;
- all-zero caller usage cannot be stored as measured usage;
- applying the approved staged artifact happens only within guarded publication
  and a failed application preserves the previous public surface.

## Consequences

- The Quality Authority Interface becomes smaller: callers submit work and
  observations; Workflow resolves authority and evidence.
- The Module gains Depth because principal issuance, evidence validation,
  revision binding, and fail-closed publication stay behind one Interface.
- Locality improves: artifact drift and fake-evidence defects are fixed at the
  Quality Authority Seam rather than across coordinator prompts.
- Quality concurrency has one owner: the shared Single-Flight Gate rather than
  separate per-Job or per-workflow coordinator conventions.
- Fresh subagents remain the normal translator and Quality execution model.
- Browser and model-provider integrations are real seams only when their
  production and test Adapters both satisfy the same receipt Interface.
- Existing published translations remain public while their next revision is
  reviewed.

## Alternatives Considered

### Trust Fresh Subagents and Keep Caller Attestations

Rejected. Fresh subagents reduce correlated reasoning and have no evident
reason to cheat, but trust is not an auditable Interface. The workflow must
prove bindings and outcomes without making claims about agent motivation.

### Reintroduce Long-Lived Reviewer Personas

Rejected. Durable identities, leases, and personas add orchestration state but
do not prove the quality of one immutable artifact. Short-lived server-issued
Run principals provide the required provenance with less Interface complexity.

### Let Artifact Submission Update Published Content in `needs_review`

Rejected. A workflow status visible only in metadata does not protect readers
from unreviewed content. Staging must be physically separate from the current
public surface.
