#!/usr/bin/env bash
# Regenerate seed SQL and reload the STS database (no Docker image rebuild).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
# Single seed file — generate_hart_seed.py writes here; reseed copies into the container.
SEED_SQL="${REPO_ROOT}/sts/seed_hart_data.sql"
CONTAINER_SEED="/var/www/html/sts/seed_hart_data.sql"
DEFAULT_HART_DIR="${HOME}/Desktop/HART/Car Cards"
COMPOSE=(docker compose --profile build)

HART_DIR="${DEFAULT_HART_DIR}"
SKIP_GENERATE=0
SKIP_IMAGES=1
RECREATE_CONTAINERS=0

usage() {
  cat <<'EOF'
Usage: reseed_hart_db.sh [options]

Regenerate sts/seed_hart_data.sql and reload the MariaDB database in the
running STS stack. Copies sts/seed_hart_data.sql into the web container and
runs load_hart_seed.sh — same create_sts_db3.sql + seed_hart_data.sql pair as start.sh.

Options:
  --hart-dir PATH        Car Cards project root (default: ~/Desktop/HART/Car Cards)
  --skip-generate        Use existing sts/seed_hart_data.sql on disk
  --sync-images          Also run sync_hart_car_images.py
  --recreate-containers  docker compose down -v && up -d before reseeding
  -h, --help             Show this help

Requires the build-profile web + db containers to be running (or use
--recreate-containers to start them).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --hart-dir)
      HART_DIR="$2"
      shift 2
      ;;
    --skip-generate)
      SKIP_GENERATE=1
      shift
      ;;
    --sync-images)
      SKIP_IMAGES=0
      shift
      ;;
    --recreate-containers)
      RECREATE_CONTAINERS=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ ! -d "${HART_DIR}" ]]; then
  echo "HART dir not found: ${HART_DIR}" >&2
  exit 1
fi

cd "${REPO_ROOT}"

if [[ "${SKIP_GENERATE}" -eq 0 ]]; then
  echo "==> Regenerating seed SQL"
  python3 "${SCRIPT_DIR}/generate_hart_seed.py" \
    --hart-dir "${HART_DIR}" \
    --config "${SCRIPT_DIR}/hart_seed_config.json" \
    --output "${SEED_SQL}"
else
  echo "==> Using existing ${SEED_SQL}"
fi

if [[ "${RECREATE_CONTAINERS}" -eq 1 ]]; then
  echo "==> Recreating containers (down -v, up -d)"
  "${COMPOSE[@]}" down -v
  "${COMPOSE[@]}" up -d
fi

WEB_CID="$("${COMPOSE[@]}" ps -q web 2>/dev/null || true)"
if [[ -z "${WEB_CID}" ]]; then
  echo "Web container is not running. Start with:" >&2
  echo "  cd ${REPO_ROOT} && docker compose --profile build up -d" >&2
  exit 1
fi

if [[ "${SKIP_IMAGES}" -eq 0 ]]; then
  echo "==> Syncing rolling stock images to ${REPO_ROOT}/images"
  python3 "${SCRIPT_DIR}/sync_hart_car_images.py" \
    --metadata "${HART_DIR}/image_metadata.csv" \
    --final-dir "${HART_DIR}/CarImagesFinal" \
    --roster "${HART_DIR}/HART_MergedCarRoster.xml" \
    --seed-sql "${SEED_SQL}" \
    --config "${SCRIPT_DIR}/hart_seed_config.json" \
    --output-dir "${REPO_ROOT}/images"
fi

echo "==> Copying seed into web container"
docker cp "${SEED_SQL}" "${WEB_CID}:${CONTAINER_SEED}"

echo "==> Reloading database (create_sts_db3.sql + seed_hart_data.sql)"
docker exec "${WEB_CID}" /var/www/html/sts/load_hart_seed.sh

echo ""
echo "Done. Database reloaded with fresh seed data."
echo "STS: http://localhost:8980/sts/ (or your configured port)"
