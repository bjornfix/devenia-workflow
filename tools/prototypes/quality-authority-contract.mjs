import { createHash } from "node:crypto";

const REQUIRED_QUALITY_KINDS = new Set([
	"deterministic_structure",
	"source_coverage",
	"localized_route_links",
	"seo_taxonomy",
	"offer_contact",
	"http_live_dom",
]);
const REQUIRED_REVIEWER_KINDS = new Set(["natural_language", "factual_accuracy"]);
const VIEWPORT_SCHEMES = new Map([
	["desktop", { width: 1440, height: 1100, device_scale_factor: 1 }],
	["mobile", { width: 390, height: 844, device_scale_factor: 1 }],
]);
const COLOR_SCHEMES = new Set(["light", "dark"]);

export class QualityAuthorityContractError extends Error {
	constructor(code, message, details = {}) {
		super(message);
		this.name = "QualityAuthorityContractError";
		this.code = code;
		this.details = details;
	}
}

function fail(code, message, details = {}) {
	throw new QualityAuthorityContractError(code, message, details);
}

function object(value, path) {
	if (!value || typeof value !== "object" || Array.isArray(value)) {
		fail("invalid_object", `${path} must be an object.`);
	}
	return value;
}

function exactKeys(value, { path, required = [], optional = [] }) {
	object(value, path);
	const allowed = new Set([...required, ...optional]);
	for (const key of Object.keys(value)) {
		if (!allowed.has(key)) fail("unexpected_field", `${path}.${key} is not part of the Interface.`);
	}
	for (const key of required) {
		if (!(key in value)) fail("missing_field", `${path}.${key} is required.`);
	}
}

function text(value, path) {
	const normalized = String(value ?? "").trim();
	if (!normalized) fail("invalid_text", `${path} must not be empty.`);
	return normalized;
}

function canonical(value) {
	if (Array.isArray(value)) return value.map(canonical);
	if (value && typeof value === "object") {
		return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonical(value[key])]));
	}
	return value;
}

function digest(prefix, value) {
	return `${prefix}_${createHash("sha256").update(JSON.stringify(canonical(value))).digest("hex")}`;
}

function clone(value) {
	return structuredClone(value);
}

function assertPrincipal(principal, role) {
	object(principal, "principal");
	if (principal.issuer !== "workflow" || principal.role !== role || !principal.principal_id) {
		fail("invalid_run_principal", `A server-issued ${role} Translation Run Principal is required.`);
	}
}

export function issueRunPrincipal(input) {
	exactKeys(input, {
		path: "principal_input",
		required: ["job_id", "run_id", "role", "claim_token_hash", "issued_at", "expires_at"],
	});
	const role = text(input.role, "principal_input.role");
	if (!new Set(["translator", "quality"]).has(role)) fail("invalid_run_role", `Unsupported Run role: ${role}`);
	const body = {
		issuer: "workflow",
		job_id: text(input.job_id, "principal_input.job_id"),
		run_id: text(input.run_id, "principal_input.run_id"),
		role,
		claim_token_hash: text(input.claim_token_hash, "principal_input.claim_token_hash"),
		issued_at: text(input.issued_at, "principal_input.issued_at"),
		expires_at: text(input.expires_at, "principal_input.expires_at"),
	};
	return { ...body, principal_id: digest("rp", body) };
}

export function artifactSurfaceRevision(surface) {
	exactKeys(surface, {
		path: "artifact_surface",
		required: ["source_revision", "job_revision", "content", "seo", "taxonomy", "route", "media"],
	});
	for (const member of ["content", "seo", "taxonomy", "route", "media"]) object(surface[member], `artifact_surface.${member}`);
	return digest("asr", surface);
}

export function stageArtifact({ job, writer_principal, surface, existing_publication }) {
	assertPrincipal(writer_principal, "translator");
	if (writer_principal.job_id !== job.job_id) fail("principal_job_mismatch", "Writer principal belongs to another Job.");
	const surfaceRevision = artifactSurfaceRevision(surface);
	const artifactBody = {
		job_id: job.job_id,
		writer_principal_id: writer_principal.principal_id,
		surface_revision: surfaceRevision,
		surface: clone(surface),
		state: "staged",
	};
	return {
		artifact: { ...artifactBody, artifact_revision: digest("a", artifactBody) },
		publication: clone(existing_publication),
	};
}

export function issueQualityEvidenceReceipt(input) {
	exactKeys(input, {
		path: "quality_receipt_input",
		required: ["kind", "passed", "artifact_revision", "surface_revision", "principal", "adapter_revision", "policy_revision", "evidence_digest", "issued_at"],
	});
	assertPrincipal(input.principal, "quality");
	if (typeof input.passed !== "boolean") fail("invalid_receipt_result", "Receipt result must be boolean.");
	const body = {
		issuer: "workflow",
		kind: text(input.kind, "quality_receipt_input.kind"),
		passed: input.passed,
		artifact_revision: text(input.artifact_revision, "quality_receipt_input.artifact_revision"),
		surface_revision: text(input.surface_revision, "quality_receipt_input.surface_revision"),
		principal_id: input.principal.principal_id,
		adapter_revision: text(input.adapter_revision, "quality_receipt_input.adapter_revision"),
		policy_revision: text(input.policy_revision, "quality_receipt_input.policy_revision"),
		evidence_digest: text(input.evidence_digest, "quality_receipt_input.evidence_digest"),
		issued_at: text(input.issued_at, "quality_receipt_input.issued_at"),
	};
	return { ...body, receipt_id: digest("qer", body) };
}

export function submitReviewerAttestation(input) {
	exactKeys(input, {
		path: "reviewer_attestation_input",
		required: ["kind", "passed", "artifact_revision", "surface_revision", "principal", "observation", "issued_at"],
	});
	assertPrincipal(input.principal, "quality");
	if (!REQUIRED_REVIEWER_KINDS.has(input.kind)) fail("invalid_reviewer_attestation_kind", "Unsupported semantic reviewer attestation.");
	if (typeof input.passed !== "boolean") fail("invalid_reviewer_attestation_result", "Reviewer attestation result must be boolean.");
	const body = {
		trust: "reviewer_attested",
		kind: input.kind,
		passed: input.passed,
		artifact_revision: text(input.artifact_revision, "reviewer_attestation_input.artifact_revision"),
		surface_revision: text(input.surface_revision, "reviewer_attestation_input.surface_revision"),
		principal_id: input.principal.principal_id,
		observation: text(input.observation, "reviewer_attestation_input.observation"),
		issued_at: text(input.issued_at, "reviewer_attestation_input.issued_at"),
	};
	return { ...body, attestation_id: digest("ra", body) };
}

export function issueBrowserRenderReceipt(input) {
	exactKeys(input, {
		path: "browser_receipt_input",
		required: [
			"artifact_revision", "surface_revision", "principal", "url", "response_digest", "viewport_scheme",
			"viewport", "color_scheme", "document_language", "document_direction", "layout_digest",
			"screenshot_digest", "adapter_revision", "policy_revision", "issued_at",
		],
	});
	assertPrincipal(input.principal, "quality");
	const expectedViewport = VIEWPORT_SCHEMES.get(input.viewport_scheme);
	if (!expectedViewport || JSON.stringify(input.viewport) !== JSON.stringify(expectedViewport)) {
		fail("browser_viewport_scheme_mismatch", "Browser receipt must use a policy-owned viewport scheme and dimensions.");
	}
	if (!COLOR_SCHEMES.has(input.color_scheme)) fail("browser_color_scheme_invalid", "Browser receipt color scheme is not policy-owned.");
	const body = {
		issuer: "quality-run-browser",
		trust: "reviewer_attested",
		kind: "browser_render",
		passed: true,
		artifact_revision: text(input.artifact_revision, "browser_receipt_input.artifact_revision"),
		surface_revision: text(input.surface_revision, "browser_receipt_input.surface_revision"),
		principal_id: input.principal.principal_id,
		url: text(input.url, "browser_receipt_input.url"),
		response_digest: text(input.response_digest, "browser_receipt_input.response_digest"),
		viewport_scheme: input.viewport_scheme,
		viewport: clone(input.viewport),
		color_scheme: input.color_scheme,
		document_language: text(input.document_language, "browser_receipt_input.document_language"),
		document_direction: text(input.document_direction, "browser_receipt_input.document_direction"),
		layout_digest: text(input.layout_digest, "browser_receipt_input.layout_digest"),
		screenshot_digest: text(input.screenshot_digest, "browser_receipt_input.screenshot_digest"),
		adapter_revision: text(input.adapter_revision, "browser_receipt_input.adapter_revision"),
		policy_revision: text(input.policy_revision, "browser_receipt_input.policy_revision"),
		issued_at: text(input.issued_at, "browser_receipt_input.issued_at"),
	};
	return { ...body, receipt_id: digest("brr", body) };
}

export function recordMeasuredUsage({ principal, provider_receipt_id, input_tokens, cached_input_tokens, output_tokens, estimated_cost_microusd }) {
	object(principal, "principal");
	text(provider_receipt_id, "provider_receipt_id");
	for (const [key, value] of Object.entries({ input_tokens, cached_input_tokens, output_tokens, estimated_cost_microusd })) {
		if (!Number.isSafeInteger(value) || value < 0) fail("invalid_measured_usage", `${key} must be a non-negative integer.`);
	}
	if (input_tokens + output_tokens === 0) fail("zero_token_usage_not_measured", "All-zero caller usage cannot be recorded as measured usage.");
	return {
		state: "measured",
		principal_id: principal.principal_id,
		provider_receipt_id,
		input_tokens,
		cached_input_tokens,
		output_tokens,
		estimated_cost_microusd,
	};
}

export function submitQualityDecision(input) {
	exactKeys(input, {
		path: "quality_decision_input",
		required: ["artifact", "quality_principal", "decision", "receipt_ids", "receipts", "reviewer_attestations", "reviewer_observations"],
		optional: ["corrections"],
	});
	assertPrincipal(input.quality_principal, "quality");
	if (input.quality_principal.job_id !== input.artifact.job_id) fail("principal_job_mismatch", "Quality principal belongs to another Job.");
	if (input.quality_principal.principal_id === input.artifact.writer_principal_id) {
		fail("writer_reviewer_principal_conflict", "Writer and Quality Run principals must differ.");
	}
	if (!new Set(["pass", "revise", "reject"]).has(input.decision)) fail("invalid_quality_decision", "Unknown Quality Decision.");
	if (!Array.isArray(input.receipt_ids) || !Array.isArray(input.receipts)) fail("invalid_quality_receipts", "Receipt identities and records are required.");
	const byId = new Map(input.receipts.map((receipt) => [receipt.receipt_id, receipt]));
	const selected = input.receipt_ids.map((id) => byId.get(id));
	if (selected.some((receipt) => !receipt)) fail("quality_receipt_missing", "A required Quality Evidence Receipt is unavailable.");
	for (const receipt of selected) {
		if (
			receipt.artifact_revision !== input.artifact.artifact_revision
			|| receipt.surface_revision !== input.artifact.surface_revision
			|| receipt.principal_id !== input.quality_principal.principal_id
		) fail("quality_receipt_binding_mismatch", "Quality Evidence Receipt is bound to another artifact, surface, or principal.");
	}
	if (!Array.isArray(input.reviewer_attestations)) fail("invalid_reviewer_attestations", "Reviewer Attestations are required.");
	for (const attestation of input.reviewer_attestations) {
		if (
			attestation.trust !== "reviewer_attested"
			|| attestation.artifact_revision !== input.artifact.artifact_revision
			|| attestation.surface_revision !== input.artifact.surface_revision
			|| attestation.principal_id !== input.quality_principal.principal_id
		) fail("reviewer_attestation_binding_mismatch", "Reviewer Attestation is bound to another artifact, surface, or principal.");
	}
	if (input.decision === "pass") {
		const qualityKinds = new Set(selected.filter((receipt) => receipt.issuer === "workflow").map((receipt) => receipt.kind));
		for (const kind of REQUIRED_QUALITY_KINDS) {
			if (!qualityKinds.has(kind)) fail("quality_receipt_set_incomplete", `Passing Quality requires receipt kind ${kind}.`);
		}
		if (selected.some((receipt) => receipt.passed !== true)) fail("quality_receipt_failed", "Passing Quality requires passing receipts.");
		for (const kind of REQUIRED_REVIEWER_KINDS) {
			if (!input.reviewer_attestations.some((attestation) => attestation.kind === kind && attestation.passed === true)) {
				fail("reviewer_attestation_set_incomplete", `Passing Quality requires reviewer attestation ${kind}.`);
			}
		}
		for (const viewportScheme of VIEWPORT_SCHEMES.keys()) {
			for (const colorScheme of COLOR_SCHEMES) {
				if (!selected.some((receipt) => receipt.kind === "browser_render" && receipt.viewport_scheme === viewportScheme && receipt.color_scheme === colorScheme)) {
					fail("browser_receipt_set_incomplete", `Missing ${viewportScheme}/${colorScheme} Browser Render Receipt.`);
				}
			}
		}
	}
	return {
		decision: input.decision,
		artifact_revision: input.artifact.artifact_revision,
		surface_revision: input.artifact.surface_revision,
		writer_principal_id: input.artifact.writer_principal_id,
		quality_principal_id: input.quality_principal.principal_id,
		receipt_ids: [...input.receipt_ids],
		reviewer_attestation_ids: input.reviewer_attestations.map((attestation) => attestation.attestation_id),
		reviewer_observations: String(input.reviewer_observations ?? ""),
		corrections: clone(input.corrections ?? []),
	};
}

export const qualityAuthorityPolicy = Object.freeze({
	required_quality_kinds: [...REQUIRED_QUALITY_KINDS],
	required_reviewer_kinds: [...REQUIRED_REVIEWER_KINDS],
	viewport_schemes: Object.fromEntries(VIEWPORT_SCHEMES),
	color_schemes: [...COLOR_SCHEMES],
});
