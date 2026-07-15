# ADR 0005: Atomic localized presentation publication

## Status

Accepted.

## Context

A translated page could be published while its language menu was rebuilt by
deleting the active items and recreating them one by one. During that interval
the theme could render the English primary-menu fallback. Public HTML caches
could retain that intermediate response after the stored localized menu was
correct again. The publish implementation returned cache purge URLs without
consuming them, and frontend verification used only a query-string cache bypass,
so canonical cached navigation was outside the effective test surface.

Plugin rollouts exposed the same missing cache-coherence contract: a frontend
request could render while an active translation plugin was being replaced,
then remain public after the plugin hooks were active again.

## Decision

Localized Presentation Publication is one deep Module inside the Translation
Module. Its Interface does not succeed until all of these invariants hold:

1. the approved content revision is published;
2. a manifest update creates a pending revision while the previous active
   manifest and all active projections remain reader-visible;
3. a complete Public Header Projection is built away from the active menus for
   the configured source language and every configured target language; every
   manifest row must resolve, and skipped rows fail the complete set closed;
4. labels, localized targets, custom links, order, parent relationships, and a
   pre-activation recovery receipt are validated for every language before one
   database transaction switches the active manifest and all language-to-term
   identities together;
5. the prior managed projection set is retired only after activation, cache
   invalidation, and public verification succeed;
6. every canonical menu-dependent public URL is invalidated through the
   Frontend Cache Adapter;
7. an origin-bypassing response and the canonical cacheable response both show
   the expected language, primary-menu labels, and localized targets on the
   language homepage and blog archive.

The complete keyed origin/canonical matrix is dispatched under one absolute
same-site concurrency cap. A canonical URL is cacheable evidence, not proof of
a cache hit: a miss or revalidation can still reach the same WordPress origin.
The hard wall deadline reserves a viable minimum for every remaining group and
reclaims time from groups that finish early; configured timeout maxima are not
debited as if they were elapsed time.

Any failure after activation restores the prior manifest and identity set from
the pre-activation receipt. Before restoration, every prior menu term is locked
and must still match that receipt; a changed prior term is never reactivated.
Rollback is complete only after its own cache purge
and origin-plus-canonical verification succeed; otherwise the Interface reports
a structured critical recovery state. A missing or corrupt managed identity
fails closed at the primary-menu rendering Adapter and never exposes a raw theme
menu or core page-list fallback. That fail-closed contract begins at the first
successful complete-set activation; an installation not yet enrolled keeps its
pre-existing header during the one-time staged rollout.
Enrollment is stored as a durable transition independent of the active manifest,
so deleting or corrupting that manifest later cannot recreate pre-enrollment
fallback behavior. Restaging the active revision atomically cancels any different
pending revision. Normal Translation Job publication uses this same complete-set
Interface and cannot call a one-language activation path.

Public Header Projection expectations come from a complete runtime manifest
and registered language data. The current raw primary menu and the current
managed menu are rendered Adapters, not expectation oracles. Source and target
languages use the same staged validation, managed term metadata, stable
identity activation, cache invalidation, and public verification sequence.
Verification compares the exact projected primary-navigation anchor sequence;
additional raw anchors are a mismatch rather than an accepted subsequence.
Every manifest item binds an explicit editorial label for the source language
and every configured target language to the stable source-item identity. A page
title or translated page title is never a menu-label fallback. Missing label
authority rejects the pending manifest before the active manifest or identities
can change; the manifest revision and recovery receipt therefore bind the exact
labels that origin and canonical verification must render.
For the schema-1 to schema-2 transition, a capability-gated migration Interface
maps retained menu objects to the same stable source-item identities. At least
two independent complete retained candidates must agree exactly per language;
one candidate or conflicting candidates remain unresolved. The migration never
uses page titles as label evidence. A selected managed menu's stored labels and
URLs are already reader authority and are not passed through mutable runtime
text localization again. If schema-2 activation fails, the locked schema-1 menu
surface receipts provide a versioned exact-navigation verification oracle for
rollback; recovery never tries to manufacture schema-2 labels for schema-1.
An un-enrolled installation uses a separate capability-gated first-enrollment
Interface. The operator supplies the verified source-menu identity; the Adapter
derives stable page, custom-link, and parent identities and accepts target label
authority only when at least two unmanaged retained menus agree completely for
every configured language. A complete explicit authority set replaces broad
retained-menu discovery for that language rather than being mixed into it.
Within each menu, exact source menu-item identity and stored stable source-item
identity are evaluated before page/URL fallback and must agree with the requested
language relation, allowing intentional duplicate page references at different
hierarchy positions without ambiguity. Legacy relation fallback is available
only when stable identity metadata is wholly absent; invalid values, duplicate
rows, foreign identities, or relation mismatches are authoritative failures.
Unrelated menus are not errors. Missing, ambiguous, conflicting, or
snapshot-to-stage changed authority rejects before mutation.
The snapshot also binds the exact fresh page object or custom URL relation for
each stable source-item identity. A canonical temporary pending receipt covers
the manifest revision, relation revision, and every candidate menu surface
revision. Staging consumes only those exact relations, revalidates the receipt
before staging, after the complete set is staged, and again at the activation
boundary, then removes the intake receipt from the active manifest. Explicit
authority sets are all-or-nothing; a missing, managed, wrong-language, or
otherwise invalid member rejects the whole operation even when two other
candidates agree.
Page Relation Authority remains in canonical WordPress posts and postmeta. A
source page is valid only while it is published and has no source or language
translation identity. A target relation is valid only when exactly one
published object of the canonical source type has exactly one source-meta row
and one requested-language row. Source type/status and absence of translation
identity are validated even while resolving target-language candidates. Every
pending projection revision receives a fresh all-language ephemeral Relation
Authority receipt, including ordinary Translation Job publication and operator
restaging; a receipt-free active reader manifest is never mutation authority.
An unresolved target relation rejects the proposed revision before any pending
option or menu mutation and preserves the exact pre-existing pending authority.
Missing or malformed receipts also reject before staging, and activation removes
the ephemeral receipts from active state. Each successful staging operation issues
an opaque Activation Receipt over the exact raw stored pending option, including
both authority-receipt sets. Normalization validates the domain manifest separately
and cannot preserve authority for a different raw value. The receipt hashes exact
PHP serialization, including top-level and nested key order, and the locked pending
row revalidates that raw receipt before deletion. The activation Interface requires and revalidates that
exact receipt before creating a menu; it cannot restage or select another global
pending manifest. Missing, stale, concurrently displaced, and normalization-equivalent
raw replacement receipts therefore
fail before projection staging while an independent replacement remains exact.
Ordinary Translation Job publication treats every occupied raw pending option as
another operation's authority, including malformed values and values that normalize
to empty. It can issue a receipt only after a missing-to-created atomic claim for
its own active-manifest refresh, and successful activation removes that claim in
the same state transaction. It never mints new authority for an existing pending
value.
GitHub Actions is CI and distribution evidence only, never a production runtime
authority or dependency. Production PHP neither reads CI environment state nor
invokes or fetches workflow runs; updater and release metadata remain outside the
publication authority model. Production Workflow never shells out or requires an
external executable; process-based release and CI tooling remains outside runtime.
The canonical database acceptance suite is repository-owned and defaults to the
MariaDB 10.11 production baseline. MySQL 8.4 is optional compatibility evidence
only. GitHub Actions is a replaceable Adapter which may invoke that mode; neither
the suite nor its local contract checks read a workflow file as their source of
truth.
The Translation Index may cross-check that
canonical relation, but it cannot choose the candidate; an unavailable Index,
index-only source identity, missing row, stale status, or different target is a
fail-closed disagreement.

Internal custom links are canonical content relations rather than mutable URL
strings. Their receipt binds source and target post IDs, fresh source and target
permalinks, the exact normalized target URL staged into `_menu_item_url`, all
route-bearing ancestor post rows, canonical route metadata, and one route
revision. External custom links remain URL-only. The final activation transaction
sorts and locks authority/current/staged menus, canonical source/target/route post
rows, the complete source/language metadata-key range, and route metadata
predicates before the last receipt revalidation. Separate-process WordPress
workers with distinct database connection IDs prove InnoDB blocks both a post
writer and metadata-predicate writers with exact lock-wait timeout 1205 while
the owned transaction is open. The metadata writers exercise real absent-row
inserts for both canonical source-ID and language keys, then delete only the
exact inserted row; an update of an existing row is not predicate-lock evidence.
Each write succeeds and restores before the lock, then succeeds and restores
twice after rollback so cleanup is proven idempotent. These behavioral oracles
make the lock contract independent of implementation flags or the custom Index
table's storage engine.
The complete schema-2 draft then enters the same atomic activation Interface.
Any failed first activation restores and verifies the exact four-option state
captured before intake, leaving no orphan pending manifest.
The first-enrollment staging boundary consumes the recovery transaction
Adapter's three-valued commit receipt explicitly. A proven rollback must match
the exact pre-state. A proven commit followed by an Adapter error is reconciled
and restored to that pre-state. An unknown commit outcome is never collapsed to
failure or success: the owner re-reads the four option surfaces, attempts an
evidence-bound restore, and remains structured critical even when the exact
safe pre-state is recovered. Stale transactional `theme_mods`, `alloptions`, and
`notoptions` cache entries are evicted before any reconciliation read.
Every recovery boundary first passes the Adapter result through one strict
receipt decoder. `success` must be boolean, `committed` must be present and
strictly `true`, `false`, or `null`, and `code` must be a non-empty string.
Missing `committed` is malformed input, never an alias for explicit `null`.
Only the exact `null` filter sentinel means no Adapter supplied a receipt and
may invoke the default COMMIT Interface. A present non-array value becomes a
malformed diagnostic receipt; it can never silently trigger a real COMMIT.
Field validity is not terminality: the request-local owned transaction receipt
must also be absent at the reconciliation boundary. An Adapter which reports a
plausible committed value while leaving the owned transaction active is
invalid, and the owner closes that exact boundary before reading final state.
Malformed receipts terminalize only a still-owned transaction, then return a
critical `invalid_receipt` outcome without activation, publication, restore,
cleanup, or newly inferred rollback authority. The production portfolio gate
enumerates every raw recovery-COMMIT method identifier across every production
PHP file, rejects indirect/string call paths, and binds every direct self/static
call to its audited owner so a new mutation boundary cannot bypass the decoder
by moving traits or changing call syntax.
Every receipt crosses that cache-cleared read, including a successful COMMIT;
success is accepted only while the exact operation-owned replacement remains
current. A successful COMMIT followed by a third four-option revision is
foreign authority, stays byte-exact untouched, and never enters activation.
Reconciliation is operation-bound: it may accept the exact captured pre-state
or restore only this operation's exact pending `expected_after` state. Any third
four-option revision is foreign authority, remains byte-exact untouched, and
returns a structured critical conflict for both committed and unknown receipts.
The same proof binding applies after activation failure. The enrollment wrapper
accepts an already exact pre-state or restores only the exact staging state and
revision returned by this attempt's successful stage transaction. It never uses
an arbitrary observed state as CAS authority and never bypasses a severe
activation rollback. Foreign option state remains untouched; receipt-matching
staged menus are not deleted under that untrusted state. A severe unresolved
rollback that ends in this attempt's exact receipt-valid staging state is
different: its explicitly absent identity slot proves no staged menu is reader
authority, so the receipt-matching menus are removed without restoring or
bypassing the severe option state. If an exact authoritative state instead
references activation projection menus, those menus remain a closed critical
reader set. Recoverable failures and exact owned-staging cleanup require zero
unreferenced managed menus.
The activation state transaction consumes the same three-valued commit Adapter
receipt. After cache eviction it classifies only exact expected state as
unapplied, exact replacement state as applied, and every third state as foreign.
This classification runs for every receipt, including `success=true` with a
proven COMMIT; the COMMIT result alone is never activation authority.
Staged deletion requires a fresh read matching that receipt, an unapplied
outcome, and identities which reference none of the staged menu IDs. A true or
unknown commit with exact replacement preserves the active menus and returns a
structured critical applied state; foreign state is untouched and critical.
The reference proof, all staged-menu surface locks, a second state and receipt
revalidation, and every receipt-bound deletion share one owned serializable
transaction. Any identity or menu revision change rolls the whole cleanup back
before a staged deletion can become durable.
The content publication transaction follows the same rule before any header
refresh or public cache work. After commit and cache eviction, exact pre-state
is unapplied only for a false or unknown receipt, and exact replacement is
applied only for a true or unknown receipt. Every third post/meta/taxonomy
surface is foreign even when the Adapter reports a successful commit, because
success proves only that this transaction committed, not that a concurrent
writer did not change the surface immediately afterwards. Foreign state remains
untouched and returns a critical indeterminate publication result. Its observed
revision is diagnostic only and is explicitly denied rollback authority. Only
an exact owned replacement, or the exact owned staged pre-state after a proven
unapplied second-phase commit, can issue a rollback receipt to the Translation
Job caller. The caller never infers ownership from `published`, commit outcome,
or an observed revision. Applied state continues through the all-language header
refresh and every later failure carries its explicit owned rollback receipt.
The Translation Job recovery transaction consumes the same three-valued
receipt for every restore, including an Adapter response whose `success` is
true. After cache eviction, an exact captured pre-publication surface is an
applied restore for a true or unknown receipt; the exact owned mutation surface
is unapplied only for a false or unknown receipt. Every third surface is foreign
and remains untouched and critical. A read-only recovery snapshot is valid only
while its captured revision remains exact after its transaction closes. A
combined content/menu restore additionally proves the prior menu identity,
prior menu revision, and absence of the rolled-back staged menu before it can
report success. Successful restore writes still require frontend invalidation
and origin plus canonical media verification before recovery is complete.
The outer Translation Job Interface must not expose the inner Module's
`published` phase flag as its final result after compensation. It preserves
that history separately as `forward_publication_applied`, evicts caches after
rollback, and compares one fresh complete-surface CAS with both the captured
pre-state and the owned forward receipt. A verified restored surface reports
`final_reader_state=restored_verified` and `published=false`; an exact restore
whose receipt, cache invalidation, or reader/media verification is incomplete
reports `restored_unverified` and `published=null`. Forward state is true only
when both the owned forward CAS and reader verification pass. Foreign, empty,
or indistinguishable revisions remain null. The response rebuilds its
translation payload after this classification, so pre-rollback content cannot
leak as final authority.
Pre-enrollment recovery uses a dedicated receipt-bound reader verifier. It
fetches homepage and blog archive on origin and canonical cache surfaces and
compares the exact observed primary-navigation label/URL sequence with the
captured oracle. It intentionally does not apply forward schema-2 localized-link
expectations to the raw menu being restored; managed/schema-1/schema-2 rollback
continues through the full frontend integrity verifier.

Language menus use persisted WordPress term IDs as authority. Names remain
display metadata and migration input, not runtime identity.
Ordinary identity reads, projection staging, menu selection, and frontend
verification are side-effect free. They resolve only a persisted term identity
whose managed-language and manifest-revision receipts match the active
manifest. A missing or corrupt identity remains byte-exact and fails closed;
configured-name discovery and any resulting identity transition are permitted
only inside the capability-gated schema migration or first-enrollment
Interface.

The Cloudflare cache Adapter also observes completed WordPress plugin rollouts
and invalidates public HTML only after the upgrade has completed, closing the
window where a response produced without the updated plugin hooks could remain
cached.

## Consequences

- Translation Job publication has a deeper Interface and fewer ordering facts
  for callers to coordinate.
- Menu synchronization no longer exposes an empty or partial active menu.
- Canonical cached HTML, not only cache-busted origin HTML, is part of the test
  surface.
- Cache invalidation failures are structured publication failures rather than
  ignored advisory URLs.
- Existing name-based menus require a one-time deterministic identity
  reconciliation before the first atomic projection.
