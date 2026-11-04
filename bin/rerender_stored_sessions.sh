#!/usr/bin/env bash
# Re-render every stored session once so its pages lose inline scripts (strict CSP).
# Uses each session's preferred switch-list style (mobile when none is recorded), so
# no session changes style. Idempotent.
# Usage: STS_API_URL=http://localhost:8980/sts/api/v1 STS_API_TOKEN=sts_... \
#        STS_SESSIONS_DIR=../sts-docker-data/backups/session_state/sessions bin/rerender_stored_sessions.sh
set -euo pipefail
api="${STS_API_URL:-http://localhost:8980/sts/api/v1}"
dir="${STS_SESSIONS_DIR:-$(cd "$(dirname "$0")/.." && pwd)/../sts-docker-data/backups/session_state/sessions}"
token="${STS_API_TOKEN:-$(cat "$(dirname "$dir")/../../config/bootstrap_admin.token")}"
for m in "$dir"/session_*/manifest.json; do
  n="$(basename "$(dirname "$m")")"; n="${n#session_}"
  style="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("preferred_switchlist_style") or "mobile")' "$m")"
  code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${token}" \
    -H 'Content-Type: application/json' -d "{\"style\":\"${style}\"}" "${api}/sessions/${n}/rerender")"
  echo "session ${n} (${style}): HTTP ${code}"
done
