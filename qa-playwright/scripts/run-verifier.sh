#!/usr/bin/env bash
set -uo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
if [[ ! -x node_modules/.bin/playwright ]]; then
  echo 'ERROR: Playwright no instalado en qa-playwright/node_modules.' >&2
  exit 2
fi
LOG="/tmp/begreen-82-playwright-$(date +%Y%m%dT%H%M%S).txt"
{
  echo 'BEGREEN_82_QA_VERIFIER=START'
  echo "GIT_HEAD=$(git -C .. rev-parse --short HEAD 2>/dev/null || echo unknown)"
  echo 'URL=https://begreen.tech-investments.net'
  echo 'MODE=READ_ONLY_WITH_LOGIN_AND_SESSION_PROJECT_SELECTION'
  echo 'NO_DATABASE_MUTATIONS=YES'
} > "$LOG"
./node_modules/.bin/playwright test --config playwright.verifier.config.ts >> "$LOG" 2>&1
code=$?
tail -n 28 "$LOG"
echo "QA_EXIT_CODE=$code"
echo "QA_LOG=$LOG"
echo "QA_REPORT=$ROOT/artifacts/verifier/html-report/index.html"
exit "$code"
