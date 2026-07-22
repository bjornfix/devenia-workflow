#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const inheritance = readFileSync(new URL("../includes/trait-source-design-inheritance.php", import.meta.url), "utf8");
const review = readFileSync(new URL("../includes/trait-source-design-review-policy.php", import.meta.url), "utf8");

assert.match(inheritance, /'available'\s*=>\s*false[\s\S]*'passed'\s*=>\s*false[\s\S]*no_source_design_policy_registered/);
assert.match(review, /empty\( \$validation\['available'\] \)[\s\S]*source_design_validation_unavailable/);
assert.match(review, /\$review_passed\s*=\s*! empty\( \$validation\['available'\] \)/);
assert.doesNotMatch(inheritance, /no_source_design_policy_registered'[\s\S]{0,180}'passed'\s*=>\s*true/);

console.log("Source Design Adapter fail-closed contract: OK");
