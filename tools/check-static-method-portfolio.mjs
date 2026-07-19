#!/usr/bin/env node
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../", import.meta.url));
const productionFiles = [
	join(root, "devenia-workflow.php"),
	...readdirSync(join(root, "includes"), { withFileTypes: true })
		.filter((entry) => entry.isFile() && entry.name.endsWith(".php"))
		.map((entry) => join(root, "includes", entry.name)),
	...readdirSync(join(root, "addons"), { withFileTypes: true })
		.filter((entry) => entry.isFile() && entry.name.endsWith(".php"))
		.map((entry) => join(root, "addons", entry.name)),
];
const source = productionFiles.map((file) => readFileSync(file, "utf8")).join("\n");
const definitions = new Set(
	[...source.matchAll(/(?:public|private|protected)\s+static\s+function\s+([a-zA-Z0-9_]+)/g)]
		.map((match) => match[1]),
);
const calls = new Set(
	[...source.matchAll(/self::([a-zA-Z0-9_]+)\s*\(/g)]
		.map((match) => match[1]),
);
const missing = [...calls].filter((method) => !definitions.has(method)).sort();

if (missing.length > 0) {
	process.stderr.write(`${JSON.stringify({ success: false, missing }, null, 2)}\n`);
	process.exit(1);
}

process.stdout.write(`${JSON.stringify({ success: true, definitions: definitions.size, calls: calls.size })}\n`);
