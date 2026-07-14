#!/usr/bin/env node
import { existsSync, readFileSync } from "node:fs";

const jobSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authorityUrl = new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url);
const authoritySource = existsSync(authorityUrl) ? readFileSync(authorityUrl, "utf8") : "";
const publicationSource = readFileSync(new URL("../includes/trait-localized-presentation-publication.php", import.meta.url), "utf8");
const taxonomySource = readFileSync(new URL("../includes/trait-taxonomy-localization.php", import.meta.url), "utf8");
const atomicOptionSource = readFileSync(new URL("../includes/trait-atomic-option-store.php", import.meta.url), "utf8");
const mainSource = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const source = `${jobSource}\n${authoritySource}`;
const failures = [];
const requireMatch = (pattern, message) => {
	if (!pattern.test(source)) failures.push(message);
};
const requireNoMatch = (value, pattern, message) => {
	if (pattern.test(value)) failures.push(message);
};
const functionBody = (name, nextName) => {
	const start = source.indexOf(`private static function ${name}`);
	const end = nextName ? source.indexOf(`private static function ${nextName}`, start + 1) : source.length;
	if (start < 0 || end <= start) return "";
	return source.slice(start, end);
};

const submitArtifact = functionBody("translation_job_submit_artifact", "translation_job_submit_quality_decision");
const submitQuality = functionBody("translation_job_submit_quality_decision", "translation_job_publish");
const qualitySchema = functionBody("translation_job_quality_schema", "translation_job_publish_schema");
const validateUsage = functionBody("translation_job_validate_usage", "translation_job_finish_run");
const claim = functionBody("translation_job_claim", "translation_job_fetch_packet");
const evidenceReceipts = functionBody("translation_job_quality_evidence_receipts", "translation_job_browser_receipt");
const browserReceipt = functionBody("translation_job_browser_receipt", "translation_job_apply_staged_artifact");
const resolvePublicationTranslation = functionBody("translation_job_resolve_publication_translation_id", "translation_job_apply_staged_artifact");
const applyStaged = functionBody("translation_job_apply_staged_artifact", "translation_job_verify_applied_surface");
const verifyApplied = functionBody("translation_job_verify_applied_surface", "translation_job_expected_taxonomy_surface");
const rollbackCas = functionBody("translation_job_rollback_cas_revision", "translation_job_capture_surface_snapshot");
const captureSnapshot = functionBody("translation_job_capture_surface_snapshot", "translation_job_restore_surface_snapshot");
const restoreSnapshot = functionBody("translation_job_restore_surface_snapshot", "translation_job_publish_failure_with_rollback");
const rollbackFailure = functionBody("translation_job_publish_failure_with_rollback", "translation_job_validate_quality_evidence_record");

requireMatch(/private static function translation_job_authenticated_principal\s*\(/, "missing server-issued Translation Run Principal Interface");
requireMatch(/private static function translation_job_surface_revision\s*\(/, "missing complete Artifact Surface Revision Interface");
requireMatch(/private static function translation_job_quality_evidence_receipts\s*\(/, "missing server-owned Quality Evidence Receipt Interface");
requireMatch(/private static function translation_job_browser_receipt\s*\(/, "missing Browser Render Receipt Interface");
requireMatch(/private static function translation_job_apply_staged_artifact\s*\(/, "missing guarded staged-artifact application Interface");

if (!submitArtifact) {
	failures.push("translation_job_submit_artifact implementation not found");
} else {
	requireNoMatch(submitArtifact, /upsert_translation\s*\(/, "artifact submission still writes through to a WordPress translation");
	requireNoMatch(submitArtifact, /sync_source_featured_image\s*\(/, "artifact submission still mutates public visible media");
	requireNoMatch(submitArtifact, /'allow_update_published'\s*=>\s*true/, "artifact submission still permits published-post mutation");
	if (!/translation_job_surface_revision\s*\(/.test(submitArtifact) || !/'state'\s*=>\s*'staged'/.test(submitArtifact)) {
		failures.push("artifact submission must store an immutable staged artifact with a complete surface revision");
	}
}

if (!claim || !/translation_job_authenticated_principal\s*\(/.test(claim) || !/'principal_id'/.test(claim)) {
	failures.push("claim must issue and persist a server-owned Run principal");
}
if (!claim || !/writer_reviewer_principal_conflict|quality_principal_must_differ/.test(claim)) {
	failures.push("Quality claim must reject the writer Run principal");
}

if (!qualitySchema || /\$check_properties|self::translation_job_quality_checks\(\)/.test(qualitySchema)) {
	failures.push("Quality schema still exposes caller booleans as passing checks");
}
if (!qualitySchema || !/evidence_receipt_ids/.test(qualitySchema) || !/reviewer_attestations/.test(qualitySchema) || !/browser_receipts/.test(qualitySchema)) {
	failures.push("Quality schema must separate server evidence receipt IDs from reviewer and browser attestations");
}
if (!submitQuality || /\$input\['checks'\]/.test(submitQuality) || !/translation_job_quality_evidence_receipts\s*\(/.test(submitQuality)) {
	failures.push("Quality submission must resolve server-owned receipts instead of trusting booleans or free text");
}
if (!evidenceReceipts || /\$input\['checks'\]/.test(evidenceReceipts) || !/evidence_receipt_ids/.test(evidenceReceipts) || !/http_live_dom/.test(evidenceReceipts)) {
	failures.push("mandatory deterministic and HTTP/live-DOM Quality Evidence Receipts are not resolved from server receipt IDs");
}
if (!browserReceipt || !/trust['\"]?\s*=>\s*['\"]reviewer_attested/.test(browserReceipt) || !/browser_adapter_receipt_ids/.test(browserReceipt)) {
	failures.push("browser evidence must distinguish validated reviewer attestations and expose an external Adapter receipt-ID Seam");
}

requireMatch(/translation_job_browser_receipt[\s\S]*artifact_revision[\s\S]*surface_revision[\s\S]*viewport_scheme[\s\S]*color_scheme/, "Browser Render Receipt must bind artifact, surface, policy viewport scheme, and color scheme");
requireMatch(/http_live_dom[\s\S]*issuer[\s\S]*(?:workflow|server)/, "Quality PASS must include a built-in server HTTP/live-DOM receipt");
requireMatch(/translation_job_surface_revision[\s\S]*['\"]seo['\"][\s\S]*taxonom(?:y|ies)[\s\S]*route[\s\S]*media/, "Artifact Surface Revision must include SEO, taxonomy, route, and visible media");

if (!validateUsage || !/zero_token_usage_not_measured/.test(validateUsage) || !/input_tokens[\s\S]*output_tokens/.test(validateUsage)) {
	failures.push("all-zero caller token usage is still accepted as measured usage");
}
requireMatch(/provider_usage_receipt|usage_receipt_id/, "measured usage must bind to a server-owned or provider Adapter receipt");

if (!applyStaged || !/already_applied/.test(applyStaged) || !/translation_job_verify_applied_surface/.test(applyStaged) || !/translation_job_translation_revision/.test(applyStaged)) {
	failures.push("staged publication must resume idempotently when the exact approved surface was already applied before an interrupted response");
}
if (!restoreSnapshot || !/with_direct_save_storage_guardrails_suspended/.test(restoreSnapshot) || !/with_reviewer_style_capture_suspended/.test(restoreSnapshot)) {
	failures.push("existing-surface rollback must restore inside the guarded internal publication boundary");
}
for (const field of ["post_author", "post_date", "post_date_gmt", "post_modified", "post_modified_gmt"]) {
	if (!captureSnapshot.includes(`'${field}'`)) failures.push(`rollback snapshot is missing ${field}`);
}
if (!restoreSnapshot || !/meta_verification/.test(restoreSnapshot) || !/taxonomy_verify_/.test(restoreSnapshot) || !/surface_restore_incomplete/.test(restoreSnapshot)) {
	failures.push("rollback must verify post meta and taxonomy restoration instead of reporting unchecked success");
}
if (!verifyApplied || !/route_canonical/.test(verifyApplied) || !/localized_parent_id/.test(verifyApplied) || !/array_key_exists\( 'featured_image_alt'/.test(verifyApplied) || !/translation_job_actual_taxonomy_surface/.test(verifyApplied)) {
	failures.push("already-applied equivalence must compare canonical route, parent, empty media values, and exact taxonomy surface");
}
if (!rollbackFailure || !/not_required_before_mutation/.test(rollbackFailure) || !/rollback_expected_surface_revision/.test(rollbackFailure) || !/missing_expected_mutation_revision/.test(rollbackFailure)) {
	failures.push("rollback must skip pre-mutation failures and require an expected mutation revision before restoring existing content");
}
if (!rollbackCas || !/post_author/.test(rollbackCas) || !/post_date/.test(rollbackCas) || !/post_modified/.test(rollbackCas) || !/get_post_meta/.test(rollbackCas) || !/taxonomies/.test(rollbackCas)) {
	failures.push("rollback compare-and-swap revision must cover all owned post fields, metadata, and taxonomies");
}
if (!captureSnapshot || !/translation_job_begin_recovery_transaction/.test(captureSnapshot) || !/translation_job_lock_recovery_surface/.test(captureSnapshot) || !/translation_job_capture_surface_snapshot_uncommitted/.test(captureSnapshot) || !/translation_job_commit_recovery_transaction/.test(captureSnapshot)) {
	failures.push("the complete pre-mutation snapshot must be captured inside one row-locked transaction");
}
if (!/translation_job_clean_recovery_caches[\s\S]*wp_cache_delete\( \$translation_id, 'post_meta' \)/.test(source) || !/translation_job_clean_term_caches[\s\S]*wp_cache_delete\( \$term_id, 'term_meta' \)/.test(source)) {
	failures.push("committed and rolled-back recovery boundaries must invalidate post-meta and term-meta object caches");
}
if (!captureSnapshot || !/translation_job_rollback_cas_revision\( 0, \$term_snapshot\['terms'\], \$identity_scope \)/.test(captureSnapshot) || !/translation_job_lock_recovery_surface\( \$translation_id, \$term_scope, \$identity_scope \)/.test(captureSnapshot)) {
	failures.push("a new-candidate snapshot must bind absent canonical identity and existing global terms inside the locked precondition");
}
if (!applyStaged || !/mutation_cas_revision['"]?\]\s*=\s*self::translation_job_rollback_cas_revision/.test(applyStaged) || applyStaged.indexOf("mutation_cas_revision'] = self::translation_job_rollback_cas_revision") > applyStaged.indexOf("translation_job_commit_recovery_transaction")) {
	failures.push("the staged mutation receipt must be captured under lock before transaction commit, including already-applied retries");
}
if (!applyStaged || applyStaged.indexOf("translation_job_resolve_publication_translation_id") < applyStaged.indexOf("translation_job_begin_recovery_transaction") || !/translation_job_snapshot_translation_identity_matches/.test(applyStaged) || /translation_job_apply_staged_artifact_uncommitted[\s\S]*translation_job_resolve_publication_translation_id/.test(applyStaged)) {
	failures.push("staged apply must resolve once inside the transaction, lock that exact identity, and reject snapshot identity drift before writes");
}
if (!/translation_job_find_translation_identity_ids/.test(source) || !/translation_job_find_translation_identity_candidate_ids_for_update/.test(source) || !/recovery_translation_candidate_lock_failed/.test(source) || !/translation_identity['"]?\s*=>/.test(rollbackCas)) {
	failures.push("canonical source/language/post-type identity, including absence, must be range-locked and included in every recovery receipt");
}
requireMatch(/translation_job_capture_term_snapshot/, "rollback snapshot must include the global translated-term scope");
requireMatch(/translation_job_taxonomy_term_state[\s\S]*get_objects_in_term/, "rollback term state must cover global term fields, meta, and object relationships");
requireMatch(/translation_job_restore_term_snapshot[\s\S]*wp_update_term[\s\S]*new_term_shared_[\s\S]*wp_delete_term/, "rollback must restore existing translated terms and delete only unshared newly-created terms");
requireMatch(/new_candidate_publication_attempt_mismatch/, "new-candidate rollback must bind deletion to the owning publication attempt");
if (!/TERM_META_PUBLICATION_ATTEMPT/.test(mainSource) || !/TERM_META_PUBLICATION_ATTEMPT/.test(taxonomySource) || !/new_term_publication_attempt_/.test(source)) {
	failures.push("new translated-term rollback must bind deletion to the publication attempt that created the term");
}
requireMatch(/translation_job_acquire_publication_lease[\s\S]*publication_already_in_progress/, "publication must serialize duplicate Workflow attempts with an atomic lease");
if (!/hash\( 'sha256', sanitize_key\( \(string\) \( \$job\['target_language'\]/.test(source) || !/translation_job_renew_publication_lease/.test(source)) {
	failures.push("publication lease must serialize every publication for one language and renew before slow public checks");
}
if (!/atomic_replace_option_value/.test(atomicOptionSource) || !/atomic_delete_option_value/.test(atomicOptionSource) || !/atomic_delete_option_value\( \$key, \$owned \)/.test(source)) {
	failures.push("lease takeover, renewal, and release must use compare-and-swap storage operations");
}
if (!/'retire_previous'\s*=>\s*false/.test(publicationSource) || !/rollback_localized_menu_projection/.test(publicationSource)) {
	failures.push("localized menu activation must retain the previous projection until cache and live verification succeed, with an explicit rollback path");
}
if (!/localized_presentation_rollback/.test(rollbackFailure) || !/rollback_cache_invalidation_failed/.test(rollbackFailure)) {
	failures.push("successful database rollback must purge the restored frontend surface and fail closed when that purge is unavailable");
}
if (/repair_internal_links\s*\(/.test(publicationSource)) {
	failures.push("localized presentation publication still performs cross-post link mutation outside its rollback snapshot");
}
if (!/translation_job_begin_recovery_transaction/.test(publicationSource) || !/translation_job_lock_recovery_surface/.test(publicationSource) || !/mutation_cas_revision\s*=\s*self::translation_job_rollback_cas_revision/.test(publicationSource) || !/translation_job_commit_recovery_transaction/.test(publicationSource)) {
	failures.push("publication transition and menu activation must capture their mutation receipt inside one row-locked transaction");
}
if (/empty\( \$publication\['success'\] \)[\s\S]{0,500}translation_job_rollback_cas_revision/.test(jobSource)) {
	failures.push("caller still samples rollback CAS after the publication Module has failed");
}
if (!/translation_job_resolve_localized_parent/.test(mainSource) || !/localized_parent_language_mismatch/.test(mainSource)) {
	failures.push("stage and write paths must share one language-correct localized-parent resolver");
}
requireMatch(/staged_taxonomy_read_failed|taxonomy_read/, "taxonomy surface reads must fail closed");
if (!/translation_job_restore_surface_snapshot[\s\S]*translation_job_begin_recovery_transaction[\s\S]*translation_job_lock_recovery_surface[\s\S]*translation_job_restore_surface_snapshot_uncommitted[\s\S]*translation_job_commit_recovery_transaction/.test(source)) {
	failures.push("rollback check and every restore write must execute inside one row-locked database transaction");
}
if (!/staged_surface_drifted_before_locked_write/.test(source) || !/publication_surface_changed_before_locked_transition/.test(publicationSource)) {
	failures.push("locked mutation boundaries must compare the captured precondition before accepting ownership");
}
const stagedRecoveryPropagationCount = (publicationSource.match(/'mutation_started'\s*=>\s*\$recover_staged_mutation/g) || []).length;
if (stagedRecoveryPropagationCount < 6 || !/prior_mutation_cas_revision/.test(publicationSource)) {
	failures.push("every early second-phase publication failure must preserve the prior committed staged-mutation recovery receipt");
}
if (!/number'\s*=>\s*2/.test(taxonomySource) || !/duplicate_localized_taxonomy_term_identity/.test(taxonomySource)) {
	failures.push("normal taxonomy mutation lookup must reject duplicate translated-term identities");
}
if (!/ensure_parent_path[\s\S]*localized_parent_path_missing/.test(mainSource) || /private static function ensure_parent_path[\s\S]{0,1800}wp_insert_post/.test(mainSource)) {
	failures.push("shared localized-parent staging must resolve existing hierarchy without creating placeholder posts");
}
if (!/menu_identity_activation/.test(mainSource) || !/atomic_replace_option_value/.test(publicationSource) || !/rollback_localized_menu_projection_uncommitted/.test(publicationSource)) {
	failures.push("menu recovery must restore the exact prior identity through CAS inside an atomic deletion transaction");
}
if (!/localized_menu_projection_revision/.test(publicationSource) || !/target_menu_changed_after_projection/.test(publicationSource)) {
	failures.push("menu rollback must bind target deletion to an exact after-receipt for term, items, metadata, and relationships");
}
if (!/term_group/.test(source) || !/post_content_filtered/.test(publicationSource) || !/'taxonomies'\s*=>\s*\$taxonomy_assignments/.test(publicationSource)) {
	failures.push("menu deletion receipt must cover complete mutable term, item-post, metadata, and taxonomy-relationship state");
}
if (!/retire_managed_localized_menu[\s\S]*translation_job_begin_recovery_transaction[\s\S]*OPTION_LOCALIZED_MENU_IDENTITIES[\s\S]*wp_delete_nav_menu/.test(publicationSource)) {
	failures.push("previous-menu retirement must verify the exact active identity under a database lock before deletion");
}
if (!/previous_menu_surface_revision/.test(mainSource) || !/lock_localized_menu_projection_surface\( \$menu_id \)/.test(publicationSource)) {
	failures.push("previous-menu retirement must lock every item row/meta/relationship and match the exact captured previous-menu receipt");
}
if (!/expected_previous_revision[\s\S]*current_previous_revision[\s\S]*hash_equals/.test(publicationSource)) {
	failures.push("combined menu rollback preflight must compare the exact previous-menu receipt before reactivation");
}
if (!/translation_job_find_scoped_term_id_for_update/.test(source) || !/TERM_META_SOURCE_ID[\s\S]*FOR UPDATE/.test(source)) {
	failures.push("recovery must use an uncached locking read and identity-meta range locks for initially absent translated terms");
}
if (!/translation_job_resolve_publication_translation_id/.test(source) || !/if \( ! empty\( \$next\['success'\] \) \) \{ delete_post_meta/.test(jobSource)) {
	failures.push("publication retry must resolve an existing translation before snapshot and retain its attempt marker until final Job transition succeeds");
}
if (!/META_SOURCE_ID/.test(resolvePublicationTranslation) || !/META_LANGUAGE/.test(resolvePublicationTranslation) || !/source->post_type\s*===\s*\(string\) \$post->post_type/.test(resolvePublicationTranslation)) {
	failures.push("retry translation IDs and canonical fallback must match exact source type, source ID, and language ownership");
}
if (!/is_array\( \$surface_snapshot \)[\s\S]{0,180}rollback_expected_surface_revision/.test(jobSource) || /\$prepublication_cas_revision\s*=\s*self::translation_job_rollback_cas_revision/.test(jobSource)) {
	failures.push("phase-two publication must use the exact staged mutation receipt without rebasing after QA");
}
if (!/translation_job_restore_publication_snapshot[\s\S]*translation_job_lock_recovery_surface[\s\S]*lock_localized_menu_projection_surface[\s\S]*localized_menu_projection_rollback_preflight[\s\S]*translation_job_restore_surface_snapshot_uncommitted[\s\S]*rollback_localized_menu_projection_uncommitted[\s\S]*translation_job_commit_recovery_transaction/.test(source)) {
	failures.push("content, terms, active menu identity, and target menu deletion must recover in one shared transaction after both preflights pass");
}
if (!/menu_recovery_plan/.test(publicationSource) || /frontend_cache_adapter_missing[\s\S]{0,500}rollback_localized_menu_projection\(/.test(publicationSource)) {
	failures.push("publication failure must defer menu recovery to the caller's combined content-menu transaction");
}

if (failures.length > 0) {
	console.error(JSON.stringify({ success: false, failures }, null, 2));
	process.exit(1);
}

console.log(JSON.stringify({ success: true, contracts: 6 }));
