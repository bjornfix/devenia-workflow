# ADR 0004: Stable public routes and explicit URL migration

## Status

Accepted.

## Context

Translation artifacts historically required callers to submit a localized slug
on every write. The normal upsert implementation then wrote both `post_name`
and page hierarchy even when it was only correcting published copy. Changing a
published parent slug changed every child URL and caused translation-index and
internal-link drift.

A separate URL Change Lockdown plugin existed, but version 1.4.3 protected only
post and term slugs on programmatic writes. It did not protect page hierarchy or
translation-localized paths, and authorized editor requests bypassed it.

## Decision

Establish a Canonical Route Contract when a WordPress object first becomes
public. Ordinary writes preserve every route-bearing value. Translation create
may establish a localized route, but translation update inherits the existing
published route and cannot replace it through the artifact Interface.

An established Public Route may change only through an explicit URL Migration.
The migration must preview the old and proposed route, include affected child
routes, require a concrete reason and explicit confirmation, create and verify
permanent redirects, refresh dependent canonical, hreflang, sitemap, cache,
internal-link and translation read models, and retain audit evidence. Failure
must leave the old Canonical Route Contract authoritative.

The generic URL stability plugin owns WordPress-native route drivers. The
translation Module owns its custom localized route and the create-versus-update
contract. Route evidence distinguishes established canonical, current observed,
and historical variants; it must report drift rather than silently bless it.

## Consequences

Improving a title, translation, keyword, or wording cannot change a published
URL. Necessary corrections remain possible, but use a separate high-friction,
auditable workflow. Existing callers no longer need to repeat route decisions
correctly on every update.
