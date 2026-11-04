#!/usr/bin/env bash
# Run the STS browser regression checks (tests/browser/steps/*.json) against a
# throwaway sandbox stack. For each steps file it restores the data source,
# prepares what the file needs through the API (see prep.mjs), runs
# cdp_check.mjs in headless Chrome and prints a pass/fail table.
#
#   tests/browser/run.sh [c1 c2 ...]        default: every steps file
#
# Environment:
#   STS_BASE               site root, default http://localhost:8981/sts
#   STS_API_TOKEN          admin token (or STS_API_TOKEN_FILE; falls back to
#                          $STS_SANDBOX_DATA/config/bootstrap_admin.token)
#   STS_API_BACKUPS_DIR    host side of the stack's backups dir, so the committed
#                          fixture can be copied in when the stack lacks it
#   STS_BROWSER_BACKUP     data source backup, default browser_fixture (committed);
#                          hart_seed (private, not in CI) also works
#   CHROME                 Chrome/Chromium binary (default: first of google-chrome,
#                          chromium, chromium-browser on PATH)
#
# NEVER point this at a live stack: it restores the database, advances sessions
# and runs the maintenance pages (reset, restart, wipe).

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
export STS_BASE=${STS_BASE:-http://localhost:8981/sts}
STS_BASE=${STS_BASE%/}
export STS_BASE
export STS_BROWSER_BACKUP=${STS_BROWSER_BACKUP:-browser_fixture}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/sts-browser.XXXXXX")
export STS_BROWSER_STATE="$WORK/state"

if [ -z "${CHROME:-}" ]; then
  for c in google-chrome google-chrome-stable chromium chromium-browser; do
    if command -v "$c" >/dev/null 2>&1; then CHROME=$(command -v "$c"); break; fi
  done
  if [ -z "${CHROME:-}" ] && [ -x "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" ]; then
    CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
  fi
fi
export CHROME="${CHROME:-}"

case "$STS_BASE" in
  *:8980/*|*:8980) echo "refusing to run against port 8980 (the live stack)" >&2; exit 2 ;;
esac

# Data each steps file needs: the session workflow, and API-made tweaks.
profile() {
  case "$1" in
    c2) echo "base offhome" ;;
    c4) echo "base marks" ;;
    tablet) echo "base ops" ;;
    c5|c6) echo "session" ;;
    *) echo "base" ;;
  esac
}

cleanup() {
  node "$HERE/prep.mjs" cleanup >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

if [ -z "$CHROME" ]; then
  echo "no Chrome found: set CHROME to a Chrome/Chromium binary" >&2
  exit 2
fi
if ! curl -fsS "$STS_BASE/api/v1/health" | grep -q '"db":"ok"'; then
  echo "the stack at $STS_BASE is not healthy" >&2
  exit 2
fi

tokens=$(node "$HERE/prep.mjs" tokens) || { echo "cannot create test tokens" >&2; exit 2; }
eval "$tokens"

if [ "$#" -gt 0 ]; then
  names=("$@")
else
  names=()
  for f in "$HERE"/steps/*.json; do
    n=$(basename "$f" .json)
    names+=("$n")
  done
fi

rows=()
failed=0
for n in "${names[@]}"; do
  f="$HERE/steps/$n.json"
  if [ ! -f "$f" ]; then
    rows+=("$n|FAIL|-|-|no such steps file")
    failed=1
    continue
  fi
  start=$(date +%s)
  # shellcheck disable=SC2046 # the profile is a word list on purpose
  envs=$(node "$HERE/prep.mjs" data $(profile "$n")); rc=$?
  if [ "$rc" -eq 3 ]; then
    rows+=("$n|SKIP|-|0|data source '$STS_BROWSER_BACKUP' not on the stack")
    continue
  elif [ "$rc" -ne 0 ]; then
    rows+=("$n|FAIL|-|0|data preparation failed (see above)")
    failed=1
    continue
  fi
  eval "$envs"
  counts=$(node "$HERE/prep.mjs" filter "$f" "$WORK/$n.json") || counts="0 0"
  kept=${counts% *}
  skipped=${counts#* }
  if [ "$kept" -eq 0 ]; then
    rows+=("$n|SKIP|0|0|every page needs a different data source")
    continue
  fi
  node "$HERE/cdp_check.mjs" --steps "$WORK/$n.json" >"$WORK/$n.out" 2>"$WORK/$n.err"
  rc=$?
  secs=$(( $(date +%s) - start ))
  summary=$(node "$HERE/prep.mjs" report "$WORK/$n.out" 2>&1)
  if [ "$rc" -eq 0 ]; then
    note=""
    [ "$skipped" -gt 0 ] && note="$skipped page(s) skipped (need another data source)"
    rows+=("$n|PASS|$kept|$secs|$note")
  else
    rows+=("$n|FAIL|$kept|$secs|see details below")
    failed=1
    echo "---- $n ----"
    echo "$summary"
    [ -s "$WORK/$n.err" ] && head -n 20 "$WORK/$n.err"
  fi
done

echo
printf '%-10s %-6s %-6s %-6s %s\n' "steps" "result" "pages" "secs" "note"
for r in "${rows[@]}"; do
  IFS='|' read -r a b c d e <<<"$r"
  printf '%-10s %-6s %-6s %-6s %s\n' "$a" "$b" "$c" "$d" "$e"
done
exit "$failed"
