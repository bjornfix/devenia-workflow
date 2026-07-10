import { createHash } from "node:crypto";

const RUN_ROLES = new Set(["translator", "quality"]);
const RUN_FAILURES = new Set(["failed_technical", "budget_exceeded", "superseded", "cancelled"]);
const QUALITY_DECISIONS = new Set(["pass", "revise", "reject"]);
const QUALITY_CHECKS = [
	"source_quality",
	"natural_language",
	"factual_accuracy",
	"source_coverage",
	"localized_search_intent",
	"offer_and_contact",
	"links_and_route",
	"rendered_experience",
];

export class TranslationJobContractError extends Error {
	constructor(code, message, details = {}) {
		super(message);
		this.name = "TranslationJobContractError";
		this.code = code;
		this.details = details;
	}
}

function fail(code, message, details = {}) {
	throw new TranslationJobContractError(code, message, details);
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

function text(value, path, { min = 1, max = 100000 } = {}) {
	const normalized = String(value ?? "").trim();
	if (normalized.length < min || normalized.length > max) {
		fail("invalid_text", `${path} must contain ${min}-${max} characters.`);
	}
	return normalized;
}

function integer(value, path, { min = 0, max = Number.MAX_SAFE_INTEGER } = {}) {
	if (!Number.isSafeInteger(value) || value < min || value > max) {
		fail("invalid_integer", `${path} must be an integer from ${min} to ${max}.`);
	}
	return value;
}

function isoDate(value, path) {
	const normalized = text(value, path, { max: 40 });
	if (!Number.isFinite(Date.parse(normalized))) fail("invalid_date", `${path} must be an ISO date.`);
	return new Date(normalized).toISOString();
}

function language(value, path) {
	const normalized = text(value, path, { max: 12 }).toLowerCase();
	if (!/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/.test(normalized)) {
		fail("invalid_language", `${path} must be a language code.`);
	}
	return normalized;
}

function canonical(value) {
	if (Array.isArray(value)) return value.map(canonical);
	if (value && typeof value === "object") {
		return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonical(value[key])]));
	}
	return value;
}

function revision(value) {
	return `a_${createHash("sha256").update(JSON.stringify(canonical(value))).digest("hex").slice(0, 32)}`;
}

export function createTokenBudget(role, overrides = {}) {
	if (!RUN_ROLES.has(role)) fail("invalid_run_role", `Unsupported Run role: ${role}`);
	exactKeys(overrides, {
		path: "budget_overrides",
		optional: ["input_token_limit", "output_token_limit", "total_token_limit", "max_attempts", "max_estimated_cost_microusd"],
	});
	const defaults = role === "translator"
		? { input_token_limit: 30000, output_token_limit: 30000, total_token_limit: 60000, max_attempts: 2 }
		: { input_token_limit: 20000, output_token_limit: 10000, total_token_limit: 30000, max_attempts: 2 };
	const budget = { ...defaults, max_estimated_cost_microusd: 0, ...overrides };
	for (const key of ["input_token_limit", "output_token_limit", "total_token_limit", "max_attempts", "max_estimated_cost_microusd"]) {
		integer(budget[key], `budget.${key}`, { min: key === "max_estimated_cost_microusd" ? 0 : 1 });
	}
	if (budget.input_token_limit + budget.output_token_limit < budget.total_token_limit) {
		fail("invalid_token_budget", "The total token limit cannot exceed the input and output limits combined.");
	}
	return { role, ...budget };
}

export function createTranslationJob(input) {
	exactKeys(input, {
		path: "job_input",
		required: ["job_id", "source_id", "source_revision", "target_language", "created_at"],
		optional: ["observability_label"],
	});
	return {
		job_id: text(input.job_id, "job_input.job_id", { max: 100 }),
		source_id: integer(input.source_id, "job_input.source_id", { min: 1 }),
		source_revision: text(input.source_revision, "job_input.source_revision", { max: 200 }),
		target_language: language(input.target_language, "job_input.target_language"),
		created_at: isoDate(input.created_at, "job_input.created_at"),
		observability_label: String(input.observability_label || "").trim(),
		status: "queued",
	};
}

export function claimTranslationJob(job, claim) {
	exactKeys(claim, {
		path: "claim",
		required: ["run_id", "claimed_at", "expires_at"],
	});
	if (job.status !== "queued") fail("job_not_claimable", `Job ${job.job_id} is ${job.status}, not queued.`);
	const claimedAt = isoDate(claim.claimed_at, "claim.claimed_at");
	const expiresAt = isoDate(claim.expires_at, "claim.expires_at");
	if (Date.parse(expiresAt) <= Date.parse(claimedAt)) fail("invalid_claim_expiry", "Claim expiry must follow claim time.");
	return {
		...job,
		status: "claimed",
		claimed_by_run_id: text(claim.run_id, "claim.run_id", { max: 100 }),
		claimed_at: claimedAt,
		expires_at: expiresAt,
	};
}

export function createTranslationRun(input) {
	exactKeys(input, {
		path: "run_input",
		required: ["run_id", "job", "role", "coordinator_id", "context_mode", "budget", "started_at"],
		optional: ["observability_label"],
	});
	const role = text(input.role, "run_input.role", { max: 20 });
	if (!RUN_ROLES.has(role)) fail("invalid_run_role", `Unsupported Run role: ${role}`);
	if (input.context_mode !== "bounded_packet") {
		fail("unbounded_context", "Translation Runs must use context_mode=bounded_packet.");
	}
	const runId = text(input.run_id, "run_input.run_id", { max: 100 });
	if (role === "translator") {
		if (input.job.status !== "claimed" || input.job.claimed_by_run_id !== runId) {
			fail("run_does_not_own_job", "Translator Run must own the current Job claim.");
		}
	} else if (input.job.status !== "quality_pending") {
		fail("job_not_ready_for_quality", "Quality Run requires a quality_pending Job.");
	}
	if (input.budget.role !== role) fail("budget_role_mismatch", "Token Budget role must match the Run role.");
	return {
		run_id: runId,
		job_id: input.job.job_id,
		role,
		coordinator_id: text(input.coordinator_id, "run_input.coordinator_id", { max: 100 }),
		context_mode: "bounded_packet",
		observability_label: String(input.observability_label || "").trim(),
		budget: input.budget,
		started_at: isoDate(input.started_at, "run_input.started_at"),
		status: "running",
	};
}

export function buildTranslationPacket(input) {
	exactKeys(input, {
		path: "packet_input",
		required: ["job", "run", "source", "fragments", "route", "taxonomy", "language_profile", "links", "validation_contract", "examples", "estimated_input_tokens"],
	});
	if (input.run.role !== "translator" || input.run.status !== "running") {
		fail("invalid_packet_run", "Only a running translator Run may receive a translation packet.");
	}
	exactKeys(input.source, {
		path: "packet_input.source",
		required: ["title", "excerpt", "seo_title", "seo_description", "reader", "offer", "proof", "next_action"],
	});
	for (const key of ["title", "excerpt", "seo_title", "seo_description", "reader", "offer", "proof", "next_action"]) {
		text(input.source[key], `packet_input.source.${key}`);
	}
	if (!Array.isArray(input.fragments) || input.fragments.length === 0 || input.fragments.length > 500) {
		fail("invalid_fragments", "Translation packet must contain 1-500 source fragments.");
	}
	const fragmentKeys = new Set();
	const fragments = input.fragments.map((fragment, index) => {
		exactKeys(fragment, { path: `packet_input.fragments[${index}]`, required: ["key", "source_html"] });
		const key = text(fragment.key, `packet_input.fragments[${index}].key`, { max: 300 });
		if (fragmentKeys.has(key)) fail("duplicate_fragment_key", `Duplicate source fragment key: ${key}`);
		fragmentKeys.add(key);
		return { key, source_html: text(fragment.source_html, `packet_input.fragments[${index}].source_html`) };
	});
	if (!Array.isArray(input.examples) || input.examples.length > 3) {
		fail("too_many_examples", "Translation packet may contain at most three approved examples.");
	}
	const estimatedInputTokens = integer(input.estimated_input_tokens, "packet_input.estimated_input_tokens", { min: 1 });
	if (estimatedInputTokens > input.run.budget.input_token_limit) {
		fail("packet_over_budget", "Estimated packet input exceeds the Run input Token Budget.", {
			estimated_input_tokens: estimatedInputTokens,
			input_token_limit: input.run.budget.input_token_limit,
		});
	}
	return {
		contract_version: 1,
		job: {
			job_id: input.job.job_id,
			source_id: input.job.source_id,
			source_revision: input.job.source_revision,
			target_language: input.job.target_language,
		},
		source: input.source,
		fragments,
		route: object(input.route, "packet_input.route"),
		taxonomy: object(input.taxonomy, "packet_input.taxonomy"),
		language_profile: object(input.language_profile, "packet_input.language_profile"),
		links: Array.isArray(input.links) ? input.links : fail("invalid_links", "packet_input.links must be an array."),
		validation_contract: object(input.validation_contract, "packet_input.validation_contract"),
		examples: input.examples,
		estimated_input_tokens: estimatedInputTokens,
	};
}

export function evaluateTokenUsage(run, usage, attempts) {
	exactKeys(usage, {
		path: "usage",
		required: ["input_tokens", "cached_input_tokens", "output_tokens", "estimated_cost_microusd"],
	});
	const normalized = {
		input_tokens: integer(usage.input_tokens, "usage.input_tokens"),
		cached_input_tokens: integer(usage.cached_input_tokens, "usage.cached_input_tokens"),
		output_tokens: integer(usage.output_tokens, "usage.output_tokens"),
		estimated_cost_microusd: integer(usage.estimated_cost_microusd, "usage.estimated_cost_microusd"),
	};
	if (normalized.cached_input_tokens > normalized.input_tokens) {
		fail("invalid_cached_usage", "Cached input tokens cannot exceed total input tokens.");
	}
	const attemptCount = integer(attempts, "attempts", { min: 1 });
	const totalTokens = normalized.input_tokens + normalized.output_tokens;
	const violations = [];
	if (normalized.input_tokens > run.budget.input_token_limit) violations.push("input_token_limit");
	if (normalized.output_tokens > run.budget.output_token_limit) violations.push("output_token_limit");
	if (totalTokens > run.budget.total_token_limit) violations.push("total_token_limit");
	if (attemptCount > run.budget.max_attempts) violations.push("max_attempts");
	if (run.budget.max_estimated_cost_microusd > 0 && normalized.estimated_cost_microusd > run.budget.max_estimated_cost_microusd) {
		violations.push("max_estimated_cost_microusd");
	}
	return { ...normalized, total_tokens: totalTokens, attempts: attemptCount, within_budget: violations.length === 0, violations };
}

function completeRun(run, usage, attempts, finishedAt, outcome) {
	const measured = evaluateTokenUsage(run, usage, attempts);
	if (!measured.within_budget && outcome !== "budget_exceeded") {
		fail("run_over_budget", "Run exceeded its Token Budget.", measured);
	}
	return { ...run, status: "terminal", outcome, finished_at: isoDate(finishedAt, "finished_at"), usage: measured };
}

export function submitTranslation(input) {
	exactKeys(input, {
		path: "translation_submission",
		required: ["job", "run", "packet", "localized_fragments", "metadata", "usage", "attempts", "finished_at"],
	});
	if (input.run.role !== "translator" || input.run.status !== "running") fail("invalid_translator_run", "Submission requires a running translator Run.");
	if (input.packet.job.job_id !== input.job.job_id) fail("packet_job_mismatch", "Packet belongs to another Job.");
	if (!Array.isArray(input.localized_fragments)) fail("invalid_localized_fragments", "localized_fragments must be an array.");
	const expected = new Set(input.packet.fragments.map((fragment) => fragment.key));
	const actual = new Set();
	const localizedFragments = input.localized_fragments.map((fragment, index) => {
		exactKeys(fragment, { path: `localized_fragments[${index}]`, required: ["key", "html"] });
		const key = text(fragment.key, `localized_fragments[${index}].key`, { max: 300 });
		if (actual.has(key)) fail("duplicate_localized_fragment", `Duplicate localized fragment key: ${key}`);
		if (!expected.has(key)) fail("unexpected_localized_fragment", `Unknown localized fragment key: ${key}`);
		actual.add(key);
		return { key, html: text(fragment.html, `localized_fragments[${index}].html`) };
	});
	const missing = [...expected].filter((key) => !actual.has(key));
	if (missing.length > 0) fail("incomplete_translation", "Every source fragment must be localized exactly once.", { missing });
	exactKeys(input.metadata, {
		path: "translation_submission.metadata",
		required: ["title", "excerpt", "slug", "localized_path", "seo_title", "seo_description", "contact_action"],
	});
	for (const key of ["title", "excerpt", "slug", "localized_path", "seo_title", "seo_description", "contact_action"]) {
		text(input.metadata[key], `translation_submission.metadata.${key}`);
	}
	const completedRun = completeRun(input.run, input.usage, input.attempts, input.finished_at, "submitted");
	const artifactBody = {
		job_id: input.job.job_id,
		source_revision: input.job.source_revision,
		target_language: input.job.target_language,
		localized_fragments: localizedFragments,
		metadata: input.metadata,
	};
	const artifact = { ...artifactBody, artifact_revision: revision(artifactBody), translator_run_id: input.run.run_id };
	const job = { ...input.job, status: "quality_pending", artifact_revision: artifact.artifact_revision };
	return { job, run: completedRun, artifact };
}

export function recordRunFailure(input) {
	exactKeys(input, {
		path: "run_failure",
		required: ["job", "run", "outcome", "code", "message", "usage", "attempts", "finished_at"],
	});
	if (!RUN_FAILURES.has(input.outcome)) fail("invalid_run_outcome", `Unsupported failure outcome: ${input.outcome}`);
	const run = completeRun(input.run, input.usage, input.attempts, input.finished_at, input.outcome);
	return {
		job: { ...input.job, status: input.outcome },
		run: { ...run, failure: { code: text(input.code, "run_failure.code", { max: 100 }), message: text(input.message, "run_failure.message") } },
	};
}

function qualityChecks(value) {
	exactKeys(value, { path: "quality_checks", required: QUALITY_CHECKS });
	return Object.fromEntries(QUALITY_CHECKS.map((key) => {
		exactKeys(value[key], { path: `quality_checks.${key}`, required: ["passed", "evidence"] });
		if (typeof value[key].passed !== "boolean") fail("invalid_quality_check", `${key}.passed must be boolean.`);
		return [key, { passed: value[key].passed, evidence: text(value[key].evidence, `quality_checks.${key}.evidence`, { min: 5 }) }];
	}));
}

export function submitQualityDecision(input) {
	exactKeys(input, {
		path: "quality_submission",
		required: ["job", "run", "artifact", "artifact_revision", "decision", "checks", "edits", "usage", "attempts", "finished_at"],
	});
	if (input.run.role !== "quality" || input.run.status !== "running") fail("invalid_quality_run", "Quality Decision requires a running quality Run.");
	if (input.job.status !== "quality_pending") fail("job_not_ready_for_quality", "Job is not quality_pending.");
	if (input.artifact.artifact_revision !== input.artifact_revision || input.job.artifact_revision !== input.artifact_revision) {
		fail("artifact_revision_mismatch", "Quality Decision must bind to the current artifact revision.");
	}
	if (!QUALITY_DECISIONS.has(input.decision)) fail("invalid_quality_decision", `Unsupported Quality Decision: ${input.decision}`);
	const checks = qualityChecks(input.checks);
	if (!Array.isArray(input.edits)) fail("invalid_quality_edits", "quality_submission.edits must be an array.");
	const allPassed = Object.values(checks).every((check) => check.passed);
	if (input.decision === "pass" && !allPassed) fail("quality_pass_with_failed_checks", "A passing Quality Decision requires every check to pass.");
	const run = completeRun(input.run, input.usage, input.attempts, input.finished_at, input.decision === "pass" ? "quality_passed" : `quality_${input.decision}`);
	const qualityDecision = {
		decision: input.decision,
		artifact_revision: input.artifact_revision,
		quality_run_id: input.run.run_id,
		coordinator_id: input.run.coordinator_id,
		checks,
		edits: input.edits,
		decided_at: run.finished_at,
	};
	return {
		job: { ...input.job, status: input.decision === "pass" ? "ready_to_publish" : input.decision === "revise" ? "changes_requested" : "rejected" },
		run,
		quality_decision: qualityDecision,
	};
}

export function assertPublishable(job, artifact, qualityDecision) {
	if (job.status !== "ready_to_publish") fail("job_not_publishable", `Job is ${job.status}, not ready_to_publish.`);
	if (qualityDecision.decision !== "pass") fail("quality_not_passed", "Quality Decision must pass before publishing.");
	if (job.artifact_revision !== artifact.artifact_revision || qualityDecision.artifact_revision !== artifact.artifact_revision) {
		fail("artifact_revision_mismatch", "Publish evidence does not match the current artifact revision.");
	}
	return true;
}
