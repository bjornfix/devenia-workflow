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
