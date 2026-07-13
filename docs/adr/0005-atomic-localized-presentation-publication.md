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
2. a complete Localized Menu Projection is built away from the active menu;
3. labels, localized targets, custom links, order, and parent relationships are
   validated before one stable language-to-term identity switch;
4. the prior managed projection is retired only after the switch;
5. every canonical menu-dependent public URL is invalidated through the
   Frontend Cache Adapter;
6. an origin-bypassing response and the canonical cacheable response both show
   the expected language, primary-menu labels, and localized targets.

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
