#!/usr/bin/env node
/**
 * extract-failures.js
 *
 * Parses a Playwright JSON report and writes a Markdown issues list
 * ready to paste into a GitHub Issues tracker or task list.
 *
 * Usage (called automatically by run-tests.sh):
 *   node extract-failures.js <input.json> <output.md> <timestamp> <specs>
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const [,, jsonFile, mdFile, timestamp, specs] = process.argv;

if (!jsonFile || !mdFile) {
  console.error('Usage: extract-failures.js <input.json> <output.md> <timestamp> <specs>');
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Load report
// ---------------------------------------------------------------------------
let report;
try {
  report = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
} catch (e) {
  console.error(`Failed to parse JSON report: ${e.message}`);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Walk the suite tree and collect all test results
// ---------------------------------------------------------------------------
const results = [];

function walkSuites(suites, filePath = '') {
  for (const suite of suites || []) {
    const currentFile = suite.file || filePath;
    // Recurse into nested suites (describe blocks)
    if (suite.suites) walkSuites(suite.suites, currentFile);

    for (const spec of suite.specs || []) {
      for (const test of spec.tests || []) {
        const status = test.status || 'unknown';
        const ok     = test.ok;
        results.push({
          file:    currentFile,
          suite:   suite.title || '',
          title:   spec.title || '',
          status,
          ok,
          errors:  test.results?.flatMap(r => r.errors || []) ?? [],
          duration: test.results?.reduce((s, r) => s + (r.duration || 0), 0) ?? 0,
        });
      }
    }
  }
}

walkSuites(report.suites);

// ---------------------------------------------------------------------------
// Categorise
// ---------------------------------------------------------------------------
const failed  = results.filter(r => !r.ok && r.status !== 'skipped');
const skipped = results.filter(r => r.status === 'skipped');
const passed  = results.filter(r => r.ok);

const stats = report.stats || {};
const totalDuration = Math.round((stats.duration ?? 0) / 1000);

// ---------------------------------------------------------------------------
// Group failures by spec file
// ---------------------------------------------------------------------------
const byFile = {};
for (const t of failed) {
  const key = t.file || 'unknown';
  if (!byFile[key]) byFile[key] = [];
  byFile[key].push(t);
}

// ---------------------------------------------------------------------------
// Build Markdown
// ---------------------------------------------------------------------------
const lines = [];

lines.push(`# ScoutKeeper E2E — Test Results`);
lines.push(`**Run:** ${timestamp}  `);
lines.push(`**Specs:** \`${specs}\`  `);
lines.push(`**Duration:** ${totalDuration}s`);
lines.push('');
lines.push('## Summary');
lines.push('');
lines.push(`| Status | Count |`);
lines.push(`|--------|-------|`);
lines.push(`| ✅ Passed  | ${passed.length} |`);
lines.push(`| ❌ Failed  | ${failed.length} |`);
lines.push(`| ⏭  Skipped | ${skipped.length} |`);
lines.push(`| **Total**  | **${results.length}** |`);
lines.push('');

if (failed.length === 0) {
  lines.push('## ✅ All tests passed — no issues to report.');
  lines.push('');
} else {
  lines.push(`## ❌ Issues List (${failed.length} failures)`);
  lines.push('');
  lines.push('> Copy each item below into your issue tracker.');
  lines.push('');

  let issueNum = 1;
  for (const [file, tests] of Object.entries(byFile)) {
    // Shorten the path to just the spec filename
    const shortFile = file
      .replace(/.*\/specs\//, 'specs/')
      .replace(/.*\\specs\\/, 'specs/');

    lines.push(`### ${shortFile}`);
    lines.push('');

    for (const t of tests) {
      lines.push(`#### Issue ${issueNum}: ${t.suite} → ${t.title}`);
      lines.push('');
      lines.push(`- **Spec:** \`${shortFile}\``);
      lines.push(`- **Describe:** ${t.suite || '(top level)'}`);
      lines.push(`- **Test:** ${t.title}`);
      lines.push(`- **Status:** ${t.status}`);
      lines.push(`- **Duration:** ${Math.round(t.duration)}ms`);
      lines.push('');

      if (t.errors.length > 0) {
        lines.push('**Error:**');
        lines.push('```');
        for (const err of t.errors) {
          const msg = (err.message || err.value || JSON.stringify(err))
            .replace(/\u001b\[[0-9;]*m/g, '') // strip ANSI colour codes
            .split('\n')
            .slice(0, 20)              // cap to 20 lines per error
            .join('\n');
          lines.push(msg);
        }
        lines.push('```');
        lines.push('');
      }

      lines.push('**Suggested action:** _investigate and fix_');
      lines.push('');
      lines.push('---');
      lines.push('');
      issueNum++;
    }
  }
}

// ---------------------------------------------------------------------------
// Skipped tests (informational)
// ---------------------------------------------------------------------------
if (skipped.length > 0) {
  lines.push(`## ⏭ Skipped Tests (${skipped.length})`);
  lines.push('');
  lines.push('These were skipped due to missing preconditions (e.g. no seeded data).');
  lines.push('They are not failures but indicate areas with no test coverage on this run.');
  lines.push('');

  const skippedByFile = {};
  for (const t of skipped) {
    const key = t.file || 'unknown';
    if (!skippedByFile[key]) skippedByFile[key] = [];
    skippedByFile[key].push(t);
  }

  for (const [file, tests] of Object.entries(skippedByFile)) {
    const shortFile = file
      .replace(/.*\/specs\//, 'specs/')
      .replace(/.*\\specs\\/, 'specs/');
    lines.push(`**${shortFile}**`);
    for (const t of tests) {
      lines.push(`- ${t.suite} → ${t.title}`);
    }
    lines.push('');
  }
}

// ---------------------------------------------------------------------------
// Write output
// ---------------------------------------------------------------------------
fs.writeFileSync(mdFile, lines.join('\n'), 'utf8');

// Console summary
console.log('');
console.log(`Test Results:`);
console.log(`  ✅  ${passed.length} passed`);
if (failed.length)  console.log(`  ❌  ${failed.length} failed`);
if (skipped.length) console.log(`  ⏭   ${skipped.length} skipped`);
console.log(`  📄  Summary → ${mdFile}`);
console.log('');
