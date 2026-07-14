#!/usr/bin/env node
import { readFileSync } from "node:fs";

const main = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const authority = readFileSync(new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url), "utf8");
const runtime = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");
const failures = [];

const functionBody = (source, name, nextName) => {
	const start = source.indexOf(`private static function ${name}`);
	const nextPrivate = nextName ? source.indexOf(`private static function ${nextName}`, start + 1) : -1;
	const nextPublic = nextName ? source.indexOf(`public static function ${nextName}`, start + 1) : -1;
	const endCandidates = [nextPrivate, nextPublic].filter((value) => value > start);
	const end = endCandidates.length ? Math.min(...endCandidates) : source.length;
	return start >= 0 && end > start ? source.slice(start, end) : "";
};

const effective = functionBody(main, "effective_translation_canonical_route", "store_translation_canonical_route");
const store = functionBody(main, "store_translation_canonical_route", "handle_explicit_translation_url_migration");
const stage = functionBody(authority, "translation_job_stage_artifact", "translation_job_surface_revision");
const apply = functionBody(authority, "translation_job_apply_staged_artifact_uncommitted", "translation_job_verify_applied_surface");
const verify = functionBody(authority, "translation_job_verify_applied_surface", "translation_job_expected_taxonomy_surface");

if (!effective) failures.push("missing deterministic effective Canonical Route Contract resolver");
if (!effective.includes("META_CANONICAL_ROUTE") || !effective.includes("META_LOCALIZED_PATH")) failures.push("effective route must own established and legacy localized route inputs");
if (!/\$route = ! \$replace[\s\S]*'' !== \$existing_path[\s\S]*\? \$existing/.test(effective)) failures.push("established Canonical Route Contracts must remain immutable");
if (/gmdate|microtime|current_time|time\s*\(/.test(effective)) failures.push("effective legacy route must not contain volatile time input");
if (!effective.includes("get_permalink( $post )") || !effective.includes("normalized_url_path( $observed_url )")) failures.push("effective route must observe the current normalized WordPress permalink before staging");
if (!effective.includes("canonical_route_observed_path_mismatch") || !effective.includes("canonical_route_observation_unavailable")) failures.push("effective route must fail closed on missing or mismatched observed route evidence");
if (!/\$route_path !== \$observed_path[\s\S]*\$localized_path !== \$observed_path/.test(effective)) failures.push("canonical and stored localized paths must both equal the observed permalink path");
for (const field of ["translation_id", "language", "post_name", "post_parent", "localized_path", "url", "path"]) {
	if (!effective.includes(`'${field}'`)) failures.push(`effective legacy route is missing ${field}`);
}
if (!store.includes("effective_translation_canonical_route") || !/empty\( \$resolution\['success'\] \)[\s\S]*return \$resolution/.test(store)) failures.push("route storage must consume and fail closed on the shared effective contract");
if (/gmdate|established_at/.test(store)) failures.push("route storage must not append volatile establishment data after staging");
if (!stage.includes("$canonical_route_resolution") || !stage.includes("return $canonical_route_resolution") || !stage.includes("'canonical_route'=> (array) $canonical_route_resolution['route']")) failures.push("artifact staging must reject parity failure before signing the shared effective contract");
if (!apply.includes("$locked_route_resolution") || !apply.includes("staged_canonical_route_drifted") || !apply.includes("'mutation_started' => false")) failures.push("locked apply must revalidate exact observed route parity before public mutation");
if (!verify.includes("effective_translation_canonical_route") || !verify.includes("metadata_exists") || !verify.includes("stored_canonical_route")) failures.push("applied verification must require the exact persisted effective contract");
if (!verify.includes("$failed[] = 'route_canonical'")) failures.push("canonical mismatch must retain the fail-closed route_canonical classification");
if (!runtime.includes("delete_post_meta( $translation_id, '_devenia_translation_canonical_route_v1' )")) failures.push("runtime must begin with a real published legacy translation missing canonical meta");
for (const proof of [
	"legacy_effective_resolution_repeat",
	"route_mismatch_job_before",
	"route_mismatch_run_before",
	"route_mismatch_claim_before",
	"canonical_route_mismatch_rejected_before_artifact_job_or_public_mutation",
	"nested_route_resolution",
	"nested_observed_wordpress_route_accepted_without_route_hardcoding",
	"legacy_staged_record",
	"legacy_rollback_result",
	"published_signed_route",
	"published_stored_route",
	"legacy_canonical_route_staged_backfilled_verified_without_url_migration",
	"legacy_canonical_route_backfill_rollback_restored_absent_meta",
]) {
	if (!runtime.includes(proof)) failures.push(`runtime is missing ${proof} proof`);
}

if (failures.length) {
	console.error(`Canonical route parity contract failed (${failures.length}):`);
	for (const failure of failures) console.error(`- ${failure}`);
	process.exit(1);
}

console.log("Canonical route parity contract passed (staging/apply/verify parity, immutable established route, exact persistence, rollback, no migration).");
