#!/usr/bin/env node
import { spawnSync } from "node:child_process";
import { readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

const root = new URL("..", import.meta.url).pathname;
const skippedDirs = new Set([".git", "vendor", "node_modules"]);

function phpFiles(dir) {
  const files = [];
  for (const entry of readdirSync(dir)) {
    if (skippedDirs.has(entry)) {
      continue;
    }
    const path = join(dir, entry);
    const stat = statSync(path);
    if (stat.isDirectory()) {
      files.push(...phpFiles(path));
    } else if (entry.endsWith(".php")) {
      files.push(path);
    }
  }
  return files;
}

const files = phpFiles(root).sort();
let failed = false;

for (const file of files) {
  const result = spawnSync("php", ["-l", file], { encoding: "utf8" });
  if (result.status !== 0) {
    failed = true;
    process.stderr.write(`${relative(root, file)}\n`);
    process.stderr.write(result.stdout);
    process.stderr.write(result.stderr);
  }
}

if (failed) {
  process.exit(1);
}

console.log(`PHP syntax OK (${files.length} files)`);
