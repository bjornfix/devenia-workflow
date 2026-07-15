#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const moduleSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authoritySource = readFileSync(new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url), "utf8");
const runtimeSource = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");

const currentSurfaceRevision = authoritySource.match(
	/private static function translation_job_current_surface_revision\( int \$translation_id \): string \{([\s\S]*?)\n\t\}/,
);
assert.ok(currentSurfaceRevision, "missing current Translation Job surface revision reader");
assert.match(
	currentSurfaceRevision[1],
	/'featured_image_alt'\s*=>\s*\(string\) get_post_meta\( \$translation_id, self::META_FEATURED_IMAGE_ALT, true \)/,
	"localized featured-image alt meta must participate in the approved-artifact baseline CAS",
);

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
assert.match(runtimeSource, /\$runtime_header_activation = \$call\( 'sync_public_header_projection'/);
assert.match(runtimeSource, /Could not activate the complete runtime Public Header Projection/);
assert.match(runtimeSource, /\$runtime_header_activation\['verification'\]\['passed'\]/);
assert.doesNotMatch(runtimeSource, /Could not seed the runtime localized primary-menu identity/);
assert.match(runtimeSource, /catch \( Throwable \$error \) \{\s*\$runtime_error = \$error;\s*\} finally \{/);
assert.match(runtimeSource, /\} finally \{[\s\S]*delete_option\( \$option_key \);[\s\S]*if \( \$runtime_error instanceof Throwable \) \{[\s\S]*exit\( 1 \);/);
assert.match(runtimeSource, /\$baseline_drift_update = update_post_meta\( \$translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, \$baseline_drift_featured_alt \)[\s\S]*\$baseline_drift_surface_revision = \$call\( 'translation_job_current_surface_revision'[\s\S]*\$baseline_snapshot = \$call\( 'translation_job_capture_surface_snapshot'/);
assert.match(runtimeSource, /\$baseline_failure = \$call\( 'translation_job_apply_staged_artifact'[\s\S]*\$baseline_observed_featured_alt_after_failure = \(string\) get_post_meta\( \$translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true \)/);
assert.match(runtimeSource, /\} finally \{[\s\S]*\$baseline_original_featured_alt_exists[\s\S]*Devenia_Workflow::META_FEATURED_IMAGE_ALT[\s\S]*\$baseline_restored_surface_revision = \$call\( 'translation_job_current_surface_revision'/);
for (const proof of [
	"Snapshot-to-lock Surface Refresh did not prove same-call zero mutation, rollback, and an exact Job reopen",
	"Surface Refresh changed an immutable prior artifact, Quality, or evidence record",
	"Identical refreshed payload did not create a new immutable generation/baseline/writer artifact",
	"Quality defensive Surface Refresh path failed",
	"Generation-three packet could not recover the latest immutable generation-two artifact",
	"Surface Refresh did not fail closed at the finite generation ceiling",
	"Identity-race lower-level proof did not preserve zero mutation, rollback, immutable records, and exact CAS refresh",
	"Localized featured-image alt baseline-drift proof did not preserve the direct edit, zero publication mutation, rollback, immutable records, and exact CAS refresh",
	"Published Translation Job media drift did not fail closed with immutable approved records and an explicit correction path",
	"Published correction staging changed the live title, status, slug, or route before a fresh passing Quality Decision and publication",
	"Published correction did not preserve the new title exclusively in its immutable staged artifact",
	"Published correction did not retain explicit translator and Quality Run principal separation",
	"Publish-held lifecycle lease did not reject a concurrent translator claim while preserving final Job CAS",
	"Claim-winning lifecycle race did not make publish re-read fail before public mutation",
	"Malformed staged COMMIT receipt was accepted, advanced, or granted rollback authority despite an exact applied replacement",
	"Malformed restore COMMIT receipt was accepted as a successful rollback or mutated immutable authority despite the exact restored state",
	"Present non-array or falsely terminal staged receipt selected default COMMIT, escaped owned terminalization, or advanced publication",
	"A falsely terminal success receipt escaped owned rollback or exposed a usable publication snapshot",
	"A falsely terminal success receipt escaped owned rollback or advanced an uncommitted surface restore",
]) {
	assert.ok(runtimeSource.includes(proof), `missing runtime proof: ${proof}`);
}

const invalidStagedReceipt = runtimeSource.match(/return array\( ([^\n]*'runtime_staged_commit_missing_committed'[^\n]*) \);/);
assert.ok(invalidStagedReceipt, "missing malformed staged COMMIT Adapter receipt fixture");
assert.doesNotMatch(invalidStagedReceipt[1], /'committed'\s*=>/, "malformed staged receipt fixture must actually omit committed");
assert.match(runtimeSource, /\$invalid_staged_commit_adapter[\s\S]*translation_job_commit_recovery_transaction[\s\S]*staged_publication_transaction_commit_receipt_invalid[\s\S]*false !== \( \$invalid_staged_publish\['rollback_authorized'\]/);
assert.match(runtimeSource, /'malformed_staged_commit_receipt_rejected_after_exact_applied_replacement' => true/);

const invalidRestoreReceipt = runtimeSource.match(/return array\( ([^\n]*'runtime_restore_commit_missing_committed'[^\n]*) \);/);
assert.ok(invalidRestoreReceipt, "missing malformed restore COMMIT Adapter receipt fixture");
assert.doesNotMatch(invalidRestoreReceipt[1], /'committed'\s*=>/, "malformed restore receipt fixture must actually omit committed");
assert.match(runtimeSource, /\$invalid_restore_commit_adapter[\s\S]*translation_job_commit_recovery_transaction[\s\S]*recovery_transaction_commit_receipt_invalid[\s\S]*\$invalid_restore_job_bytes_before[\s\S]*\$invalid_restore_quality_bytes_before/);
assert.match(runtimeSource, /'malformed_restore_commit_receipt_rejected_after_exact_restored_state' => true/);

assert.match(runtimeSource, /'non_array' => array\([\s\S]*'receipt'\s*=>\s*'runtime-non-array-receipt-' \. wp_generate_uuid4\(\)[\s\S]*'expect_adapter_receipt_type' => 'string'/);
assert.match(runtimeSource, /'claimed_commit_while_active' => array\([\s\S]*'success' => true, 'committed' => true, 'code' => 'runtime_claimed_commit_while_transaction_active'/);
assert.match(runtimeSource, /\$staged_active_commit_adapter[\s\S]*return \$staged_active_receipt;[\s\S]*transaction_not_terminal[\s\S]*\$staged_active_terminalization\['rolled_back'\][\s\S]*staged_active_surface_before !== \$staged_active_surface_after/);
assert.match(runtimeSource, /'present_non_array_commit_adapter_never_selected_default_commit' => true/);
assert.match(runtimeSource, /'active_staged_transaction_claimed_commit_terminalized_without_publication' => true/);

assert.match(runtimeSource, /\$active_snapshot_receipt = array\( 'success' => true, 'committed' => true, 'code' => 'runtime_snapshot_claimed_commit_while_transaction_active' \)/);
assert.match(runtimeSource, /\$active_snapshot_commit_adapter[\s\S]*return \$active_snapshot_receipt;[\s\S]*publication_snapshot_commit_receipt_invalid[\s\S]*array\( 'transaction_not_terminal' \)[\s\S]*\$active_snapshot_terminalization\['rolled_back'\]/);
assert.match(runtimeSource, /'active_snapshot_transaction_claimed_commit_terminalized_without_snapshot_authority' => true/);

assert.match(runtimeSource, /\$active_restore_receipt = array\( 'success' => true, 'committed' => true, 'code' => 'runtime_restore_claimed_commit_while_transaction_active' \)/);
assert.match(runtimeSource, /\$active_restore_commit_adapter[\s\S]*return \$active_restore_receipt;[\s\S]*recovery_transaction_commit_receipt_invalid[\s\S]*array\( 'transaction_not_terminal' \)[\s\S]*\$active_restore_drift_surface !== \$active_restore_surface_after/);
assert.match(runtimeSource, /'active_restore_transaction_claimed_commit_terminalized_without_restore_progress' => true/);

assert.match(runtimeSource, /\$published_title_before = \(string\) get_post_field\( 'post_title', \$translation_id \)[\s\S]*'quality_pending'[\s\S]*\$published_title_before !== \(string\) get_post_field\( 'post_title', \$translation_id \)[\s\S]*'changes_requested'[\s\S]*\$published_title_before !== \(string\) get_post_field\( 'post_title', \$translation_id \)/);
assert.match(runtimeSource, /'published_job_correction_remained_staged_during_quality_review' => true/);
assert.match(runtimeSource, /'published_job_correction_used_separate_writer_and_quality_principals' => true/);
assert.match(runtimeSource, /'localized_featured_image_alt_drift_reopened_before_stale_publication_mutation' => true/);
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

console.log(JSON.stringify({ success: true, contracts: 75, max_submission_generations: 3 }));
