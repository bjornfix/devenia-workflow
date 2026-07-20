#!/usr/bin/env node
import { existsSync, readFileSync, readdirSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const jobSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authorityUrl = new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url);
const authoritySource = existsSync(authorityUrl) ? readFileSync(authorityUrl, "utf8") : "";
const publicationSource = readFileSync(new URL("../includes/trait-localized-presentation-publication.php", import.meta.url), "utf8");
const relationAuthoritySource = readFileSync(new URL("../includes/trait-public-header-relation-authority.php", import.meta.url), "utf8");
const commitReceiptUrl = new URL("../includes/trait-recovery-commit-reconciliation.php", import.meta.url);
const commitReceiptSource = existsSync(commitReceiptUrl) ? readFileSync(commitReceiptUrl, "utf8") : "";
const taxonomySource = readFileSync(new URL("../includes/trait-taxonomy-localization.php", import.meta.url), "utf8");
const atomicOptionSource = readFileSync(new URL("../includes/trait-atomic-option-store.php", import.meta.url), "utf8");
const mainSource = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const executionIdentitySource = readFileSync(new URL("../includes/trait-execution-identity.php", import.meta.url), "utf8");
const translationProvenanceSource = readFileSync(new URL("../includes/trait-translation-provenance.php", import.meta.url), "utf8");
const runtimeSource = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");
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
const restoreSurface = functionBody("translation_job_restore_surface_snapshot", "translation_job_reconcile_restore_commit_outcome");
const reconcileRestore = functionBody("translation_job_reconcile_restore_commit_outcome", "translation_job_restore_surface_snapshot_uncommitted");
const restorePublication = functionBody("translation_job_restore_publication_snapshot", "translation_job_reconcile_publication_restore_commit_outcome");
const reconcilePublicationRestore = functionBody("translation_job_reconcile_publication_restore_commit_outcome", "translation_job_publish_failure_with_rollback");
const rollbackFailure = functionBody("translation_job_publish_failure_with_rollback", "translation_job_finalize_publication_failure_response");
const finalizePublicationFailure = functionBody("translation_job_finalize_publication_failure_response", "translation_job_validate_quality_evidence_record");
const clearRecoveryIsolation = functionBody("translation_job_clear_recovery_next_isolation", "translation_job_recovery_savepoint_name");
const beginRecoveryTransaction = functionBody("translation_job_begin_recovery_transaction", "translation_job_commit_recovery_transaction");
const commitRecoveryTransaction = functionBody("translation_job_commit_recovery_transaction", "translation_job_rollback_recovery_transaction");
const rollbackRecoveryTransaction = functionBody("translation_job_rollback_recovery_transaction", "translation_job_lock_recovery_surface");
const stageArtifact = functionBody("translation_job_stage_artifact", "translation_job_surface_revision");

if (!/'source_design_hash'\s*=>\s*self::expected_source_design_signature_hash\(\s*\(string\) \$source->post_content, \$language \s*\)/.test(stageArtifact)) {
	failures.push("staged presentation must pin the target-language expected design signature so deterministic RTL mirroring can publish");
}
if (/source_design_contract\( \$source \)\['design_hash'\]/.test(stageArtifact)) {
	failures.push("staged presentation must not pin the untranslated LTR source design hash for RTL targets");
}

if (!/private static function reviewer_identity_matches_provenance\s*\(/.test(executionIdentitySource) || !/private static function reviewer_matches_any_provenance\s*\(/.test(executionIdentitySource)) {
	failures.push("shared reviewer provenance comparisons must remain owned by the execution-identity module");
}
if (!/reviewer_identity_matches_provenance\s*\(/.test(translationProvenanceSource) || !/reviewer_matches_any_provenance\s*\(/.test(mainSource)) {
	failures.push("translation provenance and Quality read models must use the shared execution-identity comparison boundary");
}

if (!beginRecoveryTransaction || (beginRecoveryTransaction.match(/\$wpdb->(?:posts|postmeta|terms|term_taxonomy|term_relationships|termmeta|options)/g) || []).length !== 7) {
	failures.push("recovery transaction must prove the exact seven mutable WordPress core tables");
}
if (!/information_schema\.TABLES/.test(beginRecoveryTransaction) || !/SHOW TABLE STATUS WHERE Name = %s/.test(beginRecoveryTransaction) || beginRecoveryTransaction.indexOf("core_table_non_transactional") > beginRecoveryTransaction.indexOf("SHOW TABLE STATUS")) {
	failures.push("engine preflight must use primary information_schema proof and fallback only after rejecting proven non-InnoDB tables");
}
if (!/translation_job_normalize_recovery_table_name/.test(beginRecoveryTransaction) || !/core_table_identity_mismatch/.test(beginRecoveryTransaction) || !/core_table_metadata_unavailable/.test(beginRecoveryTransaction)) {
	failures.push("engine preflight must normalize exact table identities and fail closed on incomplete fallback metadata");
}
if (!/owned_transaction_already_active/.test(beginRecoveryTransaction) || !/preexisting_or_unknown_transaction_refused/.test(beginRecoveryTransaction) || !/SET TRANSACTION ISOLATION LEVEL SERIALIZABLE/.test(beginRecoveryTransaction) || /SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE/.test(beginRecoveryTransaction) || !/START TRANSACTION/.test(beginRecoveryTransaction) || !/transaction_isolation['"]?\s*=>\s*['"]SERIALIZABLE/.test(beginRecoveryTransaction) || !/translation_job_recovery_savepoint_name/.test(beginRecoveryTransaction) || !/\$wpdb->prepare\( 'SAVEPOINT %i', \$savepoint \)/.test(beginRecoveryTransaction)) {
	failures.push("begin recovery must refuse outer transactions and verify an owned active SERIALIZABLE boundary");
}
for (const level of ["READ UNCOMMITTED", "READ COMMITTED", "REPEATABLE READ", "SERIALIZABLE"]) {
	if (!clearRecoveryIsolation.includes(`$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL ${level}' )`)) failures.push(`recovery isolation reset must use fixed literal SQL for ${level}`);
}
if (/\$level|SET TRANSACTION ISOLATION LEVEL ['"]?\s*\./.test(clearRecoveryIsolation)) {
	failures.push("recovery isolation reset must not interpolate an allowlisted SQL token");
}
if (/@@(?:session\.)?in_transaction/.test(authoritySource) || !/SELECT CONNECTION_ID\(\) AS connection_id/.test(authoritySource) || !/SHOW SESSION VARIABLES WHERE Variable_name IN \('transaction_isolation', 'tx_isolation'\)/.test(authoritySource)) {
	failures.push("transaction metadata must be portable across MySQL and MariaDB without probing in_transaction variables");
}
if (!/ReflectionObject\( \$wpdb \)/.test(authoritySource) || !/PHP_VERSION_ID < 80100/.test(authoritySource) || !/translation_job_disable_reconnect_retries/.test(beginRecoveryTransaction) || beginRecoveryTransaction.indexOf("translation_job_disable_reconnect_retries") > beginRecoveryTransaction.indexOf("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE")) {
	failures.push("owned recovery must disable wpdb reconnect retries before the first transaction SQL without PHP 8.5 reflection deprecations");
}
if (!/\$wpdb->prepare\( 'RELEASE SAVEPOINT %i', \$savepoint \)/.test(commitRecoveryTransaction) || !/\$wpdb->prepare\( 'SAVEPOINT %i', \$savepoint \)/.test(commitRecoveryTransaction) || !/COMMIT AND NO CHAIN NO RELEASE/.test(commitRecoveryTransaction) || !/\$after_commit = self::translation_job_recovery_session_metadata\(\)/.test(commitRecoveryTransaction) || !/\$receipt\['connection_id'\][\s\S]*\$after_commit\['connection_id'\]/.test(commitRecoveryTransaction) || !/commit_outcome_unknown/.test(commitRecoveryTransaction) || !/'committed'\s*=>\s*! empty\( \$rollback\['success'\] \) \? false : null/.test(commitRecoveryTransaction) || !/'rollback'\s*=>\s*\$rollback/.test(commitRecoveryTransaction)) {
	failures.push("commit must prove and refresh ownership, override completion_type, and propagate the exact failed-commit rollback outcome");
}
if (!/translation_job_reconnect_guard_active/.test(commitRecoveryTransaction) || !/translation_job_restore_reconnect_retries/.test(commitRecoveryTransaction) || !/translation_job_reconnect_guard_active/.test(rollbackRecoveryTransaction) || !/translation_job_restore_reconnect_retries/.test(rollbackRecoveryTransaction)) {
	failures.push("commit and rollback must prove the reconnect guard and restore it at every proven terminal boundary");
}
if (!/\$wpdb->prepare\( 'ROLLBACK TO SAVEPOINT %i', \$savepoint \)/.test(rollbackRecoveryTransaction) || !/ROLLBACK AND NO CHAIN NO RELEASE/.test(rollbackRecoveryTransaction) || !/transaction_ownership_lost/.test(rollbackRecoveryTransaction) || !/'rolled_back'\s*=>\s*false/.test(rollbackRecoveryTransaction)) {
	failures.push("rollback must never affect a transaction whose ownership receipt is missing or lost");
}
if (/'(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT) ' \. \$savepoint/.test(`${beginRecoveryTransaction}\n${commitRecoveryTransaction}\n${rollbackRecoveryTransaction}`)) {
	failures.push("savepoint SQL must use WordPress-native prepared identifier placeholders");
}
if (/devenia_workflow_recovery_owned/.test(`${beginRecoveryTransaction}\n${commitRecoveryTransaction}\n${rollbackRecoveryTransaction}`)) {
	failures.push("recovery ownership must not use a forgeable fixed savepoint identifier");
}
const portabilityRuntimeUrl = new URL("./check-recovery-transaction-portability-runtime.php", import.meta.url);
if (!existsSync(portabilityRuntimeUrl)) {
	failures.push("missing real wpdb recovery transaction portability/fault runtime proof");
} else {
	const portabilityRuntime = readFileSync(portabilityRuntimeUrl, "utf8");
	if (!/primary_missing_one/.test(portabilityRuntime) || !/1 === count\( \$show_queries \)/.test(portabilityRuntime) || !/0 === count\( \$show_queries \)/.test(portabilityRuntime)) failures.push("runtime must prove missing-only fallback and reject fallback after proven non-InnoDB metadata");
	if (!/INSERT INTO \{\$base->options\}/.test(portabilityRuntime) || !/Failed guard committed the caller-owned outer write/.test(portabilityRuntime)) failures.push("runtime must prove outer transaction preservation with a real uncommitted write");
	if (!/null === \$commit\['committed'\]/.test(portabilityRuntime) || !/connection_id_override/.test(portabilityRuntime) || !/preserving the ownership receipt/.test(portabilityRuntime)) failures.push("runtime must prove ambiguous COMMIT and lost-connection receipt preservation");
	if (!/reconnect_after_commit/.test(portabilityRuntime) || !/Truthy COMMIT followed by changed connection identity/.test(portabilityRuntime)) failures.push("runtime must prove wpdb reconnect/retry after truthy COMMIT remains an unknown outcome");
	if (!/First standard-wpdb boundary did not disable reconnect retries/.test(portabilityRuntime) || !/Second sequential standard-wpdb boundary could not acquire the restored guard/.test(portabilityRuntime)) failures.push("runtime must prove standard wpdb retry state is restored between sequential boundaries");
	if (!/mid_write_disconnect/.test(portabilityRuntime) || !/MID_WRITE_RECONNECT_BLOCKED/.test(portabilityRuntime) || !/Mid-write reconnect produced an autocommitted partial write/.test(portabilityRuntime)) failures.push("runtime must prove a mid-write disconnect is never reissued on an autocommit connection");
	if (!/Committed boundary overstated guard cleanup/.test(portabilityRuntime) || !/Rolled-back boundary lost database truth/.test(portabilityRuntime)) failures.push("runtime must preserve committed and rolled-back truth when reconnect-guard restoration fails");
}
if (/if \( ! self::translation_job_commit_recovery_transaction\(\) \)/.test(`${authoritySource}\n${publicationSource}`) || /\|\| ! self::translation_job_commit_recovery_transaction\(\)/.test(`${authoritySource}\n${publicationSource}`)) {
	failures.push("structured commit outcomes must never be reduced to truthy-array boolean tests");
}
const pluginRoot = fileURLToPath(new URL("../", import.meta.url));
const productionPhpFiles = [];
const collectProductionPhp = (directory) => {
	for (const entry of readdirSync(directory, { withFileTypes: true })) {
		if (entry.name.startsWith(".") || ["node_modules", "tests", "tools", "vendor"].includes(entry.name)) continue;
		const path = join(directory, entry.name);
		if (entry.isDirectory()) collectProductionPhp(path);
		else if (entry.isFile() && entry.name.endsWith(".php")) productionPhpFiles.push(path);
	}
};
collectProductionPhp(pluginRoot);
const commitCallOwners = [];
const indirectCommitReferences = [];
let commitDefinitionCount = 0;
const scanRecoveryCommitReferences = (value, path) => {
	const result = { calls: [], definitions: 0, indirect: [] };
	for (const match of value.matchAll(/\btranslation_job_commit_recovery_transaction\b/g)) {
		const prefix = value.slice(0, match.index);
		const nearbyPrefix = prefix.slice(-100);
		const suffix = value.slice(match.index + match[0].length);
		if (/private\s+static\s+function\s+$/.test(nearbyPrefix)) {
			result.definitions += 1;
			continue;
		}
		if (!/(?:self|static)::\s*$/.test(nearbyPrefix) || !/^\s*\(\s*\)/.test(suffix)) {
			result.indirect.push(`${path}:${value.slice(0, match.index).split("\n").length}`);
			continue;
		}
		const owners = [...prefix.matchAll(/private static function ([a-z0-9_]+)\s*\(/g)];
		result.calls.push(`${path}:${owners.at(-1)?.[1] ?? "outside_private_function"}`);
	}
	return result;
};
for (const path of productionPhpFiles) {
	const value = readFileSync(path, "utf8");
	const scanned = scanRecoveryCommitReferences(value, relative(pluginRoot, path));
	commitDefinitionCount += scanned.definitions;
	commitCallOwners.push(...scanned.calls);
	indirectCommitReferences.push(...scanned.indirect);
}
const scannerProbe = scanRecoveryCommitReferences("private static function translation_job_commit_recovery_transaction(): array { return []; }\nprivate static function injected_boundary(): void { static::translation_job_commit_recovery_transaction(); $method = 'translation_job_commit_recovery_transaction'; }", "scanner-probe.php");
if (1 !== scannerProbe.definitions || 1 !== scannerProbe.calls.length || !scannerProbe.calls[0].endsWith(":injected_boundary") || 1 !== scannerProbe.indirect.length) {
	failures.push("recovery COMMIT portfolio scanner must detect static calls and indirect/string references independently of exact self-call syntax");
}
const expectedCommitCallOwners = [
	"includes/trait-localized-presentation-publication.php:delete_staged_public_header_projections",
	"includes/trait-localized-presentation-publication.php:publish_localized_presentation",
	"includes/trait-localized-presentation-publication.php:replace_public_header_state_transaction",
	"includes/trait-localized-presentation-publication.php:stage_first_public_header_enrollment_transaction",
	"includes/trait-translation-job-quality-authority.php:translation_job_apply_staged_artifact",
	"includes/trait-translation-job-quality-authority.php:translation_job_capture_surface_snapshot",
	"includes/trait-translation-job-quality-authority.php:translation_job_restore_publication_snapshot",
	"includes/trait-translation-job-quality-authority.php:translation_job_restore_surface_snapshot",
].sort();
if (JSON.stringify(commitCallOwners.sort()) !== JSON.stringify(expectedCommitCallOwners)) {
	failures.push(`every production recovery COMMIT call site must remain in the audited reconciliation portfolio: ${commitCallOwners.join(",")}`);
}
if (1 !== commitDefinitionCount || indirectCommitReferences.length > 0) {
	failures.push(`recovery COMMIT must have one private definition and only direct self/static audited calls; indirect references: ${indirectCommitReferences.join(",")}`);
}
if (!commitReceiptSource || !/private static function translation_job_decode_recovery_commit_receipt\s*\(/.test(commitReceiptSource) || !/array_key_exists\( 'committed', \$commit \)/.test(commitReceiptSource) || !/missing_committed/.test(commitReceiptSource) || !/invalid_committed_type/.test(commitReceiptSource) || !/success_without_committed/.test(commitReceiptSource) || !/transaction_not_terminal/.test(commitReceiptSource) || !/transaction_still_owned_at_boundary/.test(commitReceiptSource)) {
	failures.push("every recovery boundary must share one strict receipt decoder that distinguishes missing committed from explicit null");
}
if (!/private static function translation_job_recovery_commit_adapter_receipt\s*\(/.test(commitReceiptSource) || !/recovery_commit_adapter_receipt_not_array/.test(commitReceiptSource) || !/adapter_receipt_type/.test(commitReceiptSource)) {
	failures.push("present non-array Adapter values must become malformed receipts instead of selecting the default COMMIT path");
}
if (!/private static function translation_job_require_recovery_commit_receipt\s*\(/.test(commitReceiptSource) || !/translation_job_rollback_recovery_transaction\(\)/.test(commitReceiptSource)) {
	failures.push("invalid recovery receipts must terminalize only the still-owned transaction before callers reconcile state");
}
if (!/require_once __DIR__ \. '\/includes\/trait-recovery-commit-reconciliation\.php'/.test(mainSource) || !/use Devenia_Workflow_Recovery_Commit_Reconciliation;/.test(mainSource)) {
	failures.push("the strict recovery receipt boundary must be loaded by the production plugin class");
}
const receiptConsumers = [
	"reconcile_public_header_state_commit_outcome",
	"delete_staged_public_header_projections",
	"publish_localized_presentation",
	"reconcile_first_public_header_enrollment_commit_outcome",
	"translation_job_apply_staged_artifact",
	"translation_job_reconcile_snapshot_commit_outcome",
	"translation_job_reconcile_restore_commit_outcome",
	"translation_job_reconcile_publication_restore_commit_outcome",
];
for (const owner of receiptConsumers) {
	const value = owner.startsWith("translation_job_") ? authoritySource : publicationSource;
	const start = value.indexOf(`private static function ${owner}`);
	const next = value.indexOf("\n\tprivate static function ", start + 1);
	const body = start >= 0 ? value.slice(start, next > start ? next : value.length) : "";
	if (!/translation_job_require_recovery_commit_receipt\( \$commit \)/.test(body)) failures.push(`${owner} must reject malformed recovery COMMIT receipts before state classification`);
}
const adapterReceiptConsumers = [
	"replace_public_header_state_transaction",
	"publish_localized_presentation",
	"stage_first_public_header_enrollment_transaction",
	"translation_job_apply_staged_artifact",
	"translation_job_capture_surface_snapshot",
	"translation_job_restore_surface_snapshot",
	"translation_job_restore_publication_snapshot",
];
for (const owner of adapterReceiptConsumers) {
	const value = owner.startsWith("translation_job_") ? authoritySource : publicationSource;
	const start = value.indexOf(`private static function ${owner}`);
	const next = value.indexOf("\n\tprivate static function ", start + 1);
	const body = start >= 0 ? value.slice(start, next > start ? next : value.length) : "";
	if (!/null === \$commit \? self::translation_job_commit_recovery_transaction\(\) : self::translation_job_recovery_commit_adapter_receipt\( \$commit \)/.test(body) || /! is_array\( \$commit \)/.test(body)) failures.push(`${owner} must reserve only the exact null sentinel for the default COMMIT path`);
}
const stagedCleanup = (() => {
	const start = publicationSource.indexOf("private static function delete_staged_public_header_projections");
	const end = publicationSource.indexOf("private static function public_header_state_excludes_staged_menu_ids", start + 1);
	return start >= 0 && end > start ? publicationSource.slice(start, end) : "";
})();
if (!/clear_public_header_state_option_cache\(\); clean_term_cache\( \$menu_id_list \);[\s\S]*\$all_deleted[\s\S]*true === \( \$commit\['success'\][\s\S]*true === \( \$commit\['committed'\][\s\S]*\$all_deleted/.test(stagedCleanup)) {
	failures.push("the sole destructive cleanup COMMIT special case must cache-evict, prove deletion, and require exact successful committed truth");
}
if (/['"]transaction_rolled_back['"]\s*=>\s*true|\[['"]transaction_rolled_back['"]\]\s*=\s*true/.test(`${authoritySource}\n${publicationSource}`)) {
	failures.push("callers must derive rollback truth from the structured terminal outcome");
}
if (!/'transaction_rolled_back'\s*=>\s*! empty\( \$outcome\['rolled_back'\] \)/.test(authoritySource)) {
	failures.push("rollback response truth must follow the database rollback fact independently of reconnect-guard cleanup");
}
if (!/translation_job_recovery_transaction_error_fields/.test(authoritySource) || !/translation_job_recovery_transaction_error_fields/.test(publicationSource) || /transaction_exception['"][^\n]*\$error->getMessage/.test(`${authoritySource}\n${publicationSource}`)) {
	failures.push("snapshot, staged apply, and presentation errors must expose safe structured diagnostics without raw exception text");
}

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

const desktopPolicy = authoritySource.match(/'desktop' === \$viewport \? array\(\s*(\d+),\s*(\d+),\s*(\d+)\s*\)/);
const mobilePolicy = authoritySource.match(/:\s*array\(\s*(390),\s*(844),\s*(1)\s*\)/);
const desktopFixture = runtimeSource.match(/'desktop'\s*=>\s*array\(\s*'width'\s*=>\s*(\d+),\s*'height'\s*=>\s*(\d+),\s*'device_scale_factor'\s*=>\s*(\d+)\s*\)/);
const mobileFixture = runtimeSource.match(/'mobile'\s*=>\s*array\(\s*'width'\s*=>\s*(\d+),\s*'height'\s*=>\s*(\d+),\s*'device_scale_factor'\s*=>\s*(\d+)\s*\)/);
const dimensions = (match) => match ? match.slice(1).map(Number) : [];
if (
	JSON.stringify(dimensions(desktopPolicy)) !== JSON.stringify([1140, 800, 1])
	|| JSON.stringify(dimensions(mobilePolicy)) !== JSON.stringify([390, 844, 1])
	|| JSON.stringify(dimensions(desktopFixture)) !== JSON.stringify(dimensions(desktopPolicy))
	|| JSON.stringify(dimensions(mobileFixture)) !== JSON.stringify(dimensions(mobilePolicy))
) {
	failures.push("runtime browser receipt fixture dimensions must exactly match the server Quality viewport policy (desktop 1140x800, mobile 390x844, DPR 1)");
}

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
if (!/add_post_meta\([\s\S]*wp_slash\( maybe_unserialize\( \$value \) \)/.test(restoreSnapshot) || !/translation_job_restore_term_snapshot[\s\S]*add_term_meta\([\s\S]*wp_slash\( maybe_unserialize\( \$value \) \)/.test(source)) {
	failures.push("rollback must preserve structured post and term metadata through WordPress metadata slashing");
}
if (!verifyApplied || !/route_canonical/.test(verifyApplied) || !/localized_parent_id/.test(verifyApplied) || !/array_key_exists\( 'featured_image_alt'/.test(verifyApplied) || !/translation_job_actual_taxonomy_surface/.test(verifyApplied)) {
	failures.push("already-applied equivalence must compare canonical route, parent, empty media values, and exact taxonomy surface");
}
if (!rollbackFailure || !/not_required_before_mutation/.test(rollbackFailure) || !/rollback_expected_surface_revision/.test(rollbackFailure) || !/missing_expected_mutation_revision/.test(rollbackFailure)) {
	failures.push("rollback must skip pre-mutation failures and require an expected mutation revision before restoring existing content");
}
if (!/rollback_not_authorized/.test(rollbackFailure) || !/foreign_surface_not_owned/.test(rollbackFailure) || rollbackFailure.indexOf("rollback_not_authorized") > rollbackFailure.indexOf("translation_job_restore_surface_snapshot")) {
	failures.push("an explicit publication-owner rollback denial must stop before every restore and preserve foreign state");
}
if (!/forward_publication_applied/.test(rollbackFailure) || !/pre_rollback_revision/.test(rollbackFailure) || !/hash_equals\( \$forward_revision, \$pre_rollback_revision \)/.test(rollbackFailure) || /\$failure\['published'\]/.test(rollbackFailure)) {
	failures.push("forward publication phase evidence must come from exact commit/CAS state and never from the legacy final published field");
}
if (!finalizePublicationFailure || finalizePublicationFailure.indexOf("translation_job_clean_recovery_caches") > finalizePublicationFailure.indexOf("translation_job_rollback_cas_revision") || !/'' !== \$observed_revision[\s\S]*'' !== \$restored_revision[\s\S]*hash_equals\( \$restored_revision, \$observed_revision \)/.test(finalizePublicationFailure) || !/'' !== \$observed_revision[\s\S]*'' !== \$forward_revision[\s\S]*hash_equals\( \$forward_revision, \$observed_revision \)/.test(finalizePublicationFailure)) {
	failures.push("final publication response must evict caches and classify a fresh non-empty complete-surface CAS against both restored and forward receipts");
}
if (!/restored_verified[\s\S]*restored_unverified[\s\S]*forward_verified[\s\S]*forward_unverified[\s\S]*foreign/.test(finalizePublicationFailure) || !/\$final_published = \$rollback_verified \? false : null/.test(finalizePublicationFailure) || !/\$final_published = \$forward_reader_verified \? true : null/.test(finalizePublicationFailure) || !/\$failure\['published'\] = \$final_published/.test(finalizePublicationFailure)) {
	failures.push("final publication response must expose verified reader truth and use null for restored or forward surfaces whose reader verification is incomplete");
}
if (/\$failure\['published'\][\s\S]*\$final_state\s*=/.test(finalizePublicationFailure) || !/clean_post_cache\( \$translation_id \)[\s\S]*\$failure\['translation'\] = \$current_post instanceof WP_Post \? self::translation_payload\( \$current_post \) : null/.test(finalizePublicationFailure)) {
	failures.push("final response classification must ignore the incoming published field and rebuild the translation payload only after rollback cache eviction");
}
if (!/'forward_publication_applied' => true[\s\S]*'needs_live_verification' => \$needs_verification[\s\S]*'final_reader_state'[\s\S]*'published_unverified'/.test(jobSource)) {
	failures.push("successful Translation Job publication must expose explicit forward-phase and unverified reader state until the separate live-verification operation passes");
}
if (/rollback_expected_surface_revision'\]\s*=\s*\(string\) \( \$publication\['mutation_cas_revision'\]/.test(jobSource) || !/true === \( \$publication\['rollback_authorized'\]/.test(jobSource) || !/rollback_expected_surface_revision'\]\s*=\s*\(string\) \( \$publication\['rollback_expected_surface_revision'\]/.test(jobSource) || !/false === \( \$publication\['rollback_authorized'\]/.test(jobSource)) {
	failures.push("Translation Job must consume only explicit rollback authority from Localized Presentation Publication and never promote diagnostic mutation revisions");
}
if (/\$staged_apply\['mutation_surface_revision'\]/.test(jobSource) || !/true === \( \$staged_apply\['rollback_authorized'\]/.test(jobSource) || !/rollback_expected_surface_revision'\]\s*=\s*\(string\) \( \$staged_apply\['rollback_expected_surface_revision'\]/.test(jobSource) || !/false === \( \$staged_apply\['rollback_authorized'\]/.test(jobSource)) {
	failures.push("Translation Job must consume only explicit staged-apply rollback authority and never promote an observed mutation revision");
}
if (!rollbackCas || !/post_author/.test(rollbackCas) || !/post_date/.test(rollbackCas) || !/post_modified/.test(rollbackCas) || !/get_post_meta/.test(rollbackCas) || !/taxonomies/.test(rollbackCas)) {
	failures.push("rollback compare-and-swap revision must cover all owned post fields, metadata, and taxonomies");
}
if (!captureSnapshot || !/translation_job_begin_recovery_transaction/.test(captureSnapshot) || !/translation_job_lock_recovery_surface/.test(captureSnapshot) || !/translation_job_capture_surface_snapshot_uncommitted/.test(captureSnapshot) || !/translation_job_commit_recovery_transaction/.test(captureSnapshot)) {
	failures.push("the complete pre-mutation snapshot must be captured inside one row-locked transaction");
}
if (!/devenia_workflow_translation_job_snapshot_commit_adapter_result/.test(captureSnapshot) || !/translation_job_reconcile_snapshot_commit_outcome/.test(captureSnapshot) || !/captured_revision[\s\S]*observed_revision[\s\S]*'current'[\s\S]*'foreign'/.test(captureSnapshot)) {
	failures.push("read-only snapshot commit truth must be accepted only while the exact captured CAS remains current after cache eviction");
}
if (!restoreSurface || !/devenia_workflow_translation_job_restore_commit_adapter_result/.test(restoreSurface) || !/translation_job_reconcile_restore_commit_outcome/.test(restoreSurface) || /if \( empty\( \$commit\['success'\] \) \)/.test(restoreSurface)) {
	failures.push("surface restore must send every commit receipt, including success=true, through exact reconciliation");
}
if (!reconcileRestore || !/translation_job_clean_recovery_caches[\s\S]*observed_revision/.test(reconcileRestore) || !/pre_restore_exact[\s\S]*restored_exact/.test(reconcileRestore) || !/true === \$committed \|\| null === \$committed/.test(reconcileRestore) || !/false === \$committed \|\| null === \$committed/.test(reconcileRestore) || !/state_outcome' => 'foreign'/.test(reconcileRestore)) {
	failures.push("surface restore reconciliation must classify exact applied, exact unapplied, and foreign state for the three-valued commit receipt");
}
if (!restorePublication || !/devenia_workflow_translation_job_publication_restore_commit_adapter_result/.test(restorePublication) || !/translation_job_reconcile_publication_restore_commit_outcome/.test(restorePublication) || /if \( empty\( \$commit\['success'\] \) \)/.test(restorePublication) || !reconcilePublicationRestore || !/menu_pre_restore_exact[\s\S]*menu_restored_exact[\s\S]*state_outcome' => 'foreign'/.test(reconcilePublicationRestore)) {
	failures.push("combined content and menu restore must reconcile every receipt against exact content plus menu state");
}
if (!/restore_commit_applied_true_and_unknown_public_publish/.test(runtimeSource) || !/restore_commit_foreign_true_and_unknown_public_publish/.test(runtimeSource)) {
	failures.push("public Translation Job runtime must cover applied and foreign restore commits for true and unknown receipts");
}
if (!/restored_verified/.test(runtimeSource) || !/restored_unverified/.test(runtimeSource) || !/'foreign' !== \(string\) \( \$restore_publish\['final_reader_state'\]\['state'\]/.test(runtimeSource) || !/forward_publication_applied/.test(runtimeSource) || !/\$runtime_meta_status_key[\s\S]*\$invalid_restore_response_translation\['translation_status'\]/.test(runtimeSource)) {
	failures.push("real WordPress runtime must bind successful, unverified, and foreign rollback responses to final reader state plus a freshly reread translation payload");
}
if (!/staged_committed_true_applied[\s\S]*staged_committed_unknown_applied/.test(runtimeSource) || !/staged_committed_true_foreign[\s\S]*staged_committed_unknown_foreign/.test(runtimeSource) || !/devenia_workflow_staged_artifact_commit_adapter_result/.test(runtimeSource) || !/cached_excerpt_before_direct_write/.test(runtimeSource) || !/rollback_invalidations_before/.test(runtimeSource)) {
	failures.push("public Translation Job runtime must prove staged-apply true/unknown applied compensation, uncached foreign preservation, and rollback invalidation behavior");
}
const commitMatrixStart = runtimeSource.indexOf("$run_commit_reconciliation_matrices = static function");
const commitMatrixEnd = runtimeSource.indexOf("\n\t};\n\t$pre_publish_concurrency_job", commitMatrixStart);
const commitMatrixSource = commitMatrixStart >= 0 && commitMatrixEnd > commitMatrixStart ? runtimeSource.slice(commitMatrixStart, commitMatrixEnd) : "";
const restoreCommittedPresenceChecks = [...commitMatrixSource.matchAll(/array_key_exists\( 'committed', \$restore_commit_reconciliation \)/g)].length;
const restoreCommittedIdentityChecks = [...commitMatrixSource.matchAll(/\$restore_expectation\['committed'\] !== \$restore_commit_reconciliation\['committed'\]/g)].length;
if (!commitMatrixSource || /\[['"](?:committed|published)['"]\]\s*\?\?/.test(commitMatrixSource) || restoreCommittedPresenceChecks !== 2 || restoreCommittedIdentityChecks !== 2) {
	failures.push("every contract-significant commit-matrix tri-state must require field presence plus direct identity and must never use null coalescing");
}
const commitMatrixOccurrences = [...runtimeSource.matchAll(/\$run_commit_reconciliation_matrices/g)].map((match) => match.index);
const finalCorrectionPass = runtimeSource.indexOf("$post_publish_final_quality = $call(");
const finalCorrectionMedia = runtimeSource.indexOf("$post_publish_matrix_thumbnail_rows =");
if (commitMatrixOccurrences.length !== 2 || commitMatrixOccurrences[0] < 0 || commitMatrixOccurrences[1] <= finalCorrectionPass || commitMatrixOccurrences[1] <= finalCorrectionMedia || !/\$quality_payload\( \$post_publish_final_quality_claim, \$post_publish_final_artifact_revision, 'pass'/.test(runtimeSource) || !/array\( \$source_thumbnail_id \) !== \$post_publish_matrix_thumbnail_rows/.test(runtimeSource) || !/writer_principal[\s\S]*post_publish_final_quality\['quality_decision'\]\['reviewer_principal'\]/.test(runtimeSource) || !/\$post_publish_matrix_header_complete[\s\S]*'1' !== \(string\) \$post_publish_matrix_header\['enrollment'\][\s\S]*'__workflow_missing__' !== \$post_publish_matrix_header\['pending'\]/.test(runtimeSource)) {
	failures.push("commit-reconciliation matrices must run only after a fresh post-publish artifact has a separate passing Quality principal and one exact valid thumbnail authority");
}
if (!/\$build_runtime_correction_artifact\s*=\s*static function[\s\S]*\$packet\['fragments'\][\s\S]*\$packet\['links'\][\s\S]*\$link\['target_url'\][\s\S]*translation_job_anchor_hrefs[\s\S]*expected_target_urls[\s\S]*consumed_target_urls/.test(runtimeSource) || !/\$build_runtime_correction_artifact\( \$post_publish_packet, 'Runtime translated title corrected after browser QA' \)/.test(runtimeSource) || !/\$build_runtime_correction_artifact\( \$post_publish_final_packet, 'Runtime translated title approved after browser QA' \)/.test(runtimeSource) || !/\$post_publish_artifact_build\['expected_target_urls'\][\s\S]*\$post_publish_artifact_build\['consumed_target_urls'\]/.test(runtimeSource) || !/\$post_publish_final_artifact_build\['expected_target_urls'\][\s\S]*\$post_publish_final_artifact_build\['consumed_target_urls'\]/.test(runtimeSource) || /\$post_publish_artifact\s*=\s*\$artifact|\$post_publish_final_artifact\s*=\s*\$post_publish_artifact/.test(runtimeSource)) {
	failures.push("each published-correction Artifact must materialize every authoritative target from its own fresh bounded packet instead of copying stale Artifact links");
}
if (!/\$runtime_header_activation\s*=\s*\$call\([\s\S]*?'sync_public_header_projection'[\s\S]*?'activation_receipt'\s*=>\s*\(string\) \$runtime_manifest\['activation_receipt'\]/.test(runtimeSource) || !/runtime_header_activation\['verification'\]\['passed'\]/.test(runtimeSource) || !/public_header_enrollment_before/.test(runtimeSource) || !/thumbnail_srcset/.test(runtimeSource)) {
	failures.push("Translation Job runtime must establish and restore a complete managed Public Header projection and render exact hero, Open Graph, and srcset media before publication matrices");
}
if (!/\$runtime_header_activation\s*=\s*\$call\([\s\S]*?\$runtime_header_identities\s*=\s*get_option\([\s\S]*?foreach \( \(array\) \( \$runtime_header_activation\['projections'\][\s\S]*?foreach \( \(array\) \$runtime_header_identities[\s\S]*?throw new RuntimeException\( 'Could not activate the complete runtime Public Header Projection/.test(runtimeSource)) {
	failures.push("Translation Job runtime must record every generated complete-set menu before any activation RED can reach finally cleanup");
}
if (!/\$runtime_languages\s*=\s*Devenia_Workflow::languages\( true \)/.test(runtimeSource) || !/\$runtime_header_source_insert\s*=\s*wp_insert_post/.test(runtimeSource) || !/foreach \( array_keys\( \$call\( 'target_languages' \) \) as \$runtime_header_language \)/.test(runtimeSource) || !/_devenia_translation_source_id', \$runtime_header_source_id/.test(runtimeSource) || !/sync_translation_index_row', \$runtime_header_translation_id/.test(runtimeSource) || !/foreach \( array_keys\( \$runtime_languages \) as \$runtime_header_language \)/.test(runtimeSource) || !/array_unique\( array_values\( \$runtime_header_translation_ids_by_language \) \)/.test(runtimeSource) || !/wp_delete_post\( \$runtime_header_source_id, true \)/.test(runtimeSource) || /\$runtime_languages\s*=\s*array\(\s*\$runtime_source_language_code/.test(runtimeSource)) {
	failures.push("Translation Job runtime must honor the effective default-overlay language registry and provision plus clean every complete-set target fixture data-driven");
}
if (!/\$show_on_front_before\s*=\s*get_option\( 'show_on_front', \$runtime_option_missing \)/.test(runtimeSource) || !/\$page_on_front_before\s*=\s*get_option\( 'page_on_front', \$runtime_option_missing \)/.test(runtimeSource) || !/\$page_for_posts_before\s*=\s*get_option\( 'page_for_posts', \$runtime_option_missing \)/.test(runtimeSource) || !/update_option\( 'show_on_front', 'page', false \)[\s\S]*update_option\( 'page_on_front', \$runtime_header_source_id, false \)[\s\S]*update_option\( 'page_for_posts', \$runtime_header_blog_source_id, false \)/.test(runtimeSource) || !/_devenia_translation_source_id', \$runtime_header_blog_source_id/.test(runtimeSource) || !/sync_translation_index_row', \$runtime_header_blog_translation_id/.test(runtimeSource) || !/\$runtime_header_blog_surface_id[\s\S]*public_blog_archive_url_for_language/.test(runtimeSource) || !/array_unique\( array_values\( \$runtime_header_blog_translation_ids_by_language \) \)/.test(runtimeSource) || !/wp_delete_post\( \$runtime_header_blog_source_id, true \)/.test(runtimeSource) || !/'show_on_front' => \$show_on_front_before[\s\S]*'page_on_front' => \$page_on_front_before[\s\S]*'page_for_posts' => \$page_for_posts_before[\s\S]*delete_option\( \$runtime_option_key \)[\s\S]*update_option\( \$runtime_option_key, \$runtime_option_before, false \)/.test(runtimeSource)) {
	failures.push("Translation Job runtime must derive every homepage and blog URL from isolated real WordPress front/posts settings, indexed target relations, and exact option restoration");
}
if (!/translation_job_clean_recovery_caches[\s\S]*wp_cache_delete\( \$translation_id, 'post_meta' \)/.test(source) || !/translation_job_clean_term_caches[\s\S]*wp_cache_delete\( \$term_id, 'term_meta' \)/.test(source)) {
	failures.push("committed and rolled-back recovery boundaries must invalidate post-meta and term-meta object caches");
}
if (!captureSnapshot || !/translation_job_rollback_cas_revision\( 0, \$term_snapshot\['terms'\], \$identity_scope \)/.test(captureSnapshot) || !/translation_job_lock_recovery_surface\( \$translation_id, \$term_scope, \$identity_scope \)/.test(captureSnapshot)) {
	failures.push("a new-candidate snapshot must bind absent canonical identity and existing global terms inside the locked precondition");
}
if (!applyStaged || !/\$mutation_cas_revision\s*=\s*self::translation_job_rollback_cas_revision/.test(applyStaged) || applyStaged.indexOf("$mutation_cas_revision = self::translation_job_rollback_cas_revision") > applyStaged.indexOf("devenia_workflow_staged_artifact_commit_adapter_result")) {
	failures.push("the staged mutation receipt must be captured under lock before transaction commit, including already-applied retries");
}
if (!applyStaged || !/devenia_workflow_staged_artifact_commit_adapter_result[\s\S]*translation_job_clean_recovery_caches[\s\S]*observed_mutation_revision/.test(applyStaged)) {
	failures.push("staged apply must reconcile its three-valued commit receipt only after cache eviction and an exact public-surface read");
}
if (!applyStaged || !/staged_publication_transaction_commit_outcome_unknown_unapplied[\s\S]*'mutation_started'\s*=>\s*false/.test(applyStaged) || !/rollback_expected_surface_revision[\s\S]*'state_outcome'\]\s*=\s*'applied'/.test(applyStaged)) {
	failures.push("staged apply must distinguish proven unapplied state from an exact owned applied replacement");
}
if (!applyStaged || !/\$exception_before_exact\s*&&\s*\( false === \$exception_committed \|\| null === \$exception_committed \)/.test(applyStaged)) {
	failures.push("staged-apply exception reconciliation must not classify a committed=true exact pre-state as proven unapplied");
}
if (!applyStaged || !/staged_publication_transaction_commit_reconciliation_conflict[\s\S]*'mutation_cas_revision'\s*=>\s*''[\s\S]*'observed_mutation_cas_revision'[\s\S]*'rollback_authorized'\s*=>\s*false/.test(applyStaged)) {
	failures.push("foreign staged-apply state must keep its observed revision diagnostic and explicitly deny rollback authority");
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
requireMatch(/translation_job_acquire_lifecycle_lease[\s\S]*translation_job_lifecycle_lease_conflict/, "claim and publication must serialize lifecycle mutation with one atomic lease");
if (!/hash\( 'sha256', \$source_id \. '\|' \. \$language \)/.test(source) || !/translation_job_renew_lifecycle_lease/.test(source) || !/translation_job_acquire_lifecycle_lease\( \$initial_job, 'claim' \)/.test(source) || !/translation_job_acquire_lifecycle_lease\( \$initial_job, 'publish' \)/.test(source)) {
	failures.push("the lifecycle lease must bind the exact source/language, cover claim and publish, and renew before slow public checks");
}
if (!/atomic_replace_option_value/.test(atomicOptionSource) || !/atomic_delete_option_value/.test(atomicOptionSource) || !/atomic_delete_option_value\( \$key, \$owned \)/.test(source)) {
	failures.push("lease takeover, renewal, and release must use compare-and-swap storage operations");
}
if (!/refresh_public_header_projection_for_publication/.test(publicationSource) || !/public_header_failure_after_activation/.test(publicationSource) || !/public_header_rollback_projection_receipts/.test(publicationSource)) {
	failures.push("localized publication must use the complete Public Header Projection Interface with receipt-bound rollback");
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
if (stagedRecoveryPropagationCount < 5 || !/prior_mutation_cas_revision/.test(publicationSource)) {
	failures.push("every early second-phase publication failure must preserve the prior committed staged-mutation recovery receipt");
}
if (!/number'\s*=>\s*2/.test(taxonomySource) || !/duplicate_localized_taxonomy_term_identity/.test(taxonomySource)) {
	failures.push("normal taxonomy mutation lookup must reject duplicate translated-term identities");
}
if (!/ensure_parent_path[\s\S]*localized_parent_path_missing/.test(mainSource) || /private static function ensure_parent_path[\s\S]{0,1800}wp_insert_post/.test(mainSource)) {
	failures.push("shared localized-parent staging must resolve existing hierarchy without creating placeholder posts");
}
if (!/menu_identity_activation/.test(publicationSource) || !/atomic_replace_option_value/.test(publicationSource) || !/rollback_localized_menu_projection_uncommitted/.test(publicationSource)) {
	failures.push("menu recovery must restore the exact prior identity through CAS inside an atomic deletion transaction");
}
if (!/localized_menu_projection_revision/.test(publicationSource) || !/target_menu_changed_after_projection/.test(publicationSource)) {
	failures.push("menu rollback must bind target deletion to an exact after-receipt for term, items, metadata, and relationships");
}
if (!/term_group/.test(source) || !/post_content_filtered/.test(publicationSource) || !/'taxonomies'\s*=>\s*\$taxonomy_assignments/.test(publicationSource)) {
	failures.push("menu deletion receipt must cover complete mutable term, item-post, metadata, and taxonomy-relationship state");
}
if (/private static function (?:activate_localized_menu_id|retire_managed_localized_menu)\s*\(/.test(publicationSource) || /retire_previous|menu_identity_activation/.test(mainSource.slice(mainSource.indexOf("private static function sync_language_menu"), mainSource.indexOf("private static function existing_menu_label_map")))) {
	failures.push("single-language projection must not bypass the atomic complete-set activation and logical retirement Interface");
}
if (!/previous_menu_surface_revision/.test(mainSource) || !/lock_localized_menu_projection_surface\( (?:\(int\) )?(?:\$menu_id|\(int\) \$row\['menu_id'\]) \)/.test(`${publicationSource}\n${relationAuthoritySource}`)) {
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
if (!/is_array\( \$surface_snapshot \)[\s\S]{0,180}publication_expected_surface_revision/.test(jobSource) || !/\$surface_snapshot\['publication_expected_surface_revision'\]\s*=\s*\(string\) \( \$staged_apply\['mutation_cas_revision'\]/.test(jobSource) || /\$prepublication_cas_revision\s*=\s*self::translation_job_rollback_cas_revision/.test(jobSource)) {
	failures.push("phase-two publication must use the exact staged mutation receipt without rebasing after QA");
}
if (!/translation_job_restore_publication_snapshot[\s\S]*translation_job_lock_recovery_surface[\s\S]*lock_localized_menu_projection_surface[\s\S]*localized_menu_projection_rollback_preflight[\s\S]*translation_job_restore_surface_snapshot_uncommitted[\s\S]*rollback_localized_menu_projection_uncommitted[\s\S]*translation_job_commit_recovery_transaction/.test(source)) {
	failures.push("content, terms, active menu identity, and target menu deletion must recover in one shared transaction after both preflights pass");
}
if (!/publication_failure_with_public_header_rollback/.test(publicationSource) || /menu_recovery_plan/.test(publicationSource)) {
	failures.push("publication follow-up failure must use verified all-language Public Header Projection rollback rather than the retired one-language recovery plan");
}

if (failures.length > 0) {
	console.error(JSON.stringify({ success: false, failures }, null, 2));
	process.exit(1);
}

console.log(JSON.stringify({ success: true, contracts: 6 }));
