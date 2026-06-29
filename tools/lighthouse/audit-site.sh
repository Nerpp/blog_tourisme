#!/usr/bin/env bash

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

BASE_URL="http://localhost:8080"
DEVICES_CSV="mobile,desktop"
RUNS=1
LIMIT_PER_TYPE=0
MAX_URLS=0

usage() {
  cat <<'EOF'
Usage: ./tools/lighthouse/audit-site.sh [options]

Options:
  --base-url=URL           URL de base (défaut : http://localhost:8080)
  --devices=LISTE         mobile, desktop ou mobile,desktop
  --runs=N                Nombre de passages par page et appareil (défaut : 1)
  --limit-per-type=N      Validation représentative : limite les pages dynamiques de chaque type
  --max-urls=N            Validation technique : limite le nombre total d’URL
  --help                  Affiche cette aide
EOF
}

for argument in "$@"; do
  case "$argument" in
    --base-url=*) BASE_URL="${argument#*=}" ;;
    --devices=*) DEVICES_CSV="${argument#*=}" ;;
    --runs=*) RUNS="${argument#*=}" ;;
    --limit-per-type=*) LIMIT_PER_TYPE="${argument#*=}" ;;
    --max-urls=*) MAX_URLS="${argument#*=}" ;;
    --help) usage; exit 0 ;;
    *) echo "Option inconnue : $argument" >&2; usage >&2; exit 2 ;;
  esac
done

BASE_URL="${BASE_URL%/}"
if [[ ! "$BASE_URL" =~ ^https?://[^/]+([/].*)?$ ]]; then
  echo "URL de base invalide : $BASE_URL" >&2
  exit 2
fi
if [[ ! "$RUNS" =~ ^[1-9][0-9]*$ ]]; then
  echo "--runs doit être un entier strictement positif." >&2
  exit 2
fi
if [[ ! "$LIMIT_PER_TYPE" =~ ^[0-9]+$ ]]; then
  echo "--limit-per-type doit être un entier positif ou nul." >&2
  exit 2
fi
if [[ ! "$MAX_URLS" =~ ^[0-9]+$ ]]; then
  echo "--max-urls doit être un entier positif ou nul." >&2
  exit 2
fi

IFS=',' read -r -a DEVICES <<< "$DEVICES_CSV"
if [[ ${#DEVICES[@]} -eq 0 ]]; then
  echo "Aucun appareil demandé." >&2
  exit 2
fi
declare -A SEEN_DEVICES=()
for device in "${DEVICES[@]}"; do
  if [[ "$device" != "mobile" && "$device" != "desktop" ]]; then
    echo "Appareil non pris en charge : $device" >&2
    exit 2
  fi
  if [[ -n "${SEEN_DEVICES[$device]:-}" ]]; then
    echo "Appareil dupliqué : $device" >&2
    exit 2
  fi
  SEEN_DEVICES[$device]=1
done

if [[ "$BASE_URL" == "http://localhost:"* || "$BASE_URL" == "http://127.0.0.1:"* ]]; then
  docker compose up -d web >/dev/null || exit 2
fi

if ! curl --fail --silent --show-error --max-time 20 "$BASE_URL/" >/dev/null; then
  echo "Le site n’est pas joignable : $BASE_URL/" >&2
  exit 2
fi

LIGHTHOUSE_IMAGE="blog-tourisme-lighthouse:local"
if ! docker image inspect "$LIGHTHOUSE_IMAGE" >/dev/null 2>&1; then
  echo "Construction de l’image Lighthouse…"
  docker compose --profile tools build lighthouse || exit 2
fi
if ! docker image inspect "$LIGHTHOUSE_IMAGE" >/dev/null 2>&1; then
  echo "Image du service Lighthouse introuvable." >&2
  exit 2
fi

TIMESTAMP="$(date '+%Y-%m-%d_%H-%M-%S')"
CAMPAIGN_RELATIVE="var/lighthouse/$TIMESTAMP"
CAMPAIGN_DIRECTORY="$ROOT_DIR/$CAMPAIGN_RELATIVE"
if [[ -e "$CAMPAIGN_DIRECTORY" ]]; then
  echo "La campagne existe déjà : $CAMPAIGN_DIRECTORY" >&2
  exit 2
fi
mkdir -p "$CAMPAIGN_DIRECTORY/raw"

DISCOVERY_OPTIONS=(--format=json)
TSV_OPTIONS=(--format=tsv)
if [[ "$LIMIT_PER_TYPE" -gt 0 ]]; then
  DISCOVERY_OPTIONS+=("--limit-per-type=$LIMIT_PER_TYPE")
  TSV_OPTIONS+=("--limit-per-type=$LIMIT_PER_TYPE")
fi
if [[ "$MAX_URLS" -gt 0 ]]; then
  DISCOVERY_OPTIONS+=("--max-urls=$MAX_URLS")
  TSV_OPTIONS+=("--max-urls=$MAX_URLS")
fi

if ! docker compose exec -T php php bin/console app:lighthouse:urls "${DISCOVERY_OPTIONS[@]}" > "$CAMPAIGN_DIRECTORY/urls.json"; then
  echo "La découverte des URL a échoué." >&2
  exit 2
fi
if ! docker compose exec -T php php bin/console app:lighthouse:urls "${TSV_OPTIONS[@]}" > "$CAMPAIGN_DIRECTORY/urls.tsv"; then
  echo "La découverte TSV des URL a échoué." >&2
  exit 2
fi

cat > "$CAMPAIGN_DIRECTORY/campaign.env" <<EOF
createdAt=$TIMESTAMP
baseUrl=$BASE_URL
devices=$DEVICES_CSV
runs=$RUNS
limitPerType=$LIMIT_PER_TYPE
maxUrls=$MAX_URLS
EOF

printf 'id\ttype\ttitle\turl\tabsoluteUrl\tdevice\trun\tstatus\texitCode\tcommand\tlogPath\tjsonPath\thtmlPath\n' > "$CAMPAIGN_DIRECTORY/attempts.tsv"

USER_ID="$(id -u)"
GROUP_ID="$(id -g)"
HAD_ERRORS=0
TOTAL_URLS="$(($(wc -l < "$CAMPAIGN_DIRECTORY/urls.tsv") - 1))"
CURRENT_URL=0

while IFS=$'\t' read -r page_id page_type page_title page_url; do
  [[ "$page_id" == "id" ]] && continue
  [[ -z "$page_id" ]] && continue
  CURRENT_URL=$((CURRENT_URL + 1))
  absolute_url="$BASE_URL$page_url"

  for device in "${DEVICES[@]}"; do
    mkdir -p "$CAMPAIGN_DIRECTORY/raw/$device"
    for ((run = 1; run <= RUNS; run++)); do
      suffix=""
      if [[ "$RUNS" -gt 1 ]]; then
        suffix="-run-$run"
      fi
      report_stem="$page_id$suffix"
      output_relative="raw/$device/$report_stem"
      output_base="/workspace/$CAMPAIGN_RELATIVE/$output_relative"
      log_relative="raw/$device/$report_stem.log"
      log_file="$CAMPAIGN_DIRECTORY/$log_relative"
      json_relative="$output_relative.json"
      html_relative="$output_relative.html"
      command_text="docker run --rm --network host $LIGHTHOUSE_IMAGE node tools/lighthouse/run-audit.mjs $device $absolute_url $output_base"

      echo "[$CURRENT_URL/$TOTAL_URLS] $device passage $run/$RUNS — $page_title ($page_url)"
      docker run --rm \
        --network host \
        --user "$USER_ID:$GROUP_ID" \
        -e CHROME_PATH=/usr/bin/chromium \
        -e HOME=/tmp/lighthouse-home \
        -e XDG_CACHE_HOME=/tmp/lighthouse-cache \
        -e XDG_CONFIG_HOME=/tmp/lighthouse-config \
        -v "$ROOT_DIR:/workspace" \
        -w /workspace \
        "$LIGHTHOUSE_IMAGE" \
        node tools/lighthouse/run-audit.mjs "$device" "$absolute_url" "$output_base" \
        > "$log_file" 2>&1
      exit_code=$?

      status="ok"
      if [[ "$exit_code" -ne 0 || ! -f "$CAMPAIGN_DIRECTORY/$json_relative" || ! -f "$CAMPAIGN_DIRECTORY/$html_relative" ]]; then
        status="error"
        HAD_ERRORS=1
        echo "  Échec (code $exit_code), voir $log_relative" >&2
      fi

      printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$page_id" "$page_type" "$page_title" "$page_url" "$absolute_url" "$device" "$run" \
        "$status" "$exit_code" "$command_text" "$log_relative" "$json_relative" "$html_relative" \
        >> "$CAMPAIGN_DIRECTORY/attempts.tsv"
    done
  done
done < "$CAMPAIGN_DIRECTORY/urls.tsv"

docker run --rm \
  --user "$USER_ID:$GROUP_ID" \
  -v "$ROOT_DIR:/workspace" \
  -w /workspace \
  "$LIGHTHOUSE_IMAGE" \
  node tools/lighthouse/analyze-reports.mjs "/workspace/$CAMPAIGN_RELATIVE"
analyzer_exit=$?
if [[ "$analyzer_exit" -ne 0 ]]; then
  echo "La génération de la synthèse a échoué (code $analyzer_exit)." >&2
  exit "$analyzer_exit"
fi

echo "Campagne terminée : $CAMPAIGN_RELATIVE"
echo "Rapport global : $CAMPAIGN_RELATIVE/summary.md"

if [[ "$HAD_ERRORS" -ne 0 ]]; then
  exit 1
fi
