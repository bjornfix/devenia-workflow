#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const catalogue = readFileSync(join(root, "includes", "trait-ability-catalogue.php"), "utf8");
const v2Module = readFileSync(join(root, "includes", "trait-translation-job-v2.php"), "utf8");
const registeredLegacy = [...catalogue.matchAll(/^\s*'ai-translations\/([a-z0-9-]+)'\s*=>/gm)].map((match) => match[1]);
const registeredV2 = [...v2Module.matchAll(/^\s*'(v2-[a-z0-9-]+)'\s*=>\s*array\(/gm)].map((match) => match[1]);
const registered = [...registeredLegacy, ...registeredV2];
const expectedV2 = [
	"v2-discover-job", "v2-claim-job", "v2-fetch-packet", "v2-submit-artifact",
	"v2-submit-quality-decision", "v2-publish", "v2-status",
];

const internalAdapters = [
	"list-languages", "translation-fitness-status", "translation-index-status", "get-quality-profile",
	"agency-copy-brief", "list-taxonomy-terms", "update-source-qa-options", "get-source", "reserve-work",
	"release-reservation", "list-reservations", "upsert-page", "list-translations", "qa-translation",
	"publish-translation", "verify-live-translation", "workflow-status", "queue", "quality-verdict",
];

const separateModules = [
	"get-presentation-surface", "update-runtime-text", "update-featured-image-alt", "author-archive-queue",
	"update-author-archive-translation", "sync-menu", "translation-fitness-scan", "update-quality-profile",
	"record-language-rule-event", "list-language-rule-events", "learning-inbox", "review-learning-event",
	"language-policy-status", "record-copy-feedback", "get-reviewer-style-profile", "record-reviewer-style-edit",
	"quality-review-queue", "mark-source-content-integrity-reviewed", "authored-original-intake-queue",
	"update-authored-original-intake", "create-source-from-authored-original", "mark-source-generation-reviewed",
	"mark-source-taxonomy-reviewed", "mark-source-design-reviewed", "internal-link-opportunities",
	"language-packs-status", "gutenberg-content-safety-scan", "frontend-performance-status",
	"frontend-integrity-status", "warm-cache", "repair-term-archive-self-redirects", "repair-translation-author",
	"reproject-source-design", "migrate-source-design-fragments", "repair-url-hierarchy", "repair-internal-links",
	"repair-featured-images",
	"source-editor-status",
];

const retiredFromModel = [
	"lifecycle-regression-status", "wrong-language-carryover-scan", "mark-reviewed", "mark-linguistic-reviewed",
	"workflow-obligations", "production-flow", "review-queue", "mark-quality-reviewed", "mark-final-reviewed",
	"next-heartbeat-action", "accept-assignment", "current-assignment", "renew-assignment",
	"complete-assignment", "resolve-assignment-block", "heartbeat-assignment-coverage", "heartbeat-status",
];

const classified = [...internalAdapters, ...separateModules, ...retiredFromModel];
assert.equal(new Set(registered).size, registered.length, "Ability catalogue contains duplicate registrations.");
assert.equal(new Set(classified).size, classified.length, "V2 disposition classifies one ability more than once.");
assert.deepEqual([...classified].sort(), [...registeredLegacy].sort(), "V2 disposition must classify every legacy ability exactly once.");
assert.deepEqual([...registeredV2].sort(), [...expectedV2].sort(), "The v2 model Interface must expose exactly seven operations.");
assert.match(v2Module, /const TRANSLATION_JOB_V2_MAX_RUNS_PER_ROLE = 3;/, "A Job must allow one final bounded correction after a valid second Quality Decision.");
assert.match(v2Module, />= self::TRANSLATION_JOB_V2_MAX_RUNS_PER_ROLE/, "Run claims must enforce the finite per-role ceiling.");
assert.deepEqual(
	{ internal_adapters: internalAdapters.length, separate_modules: separateModules.length, retired_from_model: retiredFromModel.length },
	{ internal_adapters: 19, separate_modules: 38, retired_from_model: 17 },
);

console.log(JSON.stringify({
	success: true,
	registered_abilities: registered.length,
	legacy_abilities: registeredLegacy.length,
	v2_model_operations: registeredV2.length,
	internal_adapters: internalAdapters.length,
	separate_modules: separateModules.length,
	retired_from_model: retiredFromModel.length,
}));
