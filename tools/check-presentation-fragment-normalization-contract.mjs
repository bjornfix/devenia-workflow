#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = readFileSync(
	new URL("../includes/trait-translation-job-quality-authority.php", import.meta.url),
	"utf8",
);

function functionBody(name) {
	const marker = `function ${name}(`;
	const start = source.indexOf(marker);
	assert.notEqual(start, -1, `Missing ${name}()`);
	const open = source.indexOf("{", start);
	assert.notEqual(open, -1, `Missing ${name}() body`);
	let depth = 0;
	for (let index = open; index < source.length; index += 1) {
		if (source[index] === "{") depth += 1;
		if (source[index] === "}") depth -= 1;
		if (depth === 0) return source.slice(open + 1, index);
	}
	throw new Error(`Unterminated ${name}() body`);
}

const normalize = functionBody("translation_job_normalized_presentation_fragments");
const stage = functionBody("translation_job_stage_artifact");
const verify = functionBody("translation_job_verify_applied_surface");

assert.match(
	normalize,
	/localized_fragment_records_for_storage\s*\(\s*\$fragments\s*\)/,
	"Presentation normalization must use the exact durable fragment representation",
);
assert.match(
	normalize,
	/usort\s*\([\s\S]*strcmp\s*\([\s\S]*\['key'\]/,
	"Presentation normalization must sort the durable records by logical fragment key",
);
assert.match(
	stage,
	/'localized_fragments'\s*=>\s*self::translation_job_normalized_presentation_fragments\s*\(/,
	"New surface manifests must stage storage-normalized presentation fragments",
);
assert.match(
	stage,
	/'source_design_hash'\s*=>\s*self::expected_source_design_signature_hash\s*\(\s*\(string\)\s*\$source->post_content\s*,\s*\$language\s*\)/,
	"Staged presentation authority must use the same target-language design hash as durable storage",
);
assert.doesNotMatch(
	stage,
	/'source_design_hash'\s*=>[\s\S]*self::source_design_contract\s*\(\s*\$source\s*\)\s*\['design_hash'\]/,
	"Staging must not bind RTL artifacts to the raw LTR source-design hash",
);
assert.doesNotMatch(
	stage,
	/'localized_fragments'\s*=>\s*self::translation_job_canonicalize\s*\(/,
	"New surface manifests must not retain raw mixed text/html fragment rows",
);
assert.match(
	verify,
	/\$expected_presentation_fragments\s*=\s*self::translation_job_normalized_presentation_fragments\s*\(\s*\$presentation\['localized_fragments'\]/,
	"Applied-surface verification must normalize legacy/raw manifest fragments",
);
assert.match(
	verify,
	/\$actual_presentation_fragments\s*=\s*self::translation_job_normalized_presentation_fragments\s*\(\s*\$stored_presentation\['fragments'\]/,
	"Applied-surface verification must normalize stored fragments through the same seam",
);
assert.match(
	verify,
	/\$presentation\['source_design_hash'\][\s\S]*META_SOURCE_DESIGN_HASH[\s\S]*\$expected_presentation_fragments[\s\S]*\$actual_presentation_fragments/,
	"Exact design-hash and exact normalized fragment-value checks must remain coupled",
);

console.log("Presentation fragment normalization contract OK");
