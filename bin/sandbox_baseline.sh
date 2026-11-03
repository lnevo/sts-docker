#!/usr/bin/env bash
# Capture a behavioural baseline from the sandbox stack (project sts-sandbox, port 8981).
# Usage: [STS_DOCKER=..] [STS_SANDBOX_DATA=..] [SEED=42] [SESSIONS=2] bin/sandbox_baseline.sh <label>
# Skip a step with SKIP_<LINT|FUNCS|CATALOG|CATTESTS|SIM|DUMP|OUTPUT>=1.
set -euo pipefail
LABEL="${1:?usage: sandbox_baseline.sh <label>}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export STS_DOCKER="${STS_DOCKER:-$REPO}"
export STS_SANDBOX_DATA="${STS_SANDBOX_DATA:-$REPO/../sts-docker-data/sandbox}"
STS_SANDBOX_DATA="$(cd "$STS_SANDBOX_DATA" && pwd)"
export COMPOSE_PROJECT_NAME=sts-sandbox
export STS_BACKUPS_DIR="$STS_SANDBOX_DATA/backups"
SEED="${SEED:-42}"; SESSIONS="${SESSIONS:-2}"
H="${STS_HELPERS:-$REPO/../sts-helpers}"; H="$(cd "$H" && pwd)"
WEB=sts-sandbox-web-1; DB=sts-sandbox-db-1
OUT="$STS_SANDBOX_DATA/$LABEL"; mkdir -p "$OUT"; : > "$OUT/timings.txt"
step() { echo; echo "=== [$(date +%T)] $1"; STEP_NAME="$1"; STEP_T0=$SECONDS; }
done_step() { echo "$STEP_NAME: $((SECONDS - STEP_T0))s" | tee -a "$OUT/timings.txt"; }
docker inspect "$WEB" >/dev/null   # fail early if sandbox is down

if [[ -z "${SKIP_LINT:-}" ]]; then
  step "php-lint"
  docker exec "$WEB" sh -c 'find /var/www/html/sts -name "*.php" | sort | while read f; do php -l "$f" >/dev/null 2>&1 || echo "FAIL $f"; done' > "$OUT/php-lint.txt" || true
  [[ -s "$OUT/php-lint.txt" ]] || echo OK > "$OUT/php-lint.txt"
  done_step
fi

if [[ -z "${SKIP_FUNCS:-}" ]]; then
  step "functions"
  docker exec "$WEB" php -r 'chdir("/var/www/html/sts"); require "open_db.php"; require "session_runtime.php"; session_runtime_bootstrap(); require "operational_steps_catalog.php"; require "session_helpers.php"; $f=get_defined_functions()["user"]; sort($f); echo implode("\n",$f),"\n";' | sort > "$OUT/functions.txt"
  done_step
fi

if [[ -z "${SKIP_CATALOG:-}" ]]; then
  step "catalog.json"
  curl -sf 'http://localhost:8981/sts/operational_steps_api.php?action=catalog' | python3 -m json.tool --sort-keys > "$OUT/catalog.json"
  done_step
fi

if [[ -z "${SKIP_CATTESTS:-}" ]]; then
  step "catalog_tests"
  PRE_DIRTY="$(git -C "$H" status --short | awk '{print $2}')"  # files already modified before the tests run
  rc=0; RUN_WORKFLOW=0 "$H/bin/run_catalog_tests.sh" > "$OUT/catalog_tests.log" 2>&1 || rc=$?
  echo "run_catalog_tests exit status: $rc" >> "$OUT/catalog_tests.log"; cat "$OUT/catalog_tests.log"
  if [[ $rc -ne 0 ]]; then  # validation fails on the baseline itself; still generate the artefacts directly
    docker exec "$WEB" php /var/www/html/sts/generate_operational_steps_openapi_catalog.php /var/www/html/sts/operational_steps_catalog.openapi.generated.yaml
    docker cp "$WEB:/var/www/html/sts/operational_steps_catalog.openapi.generated.yaml" "$OUT/"
    docker exec -e STS_HELPERS_ROOT=/var/www/html "$WEB" php /tmp/generate_test_workflow_csv.php /tmp/WORKFLOW_TEST_ALL_TYPES.recipe.json || true  # exits 1 on the known matrix warning
    docker cp "$WEB:/tmp/WORKFLOW_TEST_ALL_TYPES.recipe.json" "$OUT/"
  else
    cp "$STS_DOCKER/sts/operational_steps_catalog.openapi.generated.yaml" "$OUT/"
    cp "$STS_BACKUPS_DIR/session_editor/WORKFLOW_TEST_ALL_TYPES.recipe.json" "$OUT/"
  fi
  git -C "$H" status --short | awk '{print $2}' | while read -r f; do
    grep -qxF "$f" <<<"$PRE_DIRTY" && continue  # never discard pre-existing uncommitted work
    git -C "$H" ls-files --error-unmatch "$f" >/dev/null 2>&1 && git -C "$H" checkout -- "$f" && echo "restored $f in sts-helpers"
  done || true
  done_step
fi

if [[ -z "${SKIP_SIM:-}" ]]; then
  step "cli-prepend"
  # Baseline CLI sim scripts never load plugins (coke_orders_* undefined -> empty SQL IN () -> fatal in
  # warm_start_summarize). Preload the plugin registry for CLI only; same shim is used for every label.
  docker exec "$WEB" sh -c 'cat > /usr/local/etc/php/sandbox_prepend.php <<"PHP"
<?php
if (PHP_SAPI === "cli") { foreach (["plugins/plugins.php", "plugin_registry.php"] as $f) { $p = "/var/www/html/sts/" . $f; if (is_readable($p)) { require_once $p; if (function_exists("plugins_bootstrap_all")) { plugins_bootstrap_all(); } break; } } }
PHP
echo "auto_prepend_file=/usr/local/etc/php/sandbox_prepend.php" > /usr/local/etc/php/conf.d/zz-sandbox-prepend.ini'
  trap 'docker exec "$WEB" rm -f /usr/local/etc/php/conf.d/zz-sandbox-prepend.ini' EXIT
  done_step
  # Sandbox-only: stale session output from earlier labels would be appended to (manifest.json) / mixed in.
  [[ -d "$STS_BACKUPS_DIR/session_state" ]] && find "$STS_BACKUPS_DIR/session_state" -mindepth 1 -delete
  # Sandbox-only: plugin state outside the DB (track scale seed/settings/log) persists in backups/ across labels.
  # Plugin state outside the DB persists in backups/ across labels; start each label clean.
  rm -f "$STS_BACKUPS_DIR/track_scale/seed.json" "$STS_BACKUPS_DIR/track_scale/settings.json" "$STS_BACKUPS_DIR/track_scale/session.log"
  step "warm_start"
  "$H/bin/apply_warm_start.sh" --seed "$SEED" --sessions 3 --max-sessions 3 2>&1 | tee "$OUT/warm_start.log"
  done_step
  docker exec "$DB" mariadb-dump --skip-comments --skip-dump-date -u sts_user -psts_password sts_db3 > "$OUT/db_after_warm_start.sql"
  step "sessions"
  STS_SIM_SEED="$SEED" "$H/bin/run_session_simulations.sh" --no-restore --no-warm-start --sessions "$SESSIONS" 2>&1 | tee "$OUT/sessions.log"
  docker exec "$WEB" rm -f /usr/local/etc/php/conf.d/zz-sandbox-prepend.ini
  done_step
fi

if [[ -z "${SKIP_DUMP:-}" ]]; then
  step "db.sql"
  docker exec "$DB" mariadb-dump --skip-comments --skip-dump-date -u sts_user -psts_password sts_db3 > "$OUT/db.sql"
  done_step
fi

if [[ -z "${SKIP_OUTPUT:-}" ]]; then
  step "session_output"
  mkdir -p "$OUT/session_output"
  [[ -d "$STS_BACKUPS_DIR/session_state" ]] && cp -R "$STS_BACKUPS_DIR/session_state/." "$OUT/session_output/"
  [[ -d "$STS_BACKUPS_DIR/track_scale" ]] && { mkdir -p "$OUT/session_output/track_scale"; cp "$STS_BACKUPS_DIR"/track_scale/{seed.json,settings.json} "$OUT/session_output/track_scale/" 2>/dev/null || true; }
  # any report/stats paths mentioned in the logs
  grep -hoE '/[A-Za-z0-9_./-]*(stats|report)[A-Za-z0-9_.-]*\.(txt|md|json|html|csv)' "$OUT"/*.log 2>/dev/null | sort -u > "$OUT/session_output/report_paths.txt" || true
  done_step
fi

step "manifest"
rm -f "$OUT/manifest.txt"
(cd "$OUT" && find . -type f ! -name manifest.txt | sed 's|^\./||' | sort | xargs md5sum > manifest.txt) 2>/dev/null \
  || (cd "$OUT" && find . -type f ! -name manifest.txt | sed 's|^\./||' | sort | xargs md5 -r > manifest.txt)
done_step

COMMIT="$(git -C "$STS_DOCKER" rev-parse --short HEAD 2>/dev/null || echo unknown)"
cat > "$OUT/README.md" <<EOT
# Sandbox baseline: $LABEL
Commit: $COMMIT (STS_DOCKER=$STS_DOCKER), seed=$SEED, sessions=$SESSIONS, generated $(date -u +%FT%TZ)

## Reproduce
1. Start the sandbox from the checkout under test (port 8981, throwaway DB, PROVISION_DATABASE=1):
   cd \$STS_DOCKER && STS_SANDBOX_DATA=$STS_SANDBOX_DATA COMPOSE_PROJECT_NAME=sts-sandbox docker compose -f docker-compose.yml -f docker-compose.sandbox.yml --profile build up -d --build
   (for a clean DB: \`down\` first so the /opt/sql.initialized marker is gone)
2. STS_DOCKER=\$STS_DOCKER STS_SANDBOX_DATA=$STS_SANDBOX_DATA SEED=$SEED SESSIONS=$SESSIONS bin/sandbox_baseline.sh <label>
3. Compare: diff -r <baseline-dir> <new-dir> (ignore timings.txt, manifest.txt, README.md).
Functions bootstrap: open_db.php, session_runtime.php (+session_runtime_bootstrap()), operational_steps_catalog.php, session_helpers.php.
Sim steps (preceded by a CLI-only auto_prepend shim that loads the plugin registry, removed afterwards): apply_warm_start.sh --seed $SEED --sessions 3 --max-sessions 3; run_session_simulations.sh --no-restore --no-warm-start --sessions $SESSIONS.
Note: the sim step restores nothing itself; backups/hart_seed is restored by apply_warm_start.sh.

## Timings (seconds)
$(cat "$OUT/timings.txt")

## Determinism
Expected deterministic: db_after_warm_start.sql (seeded; verified identical across two runs except history-table timestamps; mask with sed -E 's/[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]+//'), php-lint.txt, functions.txt, catalog.json, openapi yaml, recipe json (check for generated dates), db.sql structure and seeded data.
NOT deterministic even with the same seed (verified): db.sql and session_output/* after the workflow sessions (the workflow engine uses unseeded randomness; differences are in car/order assignments, not just timestamps). Also run-dependent: db.sql rows with dates or history/log tables, session_output/*, *.log (timings, paths, container ids), timings.txt, manifest.txt (hashes of those).
Confirm by running the baseline twice on the same commit and diffing before trusting a diff as a regression.
EOT
echo; echo "Baseline written to $OUT"
