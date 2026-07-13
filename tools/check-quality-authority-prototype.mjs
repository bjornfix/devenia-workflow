#!/usr/bin/env node
import assert from "node:assert/strict";
import {
	QualityAuthorityContractError,
	artifactSurfaceRevision,
	issueBrowserRenderReceipt,
	issueQualityEvidenceReceipt,
	issueRunPrincipal,
	qualityAuthorityPolicy,
	recordMeasuredUsage,
	stageArtifact,
	submitReviewerAttestation,
	submitQualityDecision,
} from "./prototypes/quality-authority-contract.mjs";

let cases = 0;
const pass = (fn) => { fn(); cases += 1; };
const rejects = (code, fn) => {
	assert.throws(fn, (error) => error instanceof QualityAuthorityContractError && error.code === code);
	cases += 1;
};

const now = "2026-07-14T09:00:00.000Z";
const job = { job_id: "tj_surface_contract_nb", source_revision: "sr_1" };
const writer = issueRunPrincipal({
	job_id: job.job_id, run_id: "run_writer", role: "translator", claim_token_hash: "writer-token-hash",
	issued_at: now, expires_at: "2026-07-14T10:00:00.000Z",
});
const reviewer = issueRunPrincipal({
	job_id: job.job_id, run_id: "run_quality", role: "quality", claim_token_hash: "quality-token-hash",
	issued_at: now, expires_at: "2026-07-14T10:00:00.000Z",
});
pass(() => assert.notEqual(writer.principal_id, reviewer.principal_id));

const baseSurface = {
	source_revision: "sr_1",
	job_revision: "jr_1",
	content: { title: "Norsk tittel", excerpt: "Norsk utdrag", fragments: [{ key: "h1", html: "Norsk" }] },
	seo: { title: "Norsk SEO", description: "Norsk SEO-beskrivelse" },
	taxonomy: { categories: [12], tags: [31] },
	route: { canonical_path: "/nb/innstikk/", parent_id: 19 },
	media: { featured_image_id: 91, featured_image_digest: "media-sha", alt: "Beskrivende tekst" },
};
const existingPublication = { post_id: 24186, status: "publish", content_digest: "current-public-digest" };
const staged = stageArtifact({ job, writer_principal: writer, surface: baseSurface, existing_publication: existingPublication });
pass(() => assert.deepEqual(staged.publication, existingPublication, "artifact submission must not mutate the existing publication"));
pass(() => assert.equal(staged.artifact.state, "staged"));

for (const [member, replacement] of [
	["seo", { ...baseSurface.seo, title: "Endret SEO" }],
	["taxonomy", { ...baseSurface.taxonomy, categories: [99] }],
	["route", { ...baseSurface.route, canonical_path: "/nb/endret/" }],
	["media", { ...baseSurface.media, featured_image_id: 92 }],
]) {
	pass(() => assert.notEqual(
		artifactSurfaceRevision({ ...baseSurface, [member]: replacement }),
		staged.artifact.surface_revision,
		`${member} must participate in the complete Artifact Surface Revision`,
	));
}

rejects("zero_token_usage_not_measured", () => recordMeasuredUsage({
	principal: reviewer, provider_receipt_id: "provider-usage-receipt", input_tokens: 0,
	cached_input_tokens: 0, output_tokens: 0, estimated_cost_microusd: 0,
}));
pass(() => assert.equal(recordMeasuredUsage({
	principal: reviewer, provider_receipt_id: "provider-usage-receipt", input_tokens: 1200,
	cached_input_tokens: 700, output_tokens: 180, estimated_cost_microusd: 450,
}).state, "measured"));

const qualityReceipts = qualityAuthorityPolicy.required_quality_kinds.map((kind) => issueQualityEvidenceReceipt({
	kind,
	passed: true,
	artifact_revision: staged.artifact.artifact_revision,
	surface_revision: staged.artifact.surface_revision,
	principal: reviewer,
	adapter_revision: `adapter-${kind}-v1`,
	policy_revision: "quality-policy-v1",
	evidence_digest: `evidence-${kind}`,
	issued_at: now,
}));
const browserReceipts = Object.entries(qualityAuthorityPolicy.viewport_schemes).flatMap(([viewport_scheme, viewport]) =>
	qualityAuthorityPolicy.color_schemes.map((color_scheme) => issueBrowserRenderReceipt({
		artifact_revision: staged.artifact.artifact_revision,
		surface_revision: staged.artifact.surface_revision,
		principal: reviewer,
		url: "https://devenia.com/nb/innstikk/",
		response_digest: `${viewport_scheme}-${color_scheme}-response`,
		viewport_scheme,
		viewport,
		color_scheme,
		document_language: "nb-NO",
		document_direction: "ltr",
		layout_digest: `${viewport_scheme}-${color_scheme}-layout`,
		screenshot_digest: `${viewport_scheme}-${color_scheme}-screenshot`,
		adapter_revision: "browser-adapter-v1",
		policy_revision: "render-policy-v1",
		issued_at: now,
	})),
);
const reviewerAttestations = qualityAuthorityPolicy.required_reviewer_kinds.map((kind) => submitReviewerAttestation({
	kind,
	passed: true,
	artifact_revision: staged.artifact.artifact_revision,
	surface_revision: staged.artifact.surface_revision,
	principal: reviewer,
	observation: `${kind} was judged against the complete immutable source and localized surface.`,
	issued_at: now,
}));
const receipts = [...qualityReceipts, ...browserReceipts];
const receiptIds = receipts.map((receipt) => receipt.receipt_id);
pass(() => assert.ok(browserReceipts.every((receipt) => receipt.trust === "reviewer_attested")));

rejects("unexpected_field", () => submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: reviewer,
	decision: "pass",
	checks: { natural_language: true, factual_accuracy: true, rendered_experience: true },
	evidence: "Caller-written free text says everything passed.",
	receipt_ids: [],
	receipts: [],
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "Not sufficient without receipts.",
}));
rejects("writer_reviewer_principal_conflict", () => submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: { ...writer, role: "quality" },
	decision: "pass",
	receipt_ids: receiptIds,
	receipts,
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "Same principal must not review its own artifact.",
}));
rejects("browser_receipt_set_incomplete", () => submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: reviewer,
	decision: "pass",
	receipt_ids: qualityReceipts.map((receipt) => receipt.receipt_id),
	receipts: qualityReceipts,
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "No browser receipts.",
}));
rejects("quality_receipt_set_incomplete", () => submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: reviewer,
	decision: "pass",
	receipt_ids: browserReceipts.map((receipt) => receipt.receipt_id),
	receipts: browserReceipts,
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "Reviewer and browser attestations cannot replace server-owned receipts.",
}));
rejects("quality_receipt_binding_mismatch", () => submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: reviewer,
	decision: "pass",
	receipt_ids: receiptIds,
	receipts: receipts.map((receipt, index) => index === 0 ? { ...receipt, surface_revision: "asr_stale" } : receipt),
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "One receipt is stale.",
}));
rejects("browser_viewport_scheme_mismatch", () => issueBrowserRenderReceipt({
	artifact_revision: staged.artifact.artifact_revision,
	surface_revision: staged.artifact.surface_revision,
	principal: reviewer,
	url: "https://devenia.com/nb/innstikk/",
	response_digest: "response",
	viewport_scheme: "desktop",
	viewport: { width: 1024, height: 768, device_scale_factor: 1 },
	color_scheme: "light",
	document_language: "nb-NO",
	document_direction: "ltr",
	layout_digest: "layout",
	screenshot_digest: "screenshot",
	adapter_revision: "browser-adapter-v1",
	policy_revision: "render-policy-v1",
	issued_at: now,
}));
pass(() => assert.equal(submitQualityDecision({
	artifact: staged.artifact,
	quality_principal: reviewer,
	decision: "pass",
	receipt_ids: receiptIds,
	receipts,
	reviewer_attestations: reviewerAttestations,
	reviewer_observations: "Natural Norwegian and the complete surface were checked by bound receipts.",
}).decision, "pass"));

console.log(JSON.stringify({ success: true, cases, receipts: receipts.length }));
