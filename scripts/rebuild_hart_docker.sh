#!/usr/bin/env bash
# Full rebuild: reseed DB + rebuild web image (only when sts/ PHP tree changed).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

usage() {
  cat <<'EOF'
Usage: rebuild_hart_docker.sh [options]

Runs reseed_hart_db.sh (regenerate sts/seed_hart_data.sql, copy into container,
reload DB), then rebuilds the web image so first-time provision via start.sh
loads the same seed file baked into the image.

Passes through: --hart-dir, --skip-generate, --sync-images,
--recreate-containers
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

"${SCRIPT_DIR}/reseed_hart_db.sh" "$@"

echo "==> Rebuilding web image"
cd "${REPO_ROOT}"
docker compose --profile build build web

echo ""
echo "Image rebuilt. Containers were not restarted — reseed already applied the DB."
