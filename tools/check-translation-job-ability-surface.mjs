#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const catalogue = readFileSync(join(root, "includes", "trait-ability-catalogue.php"), "utf8");
const translationJobModule = readFileSync(join(root, "includes", "trait-translation-job.php"), "utf8");
const registeredWorkflow = [...catalogue.matchAll(/^\s*'devenia-workflow\/([a-z0-9-]+)'\s*=>/gm)].map((match) => match[1]);
const registeredTranslationJob = [...translationJobModule.matchAll(/^\s*'(translation-job-[a-z0-9-]+)'\s*=>\s*array\(/gm)].map((match) => match[1]);
const registered = [...registeredWorkflow, ...registeredTranslationJob];
const expectedTranslationJob = [
	"translation-job-discover", "translation-job-claim", "translation-job-fetch-packet", "translation-job-submit-artifact",
	"translation-job-submit-quality-decision", "translation-job-publish", "translation-job-status", "translation-job-abandon",
	"translation-job-verify-live",
];

const removedSystemAbilities = [
	"reserve-work", "release-reservation", "list-reservations", "upsert-page", "qa-translation",
	"publish-translation", "workflow-status", "workflow-obligations", "production-flow", "queue",
	"review-queue", "quality-review-queue", "mark-reviewed", "mark-linguistic-reviewed",
	"mark-final-reviewed", "next-heartbeat-action", "accept-assignment",
	"current-assignment", "renew-assignment", "complete-assignment", "resolve-assignment-block",
	"heartbeat-assignment-coverage", "heartbeat-status", "lifecycle-regression-status",
];

assert.equal(new Set(registered).size, registered.length, "Ability catalogue contains duplicate registrations.");
for (const removed of removedSystemAbilities) {
	assert.ok(!registeredWorkflow.includes(removed), `Removed workflow system ability is still registered: ${removed}`);
}
assert.ok(registeredWorkflow.includes("mark-quality-reviewed"), "Whole-page source quality evidence must have a canonical write ability.");
assert.deepEqual([...registeredTranslationJob].sort(), [...expectedTranslationJob].sort(), "The Translation Job Interface must expose exactly the bounded operations in its executable contract.");
assert.match(translationJobModule, /const TRANSLATION_JOB_MAX_RUNS_PER_ROLE = 6;/, "A Job must enforce the current bounded per-role correction ceiling.");
assert.match(translationJobModule, /translation_job_role_attempt_count\( \$existing_runs, \$role, \$submission_generation \) >= self::TRANSLATION_JOB_MAX_RUNS_PER_ROLE/, "Run claims must enforce the finite per-role ceiling for the current server-owned generation.");
assert.match(translationJobModule, /in_array\( \(string\) \( \$run\['outcome'\] \?\? '' \), array\( 'expired', 'abandoned' \), true \)/, "Expired and abandoned non-decision Runs must not consume substantive attempt slots.");
console.log(JSON.stringify({
	success: true,
	registered_abilities: registered.length,
	workflow_abilities: registeredWorkflow.length,
	translation_job_operations: registeredTranslationJob.length,
	removed_system_abilities: removedSystemAbilities.length,
}));
