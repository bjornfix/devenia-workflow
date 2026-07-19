#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = readFileSync(new URL("../includes/trait-source-design-inheritance.php", import.meta.url), "utf8");
const start = source.indexOf("private static function store_localized_source_design_fragments");
const end = source.indexOf("private static function stored_localized_source_design_fragments", start);
assert.ok(start >= 0 && end > start, "localized fragment storage method must exist");
const method = source.slice(start, end);
const recordsAssignment = method.indexOf("$records = self::localized_fragment_records_for_storage");
const storedRead = method.indexOf("$stored = self::stored_localized_source_design_fragments");
assert.ok(recordsAssignment >= 0 && storedRead > recordsAssignment, "storage must inspect durable state even for empty input");
assert.doesNotMatch(
	method.slice(recordsAssignment, storedRead),
	/\breturn\b/,
	"empty normalized input must not bypass orphan pruning",
);

assert.match(method, /\$allowed_keys\s*=\s*array\(\)/, "storage must derive the current source-contract key set");
assert.match(method, /\$source_design\['fragments'\]/, "the supplied projection contract must be authoritative when available");
assert.match(method, /self::source_design_contract\(\s*\$source\s*\)/, "storage must fall back to the current source contract");
assert.match(method, /array_filter\([\s\S]*isset\(\s*\$allowed_keys\[/, "orphaned historical fragment keys must be pruned before persistence");
assert.ok(method.indexOf("array_merge") < method.indexOf("$allowed_keys"), "current partial values may merge only before contract pruning");
assert.ok(method.indexOf("array_filter") < method.indexOf("update_json_post_meta"), "pruned records must be persisted through the normal write path");

console.log("localized fragment storage contract checks passed");
