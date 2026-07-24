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

Legacy published translations can predate Canonical Route Contract metadata.
For those objects only, staging derives one deterministic effective contract
from the already-stored localized path and WordPress route-bearing identity.
The same resolver supplies the publication write, while applied-surface
verification still requires that exact contract to exist in post metadata.
The derivation has no observation timestamp, changes no route-bearing field,
and cannot authorize a URL migration. Once stored, it is an established route
and receives the same immutable treatment as every other contract.
Before staging, the resolver must also observe the current WordPress permalink
and normalize its path. A nonempty established canonical path, a nonempty
stored localized path, and the observed path must agree. Missing observation or
any mismatch rejects artifact staging without changing the Job, Run, claim, or
public surface. The same parity and exact staged route are checked again under
the publication row lock before the first write, closing the staging-to-apply
race without treating a route change as ordinary translation authority.

On a fresh installation, the configured static front-page source is the sole
bootstrap authority for a new language root. Its first Translation Job must use
the configured language prefix as both the root page slug and localized path,
creating `/prefix/` as ordinary WordPress page hierarchy. Other pages fail
before mutation until that translated front page exists, then resolve beneath
it. This special case establishes a missing route; it never alters an existing
Canonical Route Contract.

## Consequences

Improving a title, translation, keyword, or wording cannot change a published
URL. Necessary corrections remain possible, but use a separate high-friction,
auditable workflow. Existing callers no longer need to repeat route decisions
correctly on every update.
