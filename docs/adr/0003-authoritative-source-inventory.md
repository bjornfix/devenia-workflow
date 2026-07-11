# ADR 0003: Authoritative source inventory and exhaustion proof

## Status

Accepted.

## Context

The legacy queue sampled at most 500 recently modified posts/pages and removed
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
stable-cursor unresolved obligation reads, selecting the next v2 job, and an
Exhaustion Proof. The next-job adapter delegates creation to the existing v2
discover operation; the seven model-facing v2 operations remain unchanged.

Exhaustion is true only when the active generation is clean and completed,
`included_sources * target_languages` equals the stored obligation count, zero
obligations are unresolved, and all obligations are `published_verified`.

## Consequences

Queue priority cannot change which work exists. A source older than any finite
recent-content window remains represented. Empty legacy or obligation queue
output is no longer accepted as whole-site completion evidence; the Exhaustion
Proof is authoritative.
