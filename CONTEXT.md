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

## Rendered Information Architecture Quality

The independent judgment inside every customer-facing source and Translation
Quality Run that decides whether native page structure helps the reader
understand sequence, relationships, hierarchy, emphasis, and action at desktop
and mobile widths. Design is functional and should recede: important material is
emphasized selectively without nagging, while secondary detail remains calmly
available. The Interface requires concrete revision-bound reviewer evidence and
fails closed on both exhausting text walls and decorative repetition in which
every point shouts. It never selects cards, columns, or another layout by item
count, page identity, URL, language, or product name; the writer chooses the
quietest native structure that serves the actual content, and Quality judges the
rendered result independently.

## Bounded Artifact View

The bounded read Interface through which translator correction Runs and
independent Quality Runs receive one immutable Translation Artifact. It exposes
every localized fragment, submitted metadata, staged SEO/taxonomy/route/media
facts, writer identity, and exact Artifact and Surface Revisions. Quality packets
add every source fragment and server Quality receipts; correction packets add
the exact Quality findings. The durable Publication Surface Manifest remains
internal because its generated Gutenberg document and normalized presentation
fragments duplicate the external work content without adding authority. Packet
size is measured after this projection against the existing Run budget; the
budget is not raised to compensate for internal serialization details.

## Staged Preview Capability

The short-lived, claim-bound Interface through which an independent Source or
Translation Quality Run renders one exact immutable staged artifact through the
site's canonical theme before publication. Its URL contains a scoped HMAC
capability rather than a claim token, expires with the active Quality lease,
and cannot mutate public content. Existing translations use their own theme
shell; a translation with no WordPress post yet uses the canonical source post
only as a non-mutating theme host while the Adapter projects the staged target
content, locale, document language, and direction. Four desktop/mobile,
light/dark Browser Render Receipts bind the artifact, complete surface,
capability identity, exact host scope and post identity, viewport, language,
direction, and claim time. The immutable evidence record retains the exact
short-lived capability URL and digest but never the claim token; it re-resolves
the current host and revalidates its own content address before public mutation.

## Reader Surface Equivalence

The normalization inside live verification that compares an approved customer
action or internal route with its reader-facing representation. Its Interface
preserves exact semantic authority while accepting only reversible transport or
frontend-infrastructure representations, such as a root URL with or without its
slash and Cloudflare Email Protection's valid XOR encoding of the same mailto
action. It never skips an action, guesses a destination, or treats visibly
similar text as proof of an approved href.

## Translation Index Readiness

The deep Module that makes the derived Translation Index safe to consume and
mutate. Its Interface owns physical InnoDB schema proof, one expiring database
lease shared by rebuilds and writers, canonical WordPress-to-Index semantic
parity, isolated shadow construction, atomic table identity swap, exact schema
and readiness receipts, and fail-closed recovery. Readers continue on the last
ready canonical table while a shadow is built. Every standalone Index mutation
and every publication recovery transaction acquires the same writer lease, so a
rebuild cannot replace a table while an Index writer is active. The Module never
truncates or alters the canonical table in place, and plugin-version finalization
cannot precede a freshly proved ready state. A monotonic canonical revision makes
an older receipt stale before any canonical WordPress mutation. Mutations in one
request are projected together, but the read Interface flushes that request-owned
projection before evaluating readiness, so Index consumers always get
read-your-writes semantics without knowing the batching implementation. Every
request-local Index reader cache is bound to the same canonical-revision and
readiness-receipt epoch; an unavailable or newer revision cannot reuse an old
positive or empty result. Composite Index-derived readers cross one shared
epoch-aware cache Interface: the epoch is established before lookup, canonical
fallbacks are never cached while readiness is unavailable, and a revision that
changes during construction forces an uncached rebuild. Shallow caller caches
and manual refresh flags are not part of the Interface. The mutable Language
Registry and its runtime text projections rely on WordPress option caching only,
so their callers cannot preserve a stale registry behind an unrelated static
cache. A fresh readiness read invalidates the complete schema, receipt, and
canonical-revision option tuple before reading any field, so a separate writer
cannot make one request compare mixed old and new authority.

## Recovery Table Portfolio

The closed Module that names every semantic table role participating in a
Localized Presentation Publication recovery transaction. Its Interface returns
the seven WordPress content, taxonomy, metadata and option tables plus the
Translation Index. Engine proof, SQL placeholder count and completeness are
derived from those roles; callers never coordinate a literal table count and no
filter or external Adapter can expand the transaction authority surface.

## Localized Presentation Publication

The deep Module that turns one approved Translation Job into a stable public
content surface. Its Interface owns the content publish transition, canonical
frontend-cache invalidation, and origin plus canonical-cache verification as
one outcome. It neither accepts a menu-sync switch nor invokes Public Header
Projection. A stored post status alone is not successful Localized Presentation
Publication. This Module alone issues
rollback authority for its mutation: an observed foreign surface revision is
diagnostic evidence and can never be promoted to rollback authority by a
Translation Job caller.
Its recovery Interface accepts a captured reader snapshot only while the exact
captured surface is still current after cache eviction. A restore is complete
only when every three-valued transaction receipt has been reconciled to the
exact captured surface, frontend caches have been invalidated, and the origin
plus canonical media surfaces have verified. A third observed surface is
foreign authority and remains untouched.
The public Translation Job response separates forward-write phase evidence
from final reader truth. `forward_publication_applied` records only an exact
commit/CAS-proven forward mutation. After every rollback attempt the complete
surface CAS and translation payload are read again: `published` is boolean only
for a verified restored or forward reader surface and is null when the database
surface is restored/forward but cache or reader verification is incomplete.
Foreign, empty, or indistinguishable receipts remain null and diagnostic.
All mutation Modules share one strict Recovery COMMIT Receipt Interface.
`committed` must be an explicitly present `true`, `false`, or `null`; absence is
malformed rather than unknown. A malformed Adapter receipt closes only an
owned transaction and fails critical before any caller can continue, restore,
clean up, or derive rollback authority from the observed surface. Static
portfolio discovery scans every production PHP file for recovery COMMIT calls.
Only the exact null Adapter sentinel selects the default COMMIT; every present
non-array value is invalid. A field-valid receipt is also rejected while the
request-local owned transaction remains active, so an Adapter cannot authorize
progress by describing a terminal boundary it did not actually close. The
portfolio scan counts the raw private method identifier, rejects indirect
references, and binds all direct self/static calls to audited owners.

## Public Header Projection

The complete ordered primary-navigation projection for one configured source
or target language. Its authoritative Interface is a separate runtime manifest
plus registered language data, never the currently rendered WordPress primary
menu. Staging and first enrollment never activate it. Only the root coordinator
may invoke `activate-public-header-projection` with the exact pending receipt;
Writer, Translator, and Quality Runs never receive that authority, and content
publication never invokes it implicitly. Manifest updates create a pending revision.
That revision may enter reader authority only after every configured source and
target projection has staged, validated, and produced a recovery receipt. One
atomic transaction then switches the manifest and language identities together
with a durable receipt-bound transition. Activation is applied but incomplete at
that point: the prior complete set remains intact as rollback material and is not
retired until the transition reaches terminal forward verification.
Frontend verification observes only anchors inside the owned primary menu list.
Theme branding, search controls, secondary menus, and the presentation-injected
language selector are separate reader surfaces and cannot enter the Public Header
comparison oracle, including when tolerant HTML parsing reparents injected links.
The root coordinator resumes the exact transition through
`verify-public-header-projection`, one configured language per call. Each call
fetches that language's homepage and blog archive across origin and canonical
surfaces with WordPress Requests concurrency `1`, then CAS-accumulates compact
evidence. The transition finalizes only after the complete configured-language
matrix passes. A conclusive mismatch atomically restores the exact receipt-bound
prior reader state and enters the same bounded per-language verification path for
rollback; inconclusive transport and cache invalidation remain retryable. No
external service becomes runtime authority.
Every manifest row must resolve in every configured language; a skipped row is
an incomplete projection, not a successful partial menu. An explicit root-coordinator
call enters this pending-manifest Interface and cannot activate one language
independently. Enrollment is durable, so loss of the active manifest
or an identity after enrollment fails closed instead of reopening a raw menu.
Every ordinary Public Header identity reader, projection planner, menu selector,
and verifier is side-effect free: it accepts only the persisted identity whose
managed term receipt matches the active manifest revision. Name-based retained
menu discovery exists only inside the explicit capability-gated migration and
first-enrollment Interfaces; a missing or corrupt identity is never repaired by
the read which is supposed to detect it.
Activation and rollback both require cache invalidation and origin plus
canonical verification for every language homepage and blog archive. A raw
theme-location menu is only migration input; it cannot become public authority,
its own verification oracle, or a frontend fallback when managed identity is
missing or corrupt.
Every activation or first-enrollment COMMIT receipt is reconciled against a
cache-cleared exact four-option state before the Module reports success or
continues. Receipt-bound staged-menu cleanup owns the identity-reference proof,
all menu surface locks, revalidation, and deletion in one transaction; a changed
identity or menu revision makes cleanup fail closed without durable deletion.
Each manifest row also binds one explicit editorial label for the source and
every configured target language to its stable source-item identity. Page and
translation titles are content, not menu-label authority. A missing label fails
the pending revision closed before it can change the active projection set.
The schema-1 migration Adapter maps retained WordPress menus to stable source
item identities, requires at least two independent complete candidates with
identical labels for every language, and reports conflicts instead of choosing.
Once a managed language menu is selected, its stored signed labels and URLs are
final reader authority; mutable runtime text cannot relocalize it after receipt
validation. Schema-1 rollback uses its locked prior menu receipts and exact
stored navigation snapshot rather than attempting to invent schema-2 labels.
Before enrollment, a separate capability-gated intake takes one explicitly
verified source-menu identity, derives stable page/custom/parent identities,
and discovers target authority only from at least two agreeing unmanaged
retained menus per configured language. When the operator supplies a complete
explicit authority set for a target language, only that set is evaluated; it
is never mixed with unrelated retained menus. Exact source menu-item identity
or one valid stored stable source-item identity takes precedence over page/URL
fallback and must also match the requested-language relation, so deliberate
duplicate links remain independently owned. Only complete absence of stable
identity metadata permits legacy relation fallback; duplicate, invalid,
foreign, or relation-conflicting persisted identity fails closed. Unrelated
menus are ignored; missing, ambiguous, conflicting, or changed evidence rejects
before pending mutation. Accepted intake evidence carries a temporary canonical
receipt that binds the manifest revision, every candidate menu revision, and
the exact fresh page/custom relation observed with each editorial label.
Projection staging consumes those bound relations instead of resolving them
again, revalidates the complete receipt before and after staging and at the
locked activation boundary, and strips the one-time receipt before the schema-2
manifest becomes active reader authority. Every member of an explicit authority
set is mandatory; invalid or managed members cannot be silently omitted.
The Public Header Relation Authority Module chooses page relations only from
canonical WordPress post and postmeta rows. A source page must be published and
have no source/language translation identity. A target relation requires exactly
one published object of the same canonical type with exactly one matching source
row and one matching language row; the source type, status, and absence of
translation identity are re-read for source and target projections alike. Every
explicitly staged Public Header projection, including operator restaging of an
active manifest, carries a new complete all-language ephemeral
relation receipt. A missing target relation rejects the new revision before the
pending option or any menu changes, preserving the exact active and pre-existing
pending authority. Missing or malformed receipts likewise stop before staging,
and both intake and relation receipts are stripped from the active reader manifest.
Every successful pending write also returns an opaque Activation Receipt over the
exact raw stored pending option, including its intake and Relation Authority
receipts. Domain validation normalizes that value separately but never derives
activation authority from the normalized projection. The receipt hashes the exact
PHP-serialized array, so top-level or nested key reordering also changes authority.
Activation requires that
exact caller-owned receipt and revalidates it
before creating any menu; it never restages or activates an unrelated global
pending value. Even a raw replacement that normalizes to the same domain manifest
invalidates the prior receipt before staging and again under the locked pending-row
transition. A missing, stale, or concurrently displaced receipt is mutation-free
apart from preserving the independent writer's exact replacement. Translation
Job publication never reads, adopts, normalizes, replaces, or activates the raw
pending Public Header slot. Only an explicit Public Header staging operation
issues an Activation Receipt; successful explicit activation atomically removes
that exact pending value. GitHub Actions supplies CI and distribution evidence only. Production PHP
does not read CI state, invoke workflows, or treat a GitHub run or artifact as
runtime publication authority; release and updater metadata remain distribution
mechanisms, not runtime dependencies. Production Workflow never shells out or
requires an external executable; process-based release and CI tooling remains
outside WordPress runtime. The canonical database acceptance suite is a
repository-owned local tool which defaults to the MariaDB 10.11 production
baseline. MySQL 8.4 is optional compatibility evidence only, and a GitHub
workflow is merely one replaceable Adapter that may invoke that mode. Local
contract checks never read workflow files as authority. The
Translation Index Adapter is a fail-closed read-model cross-check only: it never
selects a candidate, and unavailable, missing, stale, or disagreeing rows reject
publication. Its schema is an owned InnoDB recovery surface; the shared
eight-table transaction preflight, exact recovery snapshot/CAS, and Relation
Authority locks include its present-or-absent source/language and
translation-post predicates. Internal custom links bind canonical source and target post IDs,
source URL, target URL, normalized staged URL, every route-bearing ancestor row,
canonical route metadata, and a route revision; external links remain URL-only.
At final activation, authority/current/staged menus, canonical source/target and
route post rows, the complete source/language metadata predicate, and route-meta
predicates are sorted, locked, and revalidated inside the owned serializable
transaction. Separate-process InnoDB runtime oracles use distinct database
connections and exact before/under/after writes to prove the route post row,
translation-identity predicate, and Translation Index identity predicates return
lock-wait timeout 1205 while the owner transaction is open. The identity proof performs a real absent-row insert and
exact compare-and-delete restoration for both the canonical source-ID and
language keys; it never substitutes an update of an existing metadata row.
Every surface restores byte-exact state twice afterward to prove cleanup is
idempotent. Request-local flags are never accepted as lock evidence.
Activation enters the atomic complete-set Interface and returns an explicit
verification-pending result. First enrollment uses the same durable transition;
a conclusive forward failure restores its exact four-option pre-intake state and
does not remove receipt-owned candidate menus until rollback verification passes.
Rollback locks and revalidates every prior term against its pre-activation
recovery receipt before restoring identities. Verification compares the exact
projected navigation anchors rather than accepting a matching subsequence.

## Localized Menu Projection

The target-language specialization of Public Header Projection.
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

## Devenia Social Sharing

The separate owned WordPress Module that renders accessible social-sharing
links without frontend JavaScript, external SDKs, tracking, runtime HTTP, or
external icon resources. Its deep Interface owns settings, network definitions,
canonical permalink input, localized heading and link text, automatic placement,
and the exact rendered-surface manifest consumed by Workflow. Workflow supplies
runtime localization and Canonical Route Contract values through owned Adapters;
it never introspects the sharing Module's implementation.
The Legacy Scriptless Sharing Adapter is the compatibility seam for the
installed upstream Scriptless Social Sharing owner. It maps that owner's share
action text, including accessible labels and email subject/body, to the same
semantic target-language `share_text` registry while preserving the owner's
links, actions, and presentation modes. It reconciles the registry's one
`{url}` slot with the owner-appended permalink. Pages and language branches
never participate in that mapping.

## Canonical SEO Surface

The complete SEO member of a Staged Translation Artifact and the deep Module
that resolves every SEO field to an explicit `set`, `delete`, or `preserve`
operation before an installed SEO Adapter receives it. Localized Presentation
Publication uses complete-replace semantics from the immutable Artifact Surface
Revision. General translation and generated-source writes use patch/derive
semantics, so a missing optional field never becomes deletion authority.

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

## Source Carryover Normalization

The Quality Adapter boundary that compares visible source-language terms with
one target-language artifact. Its preserve-term Interface accepts complete
technical names and phrases from the effective language profile plus optional
source-scoped policy. Preservation is phrase-bound: normalization removes only
an exact configured phrase before candidate extraction and target matching.
Individual component words outside that phrase remain visible to Quality and
cannot inherit a broad exemption. Callers never compensate with page IDs,
fragment keys, language branches, or generic component-word allowlists.

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

## Source Content Design Gate

The Workflow Module that makes the canonical `devenia_source_content_design_validation` Interface authoritative for every translatable canonical `page` and `post`. It consumes one site-owned validation Adapter, binds any explicit no-rewrite review to the current source hash, and prevents design inheritance, publication-experience approval, direct published-source saves, and translation fitness from bypassing the result. Workflow owns the gate and evidence lifecycle; it does not own site-specific layout classes, geometry, palette, typography, or source copy.

## Source Rewrite Quality Authority

The deep Module that keeps customer-facing source-surface changes away from the
public reader surface until one immutable Staged Source Rewrite Artifact has a
Quality Decision from a fresh Run Principal distinct from its writer. Its
Interface binds the complete old and proposed copy surfaces, source publication
surface revision, writer preservation brief, reviewer evidence, and exact
publication candidate. Structural counts and text-similarity signals may be
diagnostic evidence but never Quality authority. Quality judges the whole-page
commercial argument: buyer, problem, desired result, credible proof, offer,
boundaries, next action, the emotional connection created by the words, and the
purpose that makes every section worth reading. Publication accepts no caller
boolean or replacement content; it applies only the exact approved artifact.
The Artifact Interface accepts native structural or presentation changes even
when their extracted visible words are unchanged, because rendered information
architecture is part of the reader surface. It still rejects a byte-identical
title, excerpt, and normalized Gutenberg tree. This keeps design-only repairs
inside the same independent rendered Quality authority instead of forcing a
meaningless copy edit or an unreviewed direct write.
Every fresh source-writer, translator, and Quality Run receives a role-specific
Copy Quality Priming packet before it acts. The public plugin teaches the
transferable mechanisms behind concrete Ogilvy campaigns, contrasts generic
slop with purposeful copy, names the whole-page and literary-craft expectations,
and links a legal primary-reading library for real uncertainty. Ogilvy is an
effectiveness discipline here, never a period style or surface voice to imitate;
the authority asks whether the page works for its intended reader and purpose. A bounded
`devenia_workflow_copy_quality_site_policy` Adapter may add source-scoped
purpose, audience, facts, emphasis, prohibited claims, review questions, and
contrasts as runtime data; Workflow contains no site-specific product copy,
page IDs, or domains. Runs acknowledge the
exact priming revision; uncertainty fails closed instead of becoming an invented
rule. Every Source Rewrite Quality packet includes the owning Site Presentation
Adapter's deterministic validation of the exact proposed Gutenberg tree, and a
passing decision is rejected when that validation is unavailable or fails. If a
later public-write preflight still rejects an already approved artifact, the
publish Adapter returns the Job to one fresh bounded correction generation (or
marks it exhausted at the finite limit) instead of leaving an unpublishable
`ready_to_publish` dead end. The Source Rewrite Publish Adapter alone receives request-local authority
to cross both the neutral content-write preflight and final WordPress save seam.
It consumes the prefixed `mcp_expose_validate_content_write_policy()` Interface
owned by MCP Expose Abilities; Workflow never dispatches the owner's raw hook
and fails closed when that Interface is unavailable.
For rendered judgment, a Quality claim also receives one short-lived,
unguessable Preview Capability URL. The URL renders the exact immutable Artifact
through the canonical source's normal WordPress theme without mutating the
public source, is bound to the active Quality Run and claim expiry, and becomes
invalid when that claim terminates. It is noindex and no-store. A passing
Quality Decision requires policy-sized desktop and mobile Browser Render
Receipts whose artifact, copy revision, Preview Capability identity, viewport,
layout digest, and screenshot digest all match that exact staged surface. The
claim token is never placed in the URL, and no predictable public preview route
exists.
After exact storage and cache invalidation, a separate live verifier must find
the approved reader-facing copy on origin and canonical surfaces before the new
source hash becomes translation authority.

## Source Publication Surface

The deep Module that gives Source Inventory, Translation Jobs, Staged
Translation Artifacts, Quality, and Localized Presentation Publication one
canonical view of everything on the source that can change the localized public
reader surface. Its Interface returns a data-driven manifest and content-addressed
revision covering source content, Public Route inputs, taxonomy, source design,
and visible media. Featured-media identity includes the effective WordPress
attachment relation, canonical URL, attachment revision and metadata, source alt,
and a bounded local file identity with byte size, modification time, and SHA-256.
An expected local file that cannot be identified is a structured fail-closed
state, never permission to reuse earlier Quality evidence.

Changing any Source Publication Surface member dirties Source Inventory and
creates unresolved Translation Obligations for every configured target language.
A Translation Obligation is `published_verified` only when the current source
surface, approved Artifact Surface Revision, stored localized surface, and both
origin and canonical rendered media identities agree. The featured-image repair
ability is an Adapter into the bounded Translation Job and Localized Presentation
Publication lifecycle; it cannot directly mutate the public reader surface.

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

The **Inventory Input Epoch** is the monotonic authority revision for source and
translation surfaces, their metadata and taxonomy relations, Workflow Mode, the target-language registry and other inputs that
change obligation coverage or publication contracts. Readers never clear it.
An Inventory Generation binds the exact Input Epoch and an input-policy
signature; only a rebuild can activate a Generation at the current values.

The **Obligation Projection Epoch** is the durable revision shared by Inventory
Generation activation and Translation Job lifecycle writers. Both cross one
global writer lease. Translation Job callers use one deep commit Interface;
lease ordering, Job CAS/create, exact-row projection, state counts and recovery
remain inside the Inventory Generation Store. The Store advances the Epoch
before the Job mutation and advances the active manifest only after the exact
row, binding index and receipts persist. An interrupted writer therefore leaves
an explicit mismatch instead of a silently stale queue. A rebuild activates only
when both Epochs captured before projection are still current.

Every Generation receipt binds exact source and obligation counts, shard counts,
per-shard digests, the complete source and source-language binding indexes, and
a seekable per-shard unresolved directory for the whole site and each supported
source post type. Source cursors resume through their
exact row binding; obligation cursors seek through the directory without
materializing every unresolved ID. Exact Job commits update one row shard and
the whole-site plus matching source-type directory counts instead of sorting the whole queue. Normal cursor reads touch only the
requested bound shards; every terminal page, including a non-empty last page, and Exhaustion Proof require
a complete strict Store read. A missing, changed or corrupt shard is never
interpreted as an empty queue.

Queue readers use an opaque cursor snapshot bound to schema, Generation and both
Epochs plus the requested `all`, `page`, or `post` source-type scope, read the active Generation without re-projecting the complete
source-language Cartesian product, and revalidate the same view before returning.
Generation rebuilds capture their immutable Epoch and policy inputs once, then
advance server-owned scan and projection cursors in bounded chunks. A resume token exposes
only the continuation Interface; the Store owns intermediate rows, Epoch checks,
conflict handling and final atomic activation, so an HTTP execution window cannot
strand large sites without a completion path.
Next-Job dependency traversal loads rows through the binding index and has a hard
traversal budget rather than scanning every obligation. A scoped traversal follows
only same-type dependencies, so a page phase cannot silently select a post.
A source-dirty Generation fails
closed with `inventory_rebuild_required`; an incomplete obligation projection
fails closed with `inventory_projection_rebuild_required`. Only an explicit
rebuild may establish a new clean snapshot after either invariant fails.

## Translation Obligation

The current required outcome for one included Source Inventory row and one
configured target language. A Translation Obligation derives its revision from
the source revision, language-profile revision, and applicable route or
taxonomy contract. Its state includes source approval work, missing, stale,
queued, active Translation Job, published, or a structured technical outcome.

## Translation Policy Snapshot

An immutable, content-addressed view of the effective mutable policy for one
translation context. It covers site and operator choices such as enabled
language and copy guidance, render-review requirements, and bounded cost
settings. Translation Jobs, Runs, Quality evidence, and Translation Obligations
bind the exact Snapshot revision they consume so later policy changes cannot
silently reinterpret historical work. A Translation Policy Snapshot never
defines or relaxes protocol, authority, identity-separation, route, publication,
recovery, or evidence-binding invariants; those remain unconditional properties
of the owning Workflow Modules.

## Publication Surface Contract Revision

A code-owned, content-addressed fingerprint of the exact typed fragment
projection and supported public-surface fields for one source. It is separate
from Source Revision, Translation Job identity, plugin version, and mutable
Translation Policy. Each submission generation pins it through its Run,
artifact, staged manifest, Quality Decision, receipts, and publication
authority. Missing legacy pins, changed fingerprints, or incomplete exact
fragment coverage retire the exact active Run and claim with ownership-bound
compare-and-swap before reopening the same Job under its lifecycle lease. A
retirement conflict leaves active Job references intact and is retryable; prior
evidence and completed Runs remain immutable.

Every mutable Run write, including packet receipts, bounded budget migration,
abandonment, expiry, orphan finalization, completion, and failed-claim cleanup,
uses exact serialized-value compare-and-swap. No stale endpoint owner may
restore or replace a terminal Run.
An idempotent CAS whose expected and replacement bytes are identical succeeds
only after a direct BINARY match proves the current stored bytes still equal
the expected owner; MariaDB's zero affected rows never substitutes for that
ownership proof.

## Exhaustion Proof

Evidence that one completed Inventory Generation has been projected against
every configured target language and has no unresolved Translation
Obligations for an explicit `all`, `page`, or `post` source-type scope. It includes inventory and exclusion totals, target-language count,
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
Legacy backfill is permitted only when the stored localized path, any
established canonical path, and the normalized current WordPress permalink all
agree. This parity is required before artifact staging and again under the
publication lock before mutation; route observation failure or drift is not
translation authority.

## URL Migration

The separate, explicit workflow for changing an established Public Route when
the existing route has a concrete defect or a change is otherwise necessary.
A URL Migration records the reason, old and proposed routes, affected child
routes, authorization, redirect outcome, dependent-data refresh, verification,
and audit evidence. Normal save and translation artifact Interfaces do not
grant URL Migration authority.

## Block Layout Projection

The direction-aware Interface that derives translated native block geometry
from the authoritative source tree. Core owns directional mirroring; vendor
Adapters may replace unsafe wrapper geometry with equivalent native attributes.
GenerateBlocks Grid Gap Projection removes `horizontalGap` wrapper offsets and
reserves the same between-item gutter with `marginRight` in LTR or `marginLeft`
in RTL, derived from document-direction data. Translation publication stores
that projection by consuming the reusable Interface owned by MCP Abilities -
GeneratePress. Workflow does not implement canonical frontend projection,
GenerateBlocks CSS generation, or GenerateBlocks cache invalidation. Devenia
Site Presentation activates the same GP-MCP Module for canonical frontend
rendering. Page IDs, locales, translated text, generated block identifiers,
custom CSS, and selector inference are outside this boundary.

Legacy sharing email copy is also resolved through the Presentation Text
registry for the source language. The registry owns content-type-neutral
wording so a shared page is never mislabeled as a post; the legacy sharing
Adapter owns only placeholder compatibility and never hardcodes public copy.
