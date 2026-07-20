# ADR-0009: Bounded Artifact View

Status: accepted

## Context

Quality Runs must review every source and localized fragment and bind their
decision to one immutable Artifact and Surface Revision. The packet previously
included the complete durable Artifact Record. That record also contains the
generated Gutenberg publication document and normalized presentation fragments,
so large pages carried the same localized content up to three times. A 92-fragment
page consequently exceeded the 50,000-token Quality input budget before review.

## Decision

Workflow owns one Bounded Artifact View shared by Quality review packets and
translator correction packets. It exposes:

- the complete submitted artifact and all localized fragments;
- complete source fragments in the packet's source view;
- staged title, excerpt, SEO, taxonomy, route, media, and design-hash facts;
- writer principal, submission generation, validation state, and exact source,
  Artifact, Content, Surface, baseline, and publication-contract revisions;
- the existing link, contact-action, language, evidence, and submission contracts.

The view does not expose the generated Gutenberg document or the second
normalized copy of localized presentation fragments from the internal
Publication Surface Manifest. Server receipt generation and publication continue
to use the complete durable record behind the Interface.

The existing token budget remains unchanged. Packet revisions are computed after
projection, preserving exact Run usage measurement and idempotent fetch binding.

## Consequences

Quality retains all evidence needed for language, factual, route, metadata,
link, contact, and visual review. A correction translator retains the complete
prior submitted artifact and exact Quality findings. Internal rollback/publication
payload growth no longer consumes either external context. Future publication
fields are private by default and enter this Interface only through an explicit
work or review fact.
