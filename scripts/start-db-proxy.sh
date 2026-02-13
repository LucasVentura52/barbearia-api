#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ -f ".env" ]; then
  set -a
  # shellcheck disable=SC1091
  . ".env"
  set +a
fi

: "${PG_PROXY_REMOTE_HOST:?PG_PROXY_REMOTE_HOST not set}"

node scripts/pg-tls-proxy.mjs
