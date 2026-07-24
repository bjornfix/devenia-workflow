#!/usr/bin/env node

import fs from "node:fs";

const main = fs.readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const source = fs.readFileSync(new URL("../includes/trait-source-rewrite-quality-authority.php", import.meta.url), "utf8");
const translation = fs.readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");

const failures = [];

if (!main.includes("trait-quality-single-flight.php") || !main.includes("use Devenia_Workflow_Quality_Single_Flight;")) {
	failures.push("the plugin bootstrap must load and compose the shared Quality Single-Flight Module");
}

for (const [label, body, workflow] of [
	["Source Rewrite", source, "source_rewrite"],
	["Translation", translation, "translation"],
]) {
	if (!body.includes(`quality_single_flight_acquire( '${workflow}'`)) {
		failures.push(`${label} Quality claim must acquire the shared lease`);
	}
	if (!body.includes(`quality_single_flight_validate( '${workflow}'`)) {
		failures.push(`${label} Quality claim access must validate the shared lease`);
	}
	if (!body.includes(`quality_single_flight_release( '${workflow}'`)) {
		failures.push(`${label} terminal claim release must release only its shared lease`);
	}
	const helperName = workflow === "source_rewrite" ? "source_rewrite_release_claim" : "translation_job_release_claim";
	const helperStart = body.indexOf(`private static function ${helperName}`);
	const helperEnd = body.indexOf("\n\tprivate static function", helperStart + 1);
	const helper = helperStart >= 0 && helperEnd > helperStart ? body.slice(helperStart, helperEnd) : "";
	if (
		!helper ||
		helper.indexOf(`quality_single_flight_release( '${workflow}'`) < 0 ||
		helper.indexOf("atomic_delete_option_value") < 0 ||
		helper.indexOf(`quality_single_flight_release( '${workflow}'`) > helper.indexOf("atomic_delete_option_value")
	) {
		failures.push(`${label} terminal cleanup must release the global lease before deleting the local claim`);
	}
}

if (failures.length) {
	console.error(`Quality Single-Flight contract failed:\n- ${failures.join("\n- ")}`);
	process.exit(1);
}

console.log("Quality Single-Flight contract checks passed.");
