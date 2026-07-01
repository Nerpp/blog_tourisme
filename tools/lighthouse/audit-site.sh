#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

BASE_URL="${LIGHTHOUSE_BASE_URL:-http://localhost:8082}"

for argument in "$@"; do
  case "$argument" in
    --base-url=*) BASE_URL="${argument#*=}" ;;
    --help)
      cat <<'EOF'
Usage: ./tools/lighthouse/audit-site.sh [options]

Options:
  --base-url=URL       Instance Lighthouse publique (doit rester http://localhost:8082)
  --devices=LIST       mobile, desktop ou mobile,desktop (défaut : les deux)
  --runs=N             Nombre de passages par page et appareil (défaut : 1)
  --page=ID            Limite l'audit à un identifiant du catalogue (répétable)
  --max-pages=N        Limite le catalogue pour une validation courte
  --keep-raw           Conserve les rapports HTML et JSON individuels dans var/lighthouse/raw/
  --help               Affiche cette aide
EOF
      exit 0
      ;;
  esac
done

BASE_URL="${BASE_URL%/}"
if [[ "$BASE_URL" == "http://localhost:8080" || "$BASE_URL" == "http://127.0.0.1:8080" ]]; then
  echo "Refus d’auditer $BASE_URL : le port 8080 est l’instance de développement." >&2
  exit 2
fi
if [[ "$BASE_URL" != "http://localhost:8082" ]]; then
  echo "L’audit Lighthouse doit cibler http://localhost:8082. Reçu : $BASE_URL" >&2
  exit 2
fi

if ! curl --fail --silent --show-error --max-time 15 "$BASE_URL/_lighthouse/health" >/dev/null; then
  echo "L’instance Lighthouse ne répond pas sur $BASE_URL." >&2
  echo "Démarre-la avec : docker compose --profile tools up -d php_lighthouse web_lighthouse" >&2
  exit 2
fi

docker compose --profile tools exec -T php_lighthouse \
  php bin/console cache:clear --env=test --no-warmup

docker compose --profile tools run --rm --no-deps -e LIGHTHOUSE_BASE_URL="$BASE_URL" lighthouse \
  npm run audit:lighthouse -- "$@"
