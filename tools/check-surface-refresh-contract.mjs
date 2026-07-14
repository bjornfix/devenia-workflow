#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const moduleSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authoritySource = readFileSync(new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url), "utf8");
const runtimeSource = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");

assert.match(moduleSource, /const TRANSLATION_JOB_MAX_SUBMISSION_GENERATIONS = 3;/);
const publishAllowlist = moduleSource.match(/const TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES = array\(([\s\S]*?)\);/);
assert.ok(publishAllowlist, "missing fixed publish Surface Refresh allowlist");
assert.deepEqual(
	[...publishAllowlist[1].matchAll(/'([^']+)'/g)].map((match) => match[1]),
	[
		"staged_surface_drifted",
		"staged_surface_drifted_before_locked_write",
		"staged_translation_identity_changed_before_locked_write",
	],
);
assert.match(moduleSource, /private static function translation_job_refresh_drifted_surface\s*\(/);
assert.match(moduleSource, /'status' => 'changes_requested'[\s\S]*'submission_generation' => \$generation \+ 1[\s\S]*'surface_refresh_history' => \$history[\s\S]*'artifact_revision' => ''[\s\S]*'content_revision' => ''[\s\S]*'surface_revision' => ''[\s\S]*'quality_revision' => ''/);
assert.match(moduleSource, /'prior_artifact_revision'[\s\S]*'prior_content_revision'[\s\S]*'prior_surface_revision'[\s\S]*'prior_quality_revision'[\s\S]*'prior_baseline_surface_revision'[\s\S]*'current_surface_revision'[\s\S]*'from_generation'[\s\S]*'to_generation'/);
assert.match(moduleSource, /'surface_refresh_generation_limit'[\s\S]*'status' => 'failed_technical'/);
assert.match(moduleSource, /in_array\( \(string\) \( \$staged_apply\['code'\][\s\S]*TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES, true \)/);
assert.match(moduleSource, /! in_array\( \(string\) \( \$publication_failure\['code'\][\s\S]*TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES, true \)[\s\S]*\$publication_failure\['mutation_started'\][\s\S]*\$rollback\['success'\][\s\S]*\$rollback\['rolled_back'\]/);
assert.match(moduleSource, /'claim_baseline_mismatch'/);
assert.match(moduleSource, /'quality_packet_baseline_mismatch'/);
assert.match(moduleSource, /'quality_submission_baseline_mismatch'/);
assert.match(moduleSource, /'publish_baseline_mismatch'/);
assert.match(moduleSource, /translation_job_publish_failure_with_rollback\([\s\S]*translation_job_refresh_drifted_surface\( \$job, 'publish_baseline_mismatch', \$failure \)/);
assert.match(authoritySource, /private static function translation_job_acquire_lifecycle_lease\( array \$job, string \$operation \)[\s\S]*\$source_id \. '\|' \. \$language[\s\S]*translation_job_lifecycle_lease_conflict/);
assert.match(authoritySource, /private static function translation_job_renew_lifecycle_lease\( array \$lease_result \)[\s\S]*max\( time\(\) \+ 600, absint\( \$owned\['expires_at'\] \?\? 0 \) \+ 1 \)[\s\S]*atomic_replace_option_value\( \$key, \$owned, \$renewed \)/);
assert.doesNotMatch(authoritySource, /translation_job_(?:acquire|renew|release)_publication_lease/);
assert.match(moduleSource, /private static function translation_job_claim\( array \$input \)[\s\S]*\$job_id = self::translation_job_clean_id[\s\S]*\$role = sanitize_key[\s\S]*\$run_id = self::translation_job_clean_id[\s\S]*translation_job_acquire_lifecycle_lease\( \$initial_job, 'claim' \)[\s\S]*try \{[\s\S]*\$job = self::translation_job_get_job\( \$job_id \)[\s\S]*translation_job_claim_under_lifecycle_lease[\s\S]*finally \{[\s\S]*translation_job_release_lifecycle_lease/);
assert.match(moduleSource, /private static function translation_job_publish\( array \$input \)[\s\S]*\$initial_job = self::translation_job_get_job\( \$job_id \)[\s\S]*translation_job_acquire_lifecycle_lease\( \$initial_job, 'publish' \)[\s\S]*try \{[\s\S]*\$job = self::translation_job_get_job\( \$job_id \)[\s\S]*job_not_ready_to_publish[\s\S]*finally \{[\s\S]*translation_job_release_lifecycle_lease/);
assert.match(moduleSource, /'submission_generation' => \$submission_generation[\s\S]*'baseline_surface_revision' => \$baseline_surface_revision[\s\S]*'writer_principal_id'/);
assert.match(moduleSource, /translation_job_role_attempt_count\( \$existing_runs, \$role, \$submission_generation \)/);
assert.match(moduleSource, /surface_refresh_history[\s\S]*prior_quality_revision[\s\S]*previous_artifact/);
assert.match(moduleSource, /surface_refresh_artifact_binding_mismatch[\s\S]*active artifact is not bound to the exact current Job generation/);
assert.match(moduleSource, /'required' => array\( 'job_id' \)/);
assert.doesNotMatch(moduleSource, /! \$is_quality_authority_v3 && \$coordinator_id/);
assert.match(moduleSource, /Coordinator labels grant no authority/);
assert.match(authoritySource, /\$submission_generation[\s\S]*'submission_generation' => \$submission_generation/);
assert.match(runtimeSource, /Could not seed the runtime localized primary-menu identity/);
assert.match(runtimeSource, /catch \( Throwable \$error \) \{\s*\$runtime_error = \$error;\s*\} finally \{/);
assert.match(runtimeSource, /\} finally \{[\s\S]*delete_option\( \$option_key \);[\s\S]*if \( \$runtime_error instanceof Throwable \) \{[\s\S]*exit\( 1 \);/);
assert.match(runtimeSource, /\$baseline_drift_update = wp_update_post\([\s\S]*\$baseline_drift_surface_revision = \$call\( 'translation_job_current_surface_revision'[\s\S]*\$baseline_snapshot = \$call\( 'translation_job_capture_surface_snapshot'/);
assert.match(runtimeSource, /\} finally \{[\s\S]*\$baseline_restore = wp_update_post\([\s\S]*'post_excerpt' => \$baseline_original_excerpt[\s\S]*\$baseline_restored_surface_revision = \$call\( 'translation_job_current_surface_revision'/);
for (const proof of [
	"Snapshot-to-lock Surface Refresh did not prove same-call zero mutation, rollback, and an exact Job reopen",
	"Surface Refresh changed an immutable prior artifact, Quality, or evidence record",
	"Identical refreshed payload did not create a new immutable generation/baseline/writer artifact",
	"Quality defensive Surface Refresh path failed",
	"Generation-three packet could not recover the latest immutable generation-two artifact",
	"Surface Refresh did not fail closed at the finite generation ceiling",
	"Identity-race lower-level proof did not preserve zero mutation, rollback, immutable records, and exact CAS refresh",
	"Baseline-drift lower-level proof did not preserve zero mutation, rollback, immutable records, and exact CAS refresh",
	"Published Translation Job media drift did not fail closed with immutable approved records and an explicit correction path",
	"Published correction staging changed the live title, status, slug, or route before a fresh passing Quality Decision and publication",
	"Published correction did not preserve the new title exclusively in its immutable staged artifact",
	"Published correction did not retain explicit translator and Quality Run principal separation",
	"Publish-held lifecycle lease did not reject a concurrent translator claim while preserving final Job CAS",
	"Claim-winning lifecycle race did not make publish re-read fail before public mutation",
]) {
	assert.ok(runtimeSource.includes(proof), `missing runtime proof: ${proof}`);
}

assert.match(runtimeSource, /\$published_title_before = \(string\) get_post_field\( 'post_title', \$translation_id \)[\s\S]*'quality_pending'[\s\S]*\$published_title_before !== \(string\) get_post_field\( 'post_title', \$translation_id \)[\s\S]*'changes_requested'[\s\S]*\$published_title_before !== \(string\) get_post_field\( 'post_title', \$translation_id \)/);
assert.match(runtimeSource, /'published_job_correction_remained_staged_during_quality_review' => true/);
assert.match(runtimeSource, /'published_job_correction_used_separate_writer_and_quality_principals' => true/);
assert.match(runtimeSource, /\$track_quality_result = static function \( array \$result \) use \( &\$option_keys \): void \{[\s\S]*devenia_workflow_translation_quality_[\s\S]*devenia_tj_quality_evidence_/);
for (const resultName of ["quality", "second_quality", "third_quality", "generation_three_quality", "post_publish_quality"]) {
	assert.match(
		runtimeSource,
		new RegExp(`\\$${resultName} = \\$call\\([\\s\\S]*?\\);\\n\\t\\$track_quality_result\\( \\$${resultName} \\);`),
		`missing immutable Quality/evidence cleanup tracking for ${resultName}`,
	);
}
assert.match(runtimeSource, /\$prior_evidence_key = 'devenia_tj_quality_evidence_'[\s\S]*\$option_keys\[\] = \$prior_evidence_key/);
assert.match(runtimeSource, /\$identity_quality_key = 'devenia_workflow_translation_quality_'[\s\S]*\$option_keys\[\] = \$identity_quality_key[\s\S]*\$identity_evidence_key = 'devenia_tj_quality_evidence_'[\s\S]*\$option_keys\[\] = \$identity_evidence_key/);

console.log(JSON.stringify({ success: true, contracts: 55, max_submission_generations: 3 }));
