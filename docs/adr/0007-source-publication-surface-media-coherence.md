# ADR 0007: Source publication surface and visible-media coherence

## Status

Accepted.

## Context

The Source Inventory and Translation Job identity used a hash of title, excerpt,
and Gutenberg content. A source featured image could therefore change without
changing the source revision, dirtying Inventory, creating a new Translation
Obligation, or invalidating old Quality evidence. Localized posts could retain an
older image indefinitely while the queue and Exhaustion Proof treated historical
content work as current. A manual repair ability could change public post meta
outside the Staged Translation Artifact and Localized Presentation Publication
Interfaces, which repaired one symptom without establishing a durable invariant.

Attachment ID alone is also insufficient. WordPress can replace attachment bytes
and metadata without changing either the source or attachment ID, and duplicate
thumbnail meta can make cached core reads disagree with the value the frontend
actually renders.

## Decision

Introduce one deep Source Publication Surface Module. Its Interface returns a
canonical manifest and content-addressed revision covering source content,
Public Route inputs, taxonomy, source design, and visible media. Featured-media
identity uses the effective WordPress thumbnail relation and binds attachment ID,
canonical URL, attached-file identity, attachment revision, metadata digest,
source alt, file size, file modification time, and SHA-256. An expected local
attachment whose bytes are unreadable is a structured fail-closed state.

Source Inventory generations, signatures, Translation Job IDs and stale guards,
translator and Quality packets, Artifact Surface Revisions, and obligation
projection consume this one Interface. Source approval evidence binds the same
Source Publication Surface revision so media suitability approval cannot predate
the image it authorizes.

Source thumbnail relation changes and mutations to referenced attachment files,
metadata, or alt text dirty Source Inventory. A changed source surface makes every
target-language Translation Obligation unresolved. Historical Job flags are not
sufficient for `published_verified`: the current source media, approved Artifact
media, stored localized media, and source revision must agree.

Localized Presentation Publication verifies the approved featured-image identity
in both origin-bypassing and canonical cacheable HTML, including the rendered
featured image and Open Graph image. Missing or wrong media fails publication and
uses the existing cache-aware recovery path.

The repair-featured-images ability remains only as an authorized Adapter that
discovers or reopens bounded Translation Job work. It does not directly write
thumbnail metadata, manufacture Quality authority, or bypass Localized
Presentation Publication.

## Consequences

- Visible-media changes invalidate earlier Artifact Surface Revisions and Quality
  Decisions exactly like other public-surface changes.
- Exhaustion cannot pass while any language has stale, missing, or unverifiable
  featured media.
- One canonical media reader handles duplicate postmeta and cache behavior across
  Inventory, staging, publication, repair intake, and tests.
- Same-ID attachment replacement is observable through bounded byte identity.
- Repair intake may require fresh source approval and fresh translator and Quality
  Runs; that cost is the consequence of making public media review auditable.
