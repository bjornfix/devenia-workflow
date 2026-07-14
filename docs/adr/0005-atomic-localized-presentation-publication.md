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

Language menus use persisted WordPress term IDs as authority. Names remain
display metadata and migration input, not runtime identity.

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
