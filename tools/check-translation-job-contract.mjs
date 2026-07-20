#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
	TranslationJobContractError,
	assertPublishable,
	buildTranslationPacket,
	claimTranslationJob,
	createTokenBudget,
	createTranslationJob,
	createTranslationRun,
	evaluateTokenUsage,
	recordRunFailure,
	submitQualityDecision,
	submitTranslation,
} from "./prototypes/translation-job-contract.mjs";

let cases = 0;

function pass(fn) {
	fn();
	cases += 1;
}

function rejects(code, fn) {
	assert.throws(fn, (error) => error instanceof TranslationJobContractError && error.code === code);
	cases += 1;
}

const now = "2026-07-10T12:00:00.000Z";
const writerBudget = createTokenBudget("translator");
const qualityBudget = createTokenBudget("quality");
const runtimeSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");

pass(() => assert.match(
	runtimeSource,
	/'job_id'\s*=>\s*\(string\) \$job\['job_id'\][\s\S]*'source_revision'\s*=>\s*\(string\) \$job\['source_revision'\][\s\S]*'target_language'\s*=>\s*\(string\) \$job\['target_language'\][\s\S]*'artifact'\s*=>\s*\$artifact/,
));
pass(() => assert.match(runtimeSource, /'artifact_store_failed'/));
pass(() => assert.match(runtimeSource, /update_option\( \$artifact_key, \$artifact_record, false \)/));
pass(() => assert.match(runtimeSource, /\$stored\['source_revision'\][\s\S]*\$job\['source_revision'\]/));
pass(() => assert.match(runtimeSource, /translation_job_pack_artifact_record\( \$artifact_record \)/));
pass(() => assert.match(runtimeSource, /'artifact_encoding'\]\s*=\s*'base64-json-v1'/));
pass(() => assert.match(runtimeSource, /'surface_manifest_encoding'\]\s*=\s*'base64-json-v1'/));
pass(() => assert.match(runtimeSource, /unset\( \$record\['surface_manifest'\] \)/));
pass(() => assert.match(runtimeSource, /base64_decode\([^;]+true \)/));
pass(() => assert.match(runtimeSource, /\$configured_budget = self::translation_job_budget[\s\S]*\$run_before_migration = \$run[\s\S]*'budget_migrated_at'[\s\S]*atomic_replace_option_value\( \$run_key, \$run_before_migration, \$run \)[\s\S]*'run_budget_migration_conflict'/));
pass(() => assert.match(runtimeSource, /private static function translation_job_subagent_separation_contract\s*\(/));
pass(() => assert.match(runtimeSource, /Spawn one translator subagent[\s\S]*a different Quality subagent/));
pass(() => assert.match(runtimeSource, /'same_subagent_forbidden'\s*=>\s*true[\s\S]*'role_reuse_forbidden'\s*=>\s*true/));
pass(() => assert.match(runtimeSource, /'distinct_run_principal'\s*=>\s*true[\s\S]*'quality_decision_binds_artifact_revision'\s*=>\s*true/));
pass(() => assert.equal((runtimeSource.match(/'subagent_separation_contract'\s*=>\s*self::translation_job_subagent_separation_contract\(\)/g) || []).length, 2));
pass(() => assert.match(runtimeSource, /private static function translation_job_bounded_artifact_view\s*\(/));
pass(() => assert.match(runtimeSource, /TRANSLATION_JOB_CORRECTABLE_PUBLISH_PREFLIGHT_CODES[\s\S]*localized_slug_copied_from_source/));
pass(() => assert.match(runtimeSource, /translation_job_reopen_correctable_publish_preflight\( \$job, \$failure \)/));
pass(() => assert.match(runtimeSource, /private static function translation_job_reopen_correctable_publish_preflight[\s\S]*'status'\s*=>\s*'changes_requested'[\s\S]*'quality_revision'\s*=>\s*''[\s\S]*publish_preflight_correction/));
pass(() => assert.match(runtimeSource, /'artifact'\s*=>\s*is_array\( \$artifact \) \? self::translation_job_bounded_artifact_view\( \$artifact \) : array\(\)/));
pass(() => assert.match(runtimeSource, /'contract_version'\s*=>\s*5[\s\S]*translation_job_bounded_artifact_view/));
pass(() => assert.match(runtimeSource, /'previous_artifact'\s*=>\s*self::translation_job_bounded_artifact_view\( \$artifact \)/));
pass(() => assert.match(runtimeSource, /'staged_surface'[\s\S]*'source_design_hash'/));
pass(() => assert.doesNotMatch(
	runtimeSource.match(/private static function translation_job_bounded_artifact_view[\s\S]*?\n\t}/)?.[0] || "",
	/'gutenberg'\s*=>|'localized_fragments'\s*=>\s*\(array\) \( \$manifest\['presentation'/,
));

pass(() => assert.deepEqual(
	{ total: writerBudget.total_token_limit, attempts: writerBudget.max_attempts },
	{ total: 70000, attempts: 2 },
));
pass(() => assert.deepEqual(
	{ input: qualityBudget.input_token_limit, output: qualityBudget.output_token_limit, total: qualityBudget.total_token_limit },
	{ input: 40000, output: 10000, total: 50000 },
));
rejects("invalid_run_role", () => createTokenBudget("reviewer"));
rejects("unexpected_field", () => createTokenBudget("translator", { session_lease: true }));

const baseJob = createTranslationJob({
	job_id: "tj_source-4122_it_r1",
	source_id: 4122,
	source_revision: "r_source_4122",
	target_language: "it",
	created_at: now,
	observability_label: "Italian localization",
});
pass(() => assert.equal(baseJob.status, "queued"));

const claimedJob = claimTranslationJob(baseJob, {
	run_id: "tr_it_writer_1",
	claimed_at: now,
	expires_at: "2026-07-10T12:30:00.000Z",
});
pass(() => assert.equal(claimedJob.claimed_by_run_id, "tr_it_writer_1"));
rejects("job_not_claimable", () => claimTranslationJob(claimedJob, {
	run_id: "tr_other",
	claimed_at: now,
	expires_at: "2026-07-10T12:30:00.000Z",
}));

const writerRun = createTranslationRun({
	run_id: "tr_it_writer_1",
	job: claimedJob,
	role: "translator",
	coordinator_id: "coordinator-main",
	context_mode: "bounded_packet",
	budget: writerBudget,
	started_at: now,
	observability_label: "Italian translator subagent",
});
pass(() => assert.equal(writerRun.coordinator_id, "coordinator-main"));
rejects("unbounded_context", () => createTranslationRun({
	run_id: "tr_it_writer_1",
	job: claimedJob,
	role: "translator",
	coordinator_id: "coordinator-main",
	context_mode: "resumed_conversation",
	budget: writerBudget,
	started_at: now,
}));
rejects("unexpected_field", () => createTranslationRun({
	run_id: "tr_it_writer_1",
	job: claimedJob,
	role: "translator",
	coordinator_id: "coordinator-main",
	context_mode: "bounded_packet",
	budget: writerBudget,
	started_at: now,
	actor_lease: "ola",
}));

const fragments = Array.from({ length: 105 }, (_, index) => ({
	key: `fragment-${String(index + 1).padStart(3, "0")}`,
	source_html: index === 14
		? "<strong>1. Content</strong><br>Is the page useful for the person searching?"
		: `<p>Useful source paragraph ${index + 1} with a concrete reader outcome.</p>`,
}));

const packetInput = {
	job: claimedJob,
	run: writerRun,
	source: {
		title: "SEO factors",
		excerpt: "A practical explanation of the factors that affect search visibility.",
		seo_title: "SEO factors and search visibility",
		seo_description: "Understand the SEO factors that matter and what to improve next.",
		reader: "A business owner deciding what SEO work to prioritize.",
		offer: "Devenia can audit and improve the reader's website.",
		proof: "Devenia has delivered SEO work since 2007.",
		next_action: "Contact Devenia with the website and current problem.",
	},
	fragments,
	route: { prefix: "it", path_template: "impara/glossario/{slug}" },
	taxonomy: { categories: ["SEO"] },
	language_profile: { locale: "it_IT", tone: "natural professional Italian" },
	links: [{ source: "/services/seo/", localized: "/it/servizi/seo/" }],
	validation_contract: { complete_fragments: true, preserve_contact_action: true },
	examples: [{ source: "Contact us", target: "Contattaci" }],
	estimated_input_tokens: 24000,
};

const packet = buildTranslationPacket(packetInput);
pass(() => assert.equal(packet.fragments.length, 105));
rejects("packet_over_budget", () => buildTranslationPacket({ ...packetInput, estimated_input_tokens: 40001 }));
rejects("duplicate_fragment_key", () => buildTranslationPacket({ ...packetInput, fragments: [fragments[0], fragments[0]] }));
rejects("too_many_examples", () => buildTranslationPacket({ ...packetInput, examples: [{}, {}, {}, {}] }));
rejects("unexpected_field", () => buildTranslationPacket({ ...packetInput, conversation_history: ["old worker history"] }));

const localizedFragments = fragments.map((fragment, index) => ({
	key: fragment.key,
	html: index === 14
		? "<strong>1. Contenuto</strong><br>La pagina e utile per chi effettua la ricerca?"
		: `<p>Paragrafo italiano utile ${index + 1} con un risultato concreto per il lettore.</p>`,
}));
const metadata = {
	title: "Fattori SEO | Cosa influenza la visibilita nella ricerca",
	excerpt: "Una guida pratica ai fattori SEO che contano davvero.",
	slug: "fattori-seo",
	localized_path: "it/impara/glossario/fattori-seo",
	seo_title: "Fattori SEO e visibilita nella ricerca",
	seo_description: "Scopri quali fattori SEO migliorare e quale passo compiere ora.",
	contact_action: "Contatta Devenia indicando il sito e il problema da risolvere.",
};
const writerUsage = { input_tokens: 26000, cached_input_tokens: 0, output_tokens: 18000, estimated_cost_microusd: 42000 };
const submitted = submitTranslation({
	job: claimedJob,
	run: writerRun,
	packet,
	localized_fragments: localizedFragments,
	metadata,
	usage: writerUsage,
	attempts: 1,
	finished_at: "2026-07-10T12:06:00.000Z",
});
pass(() => assert.equal(submitted.job.status, "quality_pending"));
pass(() => assert.equal(submitted.run.usage.total_tokens, 44000));
pass(() => assert.match(submitted.artifact.artifact_revision, /^a_[a-f0-9]{32}$/));
pass(() => assert.equal(submitted.artifact.submission_generation, 1));
pass(() => {
	const refreshed = submitTranslation({
		job: { ...claimedJob, submission_generation: 2 },
		run: { ...writerRun, run_id: "tr_it_writer_generation_2", submission_generation: 2 },
		packet: { ...packet, captured_baseline_surface_revision: "sr_current_public_generation_2" },
		localized_fragments: localizedFragments,
		metadata,
		usage: writerUsage,
		attempts: 1,
		finished_at: "2026-07-10T12:06:00.000Z",
	});
	assert.equal(refreshed.artifact.submission_generation, 2);
	assert.equal(refreshed.artifact.captured_baseline_surface_revision, "sr_current_public_generation_2");
	assert.equal(refreshed.artifact.writer_principal_id, "tr_it_writer_generation_2");
	assert.notEqual(refreshed.artifact.artifact_revision, submitted.artifact.artifact_revision);
});
pass(() => {
	const samePayloadOtherJob = submitTranslation({
		job: { ...claimedJob, job_id: "tj_source-4122_it_revision2", source_revision: "r_source_4122_revision2" },
		run: { ...writerRun, job_id: "tj_source-4122_it_revision2" },
		packet: {
			...packet,
			job: { ...packet.job, job_id: "tj_source-4122_it_revision2", source_revision: "r_source_4122_revision2" },
			run: { ...packet.run, job_id: "tj_source-4122_it_revision2" },
		},
		localized_fragments: localizedFragments,
		metadata,
		usage: writerUsage,
		attempts: 1,
		finished_at: "2026-07-10T12:06:00.000Z",
	});
	assert.notEqual(samePayloadOtherJob.artifact.artifact_revision, submitted.artifact.artifact_revision);
});
rejects("incomplete_translation", () => submitTranslation({
	job: claimedJob, run: writerRun, packet, localized_fragments: localizedFragments.slice(1), metadata,
	usage: writerUsage, attempts: 1, finished_at: "2026-07-10T12:06:00.000Z",
}));
rejects("unexpected_localized_fragment", () => submitTranslation({
	job: claimedJob, run: writerRun, packet,
	localized_fragments: [...localizedFragments.slice(0, -1), { key: "invented", html: "Testo" }], metadata,
	usage: writerUsage, attempts: 1, finished_at: "2026-07-10T12:06:00.000Z",
}));
rejects("run_over_budget", () => submitTranslation({
	job: claimedJob, run: writerRun, packet, localized_fragments: localizedFragments, metadata,
	usage: { ...writerUsage, input_tokens: 40001, output_tokens: 30000 }, attempts: 1,
	finished_at: "2026-07-10T12:06:00.000Z",
}));
rejects("run_over_budget", () => submitTranslation({
	job: claimedJob, run: writerRun, packet, localized_fragments: localizedFragments, metadata,
	usage: writerUsage, attempts: 3, finished_at: "2026-07-10T12:06:00.000Z",
}));

pass(() => assert.deepEqual(
	evaluateTokenUsage(writerRun, writerUsage, 1),
	{ ...writerUsage, total_tokens: 44000, attempts: 1, within_budget: true, violations: [] },
));
rejects("invalid_run_outcome", () => recordRunFailure({
	job: claimedJob, run: writerRun, outcome: "abandoned", code: "attention", message: "No",
	usage: writerUsage, attempts: 1, finished_at: "2026-07-10T12:06:00.000Z",
}));
const budgetFailure = recordRunFailure({
	job: claimedJob,
	run: writerRun,
	outcome: "budget_exceeded",
	code: "total_token_limit",
	message: "The Run reached its configured token ceiling.",
	usage: { input_tokens: 40001, cached_input_tokens: 0, output_tokens: 30000, estimated_cost_microusd: 60000 },
	attempts: 2,
	finished_at: "2026-07-10T12:06:00.000Z",
});
pass(() => assert.equal(budgetFailure.run.usage.within_budget, false));

const qualityRun = createTranslationRun({
	run_id: "tr_it_quality_1",
	job: submitted.job,
	role: "quality",
	coordinator_id: "coordinator-main",
	context_mode: "bounded_packet",
	budget: qualityBudget,
	started_at: "2026-07-10T12:06:10.000Z",
	observability_label: "Italian quality subagent",
});
pass(() => assert.equal(qualityRun.coordinator_id, writerRun.coordinator_id));
pass(() => assert.equal(
	evaluateTokenUsage(
		qualityRun,
		{ input_tokens: 30123, cached_input_tokens: 0, output_tokens: 1000, estimated_cost_microusd: 0 },
		1,
	).within_budget,
	true,
));

const checks = Object.fromEntries([
	["source_quality", "The current source revision is useful, accurate, current, and approved before translation."],
	["natural_language", "Natural Italian syntax and terminology checked."],
	["factual_accuracy", "Claims match the current source and evidence."],
	["source_coverage", "All 105 source fragments are represented once."],
	["localized_search_intent", "Title and metadata match Italian search intent."],
	["offer_and_contact", "The Devenia offer and contact action remain concrete."],
	["links_and_route", "Localized route and internal destinations validate."],
	["rendered_experience", "Desktop and mobile reading experience is coherent."],
].map(([key, evidence]) => [key, { passed: true, evidence }]));

const quality = submitQualityDecision({
	job: submitted.job,
	run: qualityRun,
	artifact: submitted.artifact,
	artifact_revision: submitted.artifact.artifact_revision,
	decision: "pass",
	checks,
	edits: [{ field: "title", reason: "Prefer a more natural Italian search phrase." }],
	usage: { input_tokens: 17000, cached_input_tokens: 0, output_tokens: 6000, estimated_cost_microusd: 19000 },
	attempts: 1,
	finished_at: "2026-07-10T12:09:00.000Z",
});
pass(() => assert.equal(quality.job.status, "ready_to_publish"));
pass(() => assert.equal(assertPublishable(quality.job, submitted.artifact, quality.quality_decision), true));
rejects("artifact_revision_mismatch", () => submitQualityDecision({
	job: submitted.job, run: qualityRun, artifact: submitted.artifact, artifact_revision: "a_stale",
	decision: "pass", checks, edits: [],
	usage: { input_tokens: 1000, cached_input_tokens: 0, output_tokens: 1000, estimated_cost_microusd: 1000 },
	attempts: 1, finished_at: "2026-07-10T12:09:00.000Z",
}));
rejects("quality_pass_with_failed_checks", () => submitQualityDecision({
	job: submitted.job, run: qualityRun, artifact: submitted.artifact,
	artifact_revision: submitted.artifact.artifact_revision, decision: "pass",
	checks: { ...checks, natural_language: { passed: false, evidence: "The copy is still too literal." } }, edits: [],
	usage: { input_tokens: 1000, cached_input_tokens: 0, output_tokens: 1000, estimated_cost_microusd: 1000 },
	attempts: 1, finished_at: "2026-07-10T12:09:00.000Z",
}));

for (const targetLanguage of ["it", "nl", "pt"]) {
	const job = createTranslationJob({
		job_id: `tj_source-4122_${targetLanguage}_r1`, source_id: 4122,
		source_revision: "r_source_4122", target_language: targetLanguage, created_at: now,
	});
	assert.equal(job.target_language, targetLanguage);
}
cases += 3;

console.log(JSON.stringify({ success: true, cases, fixture_fragments: fragments.length, fixture_languages: 3 }));
