#!/usr/bin/env node
import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const pluginRoot = fileURLToPath(new URL("../", import.meta.url));

const productionPhpFiles = (path) => {
	if (statSync(path).isFile()) {
		return path.endsWith(".php") ? [path] : [];
	}
	return readdirSync(path).flatMap((entry) => productionPhpFiles(join(path, entry)));
};

const forbiddenExternalRuntimeCouplings = [
	["GitHub Actions or dispatch endpoint", /https?:\/\/(?:api\.)?github\.com\/[^\s"'<>]*(?:\/actions(?:[\/?#]|$)|\/dispatches(?:[\/?#]|$))/i],
	["GitHub Checks or commit-status endpoint", /https?:\/\/(?:api\.)?github\.com\/[^\s"'<>]*(?:\/check-(?:runs|suites)|\/checks(?:[\/?#]|$)|\/statuses(?:[\/?#]|$)|\/commits\/[^\s"'<>/]+\/status(?:[\/?#]|$))/i],
	["GitHub Actions artifact host", /https?:\/\/(?:[^\s"'<>]+\.)?actions\.githubusercontent\.com(?:[\/?#]|$)/i],
	["GitHub Actions workflow path", /(?:^|[^A-Za-z0-9_.-])\.github\/workflows(?:[\/?#]|$)/i],
	["GitHub Actions dispatch identifier", /(?:^|[^A-Za-z0-9_])(?:workflow_dispatch|repository_dispatch|workflow_run)(?:[^A-Za-z0-9_]|$)/],
	["CI environment read", /\b(?:getenv|defined|constant)\s*\(\s*["'](?:CI|CONTINUOUS_INTEGRATION|(?:GITHUB|ACTIONS|RUNNER)_[A-Z0-9_]+)["']/],
	["CI environment superglobal read", /\$_(?:ENV|SERVER)\s*\[\s*["'](?:CI|CONTINUOUS_INTEGRATION|(?:GITHUB|ACTIONS|RUNNER)_[A-Z0-9_]+)["']\s*\]/],
];

const externalRuntimeCouplingFindings = (source) => {
	const foldedAdjacentPhpStrings = source.replace(/(["'])\s*\.\s*(["'])/g, "");
	return forbiddenExternalRuntimeCouplings
	.filter(([, pattern]) => pattern.test(source) || pattern.test(foldedAdjacentPhpStrings))
	.map(([name]) => name);
};

const hasPhpBacktickOperator = (source) => {
	let index = 0;
	let state = source.includes("<?") ? "outside" : "code";
	let quote = "";
	let heredocLabel = "";
	while (index < source.length) {
		if (state === "outside") {
			const phpOpen = source.indexOf("<?", index);
			if (phpOpen < 0) return false;
			index = phpOpen + 2;
			state = "code";
			continue;
		}
		if (state === "line-comment") {
			if (source[index] === "\n") state = "code";
			index += 1;
			continue;
		}
		if (state === "block-comment") {
			if (source[index] === "*" && source[index + 1] === "/") {
				state = "code";
				index += 2;
			} else {
				index += 1;
			}
			continue;
		}
		if (state === "quoted") {
			if (source[index] === "\\") {
				index += 2;
			} else if (source[index] === quote) {
				state = "code";
				index += 1;
			} else {
				index += 1;
			}
			continue;
		}
		if (state === "heredoc") {
			const lineEnd = source.indexOf("\n", index);
			const end = lineEnd < 0 ? source.length : lineEnd;
			const line = source.slice(index, end);
			const terminator = line.match(new RegExp(`^[\\t ]*${heredocLabel}(?![A-Za-z0-9_])`));
			if (terminator) {
				state = "code";
				index += terminator[0].length;
			} else {
				index = lineEnd < 0 ? source.length : lineEnd + 1;
			}
			continue;
		}

		if (source[index] === "?" && source[index + 1] === ">") {
			state = "outside";
			index += 2;
			continue;
		}
		if (source[index] === "/" && source[index + 1] === "/") {
			state = "line-comment";
			index += 2;
			continue;
		}
		if (source[index] === "#" && source[index + 1] !== "[") {
			state = "line-comment";
			index += 1;
			continue;
		}
		if (source[index] === "/" && source[index + 1] === "*") {
			state = "block-comment";
			index += 2;
			continue;
		}
		if (source[index] === "'" || source[index] === '"') {
			quote = source[index];
			state = "quoted";
			index += 1;
			continue;
		}
		if (source.startsWith("<<<", index)) {
			const lineEnd = source.indexOf("\n", index);
			const headerEnd = lineEnd < 0 ? source.length : lineEnd;
			const header = source.slice(index + 3, headerEnd).trim();
			const match = header.match(/^(["']?)([A-Za-z_][A-Za-z0-9_]*)\1$/);
			if (match) {
				heredocLabel = match[2];
				state = "heredoc";
				index = lineEnd < 0 ? source.length : lineEnd + 1;
				continue;
			}
		}
		if (source.charCodeAt(index) === 96) return true;
		index += 1;
	}
	return false;
};

const productionExternalRuntimeCouplingFindings = (source) => [
	...externalRuntimeCouplingFindings(source),
	...(/github/i.test(source) ? ["GitHub host or identifier in production PHP"] : []),
	...(/\b(?:GH_TOKEN|GH_ENTERPRISE_TOKEN)\b/i.test(source) ? ["GitHub token identifier in production PHP"] : []),
	...(/\b(?:shell_exec|exec|system|passthru|popen|proc_open|pcntl_exec)\s*\(/i.test(source) ? ["external process API in production PHP"] : []),
	...(hasPhpBacktickOperator(source) ? ["PHP backtick execution operator in production PHP"] : []),
];

for (const fixture of [
	"Plugin URI: https://github.com/bjornfix/devenia-workflow",
	"Update URI: https://downloads.devenia.com/devenia-workflow/",
	"https://github.com/bjornfix/devenia-workflow/releases/download/v0.1.615/devenia-workflow.zip",
]) {
	assert.deepEqual(externalRuntimeCouplingFindings(fixture), [], "release and updater distribution metadata must not be mistaken for CI runtime authority");
}
for (const fixture of [
	"$host = 'https://api.github.com'; $path = '/repos/o/r/actions/runs'; wp_remote_get( $host . $path );",
	"wp_remote_get( 'https://api.github.com/repos/o/r/' /* stable */ . 'actions/runs' );",
	"shell_exec( 'gh api repos/o/r/actions/runs' );",
	"shell_exec( '/usr/bin/gh api repos/o/r/actions/runs' );",
	"exec( '/usr/bin/gh version' ); system( 'gh status' ); passthru( 'gh auth' ); popen( 'gh release', 'r' ); proc_open( 'gh repo', $descriptor_spec, $pipes );",
	"proc_open( ['/usr/bin/gh', 'api', 'repos/o/r/actions/runs'], $descriptor_spec, $pipes );",
	"pcntl_exec( '/usr/bin/gh', ['api', 'repos/o/r/actions/runs'] ); $output = `gh api repos/o/r/actions/runs`;",
	"<?php $items = array(<<<TXT\nsafe\nTXT,\n); $output = `id`; ?>",
	"<?php consume(<<<TXT\nsafe\nTXT); $output = `id`; ?>",
	"getenv('GH_TOKEN')",
]) {
	assert.notDeepEqual(productionExternalRuntimeCouplingFindings(fixture), [], "production PHP must reject GitHub runtime hosts and token identifiers even when endpoint fragments are separated");
}
for (const fixture of [
	"$path = '/statuses/';",
	"https://devenia.com/actions/runs",
	"$actions = array();",
	"$frequency = '10ghz'; $height = 20;",
	"$country_code = 'GH'; $tld = '.gh';",
	"https://example.gh/status",
	"<?php $sql = 'SELECT * FROM `table`'; /** `documented identifier` */ $text = \"literal `content`\"; ?>",
	"<?php $text = <<<TXT\nliteral `content`\nTXT; ?>",
]) {
	assert.deepEqual(productionExternalRuntimeCouplingFindings(fixture), [], "generic words and non-GitHub routes must not be mistaken for external CI runtime coupling");
}
for (const fixture of [
	"https://api.github.com/repos/bjornfix/devenia-workflow/actions/runs",
	"'https://api.github.com/repos/bjornfix/devenia-workflow/' . 'actions/runs'",
	"'https://api.github.com/repos/' . $repository . '/actions/workflows'",
	"$host = 'https://api.github.com'; /* separated on purpose */ $path = '/repos/o/r/actions/runs'; wp_remote_get( $host . $path );",
	"https://github.com/bjornfix/devenia-workflow/actions/workflows/recovery.yml",
	"https://api.github.com/repos/bjornfix/devenia-workflow/commits/main/check-runs",
	"'https://api.github.com/repos/' . $repository . '/commits/main/check-runs'",
	".github/workflows/recovery-transaction-mysql84.yml",
	"workflow_dispatch",
	"getenv('GITHUB_RUN_ID')",
	"getenv('GITHUB_RUN_NUMBER')",
	"getenv('ACTIONS_RESULTS_URL')",
	"$_ENV['CI']",
]) {
	assert.notDeepEqual(productionExternalRuntimeCouplingFindings(fixture), [], "the production negative gate must detect each explicit GitHub Actions or CI runtime-coupling class");
}

const productionRuntimeFiles = [
	...productionPhpFiles(join(pluginRoot, "devenia-workflow.php")),
	...productionPhpFiles(join(pluginRoot, "includes")),
	...productionPhpFiles(join(pluginRoot, "addons")),
];
const productionRuntimeCouplings = productionRuntimeFiles.flatMap((path) => productionExternalRuntimeCouplingFindings(readFileSync(path, "utf8")).map((coupling) => `${relative(pluginRoot, path)}: ${coupling}`));
assert.deepEqual(productionRuntimeCouplings, [], "production PHP must not read GitHub Actions or CI state, dispatch workflows, or fetch Actions runtime evidence");

const plugin = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const publication = readFileSync(new URL("../includes/trait-localized-presentation-publication.php", import.meta.url), "utf8");
const relationAuthority = readFileSync(new URL("../includes/trait-public-header-relation-authority.php", import.meta.url), "utf8");
const indexReadModel = readFileSync(new URL("../includes/trait-translation-index-read-model.php", import.meta.url), "utf8");
const jobs = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const runtime = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");
const publicHeaderRuntime = readFileSync(new URL("./check-public-header-projection-wordpress-runtime.php", import.meta.url), "utf8");
const liveFrontendBatchRuntime = readFileSync(new URL("./check-frontend-cache-batch-wordpress-live.php", import.meta.url), "utf8");
const lockWorker = readFileSync(new URL("./public-header-relation-lock-worker.php", import.meta.url), "utf8");
const databaseRuntimeSuite = readFileSync(new URL("./run-database-runtime-suite.sh", import.meta.url), "utf8");
const translationJobRuntimeSuite = readFileSync(new URL("./run-translation-job-runtime-suite.sh", import.meta.url), "utf8");
const activationInputStart = publicHeaderRuntime.indexOf("$activation_input = static function");
const activationInputEnd = publicHeaderRuntime.indexOf("$option_keys =", activationInputStart);
assert.ok(activationInputStart > 0 && activationInputEnd > activationInputStart, "the runtime activation-input helper must remain bounded");
const activationInput = publicHeaderRuntime.slice(activationInputStart, activationInputEnd);
const productionHeaderStateContractPasses = (source) => {
	const start = source.indexOf("$production_header_state = static function");
	const end = source.indexOf("$before =", start);
	if (start < 0 || end <= start) return false;
	const helper = source.slice(start, end);
	const assignments = source.match(/\$production_header_state\s*=/g) || [];
	return assignments.length === 1
		&& !/\bunset\s*\(\s*\$production_header_state\b/.test(source)
		&& /^\s*\$production_header_state = static function \(\): array \{\s*\$missing = '__devenia_workflow_option_missing__';\s*return array\(\s*'manifest'\s*=> get_option\( 'devenia_workflow_public_header_manifest', \$missing \),\s*'identities'\s*=> get_option\( 'devenia_workflow_localized_menu_identities', \$missing \),\s*'pending'\s*=> get_option\( 'devenia_workflow_pending_public_header_manifest', \$missing \),\s*'enrollment'\s*=> get_option\( 'devenia_workflow_public_header_enrollment', \$missing \),\s*\);\s*\};\s*$/.test(helper);
};
assert.equal(productionHeaderStateContractPasses(publicHeaderRuntime), true, "the bounded reconciliation-state reader must use the production missing-option sentinel and exact production key order for every Public Header slot");
assert.equal(productionHeaderStateContractPasses(publicHeaderRuntime.replace("$missing = '__devenia_workflow_option_missing__';", "$missing = '__workflow_missing__';")), false, "the state-reader gate must reject a harness sentinel substituted for production authority");
assert.equal(productionHeaderStateContractPasses(publicHeaderRuntime.replace("get_option( 'devenia_workflow_pending_public_header_manifest', $missing )", "get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' )")), false, "the state-reader gate must reject a pending-only sentinel substitution");
const swappedProductionStateOrder = publicHeaderRuntime.replace(
	"\t\t'identities' => get_option( 'devenia_workflow_localized_menu_identities', $missing ),\n\t\t'pending'    => get_option( 'devenia_workflow_pending_public_header_manifest', $missing ),",
	"\t\t'pending'    => get_option( 'devenia_workflow_pending_public_header_manifest', $missing ),\n\t\t'identities' => get_option( 'devenia_workflow_localized_menu_identities', $missing ),",
);
assert.equal(productionHeaderStateContractPasses(swappedProductionStateOrder), false, "the state-reader gate must reject identities/pending key-order drift that invalidates strict raw evidence");
const reversedProductionState = publicHeaderRuntime
	.replace("\treturn array(\n\t\t'manifest'", "\treturn array_reverse( array(\n\t\t'manifest'")
	.replace("\t\t'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $missing ),\n\t);\n};\n$before =", "\t\t'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $missing ),\n\t), true );\n};\n$before =");
assert.notEqual(reversedProductionState, publicHeaderRuntime, "the helper reversal mutation fixture must alter the source");
assert.equal(productionHeaderStateContractPasses(reversedProductionState), false, "the state-reader gate must reject a post-construction key-order transform");
const extraProductionStateKey = publicHeaderRuntime.replace("\t\t'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $missing ),\n\t);\n};\n$before =", "\t\t'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $missing ),\n\t\t'extra'      => true,\n\t);\n};\n$before =");
assert.notEqual(extraProductionStateKey, publicHeaderRuntime, "the helper extra-key mutation fixture must alter the source");
assert.equal(productionHeaderStateContractPasses(extraProductionStateKey), false, "the state-reader gate must reject any fifth state key");
assert.equal(productionHeaderStateContractPasses(`${publicHeaderRuntime}\n$production_header_state = static function (): array { return array(); };`), false, "the state-reader gate must reject any later helper replacement outside the bounded definition slice");

const boundedSource = (source, startMarker, endMarker) => {
	const start = source.indexOf(startMarker);
	const end = source.indexOf(endMarker, start + startMarker.length);
	return start >= 0 && end > start ? source.slice(start, end) : "";
};
// This static layer proves exact capture provenance and rejects direct write
// drift. It deliberately does not pretend to model PHP's open-ended by-ref
// function semantics; the real WordPress runtime's strict raw comparisons are
// the semantic oracle for every effect after capture.
const protectedSnapshotsHaveExactCapture = (source, variableNames) => variableNames.every((name) => {
	const exactAssignment = `$${name} = $production_header_state();`;
	if (source.split(exactAssignment).length - 1 !== 1) return false;
	const remainder = source.replace(exactAssignment, "");
	const assignment = new RegExp(`\\$${name}\\s*(?:\\[[^\\]]*\\]\\s*)*(?:=(?!=)|\\+=|-=|\\.=|\\*=|\\/=|%=|&=|\\|=|\\^=|<<=|>>=|\\?\\?=)`);
	const unset = new RegExp(`\\bunset\\s*\\(\\s*\\$${name}(?:\\s*\\[|\\s*\\))`);
	const increment = new RegExp(`(?:\\+\\+|--)\\s*\\$${name}\\b|\\$${name}\\s*(?:\\[[^\\]]*\\]\\s*)*(?:\\+\\+|--)`);
	return !assignment.test(remainder) && !unset.test(remainder) && !increment.test(remainder);
});
const stagedProjectionEvidenceContractPasses = (source) => {
	const helper = boundedSource(source, "$staged_projection_set_evidence =", "$inventory_term_ids =");
	return /static function \( array \$attempt, string \$expected_manifest_revision \)/.test(helper)
		&& /in_array\( \$menu_id, \$menu_ids, true \)/.test(helper)
		&& /hash_equals\( \$receipt, \$current_receipt \)/.test(helper)
		&& /TERM_META_MENU_MANAGED[\s\S]*TERM_META_MENU_LANGUAGE/.test(helper)
		&& /'' === \$expected_manifest_revision[\s\S]*hash_equals\( \$expected_manifest_revision, \$manifest_revision \)[\s\S]*TERM_META_PUBLIC_HEADER_MANIFEST_REVISION/.test(helper);
};
assert.equal(stagedProjectionEvidenceContractPasses(publicHeaderRuntime), true, "staged projection evidence must bind unique live menu receipts and metadata to the receipt-owned pending manifest revision");
assert.equal(stagedProjectionEvidenceContractPasses(publicHeaderRuntime.replace("! hash_equals( $expected_manifest_revision, $manifest_revision )", "false")), false, "the staged projection gate must reject a receipt/meta self-consistent projection from the wrong pending revision");
const pendingRaceRuntimeContractPasses = (source) => {
	const injection = boundedSource(source, "if ( 'pending_race' === $failure_mode )", "if ( 'enrollment_state_race' === $failure_mode )");
	const assertion = boundedSource(source, "$pending_race_before =", "$enrollment_state_race_before =");
	return /\$pending_race_foreign_state = array\( 'race' => wp_generate_uuid4\(\) \);[\s\S]*update_option\( 'devenia_workflow_pending_public_header_manifest', \$pending_race_foreign_state, false \)[\s\S]*\$pending_race_foreign_state !== get_option/.test(injection)
		&& /public_header_projection_activation_state_unresolved[\s\S]*public_header_activation_receipt_mismatch[\s\S]*'pending' !== \(string\) \( \$pending_race\['activation'\]\['slot'\]/.test(assertion)
		&& /\$pending_race_foreign_state !== \( \$pending_race_after\['pending'\][\s\S]*\$pending_race_before\['manifest'\][\s\S]*\$pending_race_before\['identities'\][\s\S]*\$pending_race_before\['enrollment'\][\s\S]*\$pending_race_before !== \( \$pending_race\['activation'\]\['before'\][\s\S]*\$pending_race_after !== \( \$pending_race\['cleanup_authority'\]\['current_state'\]/.test(assertion)
		&& /cleanup_authority'\]\['allowed'[\s\S]*'unknown'[\s\S]*receipt_current_exact[\s\S]*before_exact[\s\S]*references_excluded[\s\S]*staged_projection_cleanup_not_authorized[\s\S]*cleanup'\]\['results'/.test(assertion)
		&& /\$staged_projection_set_evidence\( \$pending_race, \(string\) \( \$pending_race_before\['pending'\]\['revision'\]/.test(assertion)
		&& /empty\( \$pending_projection_evidence\['exact'\] \)[\s\S]*count\( \$pending_projection_ids \) !== count\( \$languages \)[\s\S]*\$pending_expected_staged_ids !== \$pending_inventory_delta[\s\S]*\$expected_managed_with_staged[\s\S]*\$pending_race_raw_menus_before !== \$raw_fixture_menu_surface\(\)[\s\S]*\$pending_race_relations_before !== \$raw_relation_post_surface/.test(assertion)
		&& /array_key_exists\( 'cache_invalidation', \$pending_race \)[\s\S]*array_key_exists\( 'verification', \$pending_race \)[\s\S]*array_key_exists\( 'retirement', \$pending_race \)/.test(assertion)
		&& protectedSnapshotsHaveExactCapture(assertion, ["pending_race_before", "pending_race_after", "pending_race_restored", "state_after_identity_race", "pending_state_after_cleanup"])
		&& /\$pending_race_before = \$production_header_state\(\);[\s\S]*\$pending_race_after = \$production_header_state\(\);[\s\S]*\$pending_race_restored = \$production_header_state\(\);[\s\S]*\$state_after_identity_race = \$production_header_state\(\);[\s\S]*\$pending_state_after_cleanup = \$production_header_state\(\);/.test(assertion)
		&& /__devenia_workflow_option_missing__[\s\S]*\$pending_cleanup_authority[\s\S]*__devenia_workflow_option_missing__/.test(assertion)
		&& /\$pending_race_before !== \$pending_race_restored[\s\S]*\$pending_race_before !== \$state_after_identity_race[\s\S]*\$pending_race_before !== \$pending_state_after_cleanup/.test(assertion);
};
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime), true, "the bounded pending-race oracle must require the exact foreign raw owner, receipt-specific evidence, denied cleanup, and every intact staged projection");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("'public_header_activation_receipt_mismatch' !== (string) ( $pending_race['activation']['code'] ?? '' )", "'public_header_state_changed' !== (string) ( $pending_race['activation']['code'] ?? '' )")), false, "the pending-race gate must reject the obsolete generic state-change oracle");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_foreign_state !== ( $pending_race_after['pending'] ?? null )", "false")), false, "the pending-race gate must reject removal of exact foreign pending preservation");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("empty( $pending_projection_evidence['exact'] )", "false")), false, "the pending-race gate must reject removal of exact staged-projection evidence");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_before !== $pending_race_restored", "false")), false, "the pending-race gate must reject removal of strict four-slot fixture restoration");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_after = $production_header_state();", "$pending_race_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment' ) );")), false, "the pending-race gate must reject a hand-assembled snapshot that can drift from production key order");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_after = $production_header_state();", "$pending_race_after = $production_header_state(); $pending_race_after = array_reverse( $pending_race_after, true );")), false, "the pending-race gate must reject any post-helper snapshot override");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_after = $production_header_state();", "$pending_race_after = $production_header_state(); $pending_race_after['pending']['revision'] = 'tampered';")), false, "the pending-race gate must reject nested snapshot writes");
assert.equal(pendingRaceRuntimeContractPasses(publicHeaderRuntime.replace("$pending_race_after = $production_header_state();", "$pending_race_after = $production_header_state(); ++$pending_race_after['pending']['revision'];")), false, "the pending-race gate must reject nested snapshot increments");

const enrollmentRaceRuntimeContractPasses = (source) => {
	const injection = boundedSource(source, "if ( 'enrollment_state_race' === $failure_mode )", "if ( 'pending_key_reorder' === $failure_mode )");
	const assertion = boundedSource(source, "$enrollment_state_race_before =", "$failure_mode = ''; $failure_injected = false; $stage_b =");
	return /\$enrollment_state_race_foreign_state = array\( 'race' => wp_generate_uuid4\(\) \);[\s\S]*update_option\( 'devenia_workflow_public_header_enrollment', \$enrollment_state_race_foreign_state, false \)[\s\S]*\$enrollment_state_race_foreign_state !== get_option/.test(injection)
		&& /public_header_projection_activation_state_unresolved[\s\S]*public_header_state_changed[\s\S]*'enrollment' !== \(string\) \( \$enrollment_state_race\['activation'\]\['slot'\]/.test(assertion)
		&& /\$enrollment_state_race_foreign_state !== \( \$enrollment_state_race_after\['enrollment'\][\s\S]*\$enrollment_state_race_before\['manifest'\][\s\S]*\$enrollment_state_race_before\['pending'\][\s\S]*\$enrollment_state_race_before\['identities'\][\s\S]*\$enrollment_state_race_before !== \( \$enrollment_state_race\['activation'\]\['before'\][\s\S]*\$enrollment_state_race_after !== \( \$enrollment_state_race\['cleanup_authority'\]\['current_state'\]/.test(assertion)
		&& /cleanup_authority'\]\['allowed'[\s\S]*'unknown'[\s\S]*receipt_current_exact[\s\S]*before_exact[\s\S]*references_excluded[\s\S]*staged_projection_cleanup_not_authorized[\s\S]*cleanup'\]\['results'/.test(assertion)
		&& /staged_projection_cleanup_not_authorized[\s\S]*empty\( \$enrollment_projection_evidence\['exact'\] \)[\s\S]*count\( \$enrollment_projection_ids \) !== count\( \$languages \)[\s\S]*\$enrollment_expected_staged_ids !== \$enrollment_inventory_delta[\s\S]*\$enrollment_state_race_raw_menus_before !== \$raw_fixture_menu_surface\(\)[\s\S]*\$enrollment_state_race_relations_before !== \$raw_relation_post_surface/.test(assertion)
		&& /\$staged_projection_set_evidence\( \$enrollment_state_race, \(string\) \( \$enrollment_state_race_before\['pending'\]\['revision'\]/.test(assertion)
		&& /array_key_exists\( 'cache_invalidation', \$enrollment_state_race \)[\s\S]*array_key_exists\( 'verification', \$enrollment_state_race \)[\s\S]*array_key_exists\( 'retirement', \$enrollment_state_race \)/.test(assertion)
		&& protectedSnapshotsHaveExactCapture(assertion, ["enrollment_state_race_before", "enrollment_state_race_after", "enrollment_state_restored", "enrollment_state_after_cleanup"])
		&& /\$enrollment_state_race_before = \$production_header_state\(\);[\s\S]*\$enrollment_state_race_after = \$production_header_state\(\);[\s\S]*\$enrollment_state_restored = \$production_header_state\(\);[\s\S]*\$enrollment_state_after_cleanup = \$production_header_state\(\);/.test(assertion)
		&& /__devenia_workflow_option_missing__[\s\S]*\$enrollment_state_cleanup_authority[\s\S]*__devenia_workflow_option_missing__/.test(assertion)
		&& /\$enrollment_state_race_before !== \$enrollment_state_restored[\s\S]*delete_staged_public_header_projections[\s\S]*\$enrollment_state_race_before !== \$enrollment_state_after_cleanup[\s\S]*\$enrollment_state_race_inventory_before !== \$raw_nav_menu_inventory\(\)/.test(assertion);
};
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime), true, "a separate bounded non-pending locked race must keep the generic state-CAS rejection real and prove exact fixture restoration");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("'public_header_state_changed' !== (string) ( $enrollment_state_race['activation']['code'] ?? '' )", "'public_header_activation_receipt_mismatch' !== (string) ( $enrollment_state_race['activation']['code'] ?? '' )")), false, "the enrollment-race gate must reject substitution of pending-only receipt evidence");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("'enrollment' !== (string) ( $enrollment_state_race['activation']['slot'] ?? '' )", "'pending' !== (string) ( $enrollment_state_race['activation']['slot'] ?? '' )")), false, "the enrollment-race gate must remain bound to a non-pending state slot");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("$enrollment_state_race_foreign_state !== ( $enrollment_state_race_after['enrollment'] ?? null )", "false")), false, "the enrollment-race gate must reject removal of exact foreign enrollment preservation");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("empty( $enrollment_projection_evidence['exact'] )", "false")), false, "the enrollment-race gate must reject removal of exact projection-receipt evidence");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("! empty( $enrollment_state_race['cleanup_authority']['allowed'] )", "false")), false, "the enrollment-race gate must reject removal of cleanup denial");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("false !== ( $enrollment_state_race['cleanup_authority']['before_exact'] ?? null )", "false")), false, "the enrollment-race gate must reject removal of foreign-state cleanup classification");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("array_key_exists( 'cache_invalidation', $enrollment_state_race )", "false")), false, "the enrollment-race gate must reject removal of the pre-cache-invalidation phase boundary");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("$enrollment_state_race_after = $production_header_state();", "$enrollment_state_race_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment' ) );")), false, "the enrollment-race gate must reject a hand-assembled snapshot that can drift from production key order");
assert.equal(enrollmentRaceRuntimeContractPasses(publicHeaderRuntime.replace("$enrollment_state_race_after = $production_header_state();", "$enrollment_state_race_after = $production_header_state(); $enrollment_state_race_after = array_reverse( $enrollment_state_race_after, true );")), false, "the enrollment-race gate must reject any post-helper snapshot override");

const syncStart = plugin.indexOf("private static function sync_language_menu");
const syncEnd = plugin.indexOf("private static function existing_menu_label_map", syncStart);
assert.ok(syncStart > 0 && syncEnd > syncStart, "sync_language_menu must remain a bounded implementation");
const sync = plugin.slice(syncStart, syncEnd);
const identityStart = publication.indexOf("private static function localized_menu_id");
const identityEnd = publication.indexOf("private static function validate_localized_menu_projection", identityStart);
assert.ok(identityStart > 0 && identityEnd > identityStart, "localized_menu_id must remain a bounded identity reader");
const identityReader = publication.slice(identityStart, identityEnd);
const migrationStart = publication.indexOf("private static function migrate_public_header_label_authority");
const migrationEnd = publication.indexOf("private static function update_public_header_manifest", migrationStart);
assert.ok(migrationStart > 0 && migrationEnd > migrationStart, "label-authority migration must remain a bounded explicit Interface");
const migrationInterface = publication.slice(migrationStart, migrationEnd);
const enrollmentStart = publication.indexOf("private static function enroll_public_header_from_existing_menus");
assert.ok(enrollmentStart > 0 && migrationStart > enrollmentStart, "first enrollment must remain a bounded explicit Interface");
const enrollmentInterface = publication.slice(enrollmentStart, migrationStart);
const relationStart = relationAuthority.indexOf("private static function public_header_fresh_content_relations");
const relationEnd = relationAuthority.indexOf("private static function public_header_relation_index_cross_check", relationStart);
const indexCrossCheckEnd = relationAuthority.indexOf("private static function public_header_route_authority_snapshot", relationEnd);
const routeAuthorityEnd = relationAuthority.indexOf("private static function lock_public_header_relation_authority_surface", indexCrossCheckEnd);
assert.ok(relationStart > 0 && relationEnd > relationStart && indexCrossCheckEnd > relationEnd && routeAuthorityEnd > indexCrossCheckEnd, "Relation Authority modules must remain independently inspectable");
const canonicalContentRelations = relationAuthority.slice(relationStart, relationEnd);
const indexCrossCheck = relationAuthority.slice(relationEnd, indexCrossCheckEnd);
const routeAuthority = relationAuthority.slice(indexCrossCheckEnd, routeAuthorityEnd);
const authorityLock = relationAuthority.slice(routeAuthorityEnd);
const stateReplaceStart = publication.indexOf("private static function replace_public_header_state_transaction");
const stateReplaceEnd = publication.indexOf("private static function reconcile_public_header_state_commit_outcome", stateReplaceStart);
const stateReplace = publication.slice(stateReplaceStart, stateReplaceEnd);
const publicHeaderSyncStart = publication.indexOf("private static function sync_public_header_projection");
const publicHeaderSyncEnd = publication.indexOf("private static function stage_public_header_manifest_for_publication", publicHeaderSyncStart);
assert.ok(publicHeaderSyncStart > 0 && publicHeaderSyncEnd > publicHeaderSyncStart, "Public Header activation must remain a bounded deep Interface");
const publicHeaderSync = publication.slice(publicHeaderSyncStart, publicHeaderSyncEnd);
const activationReceiptValidationStart = publication.indexOf("private static function validate_public_header_activation_receipt");
assert.ok(activationReceiptValidationStart > 0 && publicHeaderSyncStart > activationReceiptValidationStart, "activation receipt validation must remain independently inspectable");
const activationReceiptIssueStart = publication.indexOf("private static function public_header_activation_receipt");
assert.ok(activationReceiptIssueStart > 0 && activationReceiptValidationStart > activationReceiptIssueStart, "activation receipt issuance must remain independently inspectable");
const activationReceiptIssue = publication.slice(activationReceiptIssueStart, activationReceiptValidationStart);
const activationReceiptValidation = publication.slice(activationReceiptValidationStart, publicHeaderSyncStart);
const publicationStageStart = publicHeaderSyncEnd;
const publicationStageEnd = publication.indexOf("private static function refresh_public_header_projection_for_publication", publicationStageStart);
assert.ok(publicationStageStart > 0 && publicationStageEnd > publicationStageStart, "ordinary publication staging must remain a bounded ownership Interface");
const publicationStage = publication.slice(publicationStageStart, publicationStageEnd);

assert.match(publication, /private static function verify_live_translation[\s\S]*private static function publish_localized_presentation/, "live verification must remain a separate callable Interface before publication");
assert.match(publication, /publish_localized_presentation[\s\S]*apply_translation_publish_transition[\s\S]*refresh_public_header_projection_for_publication[\s\S]*devenia_workflow_frontend_cache_invalidation_result/);
assert.doesNotMatch(publication.slice(publication.indexOf("private static function publish_localized_presentation"), publication.indexOf("private static function rollback_localized_menu_projection")), /self::verify_live_translation\s*\(/, "publication must not synchronously self-fetch the live surface");
assert.doesNotMatch(publication.slice(publication.indexOf("private static function publish_localized_presentation"), publication.indexOf("private static function rollback_localized_menu_projection")), /sync_language_menu\s*\(/, "normal publication must not activate one language directly");
assert.match(jobs, /translation_job_publish[\s\S]*publish_localized_presentation/);
assert.match(plugin, /private static function publish_translation[\s\S]*publish_localized_presentation/);
assert.match(publication, /frontend_cache_adapter_missing/);
assert.match(publication, /true !== \( \$invalidation\['success'\]/);
assert.match(publication, /devenia_workflow_localized_presentation_commit_adapter_result[\s\S]*translation_job_clean_recovery_caches[\s\S]*observed_mutation_revision/, "content publication must reconcile the commit receipt only after cache eviction and an exact public-surface read");
assert.match(publication, /before_exact && \( false === \$committed \|\| null === \$committed \)[\s\S]*publication_transaction_commit_outcome_unknown_unapplied[\s\S]*replacement_exact && \( true === \$committed \|\| null === \$committed \)[\s\S]*publication_transaction_commit_reconciliation_conflict/, "content commit false, true, and unknown outcomes must classify exact before, exact replacement, and foreign surfaces without collapsing state");
assert.match(publication, /publication_transaction_commit_reconciliation_conflict[\s\S]*'published' => null[\s\S]*'mutation_cas_revision' => ''[\s\S]*'observed_mutation_cas_revision' => \$observed_mutation_revision[\s\S]*'rollback_authorized' => false/, "foreign content state must remain critical while its observed revision stays diagnostic and never becomes rollback authority");
assert.match(publicHeaderRuntime, /array_key_exists\( 'published', \$content_attempt \)[\s\S]*null !== \$content_attempt\['published'\]/, "the WordPress runtime must distinguish an explicit published=null foreign receipt from a missing field");
assert.doesNotMatch(publicHeaderRuntime, /null !== \( \$content_attempt\['published'\] \?\? false \)/, "null coalescing must not erase the explicit published=null foreign receipt");
assert.match(publication, /'rollback_authorized' => true[\s\S]*'rollback_expected_surface_revision' => \$mutation_cas_revision/, "only an exact owned replacement receipt may authorize caller rollback");
assert.match(publication, /catch \( Throwable \$error \)[\s\S]*observed_after_exception[\s\S]*publication_transaction_exception_unapplied[\s\S]*publication_transaction_exception_applied[\s\S]*publication_transaction_exception_reconciliation_conflict/, "transaction Adapter exceptions must reconcile exact unapplied, applied, or foreign state instead of collapsing to unpublished");
assert.match(publication, /public_header_projection_publication_failed[\s\S]*commit_reconciliation[\s\S]*frontend_cache_adapter_missing[\s\S]*commit_reconciliation[\s\S]*frontend_cache_invalidation_failed[\s\S]*commit_reconciliation/, "every synchronous post-commit failure must preserve commit reconciliation and the exact mutation receipt");

assert.match(sync, /wp_create_nav_menu\( \$staging_name \)/);
assert.match(sync, /validate_localized_menu_projection[\s\S]*devenia_workflow_public_header_projection_receipt[\s\S]*'staged_only'\] = true[\s\S]*return \$base_result/, "single-language projection may only return a validated staged receipt");
assert.doesNotMatch(sync, /activate_localized_menu_id|retire_managed_localized_menu|retire_previous/, "single-language projection must not retain a bypass around complete-set activation and retirement");
assert.doesNotMatch(sync, /wp_delete_post\(/, "atomic projection must never delete active menu items before build");
assert.match(sync, /wp_delete_nav_menu\( \(int\) \$target_menu->term_id \)/, "failed staging projections must be cleaned up");

assert.match(publication, /OPTION_LOCALIZED_MENU_IDENTITIES/);
assert.match(publication, /OPTION_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /OPTION_PENDING_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /public_header_projection_plan/);
assert.match(sync, /public_header_projection_plan\( \$language/);
assert.match(publicHeaderSync, /validate_public_header_activation_receipt\( \$activation_receipt \)[\s\S]*validate_public_header_relation_receipts[\s\S]*validate_public_header_authority_receipts[\s\S]*devenia_workflow_public_header_before_activation_receipt_revalidation[\s\S]*validate_public_header_activation_receipt\( \$activation_receipt \)[\s\S]*configured_public_header_languages[\s\S]*sync_language_menu[\s\S]*activate_public_header_projection_set/, "the exact caller-owned pending receipt must be validated and race-revalidated before any projection is staged");
assert.doesNotMatch(publicHeaderSync, /stage_public_header_manifest_for_publication|if \( empty\( \$pending \) \)/, "activation must never restage or select whichever global pending manifest happens to exist");
assert.match(activationReceiptIssue, /maybe_serialize\( \$manifest \)[\s\S]*hash_hmac\( 'sha256'[\s\S]*wp_salt\( 'auth' \)/, "one opaque receipt must bind the exact PHP-serialized raw pending array, including key order and ephemeral authority fields");
assert.doesNotMatch(activationReceiptIssue, /translation_job_canonicalize|ksort|wp_json_encode/, "receipt issuance must never sort or normalize raw pending authority before hashing it");
assert.match(activationReceiptValidation, /public_header_activation_receipt_missing[\s\S]*get_option\( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, \$missing \)[\s\S]*public_header_pending_manifest_missing[\s\S]*normalize_public_header_manifest\( \$raw_pending \)[\s\S]*public_header_activation_receipt\( \$raw_pending \)[\s\S]*public_header_activation_receipt_mismatch[\s\S]*\$raw_pending !== \$pending[\s\S]*public_header_pending_manifest_not_canonical/, "activation must HMAC-bind the exact raw stored pending serialization before accepting its separately normalized strict canonical domain manifest");
assert.match(activationInput, /string \$activation_receipt[\s\S]*'activation_receipt' => \$activation_receipt/, "runtime activation attempts must consume an issued staging receipt explicitly");
assert.doesNotMatch(activationInput, /get_option|public_header_activation_receipt|ReflectionMethod/, "the runtime helper must never derive or forge authority from global pending state");
assert.match(publication, /stage_public_header_manifest_for_publication[\s\S]*'activation_receipt' => self::public_header_activation_receipt\( \(array\) \$stored \)[\s\S]*refresh_public_header_projection_for_publication[\s\S]*'activation_receipt' => \(string\) \$staging\['activation_receipt'\]/, "ordinary publication must carry the receipt for its exact verified stored value directly into activation");
assert.match(publicationStage, /get_option\( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, \$missing \)[\s\S]*\$missing !== \$before[\s\S]*public_header_pending_manifest_ownership_conflict[\s\S]*public_header_manifest\(\)[\s\S]*public_header_relation_receipts_for_manifest[\s\S]*\$missing !== get_option\( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, \$missing \)[\s\S]*atomic_create_option\( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, \$pending \)[\s\S]*\$stored = get_option[\s\S]*public_header_activation_receipt\( \(array\) \$stored \)/, "ordinary publication must reject every occupied raw pending slot before normalization and issue a receipt only over its exact verified missing-to-created stored value");
assert.doesNotMatch(publicationStage, /pending_public_header_manifest\(|atomic_replace_option_value|existing_pending/, "ordinary publication must never normalize, adopt, or replace another pending owner");
assert.match(publication, /activate_public_header_projection_set[\s\S]*'pending'\s*=> \$missing[\s\S]*replace_public_header_state_transaction/, "successful activation must atomically release the pending ownership slot");
assert.match(enrollmentInterface, /stage_first_public_header_enrollment_transaction[\s\S]*'activation_receipt'\s*=> \(string\) \$stage\['activation_receipt'\]/, "first enrollment must carry its transaction-owned pending receipt into activation");
assert.match(publication, /update_public_header_manifest[\s\S]*\$existing_pending_is_canonical[\s\S]*'activation_receipt' => self::public_header_activation_receipt\( \$before \)[\s\S]*\$stored = get_option[\s\S]*'activation_receipt' => self::public_header_activation_receipt\( \(array\) \$stored \)/, "operator and migration staging responses must reject noncanonical adoption and return receipts only for exact verified stored pending values");
assert.match(publication, /\$stored_pending === \$pending[\s\S]*public_header_activation_receipt\( \$stored_pending \)[\s\S]*\$before === \$existing_pending[\s\S]*\$stored !== \$manifest/, "first enrollment and operator staging must issue authority only for strict raw stored identity, never canonicalized equality");
assert.match(plugin, /sync_menu_input_schema[\s\S]*'required'\s*=> array\( 'activation_receipt' \)[\s\S]*\^phact_\[a-f0-9\]\{48\}\$/, "the public sync-menu Interface must require the opaque activation receipt");
assert.doesNotMatch(publication, /private static function rollback_localized_menu_projection\s*\(/, "the dead standalone menu rollback transaction must not reintroduce a raw COMMIT path");
assert.doesNotMatch(publication, /private static function (?:activate_localized_menu_id|retire_managed_localized_menu)\s*\(/, "complete-set Public Header Projection must remain the only activation and retirement Interface");
assert.match(publication, /activate_public_header_projection_set[\s\S]*replace_public_header_state_transaction/);
assert.match(publication, /devenia_workflow_public_header_before_locked_state_transition[\s\S]*replace_public_header_state_transaction/, "the runtime fixture needs an exact snapshot-to-lock transaction boundary");
assert.match(stateReplace, /lock_public_header_relation_authority_surface[\s\S]*public_header_staged_receipt_changed/);
assert.match(stateReplace, /FOR UPDATE[\s\S]*'pending' === \$slot[\s\S]*public_header_activation_receipt\( \$current \)[\s\S]*public_header_activation_receipt_mismatch[\s\S]*'pending' === \$slot \? \$current === \$expected_value/, "the locked pending row must revalidate the exact raw receipt and strict serialized identity before deletion");
assert.match(publication, /replace_public_header_state_transaction[\s\S]*OPTION_PUBLIC_HEADER_MANIFEST[\s\S]*OPTION_LOCALIZED_MENU_IDENTITIES[\s\S]*OPTION_PENDING_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /translation_job_canonicalize\( \$current \) === self::translation_job_canonicalize\( \$replacement_value \)[\s\S]*\$written = true/, "an already-equal locked option value must be an idempotent successful write");
assert.match(publication, /rollback_public_header_state_transaction[\s\S]*translation_job_rollback_recovery_transaction[\s\S]*clear_public_header_state_option_cache/);
assert.match(stateReplace, /\$expected_exact =[\s\S]*if \( ! \$expected_exact \)[\s\S]*rollback_public_header_state_transaction\(\)[\s\S]*public_header_state_changed/, "a rejected partial option transaction must discard uncommitted cache values");
assert.match(publication, /OPTION_PUBLIC_HEADER_ENROLLMENT/);
assert.match(publication, /public_header_rollback_projection_receipts[\s\S]*previous_menu_surface_revision/);
assert.match(publication, /public_header_rollback_projection_receipts[\s\S]*public_header_navigation_snapshot_from_menu[\s\S]*expected_navigation/);
assert.match(publication, /verify_public_header_projection_set\( self::configured_public_header_languages\(\),[\s\S]*\$rollback_receipts\['expected_navigation'\]/, "rollback verification must use the receipt-bound prior navigation snapshot");
assert.match(publication, /public_header_projection_incomplete/);
assert.match(publication, /normalize_public_header_manifest_items\( \$input\['items'\] \?\? array\(\), true \)/, "manifest registration must require complete editorial label authority");
assert.match(publication, /public_header_label_authority_missing/);
assert.match(publication, /missing_editorial_label_authority/);
assert.match(migrationInterface, /current_user_can\( 'manage_options' \)/, "legacy label-authority migration must be capability-gated at its explicit Interface");
assert.match(enrollmentInterface, /current_user_can\( 'manage_options' \)/, "first enrollment must be capability-gated at its explicit Interface");
assert.match(publication, /public_header_source_manifest_from_menu/);
assert.match(publication, /get_nav_menu_locations[\s\S]*public_header_source_menu_not_primary/);
assert.match(publication, /insufficient_independent_authority_candidates/);
assert.match(publication, /public_header_enrollment_authority_changed/);
assert.match(enrollmentInterface, /\$source_language !== \$language && empty\( \$explicit_ids \)[\s\S]*foreach \( \$retained_menus as \$retained_menu \)/, "a complete explicit target authority set must replace broad retained-menu discovery");
assert.match(migrationInterface, /\$explicit_ids[\s\S]*\$candidate_ids = \$explicit_ids[\s\S]*if \( empty\( \$explicit_ids \) \)[\s\S]*wp_get_nav_menus/, "schema-1 migration must also treat a supplied authority set as closed");
assert.match(publication, /public_header_editorial_label_snapshot[\s\S]*public_header_menu_item_source_identity[\s\S]*\$matches_source_item[\s\S]*\$matches_stable[\s\S]*\$matches_relation[\s\S]*stable_identity_relation_mismatch[\s\S]*\$identity_observed \? \$identity_candidates : \$fallback_candidates/, "exact source-item identity must match the requested-language relation and win before page or URL fallback");
assert.match(publication, /public_header_menu_item_source_identity[\s\S]*metadata_exists\( 'post'[\s\S]*get_post_meta\( \$item_id, self::MENU_ITEM_META_SOURCE_ITEM_ID, false \)[\s\S]*stable_identity_row_count_invalid[\s\S]*stable_identity_value_invalid/, "only truly absent stable identity metadata may enable legacy relation fallback");
assert.match(publication, /\$manifest_source_item_ids[\s\S]*foreign_stable_identity/, "every persisted stable identity in one candidate menu must belong to the exact manifest authority set");
assert.match(relationAuthority, /public_header_ephemeral_relation_snapshot[\s\S]*public_header_fresh_custom_relation[\s\S]*public_header_fresh_content_relations/, "one Relation Authority Module must own canonical page and custom-link resolution");
assert.doesNotMatch(publication + relationAuthority, /private static function public_header_(?:fresh_relation_snapshot|fresh_page_relations|translation_index_relation_cross_check|lock_public_header_authority_surface)/, "the legacy parallel relation authority seam must stay removed");
assert.doesNotMatch(relationAuthority, /localized_internal_link_map|localized_internal_link_target|find_translation_id/, "relation authority must never select through request-static or Translation Index-first fallbacks");
assert.match(canonicalContentRelations, /FROM \{\$wpdb->posts\}[\s\S]*source_meta_sql[\s\S]*relation_source_translation_identity_present[\s\S]*INNER JOIN \{\$wpdb->postmeta\} source_meta[\s\S]*INNER JOIN \{\$wpdb->postmeta\} language_meta[\s\S]*1 !== count\( \$ids \)[\s\S]*public_header_relation_index_cross_check/, "canonical source status/type/identity and exact target metadata must choose the sole relation before the Index cross-check");
assert.doesNotMatch(canonicalContentRelations, /translation_index_available|translation_index_table/, "Translation Index must never select Public Header relation candidates");
assert.match(indexCrossCheck, /translation_index_available[\s\S]*public_header_page_relation_index_unavailable[\s\S]*source_page_classified_as_translation[\s\S]*canonical_relation_disagrees_with_index/, "an unavailable or disagreeing Translation Index must fail closed only after canonical selection");
assert.match(routeAuthority, /post_parent[\s\S]*post_name[\s\S]*META_LOCALIZED_PATH[\s\S]*META_CANONICAL_ROUTE[\s\S]*get_permalink[\s\S]*public_header_route_permalink_drift[\s\S]*phroute_/, "internal custom links must bind canonical source/target URLs and complete route-bearing state");
assert.match(relationAuthority, /'source_url'[\s\S]*'target_url'[\s\S]*'url'[\s\S]*'route_post_ids'[\s\S]*'route_revision'/, "internal custom-link receipts must bind source, target, URL, and route revision authority");
assert.match(authorityLock, /lock_localized_menu_projection_surface[\s\S]*\{\$wpdb->posts\}[\s\S]*FOR UPDATE[\s\S]*\{\$wpdb->postmeta\}[\s\S]*FOR UPDATE[\s\S]*canonical_meta_predicate_locked[\s\S]*canonical_route_predicate_locked/, "authority/current/staged menus, canonical posts, identity predicates, and route predicates must be explicitly locked");
assert.match(authorityLock, /\{\$wpdb->postmeta\}[\s\S]*self::META_SOURCE_ID, self::META_LANGUAGE/, "the locked metadata predicate must cover both canonical translation identity keys");
assert.match(authorityLock, /'canonical_meta_predicate_locked' => \$relation_authority_present[\s\S]*'canonical_route_predicate_locked' => ! empty\( \$route_post_ids \)/, "lock telemetry must report the route predicate only when an internal route surface was actually locked");
assert.match(stateReplace, /lock_public_header_relation_authority_surface[\s\S]*devenia_workflow_public_header_authority_after_locked_surface[\s\S]*validate_public_header_relation_receipts[\s\S]*validate_public_header_authority_receipts/, "the deterministic final-boundary race Seam must run only after complete relation locks and before both revalidations");
assert.match(relationAuthority, /public_header_relation_receipts_for_manifest[\s\S]*configured_public_header_languages[\s\S]*validate_public_header_relation_receipts[\s\S]*public_header_relation_receipts_missing/, "every pending projection must carry one complete all-language ephemeral relation receipt set");
assert.match(publication, /public_header_authority_receipts[\s\S]*manifest_revision[\s\S]*relation_revision[\s\S]*candidates[\s\S]*receipt_revision/, "temporary authority receipts must bind manifest, relation, candidates, and their canonical receipt revision");
assert.match(publication, /validate_public_header_authority_receipts[\s\S]*array_key_exists\( 'authority_receipts'[\s\S]*public_header_authority_receipts_invalid[\s\S]*public_header_authority_receipt_language_set_invalid[\s\S]*public_header_authority_receipt_revision_invalid/, "malformed or incomplete authority receipts must fail closed rather than fall back dynamically");
assert.match(publication, /sync_public_header_projection[\s\S]*validate_public_header_authority_receipts[\s\S]*devenia_workflow_public_header_authority_before_final_revalidation[\s\S]*public_header_authority_changed_before_activation/, "authority receipts must be validated before and after complete-set staging");
assert.match(publication, /stage_public_header_manifest_for_publication[\s\S]*public_header_relation_receipts_for_manifest[\s\S]*OPTION_PENDING_PUBLIC_HEADER_MANIFEST/, "ordinary Translation Job and operator restaging must mint fresh relation receipts before pending state exists");
assert.match(publication, /public_header_projection_plan[\s\S]*require_relation_receipts[\s\S]*validate_public_header_relation_receipts[\s\S]*page_relation_authority_missing[\s\S]*custom_relation_authority_missing/, "projection mutation must consume exact receipt-bound page and custom relations without fallback");
assert.match(publicHeaderRuntime, /wp_get_nav_menu_items\( absint\( \$projection\['target_menu'\]\['id'\][\s\S]*_devenia_translation_source_menu_item_id[\s\S]*\$relation\['object_id'\][\s\S]*normalize_primary_navigation_url[\s\S]*staged_receipt_surface_exact/, "runtime evidence must compare actual staged object IDs and custom URLs directly with receipt relations");
assert.match(publicHeaderRuntime, /extra_meta_candidate[\s\S]*add_post_meta[\s\S]*authority_menu[\s\S]*wp_update_post[\s\S]*devenia_workflow_public_header_authority_after_locked_surface/, "runtime must inject real relation and authority-menu mutations at the locked final boundary");
assert.match(publicHeaderRuntime, /raw_nav_menu_inventory[\s\S]*missing_relation_menu_inventory_before[\s\S]*locked_route_menu_inventory_before/, "raw failure oracles must include the complete nav_menu inventory so unknown staged menus cannot escape the known fixture set");
assert.match(publicHeaderRuntime, /public-header-relation-lock-worker\.php[\s\S]*proc_open[\s\S]*expected_keys = array\( 'success', 'phase', 'errno', 'connection_id', 'engine' \)/, "the parent runtime must execute the separate worker without a shell and require its five exact receipt fields");
assert.match(publicHeaderRuntime, /'' !== \(string\) \$stderr[\s\S]*\$exact_json !== \$stdout/, "the parent runtime must reject worker stderr and every non-canonical stdout byte");
assert.match(publicHeaderRuntime, /'post_slug'[\s\S]*'identity_meta'[\s\S]*\$run_external_writer\( 'under'[\s\S]*1205/, "both post-row and metadata-predicate modes must reach the exact under-lock timeout proof");
assert.match(publicHeaderRuntime, /identity_meta_source[\s\S]*_devenia_translation_source_id[\s\S]*'expected' => 'absent'[\s\S]*identity_meta_language[\s\S]*_devenia_translation_language[\s\S]*'expected' => 'absent'/, "the predicate oracle must INSERT against absent source-ID and language-key rows, not update existing metadata");
assert.match(publicHeaderRuntime, /\$run_external_writer\( 'before'[\s\S]*\$run_external_writer\( 'after'[\s\S]*\$run_external_writer\( 'after'/, "worker phases need positive before/after writes and a second idempotent cleanup pass");
assert.match(publicHeaderRuntime, /SELECT CONNECTION_ID\(\)[\s\S]*worker_connections_distinct[\s\S]*\$connection_id === \$external_writer_main_connection_id/, "every worker phase must use a distinct connection from the stable owning transaction connection");
assert.match(publicHeaderRuntime, /writer_state_before[\s\S]*writer_raw_menus_before[\s\S]*writer_menu_inventory_before[\s\S]*writer_relation_surface_before[\s\S]*writer_state_after/, "separate writer proofs must compare exact options, menus, global inventory, post rows, and metadata after rollback and cleanup");
assert.match(publicHeaderRuntime, /existing_source_item_ids[\s\S]*max\( \$existing_source_item_ids \)[\s\S]*missing_target_state_before[\s\S]*update_public_header_manifest[\s\S]*relation_translation_missing[\s\S]*missing_target_state_after[\s\S]*public_header_relation_receipt_build_failed[\s\S]*public_header_page_relation_authority_incomplete/, "a missing target translation must use a collision-free fixture and assert the exact nested relation-authority error chain before any state mutation");
assert.match(publicHeaderRuntime, /missing_target_raw_menus_before[\s\S]*missing_target_menu_inventory_before[\s\S]*missing_target_relation_surface_before[\s\S]*missing_target_raw_menus_before !== \$raw_fixture_menu_surface\(\)[\s\S]*missing_target_menu_inventory_before !== \$raw_nav_menu_inventory\(\)[\s\S]*missing_target_relation_surface_before !== \$raw_relation_post_surface/, "the missing-target oracle must preserve raw fixture menus, the global nav-menu inventory, and the canonical post/meta surface");
assert.doesNotMatch(publicHeaderRuntime, /source_item_id' => 800004[^\n]*Missing short target/, "the missing-target fixture must never collide with a real manifest source-item identity");
assert.match(lockWorker, /identity_meta\|post_slug[\s\S]*reconnect_retries[\s\S]*new wpdb[\s\S]*innodb_lock_wait_timeout = 1/, "the worker must create a disposable non-reconnecting database connection for two allowlisted lock surfaces");
assert.match(lockWorker, /INSERT INTO %i \(post_id, meta_key, meta_value\)[\s\S]*WHERE NOT EXISTS[\s\S]*DELETE FROM %i WHERE meta_id[\s\S]*UPDATE %i SET post_name/, "the worker must test an absent metadata predicate with exact INSERT/delete restoration and the post row with a CAS UPDATE");
assert.match(lockWorker, /'identity_meta' === \$surface_mode[\s\S]*_devenia_translation_source_id[\s\S]*_devenia_translation_language[\s\S]*'absent' !== \$expected/, "identity-predicate worker input must require an absent pre-state and allow only the two canonical identity keys");
assert.doesNotMatch(lockWorker, /UPDATE %i SET meta_value/, "an existing-row metadata UPDATE is not evidence for the absent identity predicate lock");
assert.match(lockWorker, /information_schema\.TABLES[\s\S]*'INNODB'/, "the worker must fail closed unless the exact written surface is InnoDB");
assert.match(lockWorker, /'after' === \$phase[\s\S]*restore[\s\S]*\$write_applied[\s\S]*finally[\s\S]*restore/, "unexpected success and after-phase recovery must remain fail-closed and cleanup-aware");
assert.match(lockWorker, /'success'[\s\S]*'phase'[\s\S]*'errno'[\s\S]*'connection_id'[\s\S]*'engine'/, "worker output must stay limited to the five allowlisted receipt fields");
const wrongIndexRuntime = boundedSource(runtime, "$wrong_index_job_key =", "$attempt_limit_job_key =");
const wrongIndexRuntimeContractPasses = (source) => {
	const adapterStart = source.indexOf("$wrong_index_commit_adapter =");
	const realCommit = "$actual = $call( 'translation_job_commit_recovery_transaction' );";
	const indexDelete = "$call( 'delete_translation_index_for_post', $wrong_index_target_id, $wrong_index_target_post );";
	return adapterStart > 0
		&& !source.slice(0, adapterStart).includes("delete_translation_index_for_post")
		&& source.split(realCommit).length - 1 === 1
		&& source.split(indexDelete).length - 1 === 1
		&& (source.match(/\$actual\s*=/g) || []).length === 1
		&& source.indexOf(realCommit, adapterStart) < source.indexOf(indexDelete, adapterStart)
		&& /\$wrong_index_rows_before[\s\S]*1 !== count\( \(array\) \$wrong_index_rows_before \)/.test(source)
		&& /\$wrong_index_commit_adapter = static function[\s\S]*\+\+\$wrong_index_commit_calls;[\s\S]*if \( 1 !== \$wrong_index_commit_calls \) \{ throw new RuntimeException\( 'Wrong-index COMMIT Adapter was invoked more than once\.' \); \}[\s\S]*translation_job_commit_recovery_transaction[\s\S]*true !== \$actual\['committed'\][\s\S]*delete_translation_index_for_post[\s\S]*\$wrong_index_rows_missing[\s\S]*\$wrong_index_commit_receipt = \$actual;[\s\S]*\$wrong_index_injected = true;[\s\S]*return \$actual;/.test(source)
		&& /add_filter\( 'devenia_workflow_localized_presentation_commit_adapter_result', \$wrong_index_commit_adapter, 10, 4 \)[\s\S]*translation_job_publish[\s\S]*remove_filter\( 'devenia_workflow_localized_presentation_commit_adapter_result', \$wrong_index_commit_adapter, 10 \)/.test(source)
		&& /\$wrong_index_content_authority_exact[\s\S]*wrong_index_meta_without_ids[\s\S]*! \$wrong_index_injected[\s\S]*1 !== \$wrong_index_commit_calls[\s\S]*\$wrong_index_commit_receipt\['success'\][\s\S]*! empty\( \$wrong_index_rows_missing \)[\s\S]*forward_publication_applied[\s\S]*published[\s\S]*rollback'\]\['success[\s\S]*restored_exact[\s\S]*restored_verified[\s\S]*public_header_page_relation_index_mismatch[\s\S]*\$wrong_index_rows_before !== \$wrong_index_rows_after[\s\S]*! \$wrong_index_content_authority_exact/.test(source);
};
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime), true, "ordinary Translation Job publication must pass intact reader preflight, remove one exact Index row only after real content COMMIT, fail fresh relation staging, and restore the exact row");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("$actual = $call( 'translation_job_commit_recovery_transaction' );", "$actual = array( 'success' => true, 'committed' => true );")), false, "the wrong-Index gate must reject a fabricated COMMIT receipt");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("$call( 'delete_translation_index_for_post', $wrong_index_target_id, $wrong_index_target_post );", "$call( 'sync_translation_index_row', $wrong_index_target_id );")), false, "the wrong-Index gate must require the real post-COMMIT row loss");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("$actual = $call( 'translation_job_commit_recovery_transaction' );", "$call( 'delete_translation_index_for_post', $wrong_index_target_id, $wrong_index_target_post ); $actual = $call( 'translation_job_commit_recovery_transaction' );")), false, "the wrong-Index gate must reject Index loss before the real content COMMIT");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("if ( 1 !== $wrong_index_commit_calls ) { throw new RuntimeException( 'Wrong-index COMMIT Adapter was invoked more than once.' ); }", "if ( 1 !== $wrong_index_commit_calls ) { return null; }")), false, "the wrong-Index gate must make every unexpected second Adapter invocation a hard RED");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $wrong_index_commit_adapter, 10, 4 );", "add_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $wrong_index_commit_adapter, 10, 4 );")), false, "the wrong-Index gate must bind the race to the content-COMMIT boundary after reader preflight");
assert.equal(wrongIndexRuntimeContractPasses(wrongIndexRuntime.replace("return $actual;", "return $default;")), false, "the wrong-Index gate must return the real COMMIT receipt to production reconciliation");
assert.doesNotMatch(runtime, /devenia_workflow_retire_previous_localized_menu_projection/, "the Translation Job runtime must not register the retired no-op menu-retirement hook");
assert.match(runtime, /wrong_index_menu_inventory_before[\s\S]*raw_nav_menu_inventory[\s\S]*ordinary_translation_job_wrong_index_preserved_raw_authority/, "wrong-Index publication must preserve the complete raw nav_menu inventory as well as content and authority rows");
const failedProjectionStart = runtime.indexOf("$fail_projection_write =");
const failedProjectionEnd = runtime.indexOf("if ( $source_thumbnail_id !==", failedProjectionStart);
assert.ok(failedProjectionStart > 0 && failedProjectionEnd > failedProjectionStart, "missing bounded atomic projection failure fixture");
const failedProjectionContractPasses = (source) => {
	const start = source.indexOf("$fail_projection_write =");
	const end = source.indexOf("if ( $source_thumbnail_id !==", start);
	if (start < 0 || end <= start) return false;
	const bounded = source.slice(start, end);
	return /sync_language_menu', array\([\s\S]*'language' => \$language[\s\S]*'manifest' => \$runtime_pending_manifest/.test(bounded)
		&& /menu_projection_write_failed[\s\S]*\$runtime_active_menu_id !== absint\( \$identity_after_failure\[ \$language \]\['menu_id'\]/.test(bounded);
};
assert.equal(failedProjectionContractPasses(runtime), true, "the atomic projection write-failure oracle must cross a current receipt-bound manifest before testing writer cleanup and active identity preservation");
assert.equal(failedProjectionContractPasses(runtime.replace(", 'manifest' => $runtime_pending_manifest", "")), false, "the projection failure gate must reject a direct call without current relation receipts");
assert.match(databaseRuntimeSuite, /DATABASE_EXPECTATION:-mariadb[\s\S]*mariadb\)[\s\S]*10\\\.11\\\.[\s\S]*mysql-8\.4\)[\s\S]*8\\\.4\\\.[\s\S]*check-recovery-transaction-portability-runtime\.php[\s\S]*scenarios[\s\S]*22[\s\S]*check-public-header-projection-wordpress-runtime\.php[\s\S]*separate_connection_post_lock_blocked_writer[\s\S]*separate_connection_meta_predicate_lock_blocked_writer[\s\S]*check-translation-job-runtime\.php[\s\S]*ordinary_translation_job_wrong_index_preserved_raw_authority/, "the repository-owned database suite must default to MariaDB and require every real relation-authority runtime proof while retaining optional MySQL compatibility");
assert.match(databaseRuntimeSuite, /check-primary-navigation-parser\.php[\s\S]*Primary navigation parser contract passed\./, "the repository-owned release runtime suite must execute and verify the lightweight primary-navigation parser contract");
assert.match(databaseRuntimeSuite, /check-frontend-cache-batch\.php[\s\S]*Frontend cache batch contract passed\./, "the repository-owned release runtime suite must execute and verify bounded frontend cache batching");
assert.doesNotMatch(databaseRuntimeSuite, /GITHUB_|\.github\/workflows|github\.com|workflow_(?:dispatch|run)|repository_dispatch/i, "the canonical database runtime suite must run without GitHub or GitHub Actions");
assert.match(translationJobRuntimeSuite, /check-new-page-localized-path-contract\.php[\s\S]*DEVENIA_WORKFLOW_RUNTIME_SCOPE=new-page-route[\s\S]*check-translation-job-runtime\.php[\s\S]*legacy_new_page_localized_path_derived_from_signed_route_inputs[\s\S]*translation-job-change-scoped/, "route/publication releases must have a bounded Translation Job gate instead of exercising the unrelated Public Header stress suite on shared dev hosting");
assert.doesNotMatch(translationJobRuntimeSuite, /check-public-header-projection-wordpress-runtime\.php/, "the change-scoped Translation Job gate must not run the unrelated Public Header stress suite");
assert.match(publication, /activate_public_header_projection_set[\s\S]*unset\( \$active_manifest\['authority_receipts'\] \)[\s\S]*unset\( \$active_manifest\['relation_receipts'\] \)/, "ephemeral intake and relation receipts must never persist as active reader authority");
assert.match(migrationInterface, /devenia_workflow_public_header_migration_before_final_authority_revalidation[\s\S]*public_header_manifest\(\)[\s\S]*validate_public_header_authority_receipts/, "schema-1 migration must revalidate exact active and intake authority before staging");
assert.match(plugin, /localized_link_expected_target_map[\s\S]*source_language_code\(\) === \$language/, "localized link relation logic must use configured source-language authority");
const queryIdentityLinkAuthorityPasses = (pluginSource, readModelSource, runtimeSource) => {
	const map = boundedSource(pluginSource, "private static function localized_link_expected_target_map", "private static function frontend_row_target_link_variants");
	const indexedMapEnd = map.indexOf("$cache[ $language ] = $map;");
	const indexedMap = indexedMapEnd > 0 ? map.slice(0, indexedMapEnd) : "";
	const shortlinks = boundedSource(pluginSource, "private static function content_shortlink_variants", "private static function frontend_row_legacy_source_slug_link_variants");
	const target = boundedSource(pluginSource, "private static function localized_internal_link_target", "private static function wordpress_content_query_id_from_parts");
	const queryBranchStart = target.indexOf("if ( $content_query_id )");
	const queryBranchEnd = target.indexOf("} else {", queryBranchStart);
	const queryBranch = queryBranchStart >= 0 && queryBranchEnd > queryBranchStart ? target.slice(queryBranchStart, queryBranchEnd) : "";
	const frontendRows = boundedSource(readModelSource, "private static function frontend_rows_from_index_rows", "\n}");
	const refreshRuntime = boundedSource(runtimeSource, "$refresh_writer_packet =", "$build_runtime_refresh_artifact =");
	const unknownQueryRuntime = boundedSource(runtimeSource, "try {", "$replace_fragment =");
	const packetRuntime = boundedSource(runtimeSource, "$packet = $call( 'translation_job_fetch_packet'", "$pre_submit_surface_revision =");
	return /array_merge\([\s\S]*\$source_variants\[ \$source_id \][\s\S]*content_shortlink_variants\( \(int\) \$source_id \)/.test(indexedMap)
		&& /frontend_row_target_link_variants\( \$row, false \)[\s\S]*content_shortlink_variants\( \$translation_id, \$lang \)/.test(indexedMap)
		&& /if \( ! \$post_id \)[\s\S]*foreach \( array\( 'page_id', 'p', 'post_id' \)/.test(shortlinks)
		&& !/get_post\(/.test(shortlinks)
		&& (target.match(/\$candidates\s*=/g) || []).length === 2
		&& !/\$candidates\s*\[|array_(?:push|unshift)\(\s*\$candidates|array_merge\(\s*\$candidates/.test(target)
		&& /\$candidates = self::content_shortlink_variants\( \$content_query_id \)/.test(queryBranch)
		&& !/\$path|trailingslashit|untrailingslashit/.test(queryBranch)
		&& /} else \{[\s\S]*\$path[\s\S]*trailingslashit\( \$path \)/.test(target)
		&& /\$source_url\s*= '' === \$source_path \? '' : home_url\( '\/' \. \$source_path \. '\/' \)[\s\S]*\( '' === \$source_url && '' === \$source_path \)/.test(frontendRows)
		&& !/get_permalink\(|get_post\(/.test(frontendRows)
		&& /\$query_identity_root_map[\s\S]*array\( 'page_id', 'p', 'post_id' \)[\s\S]*home_url\([\s\S]*'\/\?'[\s\S]*null !== \$call\( 'localized_internal_link_target'[\s\S]*Unknown WordPress query-ID link fell through/.test(unknownQueryRuntime)
		&& /\$untranslated_packet_link_rows[\s\S]*\$localized_packet_link_rows[\s\S]*2 !== count\( \$links \)[\s\S]*false !== \( \$untranslated_packet_link\['published_target_available'\][\s\S]*retain_source_url_until_localized_target_is_published[\s\S]*true !== \( \$localized_packet_link\['published_target_available'\][\s\S]*use_published_localized_target[\s\S]*\$localized_packet_link\['source_url'\][\s\S]*=== \$call\( 'normalized_comparable_url', \$expected_localized_packet_link_url \)/.test(packetRuntime)
		&& /\$refresh_link_rows[\s\S]*\$linked_source_id === absint\( \$row\['source_post_id'\][\s\S]*\$linked_source_id !== absint\( \$refresh_link_row\['target_post_id'\][\s\S]*published_target_available[\s\S]*retain_source_url_until_localized_target_is_published[\s\S]*Untranslated query-ID source link was aliased/.test(refreshRuntime);
};
assert.equal(queryIdentityLinkAuthorityPasses(plugin, indexReadModel, runtime), true, "query-ID links must resolve only through their exact content identity, never a generic path candidate, and the real packet must retain an untranslated source");
const missingIndexedSourceShortlinks = plugin.replace("self::content_shortlink_variants( (int) $source_id )", "array()");
assert.notEqual(missingIndexedSourceShortlinks, plugin, "the indexed-source-shortlink mutation fixture must alter production source");
assert.equal(queryIdentityLinkAuthorityPasses(missingIndexedSourceShortlinks, indexReadModel, runtime), false, "the query-ID gate must reject removal of indexed source shortlink identities");
const missingIndexedTargetShortlinks = plugin.replace("self::content_shortlink_variants( $translation_id, $lang )", "array()");
assert.notEqual(missingIndexedTargetShortlinks, plugin, "the indexed-target-shortlink mutation fixture must alter production source");
assert.equal(queryIdentityLinkAuthorityPasses(missingIndexedTargetShortlinks, indexReadModel, runtime), false, "the query-ID gate must reject removal of indexed translated-target shortlink identities");
assert.equal(queryIdentityLinkAuthorityPasses(plugin.replace("$candidates = self::content_shortlink_variants( $content_query_id );", "$candidates = array( $path );"), indexReadModel, runtime), false, "the query-ID gate must reject generic path fallback for an explicit WordPress content ID");
const appendedQueryPathFallback = plugin.replace("\t\t}\n\n\t\tforeach ( $candidates as $candidate ) {", "\t\t}\n\t\tif ( $content_query_id ) { $candidates[] = $path; }\n\n\t\tforeach ( $candidates as $candidate ) {");
assert.notEqual(appendedQueryPathFallback, plugin, "the post-branch path-fallback mutation fixture must alter production source");
assert.equal(queryIdentityLinkAuthorityPasses(appendedQueryPathFallback, indexReadModel, runtime), false, "the query-ID gate must reject a generic path candidate appended after the protected query branch");
const collapsedEmptySourcePath = indexReadModel.replace("$source_url     = '' === $source_path ? '' : home_url( '/' . $source_path . '/' );", "$source_url     = home_url( '/' . $source_path . '/' );");
assert.notEqual(collapsedEmptySourcePath, indexReadModel, "the empty-source-path mutation fixture must alter production source");
assert.equal(queryIdentityLinkAuthorityPasses(plugin, collapsedEmptySourcePath, runtime), false, "the query-ID gate must reject collapsing an empty source path into root-path authority");
assert.equal(queryIdentityLinkAuthorityPasses(plugin, indexReadModel, runtime.replace("$linked_source_id !== absint( $refresh_link_row['target_post_id'] ?? 0 )", "$linked_source_id < 1")), false, "the runtime oracle must bind the packet target to the exact untranslated source identity");
assert.match(publication, /intake_state_restore/);
assert.match(enrollmentInterface, /stage_first_public_header_enrollment_transaction[\s\S]*lock_public_header_relation_authority_surface[\s\S]*theme_mods_[\s\S]*FOR UPDATE[\s\S]*devenia_workflow_public_header_enrollment_before_locked_stage_revalidation/);
assert.match(publication, /reconcile_first_public_header_enrollment_commit_outcome[\s\S]*! \$pre_state_proven && \$applied_state_proven[\s\S]*foreign_state_observed[\s\S]*public_header_enrollment_commit_reconciliation_conflict[\s\S]*public_header_enrollment_commit_outcome_unknown_conflict[\s\S]*public_header_enrollment_commit_reconciliation_failed/, "reconciliation may restore only this operation's exact expected-after state and must preserve foreign state");
assert.match(publication, /current_is_before[\s\S]*current_is_owned_staging[\s\S]*activation_severe[\s\S]*public_header_enrollment_severe_rollback_not_bypassed[\s\S]*public_header_enrollment_intake_restore_conflict/, "post-activation intake recovery must accept exact before, restore only the receipt-bound staging state, and preserve foreign or severe state");
assert.match(publication, /expected_state_revision[\s\S]*translation_job_canonicalize\( \$expected_state \)/, "first-enrollment stage must bind its exact owned staging state receipt");
assert.match(publication, /owned_staging_receipt_valid[\s\S]*hash_equals\( \(string\) \$stage\['expected_state_revision'\], \$owned_staging_revision \)[\s\S]*current_is_owned_staging/, "post-activation restore must validate the stage-state receipt before CAS");
assert.match(publication, /\$current_is_owned_staging && self::public_header_state_excludes_staged_menu_ids\( \$after_restore,[\s\S]*true \)/, "severe cleanup may use only the exact receipt-valid owned staging state, never arbitrary foreign state");
assert.match(publication, /__devenia_workflow_option_missing__' === \$identities[\s\S]*return \$exact_owned_state/, "an exact owned staging receipt must prove its intentionally absent identity slot excludes staged menu references");
assert.match(publication, /reconcile_public_header_state_commit_outcome[\s\S]*false === \$committed[\s\S]*true === \$committed[\s\S]*null === \$committed[\s\S]*state_outcome[\s\S]*foreign/, "activation commit must preserve three-valued applied, unapplied, and foreign outcomes");
assert.match(publication, /devenia_workflow_public_header_state_commit_adapter_result[\s\S]*clear_public_header_state_option_cache\(\)[\s\S]*return self::reconcile_public_header_state_commit_outcome/, "every activation COMMIT receipt, including success=true/committed=true, must cross cache-cleared exact reconciliation");
assert.match(publication, /reconcile_public_header_state_commit_outcome[\s\S]*\$state_class = \$expected_exact \? 'expected'[\s\S]*true === \( \$commit\['success'\][\s\S]*public_header_state_commit_applied[\s\S]*public_header_state_commit_reconciliation_conflict/, "activation success requires the exact owned replacement while expected and foreign post-COMMIT state remain classified");
assert.match(publication, /devenia_workflow_public_header_enrollment_commit_adapter_result[\s\S]*clear_first_public_header_enrollment_option_cache[\s\S]*reconcile_first_public_header_enrollment_commit_outcome/, "every first-enrollment COMMIT receipt must cross cache-cleared exact reconciliation");
assert.match(publication, /public_header_activation_cleanup_authority[\s\S]*clear_public_header_state_option_cache[\s\S]*receipt_current_exact[\s\S]*outcome_unapplied[\s\S]*public_header_state_excludes_staged_menu_ids/, "staged cleanup requires a fresh exact unapplied-state proof and no identity references");
assert.match(publication, /delete_staged_public_header_projections[\s\S]*translation_job_begin_recovery_transaction[\s\S]*lock_localized_menu_projection_surface[\s\S]*FOR UPDATE[\s\S]*public_header_state_excludes_staged_menu_ids[\s\S]*devenia_workflow_public_header_staged_cleanup_before_locked_revalidation[\s\S]*staged_projection_cleanup_identity_changed[\s\S]*wp_delete_nav_menu[\s\S]*translation_job_commit_recovery_transaction/, "identity proof, receipt revalidation, and every staged-menu deletion must share one owned transaction and fail closed on the deterministic race Seam");
assert.match(publication, /public_header_projection_activation_state_unresolved/, "applied, unknown, or foreign activation state must remain structured critical without cleanup");
assert.match(publication, /clear_first_public_header_enrollment_option_cache[\s\S]*alloptions[\s\S]*notoptions/, "transaction rollback must evict stale theme and option cache values");
assert.match(publication, /pre_enrollment_public_header_recovery_snapshot[\s\S]*array\( 'origin', 'canonical' \)[\s\S]*expected_navigation/);
assert.match(publication, /verify_pre_enrollment_public_header_navigation[\s\S]*array\( 'origin', 'canonical' \)[\s\S]*primary_navigation_from_html[\s\S]*\$actual === \$expected/, "pre-enrollment recovery must use a dedicated exact raw-navigation verifier");
assert.match(publication, /primary_navigation_from_html[\s\S]*\/\/ul\[contains\(concat\(' ', normalize-space\(@class\), ' '\), ' menu '\)\][\s\S]*devenia-language-trigger[\s\S]*devenia-language-menu-item/, "primary navigation parsing must target the owned menu list and exclude the presentation-injected language selector by generic class identity");
assert.match(plugin, /devenia-language-group-heading[\s\S]*lang="%1\$s" dir="%2\$s"[\s\S]*hreflang_for_language\( \$current_language \)[\s\S]*language_direction_for_language\( \$current_language \)/, "language-selector region headings must declare the current language and its semantic direction without locale-specific presentation rules");
assert.match(plugin, /PUBLIC_HEADER_REQUEST_CONCURRENCY_LIMIT = 8[\s\S]*PUBLIC_HEADER_BATCH_BUDGET_SECONDS = 75/, "the Public Header transport must own explicit site-neutral same-site concurrency and wall-runtime bounds");
assert.match(publication, /public_header_dispatch_timeout[\s\S]*reserved_for_later[\s\S]*remaining_dispatches - 1[\s\S]*minimum_timeout[\s\S]*fetch_frontend_cache_surfaces[\s\S]*devenia_workflow_public_header_self_fetch_concurrency_limit[\s\S]*min\( self::PUBLIC_HEADER_REQUEST_CONCURRENCY_LIMIT[\s\S]*array_chunk\( \$native, \$concurrency_limit, true \)[\s\S]*public_header_batch_budget_exceeded[\s\S]*deadline = microtime\( true \) \+ self::PUBLIC_HEADER_BATCH_BUDGET_SECONDS[\s\S]*wall_remaining = \(int\) floor\( \$deadline - microtime\( true \) \)[\s\S]*public_header_batch_budget_exhausted[\s\S]*request_multiple[\s\S]*public_header_batch_result_key_mismatch[\s\S]*public_header_batch_member_failed/, "frontend cache evidence must keep every cache surface under one absolute same-site cap and preserve the wall deadline, key set, and member fail-closed mapping");
const frontendCacheBatchMethod = publication.match(/private static function fetch_frontend_cache_surfaces[\s\S]*?\n\t}/)?.[0] ?? "";
assert.equal((frontendCacheBatchMethod.match(/request_multiple\(/g) ?? []).length, 1, "the complete Public Header matrix must have exactly one real multi-dispatch site");
assert.doesNotMatch(frontendCacheBatchMethod, /fetch_frontend_cache_surface\(|wp_(?:safe_)?remote_get\(/, "the concurrent all-language Interface must never hide a single-request fallback path");
assert.doesNotMatch(frontendCacheBatchMethod, /canonical_dispatch|origin_dispatch|public_header_origin_concurrency_limit/, "canonical requests must never regain an unbounded or separately privileged dispatch path");
assert.doesNotMatch(frontendCacheBatchMethod, /remaining_budget/, "the wall deadline must reclaim actual fast-group time instead of debiting configured timeout maxima");
assert.match(frontendCacheBatchMethod, /\$wall_remaining = \(int\) floor\( \$deadline - microtime\( true \) \);\s*\$dispatch_timeout = self::public_header_dispatch_timeout\( \$requested_timeout, \$remaining_dispatches, \$wall_remaining, \$minimum_timeout \);\s*if \( \$dispatch_timeout < \$minimum_timeout \)[\s\S]*?'timeout' => \$dispatch_timeout[\s\S]*?'connect_timeout' => \$dispatch_timeout[\s\S]*?request_multiple\( \$dispatch, \$dispatch_options \)/, "the live dispatch loop must consume the fresh wall deadline through the allocator, minimum gate, and exact Requests timeout options");
assert.match(liveFrontendBatchRuntime, /configured_public_header_languages[\s\S]*public_header_frontend_cache_response_set[\s\S]*primary_navigation_from_html[\s\S]*coordinates[\s\S]*http_200[\s\S]*parser_pass[\s\S]*exit\( 1 \)/, "the release gate must execute the exact installed same-site transport and parser across every configured coordinate");
assert.match(liveFrontendBatchRuntime, /false !== has_filter\( 'devenia_workflow_frontend_cache_batch_adapter_result' \)[\s\S]*refuses to run while a frontend batch Adapter is registered/, "the live same-site release gate must reject every Adapter that could bypass the real WordPress Requests transport");
assert.match(liveFrontendBatchRuntime, /DEVENIA_WORKFLOW_FRONTEND_MATRIX_B64[\s\S]*array_keys\( \$matrix \) !== \$languages[\s\S]*'https'[\s\S]*\$matrix_host !== \$host[\s\S]*fetch_frontend_cache_surfaces[\s\S]*\$fetched[\s\S]*supplemental_explicit_matrix[\s\S]*production_self_fetch_proven' => empty\( \$matrix \)/, "an explicit same-host matrix may exercise the real private batch transport only as visibly supplemental evidence; only installed-site URLs can prove production self-fetch");
assert.doesNotMatch(liveFrontendBatchRuntime, /devenia\.com|\b(?:nb|de|fr|es|sv|da|fi|it|nl|pt|zh|ja|vi|ar)\b/, "the live same-site gate must derive languages and URLs from installed WordPress authority");
assert.match(publication, /fetch_frontend_cache_surfaces[\s\S]*devenia_workflow_frontend_cache_batch_adapter_result[\s\S]*request_multiple[\s\S]*public_header_batch_result_key_mismatch[\s\S]*WP_HTTP_Requests_Response/, "the plugin-owned whole-batch Adapter and the real cURL multi result must cross the same exact-key and response-normalization path");
assert.match(publication, /'verify' => ABSPATH \. WPINC \. '\/certificates\/ca-bundle\.crt'/, "the direct Requests batch must retain WordPress-owned CA trust instead of depending on the host system root store");
assert.doesNotMatch(publication.match(/private static function fetch_frontend_cache_surfaces[\s\S]*?\n\t}/)?.[0] ?? "", /pre_http_request|http_request_args|http_response/, "the plugin-owned batch Adapter must not partially emulate WordPress HTTP filter contracts");
assert.doesNotMatch(publicHeaderRuntime, /devenia_workflow_frontend_cache_batch_adapter_result|<nav id="site-navigation"><ul class="menu">/, "the server-side Public Header stress runtime must not recreate the retired self-fetch oracle");
assert.match(runtime, /\$runtime_batch_http[\s\S]*devenia_workflow_frontend_cache_batch_adapter_result[\s\S]*sync_public_header_projection[\s\S]*remove_filter\( 'devenia_workflow_frontend_cache_batch_adapter_result'/, "the exact Translation Job runtime must keep Public Header verification deterministic through the same whole-batch Adapter while retaining its separate single-request media fixture");
assert.match(runtime, /<nav id="site-navigation"><ul class="menu">/, "the exact Translation Job runtime must render the owned primary menu-list boundary consumed by the production parser");
assert.match(publication, /verify_public_header_projection_set[\s\S]*self_referential_http_disabled[\s\S]*verify_pre_enrollment_public_header_navigation[\s\S]*self_referential_http_disabled/, "ordinary same-request forward checks must not self-fetch the WordPress origin");
assert.match(publication, /pre_enrollment_public_header_recovery_snapshot[\s\S]*public_header_frontend_cache_response_set/, "the explicit recovery snapshot must consume one complete bounded response set");
assert.match(publication, /frontend_public_surface_integrity_for_url[\s\S]*\?array \$provided_responses = null[\s\S]*null !== \$provided_responses[\s\S]*\$provided_responses\[ \$cache_surface \] \?\? array\(\)[\s\S]*fetch_frontend_cache_surface/, "a supplied Public Header batch must fail closed on a missing coordinate instead of silently retrying the old sequential path");
assert.match(publication, /public_header_pre_enrollment_oracle_missing[\s\S]*response_evidence[\s\S]*body_length/, "pre-enrollment failures must expose structured member and body evidence instead of hiding transport exhaustion behind an empty-oracle code");
assert.match(publication, /\$rollback_receipts\['pre_enrollment'\][\s\S]*verify_pre_enrollment_public_header_navigation[\s\S]*verify_public_header_projection_set/, "only pre-enrollment rollback may bypass forward managed-menu integrity rules");
assert.match(publication, /delete_staged_public_header_projections[\s\S]*staged_menu_receipt_mismatch[\s\S]*staged_menu_delete_failed/);
for (const provenance of ["explicit_authority", "retained_relation_match"]) {
  assert.match(publication, new RegExp(provenance), `first enrollment must preserve ${provenance} provenance`);
  assert.match(publicHeaderRuntime, new RegExp(provenance), `runtime must assert ${provenance} provenance behaviorally`);
}
assert.doesNotMatch(publication.slice(publication.indexOf("private static function enroll_public_header_from_existing_menus"), publication.indexOf("private static function migrate_public_header_label_authority")), /get_the_title/, "first enrollment must never infer labels from page titles");
assert.match(publication, /public_header_editorial_label_snapshot/);
assert.match(publication, /authority_candidate_conflict/);
assert.match(publication, /insufficient_independent_authority_candidates/);
assert.match(publication, /generated_label_drift/);
for (const provenance of ["known_identity", "configured_name", "retained_relation_match"]) {
  assert.match(publication, new RegExp(`resolved_from[\\s\\S]*${provenance}`), `migration evidence must preserve ${provenance} provenance`);
}
assert.doesNotMatch(publication.slice(publication.indexOf("private static function migrate_public_header_label_authority"), publication.indexOf("private static function update_public_header_manifest")), /get_the_title/, "label-authority migration must never infer editorial labels from content titles");
assert.match(publication, /\$item\['labels'\]\[ \$language \]/);
const planStart = publication.indexOf("private static function public_header_projection_plan");
const planEnd = publication.indexOf("private static function public_blog_archive_url_for_language", planStart);
const plan = publication.slice(planStart, planEnd);
assert.doesNotMatch(plan, /get_the_title|localized_menu_item_title/, "projection labels must never fall back to page titles or mutable runtime replacements");
assert.match(publication, /cancelled_pending/);
assert.doesNotMatch(sync, /wp_get_nav_menu_items\( \$source_menu/, "raw source menus must never be projection authority");
assert.doesNotMatch(publication, /resolved_from'\s*=> 'primary_theme_location'/, "raw primary theme location must never become an authoritative identity");
assert.match(identityReader, /private static function localized_menu_id\( string \$language \): int[\s\S]*OPTION_LOCALIZED_MENU_IDENTITIES[\s\S]*public_header_manifest[\s\S]*TERM_META_PUBLIC_HEADER_MANIFEST_REVISION[\s\S]*return 0;/, "ordinary identity reads must accept only exact stored active-manifest authority");
assert.doesNotMatch(identityReader, /update_option|add_option|delete_option|wp_get_nav_menus|menu_name|migrated_at|duplicate_ids/, "ordinary identity reads must be side-effect free and must never rediscover authority by configured name");
assert.doesNotMatch(plugin + publication, /localized_menu_id\([^\)]*,/, "ordinary callers must not retain a migration switch on the identity reader");
assert.match(plugin, /use_language_primary_menu[\s\S]*localized_menu_id\( \$language \)/);
assert.match(plugin, /add_filter\( 'pre_wp_nav_menu', array\( __CLASS__, 'fail_closed_primary_menu_markup' \)/);
assert.match(plugin, /fail_closed_primary_menu_markup[\s\S]*public_header_projection_is_enrolled[\s\S]*localized_menu_id\( \$language \)[\s\S]*: ''/);
assert.match(plugin, /is_language_menu_already_selected[\s\S]*localized_menu_id\( \$language \)/);
assert.match(plugin, /\$language_menu_already_selected[\s\S]*return \$items;/, "an already-selected managed projection must be final reader authority");
assert.doesNotMatch(plugin.slice(plugin.indexOf("public static function use_language_primary_menu"), plugin.indexOf("public static function localize_nav_menu_objects")), /['"]en['"]\s*===|===\s*['"]en['"]/, "source menu selection must be registry-driven");

assert.match(publication, /array\( 'origin', 'canonical' \)/);
assert.match(publication, /Live verification is a separate, callable step[\s\S]*'needs_live_verification' => true/, "publication must return an explicit incomplete live-verification invariant");
assert.doesNotMatch(publication, /\$input\['verify_live'\]/, "localized publication must not accept a synchronous verification switch");
assert.match(jobs, /'translation-job-verify-live'[\s\S]*translation_job_verify_live/, "Translation Job must expose the separate mandatory live-verification operation");
assert.match(publication, /'cf_cache_status'/);
assert.match(publication, /'age'/);
assert.match(publication, /frontend_primary_menu_projection_mismatch/);
assert.match(publication, /expected_localized_primary_navigation[\s\S]*public_header_projection_plan/);
assert.match(publication, /if \( \$actual === \$expected \)/);
assert.match(publication, /sync_public_header_projection[\s\S]*'homepage'[\s\S]*'blog_archive'/);
assert.match(publication, /public_header_failure_after_activation[\s\S]*public_header_projection_rollback[\s\S]*verify_public_header_projection_set[\s\S]*public_header_projection_severe_rollback_failure/);
assert.doesNotMatch(publicHeaderRuntime, /verification_fault_remaining|verification_extra_anchor/, "the server runtime must not retain dead self-fetch fault injection after live verification moved to the coordinator");
assert.match(publicHeaderRuntime, /forward_live_verification_delegated[\s\S]*rollback_live_verification_delegated/, "schema rollback must prove stored authority while making the delegated live-verification boundary explicit");
assert.match(publication, /TERM_META_PUBLIC_HEADER_MANIFEST_REVISION/);
assert.match(publication, /localized_menu_items_in_render_order[\s\S]*\$append\( \$item_id \)/, "nested menus must be compared in WordPress walker depth-first order");
assert.match(plugin, /frontend_integrity_language_filter[\s\S]*source_language_code\(\)[\s\S]*target_languages/);
assert.match(plugin, /frontend_integrity_surface_filter[\s\S]*blog_archives/);

for (const evidence of [
	"duplicate_source_page_references_disambiguated_by_item_identity",
	"duplicate_target_page_references_disambiguated_by_stable_identity",
	"wrong_language_stable_identity_rejected",
	"duplicate_exact_stable_identity_ambiguous",
	"duplicate_stable_identity_rows_rejected",
	"foreign_stable_identity_cannot_fallback",
	"mixed_foreign_identity_cannot_hide_behind_legacy_fallback",
	"absent_stable_identity_legacy_relation_fallback_verified",
	"single_explicit_authority_not_auto_supplemented",
	"explicit_authority_sets_all_or_nothing",
	"complete_explicit_authority_isolated_from_retained_conflicts",
	"real_theme_location_pre_enrollment_preserved",
	"real_theme_location_managed_source_exercised",
	"wp_nav_menu_args_managed_source_exercised",
	"editorial_labels_bound_by_source_item_identity",
	"source_short_label_not_page_title",
	"target_editorial_label_not_translated_page_title",
	"custom_child_label_and_parent_preserved",
	"missing_label_authority_preserved_old_active_set",
	"schema1_managed_label_runtime_override_bypassed",
	"schema1_label_authority_conflict_failed_closed",
	"schema1_explicit_authority_sets_all_or_nothing",
	"schema1_authority_revision_race_rejected",
	"schema1_to_schema2_migration_draft_created",
	"schema1_relation_receipt_race_rejected_before_activation",
	"relation_authority_consumed_by_staging",
	"source_translation_meta_identity_rejected",
	"source_translation_index_identity_rejected",
	"missing_relation_receipts_failed_without_raw_state_mutation",
	"meta_only_target_relation_rejected_at_locked_boundary",
	"staged_object_ids_and_custom_urls_equal_receipts",
	"internal_custom_route_drift_rolled_back_exactly",
	"relation_receipts_not_persisted_in_active_manifest",
	"authority_menu_changed_at_locked_boundary_rejected",
	"canonical_relation_predicate_locked_before_activation",
	"separate_connection_post_lock_blocked_writer",
	"separate_connection_meta_predicate_lock_blocked_writer",
	"authority_receipts_not_persisted_in_active_manifest",
	"schema1_post_activation_rollback_verified",
	"schema1_to_schema2_repair_activated",
	"unenrolled_authority_draft_mutation_free",
	"unenrolled_raw_navigation_oracle_exact",
	"unenrolled_commit_outcomes_reconciled",
	"unenrolled_unknown_commit_outcome_structured_critical",
	"unenrolled_foreign_commit_state_preserved",
	"unenrolled_post_activation_foreign_state_preserved",
	"unenrolled_severe_rollback_reference_closure_proven",
	"activation_commit_tristate_cleanup_authority_proven",
	"successful_header_commits_reconciled_before_activation",
	"staged_cleanup_identity_reference_race_failed_closed",
	"content_publication_commit_tristate_proven",
	"content_publication_applied_commit_refreshed_verified_header",
	"malformed_first_enrollment_commit_receipt_failed_closed",
	"malformed_header_activation_commit_receipt_failed_closed",
	"malformed_content_publication_commit_receipt_failed_closed",
	"non_array_header_commit_receipt_rolled_back_failed_closed",
	"unterminated_success_header_commit_receipt_rolled_back_failed_closed",
	"pending_foreign_race_preserved_then_fixture_cleaned",
	"locked_non_pending_state_race_rejected",
	"unenrolled_unrelated_menus_ignored",
	"unenrolled_locked_primary_source_authority_races_rejected",
	"unenrolled_server_owned_post_activation_failure_phases_recovered",
	"unenrolled_failure_restored_exact_option_state",
	"unenrolled_schema2_atomic_activation_verified",
	"managed_identity_missing_failed_closed",
	"managed_identity_corrupt_failed_closed",
	"managed_identity_missing_verification_failed_without_mutation",
	"managed_identity_corrupt_verification_failed_without_mutation",
	"identity_migration_interface_capability_gated",
	"missing_active_manifest_durable_enrollment_failed_closed",
	"pending_manifest_preserved_old_active_set",
	"active_restage_cancelled_stale_pending",
	"activation_receipt_binds_exact_pending_manifest",
	"missing_activation_receipt_failed_without_mutation",
	"stale_activation_receipt_rejected",
	"concurrent_pending_replacement_rejected_before_staging",
	"raw_normalization_equivalent_pending_rejected_before_staging",
	"raw_key_reorder_pending_rejected_before_staging",
	"raw_key_reorder_pending_rejected_at_locked_boundary",
	"exact_activation_receipt_activated",
	"ordinary_publication_rejected_raw_pending_owners",
	"ordinary_publication_rejected_independent_pending_without_mutation",
	"ordinary_publication_self_staged_exact_activation",
	"missing_target_projection_failed_closed",
	"missing_target_translation_rejected_before_pending_mutation",
	"all_language_atomic_activation_exercised",
	"pre_activation_receipt_failure_rejected",
	"staged_revision_race_rejected",
	"pending_manifest_race_rejected",
	"invalidation_failure_cache_safe_rollback",
	"idempotent_enrollment_transition_exercised",
	"retirement_failure_cache_safe_rollback",
	"rollback_cache_failure_structured_critical",
	"changed_old_receipt_never_reactivated",
	"live_header_verification_delegated_to_external_coordinator",
]) {
	assert.match(publicHeaderRuntime, new RegExp(evidence));
}
assert.match(publicHeaderRuntime, /missing_activation_state_before[\s\S]*sync_public_header_projection', array\( 'timeout' => 5 \)[\s\S]*public_header_activation_receipt_missing[\s\S]*missing_activation_state_after[\s\S]*missing_activation_raw_menus_before[\s\S]*missing_activation_inventory_before[\s\S]*missing_activation_relation_before/, "missing activation ownership must preserve raw options, menu rows, global menu inventory, posts, and metadata before staging");
assert.match(publicHeaderRuntime, /stale_activation_receipt[\s\S]*Independent receipt owner[\s\S]*public_header_activation_receipt_mismatch[\s\S]*stale_receipt_state_before[\s\S]*stale_receipt_state_after/, "a prior staging owner must not activate the current independently staged pending manifest");
assert.match(publicHeaderRuntime, /\$activation_receipt_race = static function[\s\S]*replace_pending[\s\S]*Concurrent receipt owner[\s\S]*devenia_workflow_public_header_before_activation_receipt_revalidation[\s\S]*concurrent_receipt_attempt[\s\S]*public_header_activation_receipt_mismatch[\s\S]*array_key_exists\( 'projections', \$concurrent_receipt_attempt \)/, "a concurrent pending replacement must be rejected at the deterministic pre-staging receipt seam without creating projections");
assert.match(publicHeaderRuntime, /raw_normalization_equivalent[\s\S]*receipt_race_discarded_field[\s\S]*raw_equivalent_state_before[\s\S]*raw_equivalent_raw_menus_before[\s\S]*raw_equivalent_inventory_before[\s\S]*raw_equivalent_relations_before[\s\S]*raw_equivalent_attempt[\s\S]*public_header_activation_receipt_mismatch[\s\S]*array_key_exists\( 'projections', \$raw_equivalent_attempt \)[\s\S]*raw_equivalent_pending_after !== \( \$activation_receipt_race_result\['pending'\][\s\S]*raw_equivalent_relations_before !== \$raw_relation_post_surface\( \$canonical_relation_post_ids \)[\s\S]*restored_raw_pending/, "a normalization-equivalent raw replacement must invalidate the old receipt before staging, preserve exact foreign raw and projection authority, and require explicit canonical operator restaging");
assert.match(publicHeaderRuntime, /\$reorder_raw_pending = static function[\s\S]*array_reverse\( \$raw_pending\['items'\]\[0\], true \)[\s\S]*array_reverse\( \$raw_pending, true \)/, "the runtime key-order fixture must reorder both nested item authority and the top-level raw pending array");
assert.match(publicHeaderRuntime, /raw_key_reorder[\s\S]*key_reorder_attempt[\s\S]*public_header_activation_receipt_mismatch[\s\S]*array_key_exists\( 'projections', \$key_reorder_attempt \)[\s\S]*key_reorder_pending_after !== \( \$activation_receipt_race_result\['pending'\][\s\S]*key_reorder_relations_before !== \$raw_relation_post_surface\( \$canonical_relation_post_ids \)/, "top-level and nested raw key reordering must invalidate the old receipt before staging with exact foreign raw and zero projection mutation");
assert.match(publicHeaderRuntime, /pending_key_reorder[\s\S]*locked_reorder_state_before[\s\S]*locked_reorder_raw_menus_before[\s\S]*locked_reorder_inventory_before[\s\S]*locked_reorder_relations_before[\s\S]*locked_reorder_attempt[\s\S]*public_header_projection_activation_failed[\s\S]*public_header_activation_receipt_mismatch[\s\S]*array_key_exists\( 'projections', \$locked_reorder_attempt \)[\s\S]*locked_reorder_pending_after !== \( \$locked_pending_reorder_result\['pending'\][\s\S]*locked_reorder_relations_before !== \$raw_relation_post_surface\( \$canonical_relation_post_ids \)/, "the locked option row must reject a raw key reorder by receipt mismatch, delete every owned staged projection, and preserve exact foreign authority");
assert.match(publicHeaderRuntime, /array\( 'empty' => array\(\), 'malformed' => 'raw-pending-owner-'[\s\S]*refresh_public_header_projection_for_publication'[\s\S]*public_header_pending_manifest_ownership_conflict[\s\S]*raw_pending_state_before !== \$raw_pending_state_after[\s\S]*raw_pending_menus_before !== \$raw_fixture_menu_surface\(\)[\s\S]*raw_pending_inventory_before !== \$raw_nav_menu_inventory\(\)[\s\S]*raw_pending_relations_before !== \$raw_relation_post_surface/, "empty-normalized and malformed raw pending owners must remain exact and mutation-free");
assert.match(publicHeaderRuntime, /independent_pending_stage[\s\S]*publication_ownership_state_before[\s\S]*publication_ownership_raw_menus_before[\s\S]*publication_ownership_inventory_before[\s\S]*publication_ownership_relations_before[\s\S]*refresh_public_header_projection_for_publication'[\s\S]*public_header_pending_manifest_ownership_conflict[\s\S]*publication_ownership_state_before !== \$publication_ownership_state_after[\s\S]*publication_ownership_raw_menus_before !== \$raw_fixture_menu_surface\(\)[\s\S]*publication_ownership_inventory_before !== \$raw_nav_menu_inventory\(\)[\s\S]*publication_ownership_relations_before !== \$raw_relation_post_surface/, "ordinary publication must reject a valid independently staged pending owner before creating projections and preserve options, raw menus, global inventory, and canonical relation rows exactly");
assert.match(publicHeaderRuntime, /cancel_independent_pending[\s\S]*publication_self_stage = \$call\( 'refresh_public_header_projection_for_publication'[\s\S]*manifest_staging'\]\['activation_receipt'[\s\S]*get_option\( 'devenia_workflow_pending_public_header_manifest', false \)/, "after explicit owner cancellation, ordinary publication must self-stage, carry its own receipt, activate, and release the pending slot");
assert.match(publicHeaderRuntime, /public_header_enrollment_commit_receipt_invalid[\s\S]*missing_committed[\s\S]*public_header_state_commit_receipt_invalid[\s\S]*missing_committed[\s\S]*publication_transaction_commit_receipt_invalid[\s\S]*missing_committed/, "the real WordPress runtime must reject a committed transaction whose Adapter receipt omits committed at all three Public Header publication seams");
assert.match(publicHeaderRuntime, /\$activation_receipt_state_after = \$production_header_state\(\)[\s\S]*\$activation_receipt_state_after\['pending'\][\s\S]*\$activation_transaction\['current_state'\]\['pending'\][\s\S]*translation_job_canonicalize', \$activation_receipt_state_after[\s\S]*\$unterminated_state_before = \$production_header_state\(\)[\s\S]*\$unterminated_state_after = \$production_header_state\(\)/, "receipt-domain and nonterminal runtime comparisons must consume the bounded production-sentinel state reader and prove successful activation released pending");
assert.match(publicHeaderRuntime, /'non_array' === \$activation_commit_mode[\s\S]*return false;[\s\S]*'unterminated_success' === \$activation_commit_mode[\s\S]*'success' => true, 'committed' => true/, "the runtime must inject both a present non-array receipt and a structurally valid receipt that leaves the owned transaction open");
assert.match(publicHeaderRuntime, /missing_committed[\s\S]*transaction_not_terminal[\s\S]*recovery_commit_adapter_receipt_not_array[\s\S]*adapter_receipt_type[\s\S]*transaction_rolled_back/, "non-terminal Adapter receipts must be normalized, rejected, and terminalized by owned rollback before any forward Public Header work");

for (const evidence of [
	"translation_publish_preserved_seeded_menu_identity",
	"atomic_menu_failure_preserved_active_identity",
	"ordinary_identity_reader_failed_closed_without_migration",
	"frontend_cache_invalidation_adapter_consumed",
	"canonical_english_menu_cache_rejected",
	"foreign_publication_revision_never_became_rollback_authority",
	"foreign_publication_true_and_unknown_preserved_job_content_header",
]) {
	assert.match(runtime, new RegExp(evidence));
}

console.log("Localized presentation publication contract OK");
