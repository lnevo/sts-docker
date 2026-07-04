#!/bin/bash
# Reload STS schema + HART seed (used by reseed_hart_db.sh).
#
# Runs create_sts_db3.sql first (system defaults), then seed_hart_data.sql.
# start.sh uses the same two files on first provision — see start.sh.
set -euo pipefail

SCHEMA="/var/www/html/sts/create_sts_db3.sql"
SEED="/var/www/html/sts/seed_hart_data.sql"

if [[ ! -f "${SCHEMA}" ]]; then
  echo "Schema not found: ${SCHEMA}" >&2
  exit 1
fi

if [[ ! -f "${SEED}" ]]; then
  echo "Seed not found: ${SEED}" >&2
  exit 1
fi

mysql -h "${MYSQL_HOST}" -u "${MYSQL_USER}" -p"${MYSQL_PASSWORD}" \
  -e "DROP DATABASE IF EXISTS \`${MYSQL_DATABASE}\`; CREATE DATABASE \`${MYSQL_DATABASE}\`;"

mysql -h "${MYSQL_HOST}" -u "${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" < "${SCHEMA}"
mysql -h "${MYSQL_HOST}" -u "${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" < "${SEED}"
touch /opt/sql.initialized
