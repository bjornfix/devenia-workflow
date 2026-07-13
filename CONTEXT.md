# Domain Context

## Workflow Core

The deep Module that owns site-level Workflow Mode, Source Editor Adapters,
bounded source improvement, Translation Job orchestration, observability, and
ability registration. Its Interface is named `Devenia_Workflow` in PHP,
`devenia_workflow_*` for WordPress hooks and storage, and
`devenia-workflow/*` for abilities. AI is an implementation choice and is not
part of this Module's domain name.

## Translation Module

The deep Module inside Workflow Core that owns Translation Jobs, Translation
Runs, Quality Decisions, Translation Obligations, localized routes, language
rules, translation provenance, and translation read models. Translation
specific Interfaces retain `Translation` in their functional names while using
the shared `Devenia_Workflow` product namespace.

## Localized Presentation Publication

The deep Module that turns one approved Translation Job into a stable public
reader surface. Its Interface owns the content publish transition, Atomic
Localized Menu Projection, canonical frontend-cache invalidation, and origin
plus canonical-cache verification as one outcome. A stored post status alone
is not successful Localized Presentation Publication.

## Localized Menu Projection

The complete ordered navigation projection for one configured target language.
It binds a stable WordPress menu term identity to the expected translated page
targets, localized labels, custom links, and parent graph. A replacement
projection is built and validated away from the active term, then activated in
one identity update; readers never observe a deleted or partially rebuilt menu.

## Frontend Cache Adapter

An Adapter at the cache-invalidation Seam that makes stable WordPress
presentation state authoritative over public HTML caches. The WordPress
Adapter clears local object/content caches and the Cloudflare Adapter purges
canonical public URL prefixes after publication or plugin rollout. Cache-aware
verification reads both an origin-bypassing view and the canonical cacheable
view.

## Translation Job

A bounded obligation to produce or review one target-language artifact for one
source revision. A Translation Job owns status, atomic claim, input packet,
submitted artifact, validation result, and terminal outcome. It does not own a
long-lived model conversation or persona.

## Translation Run

One short-lived model execution for exactly one Translation Job phase, such as
translation or quality critique. A Translation Run has an immutable `run_id`,
server-issued Translation Run Principal, execution metadata, measured or
explicitly unavailable model usage, duration, and output. It starts with a
purpose-built input packet and exits after submission; conversation history is
never workflow state.

## Translation Run Principal

The server-issued execution identity for one Translation Run. It binds the Job,
`run_id`, role, claim token, and claim lifetime. A coordinator or observability
label cannot choose or impersonate it. The Quality Run Principal for an artifact
must be fresh and different from its writer principal; this proves execution
separation without claiming different human identities or agent motives.

## Staged Translation Artifact

One immutable, complete target-language publication candidate stored outside
the current public reader surface. It may reserve a non-public WordPress object
for a new translation, but submitting it never mutates an existing published
post. Only Localized Presentation Publication applies an approved staged
artifact.

## Artifact Surface Revision

The content-addressed identity of the complete Staged Translation Artifact
surface: source and Job revisions, title, excerpt, fragments, SEO, taxonomy,
Canonical Route Contract inputs, and visible media. Quality and publication
evidence bind to this revision; changing any member invalidates earlier
receipts and decisions.

## Quality Authority Module

The deep Module that issues Translation Run Principals, validates Quality
Evidence Receipts and Browser Render Receipts, binds a Quality Decision to one
Artifact Surface Revision, and makes publication fail closed. Its Interface
accepts receipt identities and principal-bound Reviewer Attestations, not
caller-selected authority or booleans as sufficient proof.

## Quality Evidence Receipt

An immutable server-owned record that one deterministic Workflow check or
validated Adapter evaluated one exact Artifact Surface Revision under one
policy revision. It records check kind, result, issuing Adapter, evidence
digest, and issuance time. Required receipts cover structure and source
coverage, links and route, SEO and taxonomy, offer and contact preservation,
and server-observed HTTP/live DOM. Semantic reviewer judgment is a Reviewer
Attestation, not a server-owned Quality Evidence Receipt.

## Reviewer Attestation

A natural-language, factual, or visual judgment made by one fresh,
server-authenticated Quality Run Principal and bound to one Artifact Surface
Revision. Workflow can prove who attested to which immutable surface, but does
not claim PHP proved linguistic taste or screenshot meaning. Reviewer
Attestations cannot produce a Quality pass without all mandatory server-owned
Quality Evidence Receipts.

## Browser Render Receipt

A structured Reviewer Attestation, or stronger receipt issued through an
external browser Adapter Seam, that binds the artifact and Artifact Surface
Revision to URL/response identity, approved viewport and color schemes,
language/direction, render measurements, Adapter revision, and screenshot or
trace digest. Reviewer-produced receipts are explicitly stored with
`trust=reviewer_attested`; Workflow validates their bindings but does not claim
it independently inspected the pixels. A built-in server HTTP/live-DOM Quality
Evidence Receipt remains mandatory for every pass.

## Measured Run Usage

Token and cost usage bound to one Translation Run Principal by a server-owned
or validated provider Adapter receipt. Caller numbers are estimates. An all-zero
caller payload is never measured usage; unavailable provider measurements are
stored explicitly as unavailable.

## Quality Decision

The pass, revise, or reject result produced by a quality Translation Run. A
Quality Decision records the checked Artifact Surface Revision, distinct
Quality Run Principal, required Quality Evidence Receipt identities, reviewer
Attestations, and any corrections. A passing decision is publication authority
only when the Quality Authority Module verifies every current server receipt
and required attestation. The same coordinator may orchestrate both fresh Runs,
but coordinator identity grants no authority and the writer and Quality
principals must differ.

## Token Budget

The maximum model input, cached input, output, attempts, and estimated provider
cost permitted for one Translation Run. Exceeding a Token Budget is a terminal,
structured technical outcome. It never starts an unbounded retry or wait loop.

## Observability Label

A human-readable label attached to a Translation Run for operator visibility.
It grants no authority, survives no session, and is not used to decide quality,
Job eligibility, or ownership.

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

## Inventory Generation Store

The Module that persists one immutable Inventory Generation behind a small
Interface for source pages, unresolved Translation Obligations, counts,
refresh, activation, and cleanup. Its WordPress Adapter writes bounded,
generation-keyed, non-autoloaded option shards first and activates them only by
flipping the completed generation manifest. Translation Job discovery and
Exhaustion Proof consume this same Interface.

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

## Atomic Option Create

The storage Module that creates an internal option row only when its unique
`option_name` is absent. The Interface returns true only for the caller that
inserted the row and never updates an existing value. Translation Job, Run,
artifact, and Quality Decision records use this Interface for bounded atomic
state transitions.

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

## Workflow Mode

The site-level choice between `multilingual` and `source_only`. Multilingual
mode owns translated frontend routing, locale switching, and target Translation
Obligations. Source-only mode keeps WordPress locale and HTML language
authoritative, creates no target Translation Obligations, and retains native
Source Editor Adapter and content-optimization abilities. Workflow Mode is
configuration, not an inferred property of one page.

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
