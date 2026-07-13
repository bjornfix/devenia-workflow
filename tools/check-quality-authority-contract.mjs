#!/usr/bin/env node
import { existsSync, readFileSync } from "node:fs";

const jobSource = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const authorityUrl = new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url);
const authoritySource = existsSync(authorityUrl) ? readFileSync(authorityUrl, "utf8") : "";
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

if (failures.length > 0) {
	console.error(JSON.stringify({ success: false, failures }, null, 2));
	process.exit(1);
}

console.log(JSON.stringify({ success: true, contracts: 6 }));
