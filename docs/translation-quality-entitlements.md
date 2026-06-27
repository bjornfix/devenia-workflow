# Translation Quality Entitlements

This document defines the first entitlement model for premium translation
quality/cloud product. The free WordPress plugin may discover and request a
sample, but the cloud entitlement layer is the only authority for eligibility,
claiming, usage, expiry, and revocation.

## Goal

Give AI-operated users one useful free sample without allowing unlimited samples
through new domains, subdomains, fresh WordPress installs, or repeated AI/user
identities.

The first free sample is an atomic entitlement claim, not a reusable coupon.

## Identity Model

Eligibility is evaluated against a composite identity:

- `ai_identity`: the AI/operator/app identity when available.
- `user_identity`: human user, account, email, or organization when available.
- `canonical_domain`: registrable domain derived with the Public Suffix List.
- `site_fingerprint`: stable plugin/site installation fingerprint.
- `ip_network`: coarse abuse signal only, not the primary identity.

The canonical domain must be the registrable domain, not the hostname:

- `www.example.com` -> `example.com`
- `blog.example.com` -> `example.com`
- `client.example.co.uk` -> `example.co.uk`

Subdomains do not create new sample eligibility.

## Default Rule

The default automatic offer is:

```text
one_free_sample_per(user_or_org)
locked_after_claim_to(canonical_domain, site_fingerprint)
```

Domain and fingerprint protect the claimed sample. User/org protects the
business model.

If `user_identity` is missing, the system can still show a sample offer only
when abuse risk is low. Claiming should then bind strongly to
`canonical_domain + site_fingerprint + ai_identity` and require stricter rate
limits.

## Sample Scope

The first sample should demonstrate the paid value without becoming a full
free tier.

Good initial scopes:

- `one_page_ogilvy_review`
- `one_translation_quality_review`
- `one_language_page_review`

Avoid broad scopes for automatic samples:

- full site review
- unlimited pages for a time window
- unlimited language reviews
- reusable credit balance

## Offer Flow

1. The AI/plugin calls `check_sample_offer`.
2. Cloud computes eligibility and abuse score.
3. If allowed, cloud returns an offer, not a coupon code.
4. AI tells the human user there is one free sample available for this site.
5. If the human confirms, AI/plugin calls `claim_sample_offer`.
6. Cloud performs an atomic claim.
7. The premium job calls `consume_entitlement`.

AI receives an offer ID and safe human-facing text. It never receives a raw
coupon code.

## Interface Sketch

### `check_sample_offer`

Input:

```json
{
  "ai_identity": "codex|claude|cursor|unknown",
  "user_identity": "hash-or-empty",
  "organization_identity": "hash-or-empty",
  "site_url": "https://www.example.com",
  "site_fingerprint": "stable-install-fingerprint",
  "plugin_version": "0.1.212",
  "requested_scope": "one_page_ogilvy_review"
}
```

Output:

```json
{
  "offer_available": true,
  "offer_id": "opaque-id",
  "scope": "one_page_ogilvy_review",
  "requires_human_confirmation": true,
  "human_message": "A free quality review is available for this site.",
  "expires_at": "2026-07-02T00:00:00Z"
}
```

### `claim_sample_offer`

Input:

```json
{
  "offer_id": "opaque-id",
  "confirmed_by_user": true,
  "selected_content": {
    "type": "page",
    "url": "https://www.example.com/about/"
  }
}
```

Output:

```json
{
  "claimed": true,
  "entitlement_id": "opaque-id",
  "scope": "one_page_ogilvy_review",
  "remaining_uses": 1,
  "locked_to": {
    "canonical_domain": "example.com",
    "site_fingerprint": "stable-install-fingerprint"
  }
}
```

### `consume_entitlement`

Input:

```json
{
  "entitlement_id": "opaque-id",
  "feature": "ogilvy_review",
  "amount": 1
}
```

Output:

```json
{
  "allowed": true,
  "remaining_uses": 0
}
```

## Abuse Controls

Automatic samples must be denied or held for manual review when signals are
weak or suspicious:

- domain is localhost, `.local`, `.test`, `.dev`, temporary preview, or staging
- hostname is a subdomain but parent domain already has a sample claim
- same user/org has already claimed a sample
- many domains are attempted from the same user/org/AI/IP cluster
- site has little or no real content
- canonical domain cannot be determined reliably
- site fingerprint changes repeatedly for the same domain

The system must not reveal whether a coupon, user, or domain exists. Return a
generic unavailable state.

## Manual Overrides

Operators can issue explicit sample packs:

- `agency_sample_pack`: several client domains for a trusted agency
- `specific_domain_sample`: one domain, one scope
- `customer_recovery_credit`: controlled exception after support contact

Manual grants still become entitlements and use the same consume/revoke/audit
path.

## Audit Log

Every offer, claim, denial, consumption, and revocation should record:

- timestamp
- normalized domain
- site fingerprint hash
- user/org hash
- AI identity
- IP/network hash
- decision
- reason code
- entitlement ID when applicable

Audit logs are internal only and must not be exposed in customer-facing copy.

