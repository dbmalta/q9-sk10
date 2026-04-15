#!/usr/bin/env bash
# =============================================================================
#  ScoutKeeper — E2E Test Runner
#
#  Run from WSL:
#    bash run-tests.sh                       # full comprehensive suite
#    bash run-tests.sh specs/comprehensive   # same, explicit
#    bash run-tests.sh specs/comprehensive/03-members.spec.ts  # one file
#    bash run-tests.sh specs/auth.spec.ts    # original specs
#
#  Results are saved to:
#    test-results/YYYY-MM-DD_HH-MM-SS.json         — Playwright JSON
#    test-results/YYYY-MM-DD_HH-MM-SS-summary.md   — Issues list (failures)
#    test-results/YYYY-MM-DD_HH-MM-SS-raw.log       — Full console output
#    playwright-report/index.html                   — HTML report (overwritten)
# =============================================================================

set -uo pipefail

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS_DIR="$SCRIPT_DIR/test-results"
TIMESTAMP=$(date '+%Y-%m-%d_%H-%M-%S')
SPECS="${1:-specs/comprehensive}"

JSON_FILE="$RESULTS_DIR/${TIMESTAMP}.json"
SUMMARY_FILE="$RESULTS_DIR/${TIMESTAMP}-summary.md"
RAW_LOG="$RESULTS_DIR/${TIMESTAMP}-raw.log"

mkdir -p "$RESULTS_DIR"
cd "$SCRIPT_DIR"

# ---------------------------------------------------------------------------
# Header
# ---------------------------------------------------------------------------
echo ""
echo "┌─────────────────────────────────────────────────────────────────┐"
echo "│  ScoutKeeper E2E Test Run                                       │"
echo "│  $(date '+%Y-%m-%d %H:%M:%S')                                  │"
printf  "│  Specs: %-55s │\n" "$SPECS"
echo "└─────────────────────────────────────────────────────────────────┘"
echo ""

# ---------------------------------------------------------------------------
# Run Playwright
#   --project=chromium  — only Chromium is installed in WSL
#   reporters: line (live output) + json (for parsing) + html (for viewing)
# ---------------------------------------------------------------------------
PLAYWRIGHT_JSON_OUTPUT_NAME="$JSON_FILE" \
  npx playwright test \
    --project=chromium \
    --reporter=line,json,html \
    "$SPECS" 2>&1 | tee "$RAW_LOG"

PW_EXIT=${PIPESTATUS[0]}

# ---------------------------------------------------------------------------
# Generate issues-list summary from JSON
# ---------------------------------------------------------------------------
if [ -f "$JSON_FILE" ]; then
  node "$SCRIPT_DIR/scripts/extract-failures.js" \
    "$JSON_FILE" \
    "$SUMMARY_FILE" \
    "$TIMESTAMP" \
    "$SPECS"
else
  echo "⚠  JSON output file not found — cannot generate summary." >&2
fi

# ---------------------------------------------------------------------------
# Footer
# ---------------------------------------------------------------------------
echo ""
echo "┌─────────────────────────────────────────────────────────────────┐"
echo "│  Output files                                                   │"
printf "│  JSON:    %-53s │\n" "${JSON_FILE/$SCRIPT_DIR\//}"
printf "│  Summary: %-53s │\n" "${SUMMARY_FILE/$SCRIPT_DIR\//}"
printf "│  Log:     %-53s │\n" "${RAW_LOG/$SCRIPT_DIR\//}"
echo "│  HTML:    playwright-report/index.html                         │"
echo "└─────────────────────────────────────────────────────────────────┘"
echo ""

if [ "$PW_EXIT" -eq 0 ]; then
  echo "✅  All tests passed."
else
  echo "❌  Some tests failed. See $SUMMARY_FILE for the issues list."
fi
echo ""

exit $PW_EXIT
