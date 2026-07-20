#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

for command_name in git gh; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "la commande '$command_name' est requise."
done

PROJECT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || fail "cette commande doit être exécutée dans un dépôt Git."

cd "$PROJECT_ROOT"

REPOSITORY=Nerpp/blog_tourisme
WORKFLOW=.github/workflows/ci.yml

gh auth status >/dev/null 2>&1 \
    || fail "GitHub CLI n'est pas authentifié."

main_reference="$(git ls-remote --exit-code origin refs/heads/main)" \
    || fail "impossible de lire origin/main."
read -r main_sha remote_ref extra <<< "$main_reference"

[[ -z "${extra:-}" && "$remote_ref" == refs/heads/main && "$main_sha" =~ ^[0-9a-f]{40}$ ]] \
    || fail "la réponse de origin/main est invalide."

quality_check_state="$(
    gh api \
        -H 'Accept: application/vnd.github+json' \
        "repos/$REPOSITORY/commits/$main_sha/check-runs?per_page=100" \
        --jq '[.check_runs[] | select(.name == "Quality")] | if length == 0 then "missing" else sort_by(.id) | last | (.status + ":" + (.conclusion // "")) end'
)" || fail "impossible de vérifier le check Quality."

[[ "$quality_check_state" == completed:success ]] \
    || fail "le check Quality n'a pas réussi pour le SHA exact de origin/main."

github_actor="$(gh api user --jq .login)" \
    || fail "impossible d'identifier le compte GitHub authentifié."
[[ "$github_actor" =~ ^[A-Za-z0-9-]+$ ]] \
    || fail "le compte GitHub authentifié est invalide."

printf 'SHA de origin/main validé par Quality : %s\n' "$main_sha"

if [[ "${CONFIRM_PRODUCTION:-0}" != 1 ]]; then
    [[ -t 0 ]] \
        || fail "confirmation interactive impossible. Utilisez explicitement CONFIRM_PRODUCTION=1."
    read -r -p "Redéployer ce SHA exact en production ? [y/N] " confirmation
    [[ "$confirmation" == y || "$confirmation" == Y ]] \
        || fail "opération annulée."
fi

list_dispatch_run_ids() {
    gh api \
        -H 'Accept: application/vnd.github+json' \
        "repos/$REPOSITORY/actions/workflows/ci.yml/runs?event=workflow_dispatch&branch=main&head_sha=$main_sha&actor=$github_actor&per_page=100" \
        --jq '.workflow_runs[].id'
}

known_run_ids="$(list_dispatch_run_ids)" \
    || fail "impossible de lire les exécutions existantes du workflow."

printf 'Déclenchement du redéploiement de %s...\n' "$main_sha"
gh workflow run "$WORKFLOW" \
    --repo "$REPOSITORY" \
    --ref main \
    -f mode=redeploy-production >/dev/null \
    || fail "le déclenchement du workflow a échoué."

run_id=
for _attempt in {1..30}; do
    current_run_ids="$(list_dispatch_run_ids)" \
        || fail "impossible de rechercher l'exécution déclenchée."

    while IFS= read -r candidate_run_id; do
        [[ -n "$candidate_run_id" ]] || continue
        if ! grep -Fqx -- "$candidate_run_id" <<< "$known_run_ids"; then
            run_id=$candidate_run_id
            break
        fi
    done <<< "$current_run_ids"

    [[ -z "$run_id" ]] || break
    sleep 2
done

[[ -n "$run_id" ]] \
    || fail "le run workflow_dispatch correspondant au SHA $main_sha est introuvable."

printf 'Suivi du run GitHub Actions %s pour le SHA %s...\n' "$run_id" "$main_sha"
gh run watch "$run_id" \
    --repo "$REPOSITORY" \
    --exit-status \
    || fail "le redéploiement de production a échoué."

printf 'Redéploiement de production réussi pour le SHA %s.\n' "$main_sha"
