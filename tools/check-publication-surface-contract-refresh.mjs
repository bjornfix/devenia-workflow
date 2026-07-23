#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const jobSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authoritySource = readFileSync(new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url), "utf8");
const inventorySource = readFileSync(new URL("../includes/trait-source-inventory.php", import.meta.url), "utf8");
const atomicOptionSource = readFileSync(new URL("../includes/trait-atomic-option-store.php", import.meta.url), "utf8");
const runtimeSource = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");

const body = (name, source = jobSource) => {
	const start = source.indexOf(`private static function ${name}(`);
	assert.ok(start >= 0, `missing ${name}`);
	const end = source.indexOf("\n\tprivate static function ", start + 1);
	return source.slice(start, end > start ? end : source.length);
};

const authorityBody = (name) => body(name, authoritySource);

const fingerprint = body("translation_job_publication_surface_contract_revision");
assert.match(fingerprint, /TRANSLATION_JOB_PUBLICATION_SURFACE_CONTRACT_SCHEMA/);
assert.match(fingerprint, /fragment_projection/);
assert.doesNotMatch(fingerprint, /self::VERSION|OPTION_|get_option/);
assert.match(jobSource, /TRANSLATION_JOB_PUBLICATION_SURFACE_CONTRACT_SCHEMA = 'publication-surface-contract-v5-rtl-grid-gap-rendered-information-architecture-quality'/, "RTL jobs must bind native grid-gap projection and rendered information-architecture Quality");
assert.match(jobSource, /TRANSLATION_JOB_LTR_PUBLICATION_SURFACE_CONTRACT_SCHEMA = 'publication-surface-contract-v3-rendered-information-architecture-quality'/, "LTR jobs must bind rendered information-architecture Quality");
assert.match(fingerprint, /is_rtl_language\( \$language \)[\s\S]*TRANSLATION_JOB_PUBLICATION_SURFACE_CONTRACT_SCHEMA[\s\S]*TRANSLATION_JOB_LTR_PUBLICATION_SURFACE_CONTRACT_SCHEMA/, "only RTL jobs may advance to the target-design-signature contract");
assert.match(body("translation_job_discover"), /translation_job_publication_surface_contract_revision\( \$source, \$language \)/);
assert.match(body("translation_job_publication_surface_contract_state"), /translation_job_publication_surface_contract_revision\( \$source, \(string\) \( \$job\['target_language'\]/);
assert.match(inventorySource, /translation_job_publication_surface_contract_revision\( \$source, \$language \)/);

const refresh = body("translation_job_refresh_publication_surface_contract_under_lifecycle_lease");
assert.match(refresh, /contract_refresh_history/);
assert.match(refresh, /translation_job_transition/);
assert.match(refresh, /'status' => 'changes_requested'/);
assert.match(refresh, /'submission_generation' => \$generation \+ 1/);
for (const ref of ["artifact_revision", "content_revision", "surface_revision", "quality_revision", "active_run_id"]) {
	assert.match(refresh, new RegExp(`'${ref}' => ''`), `refresh must clear ${ref}`);
}
assert.match(refresh, /mismatch_evidence/);
assert.match(refresh, /active_refs/);
assert.match(refresh, /contract_refresh_generation_limit/);
assert.match(refresh, /'idempotent' => true/, "a repeated generation-limit refresh must be read-only");
assert.match(refresh, /translation_job_retire_contract_refresh_run_and_claim\( \$job, \$active_claim \)/);
const retirementCall = refresh.indexOf("translation_job_retire_contract_refresh_run_and_claim( $job, $active_claim )");
const generationTransition = refresh.indexOf("$terminal = self::translation_job_transition");
const refreshTransition = refresh.indexOf("$transition = self::translation_job_transition");
assert.ok(retirementCall >= 0 && generationTransition > retirementCall && refreshTransition > retirementCall, "Run/claim retirement must complete before either Job transition");
const idempotentLimit = refresh.indexOf("if ( $already_at_limit )");
assert.ok(idempotentLimit > retirementCall, "generation-ceiling idempotence must retry pending retirement first");
assert.doesNotMatch(refresh, /translation_job_(?:artifact|run|quality|quality_evidence|quality_receipt)_key[\s\S]*(?:update_option|delete_option)/);

const retirement = body("translation_job_retire_contract_refresh_run_and_claim");
assert.match(retirement, /translation_job_finish_run_without_usage\( \$run, 'contract_refresh_required' \)/);
assert.match(retirement, /'completed' !== \(string\) \( \$run\['status'\]/);
assert.match(retirement, /translation_job_release_claim\( \$claim \)/);
assert.match(retirement, /contract_refresh_claim_release_conflict/);
assert.match(retirement, /maybe_serialize\( \$claim \) !== maybe_serialize\( \$current_claim \)/, "successor ownership must be checked before retirement");
assert.ok(retirement.indexOf("maybe_serialize( $claim ) !== maybe_serialize( $current_claim )") < retirement.indexOf("translation_job_finish_run_without_usage"), "a successor claim conflict must preserve the old Run and Job refs");
assert.match(retirement, /contract_refresh_run_terminal_conflict/, "a completed Run with another outcome is immutable");

const finishRun = body("translation_job_finish_run");
assert.match(finishRun, /'running' !== \(string\) \( \$run\['status'\]/);
assert.match(finishRun, /atomic_replace_option_value\( self::translation_job_run_key\( \(string\) \$run\['run_id'\] \), \$run, \$completed \)/);
assert.doesNotMatch(finishRun, /update_option\s*\(/, "Run completion must be ownership-bound CAS, never an unconditional overwrite");
const finishWithoutUsage = body("translation_job_finish_run_without_usage");
assert.match(finishWithoutUsage, /return self::translation_job_finish_run\( \$run, \$outcome/);
assert.doesNotMatch(finishWithoutUsage, /update_option\s*\(/);

const fetchPacket = body("translation_job_fetch_packet");
assert.match(fetchPacket, /\$run_before_packet = \$run[\s\S]*atomic_replace_option_value\( self::translation_job_run_key[\s\S]*\$run_before_packet, \$run \)/);
assert.match(fetchPacket, /\$packet_revision !== \(string\) \( \$run_before_packet\['packet_revision'\]/, "an unchanged packet must preserve its first receipt timestamp and exercise idempotent exact CAS");
assert.match(fetchPacket, /run_packet_record_conflict/);
const abandon = body("translation_job_abandon");
const abandonRunCas = abandon.indexOf("atomic_replace_option_value( self::translation_job_run_key");
const abandonJobTransition = abandon.indexOf("$transition = self::translation_job_transition");
assert.ok(abandonRunCas >= 0 && abandonJobTransition > abandonRunCas, "abandon must CAS-complete its exact Run before changing Job state");
assert.match(abandon, /run_abandon_conflict/);
const claimAccessBody = body("translation_job_claim_access");
assert.match(claimAccessBody, /\$terminal_retry_allowed = 'completed' === \$run_status/);
assert.match(claimAccessBody, /job_run_not_active/);
assert.match(claimAccessBody, /\$run_before_migration = \$run[\s\S]*atomic_replace_option_value\( \$run_key, \$run_before_migration, \$run \)/);
assert.match(claimAccessBody, /run_budget_migration_conflict/);
for (const operation of ["translation_job_expire_run", "translation_job_finalize_orphaned_runs"]) {
	assert.match(body(operation), /atomic_replace_option_value\(/, `${operation} must mutate Runs only by exact CAS`);
}
assert.doesNotMatch(jobSource, /update_option\(\s*self::translation_job_run_key/, "no direct Run-key update may remain");
assert.doesNotMatch(jobSource, /update_option\(\s*\$run_key/, "no aliased direct Run-key update may remain");
assert.doesNotMatch(jobSource, /delete_option\(\s*self::translation_job_run_key/, "Run rollback deletion must be exact-value CAS");
for (const functionMatch of jobSource.matchAll(/private static function\s+(\w+)\s*\([^)]*\)[^{]*\{/g)) {
	const operationBody = body(functionMatch[1]);
	if (operationBody.includes("translation_job_run_key") || /\$run_key\b/.test(operationBody)) {
		assert.doesNotMatch(operationBody, /(?:update_option|delete_option)\s*\(/, `${functionMatch[1]} contains an unconditional mutable Run writer`);
	}
}
for (const operation of ["translation_job_submit_artifact", "translation_job_submit_quality_decision"]) {
	const operationBody = body(operation);
	const guardedFinish = operationBody.lastIndexOf("if ( ! self::translation_job_finish_run(");
	const jobTransition = operationBody.lastIndexOf("$next = self::translation_job_transition");
	assert.ok(guardedFinish >= 0 && jobTransition > guardedFinish, `${operation} must stop when the exact Run completion CAS loses before changing Job state`);
	assert.match(operationBody.slice(guardedFinish, jobTransition), /run_completion_conflict/);
}

const releaseClaim = body("translation_job_release_claim");
assert.match(releaseClaim, /\$job_id[\s\S]*\$run_id[\s\S]*\$token_hash/);
assert.match(releaseClaim, /atomic_delete_option_value\( self::translation_job_claim_key\( \$job_id \), \$claim \)/);
assert.doesNotMatch(releaseClaim, /delete_option\s*\(/);
for (const match of jobSource.matchAll(/translation_job_release_claim\(\s*([^\n;)]+)/g)) {
	if (match[1].trim().startsWith("array ")) continue;
	assert.ok(match[1].trim().startsWith("$"), `claim release must receive exact ownership record: ${match[0]}`);
	assert.notEqual(match[1].trim(), "$job_id", "claim release must not delete by Job id alone");
}

const claim = body("translation_job_claim_under_lifecycle_lease");
const createRun = claim.indexOf("atomic_create_option( self::translation_job_run_key");
const refreshCall = claim.indexOf("translation_job_refresh_publication_surface_contract_under_lifecycle_lease");
const qualityStop = claim.indexOf("'contract_refresh_required'");
assert.ok(refreshCall >= 0 && qualityStop > refreshCall && createRun > qualityStop, "stale Quality must stop before claim/Run creation");
assert.doesNotMatch(claim.slice(0, refreshCall), /atomic_create_option\( self::translation_job_run_key/);

for (const operation of [
	"translation_job_fetch_packet",
	"translation_job_submit_artifact",
	"translation_job_submit_quality_decision",
]) {
	assert.match(body(operation), /translation_job_require_current_publication_surface_contract/, `${operation} must reject a wrong revision before work`);
}
assert.match(body("translation_job_publish"), /translation_job_refresh_publication_surface_contract_under_lifecycle_lease\( \$job, 'publish' \)/);
assert.match(body("translation_job_discover"), /\$refresh[\s\S]*empty\( \$refresh\['success'\] \)[\s\S]*return \$refresh/);
assert.match(body("translation_job_status"), /contract_stale[\s\S]*next_role[\s\S]*translator/);
assert.match(body("translation_job_claim_access"), /job_claim_contract_revision_mismatch/);

for (const field of [
	"publication_surface_contract_revision",
	"contract_refresh_history",
]) {
	assert.ok(jobSource.includes(field), `missing Job binding ${field}`);
}
const artifactSubmit = body("translation_job_submit_artifact");
assert.match(artifactSubmit, /\$artifact_revision[\s\S]*'publication_surface_contract_revision' => \(string\) \$job\['publication_surface_contract_revision'\]/);
assert.match(artifactSubmit, /\$artifact_record = array\([\s\S]*'publication_surface_contract_revision' => \(string\) \$job\['publication_surface_contract_revision'\]/);
assert.match(authorityBody("translation_job_stage_artifact"), /\$manifest = array\([\s\S]*'publication_surface_contract_revision' => \(string\) \( \$job\['publication_surface_contract_revision'\]/);
assert.match(body("translation_job_submit_quality_decision"), /\$quality = array\([\s\S]*'publication_surface_contract_revision' => \(string\) \$job\['publication_surface_contract_revision'\]/);
assert.match(authorityBody("translation_job_server_quality_receipts"), /\$body = array\([\s\S]*'publication_surface_contract_revision' => \(string\) \$job\['publication_surface_contract_revision'\]/);
assert.match(authorityBody("translation_job_quality_evidence_receipts"), /\$record = array\([\s\S]*'publication_surface_contract_revision' => \(string\) \$job\['publication_surface_contract_revision'\]/);
const evidenceAuthority = authorityBody("translation_job_validate_quality_evidence_record");
assert.match(evidenceAuthority, /\$record\['publication_surface_contract_revision'\][\s\S]*\$artifact_record\['publication_surface_contract_revision'\]/);
assert.match(evidenceAuthority, /\$quality\['publication_surface_contract_revision'\][\s\S]*\$artifact_record\['publication_surface_contract_revision'\]/);
assert.match(evidenceAuthority, /\$receipt\['publication_surface_contract_revision'\][\s\S]*\$record\['publication_surface_contract_revision'\]/);
assert.match(authoritySource, /publication_surface_contract_revision_stale/);
assert.match(authoritySource, /publication_surface_contract_coverage_mismatch/);
assert.match(inventorySource, /publication_contract_stale/);
assert.match(inventorySource, /translation_job_publication_surface_contract_revision/);
assert.match(inventorySource, /translation_job_publication_surface_contract_state\( \$job \)/);
assert.match(inventorySource, /\$job_contract\['contract_stale'\]/);
assert.match(runtimeSource, /Stale Quality contract created a Run\/claim or failed to preserve immutable artifact evidence/);
assert.match(runtimeSource, /contract_gap_artifact_bytes[\s\S]*contract_refresh_required[\s\S]*false !== get_option\( \$contract_gap_quality_run_key[\s\S]*contract_refresh_history[\s\S]*submission_generation/);
assert.match(runtimeSource, /Exact claim release removed a successor claim/);
assert.match(runtimeSource, /Contract Refresh did not terminally retire the active Run/);
assert.match(runtimeSource, /Repeated generation-limit Contract Refresh mutated history or Job state/);
assert.match(runtimeSource, /Contract Refresh retirement failure changed Job state or immutable Run outcome/);
assert.match(runtimeSource, /Contract Refresh successor-claim conflict lost the successor claim, active refs, or old Run/);
assert.match(runtimeSource, /Old submit overwrote the terminal contract_refresh_required Run/);
assert.match(runtimeSource, /Generation-ceiling retry did not finish pending retirement before the idempotent terminal state/);
assert.match(runtimeSource, /Stale fetch_packet after Contract Refresh changed the terminal Run or refreshed Job/);
assert.match(runtimeSource, /Stale abandon after Contract Refresh changed the terminal Run or refreshed Job/);
assert.match(runtimeSource, /Budget migration stale-owner CAS replaced a terminal Run/);
assert.match(runtimeSource, /Repeated same-second packet fetch did not pass the exact idempotent Run CAS/);
assert.match(runtimeSource, /Idempotent exact CAS did not distinguish the owned no-op from a changed current value/);

assert.match(atomicOptionSource, /\$expected_bytes = maybe_serialize\( \$expected \)/);
assert.match(atomicOptionSource, /\$replacement_bytes = maybe_serialize\( \$replacement \)/);
assert.match(atomicOptionSource, /if \( \$expected_bytes === \$replacement_bytes \)/);
assert.match(atomicOptionSource, /SELECT option_name[\s\S]*BINARY option_value = BINARY %s/);
assert.match(atomicOptionSource, /return \$key === \(string\) \$matched_key/);

const exactCasModel = (current, expected, replacement) => {
	if (current !== expected) return { success: false, current };
	return { success: true, current: replacement };
};
assert.deepEqual(exactCasModel("owned", "owned", "owned"), { success: true, current: "owned" });
assert.deepEqual(exactCasModel("successor", "owned", "owned"), { success: false, current: "successor" });

// Pure lifecycle model exercises the boundary cases without a WordPress database.
const modelRefresh = ({ generation, max = 3, pinned, current, expectedKeys, artifactKeys, refs, attempts = [], history = [] }) => {
	const coverageMatches = expectedKeys.length === artifactKeys.length
		&& new Set(expectedKeys).size === expectedKeys.length
		&& new Set(artifactKeys).size === artifactKeys.length
		&& expectedKeys.every((key) => artifactKeys.includes(key));
	const stale = !pinned || pinned !== current || !coverageMatches;
	if (!stale) return { refreshed: false, generation, refs, attempts, history };
	const entry = { pinned, current, coverageMatches, refs: structuredClone(refs), from: generation, to: generation < max ? generation + 1 : null };
	if (generation >= max) return { refreshed: false, failed: true, generation, refs, attempts, history: [...history, entry] };
	return {
		refreshed: true,
		generation: generation + 1,
		pinned: current,
		refs: { artifact: "", content: "", surface: "", quality: "", activeRun: "" },
		attempts,
		history: [...history, entry],
	};
};

const priorRefs = { artifact: "a_old", content: "c_old", surface: "sr_old", quality: "q_old", activeRun: "r_old" };
const expected69 = Array.from({ length: 69 }, (_, i) => `f${i}`);
const priorAttempts = [{ run: "r_t1", role: "translator", generation: 1 }, { run: "r_q1", role: "quality", generation: 1 }];
const stale68 = modelRefresh({ generation: 1, pinned: "pscr_old", current: "pscr_new", expectedKeys: expected69, artifactKeys: expected69.slice(0, 68), refs: priorRefs, attempts: priorAttempts });
assert.equal(stale68.refreshed, true);
assert.equal(stale68.generation, 2);
assert.deepEqual(stale68.refs, { artifact: "", content: "", surface: "", quality: "", activeRun: "" });
assert.deepEqual(stale68.history[0].refs, priorRefs, "history must retain the old active references");
assert.equal(stale68.history[0].coverageMatches, false);
assert.deepEqual(stale68.attempts, priorAttempts, "prior Run attempts remain immutable across the generation bump");
assert.equal(stale68.attempts.filter((attempt) => attempt.generation === stale68.generation).length, 0, "the refreshed generation remains claimable");

const current = modelRefresh({ generation: 2, pinned: "pscr_new", current: "pscr_new", expectedKeys: expected69, artifactKeys: expected69, refs: priorRefs });
assert.equal(current.refreshed, false, "current contract refresh must be idempotent");
assert.deepEqual(current.refs, priorRefs);

const wrongRevision = modelRefresh({ generation: 1, pinned: "pscr_wrong", current: "pscr_new", expectedKeys: expected69, artifactKeys: expected69, refs: priorRefs });
assert.equal(wrongRevision.refreshed, true, "wrong pinned revision must never be accepted");

const legacy = modelRefresh({ generation: 1, pinned: "", current: "pscr_new", expectedKeys: expected69, artifactKeys: [], refs: priorRefs });
assert.equal(legacy.refreshed, true, "missing legacy pin is stale");

const ceiling = modelRefresh({ generation: 3, pinned: "pscr_old", current: "pscr_new", expectedKeys: expected69, artifactKeys: expected69.slice(0, 68), refs: priorRefs });
assert.equal(ceiling.failed, true);
assert.equal(ceiling.generation, 3);
assert.deepEqual(ceiling.refs, priorRefs, "finite ceiling must fail closed without discarding authority refs");

console.log(JSON.stringify({ success: true, cases: 6, stale_fragment_fixture: "68/69", max_submission_generations: 3 }));
