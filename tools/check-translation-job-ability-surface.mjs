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
	"translation-job-submit-quality-decision", "translation-job-publish", "translation-job-status",
];

const removedSystemAbilities = [
	"reserve-work", "release-reservation", "list-reservations", "upsert-page", "qa-translation",
	"publish-translation", "workflow-status", "workflow-obligations", "production-flow", "queue",
	"review-queue", "quality-review-queue", "mark-reviewed", "mark-linguistic-reviewed",
	"mark-quality-reviewed", "mark-final-reviewed", "next-heartbeat-action", "accept-assignment",
	"current-assignment", "renew-assignment", "complete-assignment", "resolve-assignment-block",
	"heartbeat-assignment-coverage", "heartbeat-status", "lifecycle-regression-status",
];

assert.equal(new Set(registered).size, registered.length, "Ability catalogue contains duplicate registrations.");
for (const removed of removedSystemAbilities) {
	assert.ok(!registeredWorkflow.includes(removed), `Removed workflow system ability is still registered: ${removed}`);
}
assert.deepEqual([...registeredTranslationJob].sort(), [...expectedTranslationJob].sort(), "The Translation Job Interface must expose exactly seven operations.");
assert.match(translationJobModule, /const TRANSLATION_JOB_MAX_RUNS_PER_ROLE = 3;/, "A Job must allow one final bounded correction after a valid second Quality Decision.");
assert.match(translationJobModule, />= self::TRANSLATION_JOB_MAX_RUNS_PER_ROLE/, "Run claims must enforce the finite per-role ceiling.");
console.log(JSON.stringify({
	success: true,
	registered_abilities: registered.length,
	workflow_abilities: registeredWorkflow.length,
	translation_job_operations: registeredTranslationJob.length,
	removed_system_abilities: removedSystemAbilities.length,
}));
