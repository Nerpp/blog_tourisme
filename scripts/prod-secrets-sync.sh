#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

PROJECT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || fail "cette commande doit être exécutée dans un dépôt Git."

cd "$PROJECT_ROOT"

SSH_HOST="${SSH_HOST:-mb89iw.ftp.infomaniak.com}"
SSH_PORT="${SSH_PORT:-22}"
SSH_USER="${SSH_USER:-mb89iw_regiaurelienblog}"
DEPLOY_PATH="${DEPLOY_PATH:-/home/clients/818e8818e353c47dcb970919c8723bd2/sites/estela-exploration-app}"
PHP_BIN="${PHP_BIN:-/opt/php8.4/bin/php}"
SITE_URL="${SITE_URL:-https://estela-exploration.fr}"

VAULT_DIR=config/secrets/prod
PRIVATE_KEY="$VAULT_DIR/prod.decrypt.private.php"
PUBLIC_KEY="$VAULT_DIR/prod.encrypt.public.php"
SECRET_LIST="$VAULT_DIR/prod.list.php"
REQUIRED_SECRETS=(
    BREVO_MAILER_DSN
    OAUTH_GOOGLE_CLIENT_ID
    OAUTH_GOOGLE_CLIENT_SECRET
)

for command_name in git ssh rsync sha256sum curl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "la commande '$command_name' est requise."
done

[[ "$SSH_PORT" =~ ^[0-9]+$ ]] \
    || fail "SSH_PORT doit être numérique."
[[ "$SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] \
    || fail "SSH_HOST contient des caractères non autorisés."
[[ "$SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] \
    || fail "SSH_USER contient des caractères non autorisés."
[[ "$DEPLOY_PATH" =~ ^/[A-Za-z0-9._/-]+$ && "$DEPLOY_PATH" != / ]] \
    || fail "DEPLOY_PATH doit être un chemin absolu non racine sans caractères spéciaux."
[[ "$PHP_BIN" =~ ^/[A-Za-z0-9._/-]+$ ]] \
    || fail "PHP_BIN doit être un chemin absolu sans caractères spéciaux."
[[ "$SITE_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/.*)?$ ]] \
    || fail "SITE_URL doit être une URL HTTPS valide."

[[ -f "$PUBLIC_KEY" ]] \
    || fail "le fichier public du vault de production est absent."
[[ -f "$SECRET_LIST" ]] \
    || fail "la liste du vault de production est absente."

if git ls-files --error-unmatch -- "$PRIVATE_KEY" >/dev/null 2>&1; then
    fail "la clé privée de production est suivie par Git."
fi

shopt -s nullglob
for secret_name in "${REQUIRED_SECRETS[@]}"; do
    encrypted_files=("$VAULT_DIR/prod.$secret_name."*.php)
    if (( ${#encrypted_files[@]} != 1 )); then
        fail "le vault local doit contenir exactement un fichier chiffré pour $secret_name."
    fi
done
shopt -u nullglob

SSH_DESTINATION="$SSH_USER@$SSH_HOST"
SSH_OPTIONS=(
    -p "$SSH_PORT"
    -o BatchMode=yes
    -o StrictHostKeyChecking=yes
)

printf 'Vérification de la connexion SSH...\n'
ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" true \
    || fail "connexion SSH impossible."

printf -v remote_private_key '%q' "$DEPLOY_PATH/$PRIVATE_KEY"
printf -v remote_public_key '%q' "$DEPLOY_PATH/$PUBLIC_KEY"

ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" \
    "test -f $remote_private_key" \
    || fail "la clé privée de production est absente du serveur."

ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" \
    "test -f $remote_public_key" \
    || fail "la clé publique du vault est absente du serveur."

local_public_hash="$(sha256sum "$PUBLIC_KEY" | awk '{print $1}')"
remote_public_hash="$(
    ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" \
        "sha256sum $remote_public_key | cut -d ' ' -f 1"
)"

[[ "$local_public_hash" == "$remote_public_hash" ]] \
    || fail "les clés publiques locale et distante ne correspondent pas. Aucun changement effectué."

printf 'Préflight réussi : vault local complet, clé privée distante présente et clé publique identique.\n'
printf 'Cible : %s@%s:%s\n' "$SSH_USER" "$SSH_HOST" "$DEPLOY_PATH"

if [[ "${CONFIRM_PRODUCTION:-0}" != 1 ]]; then
    [[ -t 0 ]] \
        || fail "confirmation interactive impossible. Utilisez explicitement CONFIRM_PRODUCTION=1."
    read -r -p "Synchroniser les secrets de production et reconstruire le cache ? [y/N] " confirmation
    [[ "$confirmation" == y || "$confirmation" == Y ]] \
        || fail "opération annulée."
fi

printf 'Création de la sauvegarde distante du vault...\n'
printf -v remote_environment 'DEPLOY_PATH=%q' "$DEPLOY_PATH"
ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" \
    "$remote_environment sh -se" <<'REMOTE_BACKUP'
set -eu

vault_dir="$DEPLOY_PATH/config/secrets/prod"
backup_dir="$DEPLOY_PATH/var/backups/secrets"
backup_file="$backup_dir/prod-vault-$(date -u +%Y%m%dT%H%M%SZ)-$$.tar.gz"
temporary_backup="$backup_file.tmp"

test -d "$vault_dir"
mkdir -p "$backup_dir"
umask 077
trap 'rm -f "$temporary_backup"' EXIT HUP INT TERM
tar -C "$DEPLOY_PATH/config/secrets" -czf "$temporary_backup" prod
mv "$temporary_backup" "$backup_file"
chmod 600 "$backup_file"
trap - EXIT HUP INT TERM

find "$backup_dir" -maxdepth 1 -type f -name 'prod-vault-*.tar.gz' -printf '%T@ %p\n' \
    | LC_ALL=C sort -nr \
    | awk 'NR > 10 { sub(/^[^ ]+ /, ""); print }' \
    | while IFS= read -r obsolete_backup; do
        rm -f -- "$obsolete_backup"
    done
REMOTE_BACKUP

printf 'Synchronisation du seul vault de production...\n'
rsync \
    --archive \
    --compress \
    --exclude='prod.decrypt.private.php' \
    -e "ssh -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=yes" \
    "$VAULT_DIR/" \
    "$SSH_DESTINATION:$DEPLOY_PATH/$VAULT_DIR/"

printf 'Validation distante et reconstruction du cache de production...\n'
printf -v remote_environment 'DEPLOY_PATH=%q PHP_BIN=%q' "$DEPLOY_PATH" "$PHP_BIN"
ssh "${SSH_OPTIONS[@]}" "$SSH_DESTINATION" \
    "$remote_environment sh -se" <<'REMOTE_VALIDATE'
set -eu

cd "$DEPLOY_PATH"
vault_dir=config/secrets/prod

test -f "$vault_dir/prod.decrypt.private.php"
test -f "$vault_dir/prod.encrypt.public.php"
test -f "$vault_dir/prod.list.php"

for secret_name in \
    BREVO_MAILER_DSN \
    OAUTH_GOOGLE_CLIENT_ID \
    OAUTH_GOOGLE_CLIENT_SECRET
do
    set -- "$vault_dir/prod.$secret_name."*.php
    test "$#" -eq 1
    test -f "$1"
done

rm -rf var/cache/prod
if ! APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console cache:warmup --env=prod --no-debug >/dev/null 2>&1; then
    echo "La reconstruction du cache de production a échoué." >&2
    exit 1
fi

secrets_output="$(mktemp)"
trap 'rm -f "$secrets_output"' EXIT HUP INT TERM
chmod 600 "$secrets_output"
if ! APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console secrets:list --env=prod --no-debug >"$secrets_output" 2>/dev/null; then
    echo "La lecture des noms du vault de production a échoué." >&2
    exit 1
fi

for secret_name in \
    BREVO_MAILER_DSN \
    OAUTH_GOOGLE_CLIENT_ID \
    OAUTH_GOOGLE_CLIENT_SECRET
do
    grep -F "$secret_name" "$secrets_output" >/dev/null
done
REMOTE_VALIDATE

http_status() {
    local path=$1

    curl \
        --silent \
        --show-error \
        --retry 3 \
        --retry-delay 5 \
        --output /dev/null \
        --write-out '%{http_code}' \
        "${SITE_URL%/}$path"
}

printf 'Contrôle HTTP de la production...\n'
[[ "$(http_status /)" == 200 ]] \
    || fail "la page d'accueil ne répond pas avec HTTP 200."
[[ "$(http_status /login)" == 200 ]] \
    || fail "la page /login ne répond pas avec HTTP 200."

headers_file="$(mktemp)"
trap 'rm -f "$headers_file"' EXIT HUP INT TERM
chmod 600 "$headers_file"
google_status="$(
    curl \
        --silent \
        --show-error \
        --retry 3 \
        --retry-delay 5 \
        --output /dev/null \
        --dump-header "$headers_file" \
        --write-out '%{http_code}' \
        "${SITE_URL%/}/connect/google"
)"

[[ "$google_status" == 302 ]] \
    || fail "/connect/google ne répond pas avec HTTP 302."
awk '
    tolower($0) ~ /^location:[[:space:]]*https:\/\/accounts\.google\.com([\/:?]|$)/ { found = 1 }
    END { exit !found }
' "$headers_file" \
    || fail "la redirection Google ne cible pas accounts.google.com."

printf '\nSynchronisation terminée avec succès.\n'
printf '  - sauvegarde distante créée et limitée aux 10 plus récentes\n'
printf '  - clé privée distante préservée\n'
printf '  - trois secrets obligatoires détectés sans révéler leur valeur\n'
printf '  - cache de production reconstruit\n'
printf '  - contrôles HTTP /, /login et /connect/google réussis\n'
