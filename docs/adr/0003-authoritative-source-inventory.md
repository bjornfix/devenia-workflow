# ADR 0003: Authoritative source inventory and exhaustion proof

## Status

Accepted.

## Context

The superseded queue sampled at most 500 recently modified posts/pages and removed
translation objects after the query. A large set of newer translations could
therefore hide an older source with no translations. An empty queue only proved
that the sample was empty, not that the website was complete.

## Decision

Build a complete generation of all current `page` and `post` objects in stable
WordPress ID order. Include published, publicly viewable, password-free source
objects. Retain all other objects in the generation with a structured exclusion
reason. Public noindex content remains applicable because it is publicly
visible.

Project exactly one Translation Obligation for every included source and every
configured target language before prioritization. Store the source revision,
translation/job identity and current lifecycle state in a generation-bound
read model. Activate the new generation only after both inventory and projection
finish. Post saves, trashing, restoration and deletion dirty the active
generation and invalidate exhaustion until a rebuild completes.

Expose operator abilities for full rebuild, stable-cursor inventory reads,
stable-cursor unresolved obligation reads, selecting the next Translation Job, and an
Exhaustion Proof. The next-job adapter delegates creation to the current
Translation Job discover operation; the seven model-facing Translation Job operations remain unchanged.

Obligation queue reads, next-Job selection, dependency traversal, and Exhaustion
Proof accept one explicit source-type scope: `all`, `page`, or `post`. The scope
is part of the cursor snapshot and uses generation-bound per-type unresolved
indexes. Dependency ordering cannot cross from a scoped page phase into a post
or vice versa. This keeps phase policy behind the same Inventory Interface
instead of requiring coordinators to join source and obligation cursors into a
shadow queue.

Exhaustion is true only when the active generation is clean and completed,
`included_sources_in_scope * target_languages` equals the stored obligation
count in scope, zero scoped obligations are unresolved, and all scoped
obligations are `published_verified`.

## Consequences

Queue priority cannot change which work exists. A source older than any finite
recent-content window remains represented. Empty sampled or obligation queue
output is no longer accepted as whole-site completion evidence; the Exhaustion
Proof is authoritative.
