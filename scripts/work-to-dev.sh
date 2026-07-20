#!/usr/bin/env bash

set -Eeuo pipefail

die() {
    echo
    echo "Erreur : $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "la commande '$1' est introuvable."
}

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || die "cette commande doit être exécutée dans le dépôt Git."

cd "$ROOT"

read -r -a COMPOSE_CMD <<< "${COMPOSE:-docker compose}"

require_command git
require_command gh
require_command make

echo "Préparation de la promotion work vers dev..."

#
# Vérification des opérations Git inachevées
#

for marker in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD; do
    if [[ -f "$(git rev-parse --git-path "$marker")" ]]; then
        die "une opération Git est déjà en cours : $marker."
    fi
done

for directory in rebase-merge rebase-apply; do
    if [[ -d "$(git rev-parse --git-path "$directory")" ]]; then
        die "un rebase Git est déjà en cours."
    fi
done

#
# La promotion doit obligatoirement partir de work
#

CURRENT_BRANCH="$(git branch --show-current)"

if [[ "$CURRENT_BRANCH" != "work" ]]; then
    die "la branche active est '${CURRENT_BRANCH:-HEAD détachée}'. Passe d'abord sur work."
fi

#
# Aucun fichier non commité ne doit entrer accidentellement dans la promotion
#

if [[ -n "$(git status --porcelain)" ]]; then
    echo
    echo "Le dépôt contient des modifications non commitées :"
    echo
    git status --short

    die "commit ou stash nécessaire avant la promotion."
fi

#
# Vérification de l'authentification GitHub CLI
#

if ! gh auth status >/dev/null 2>&1; then
    die "GitHub CLI n'est pas authentifié. Lance 'gh auth login'."
fi

#
# Actualisation des références
#

echo
echo "Récupération des références distantes..."
git fetch origin --prune

git show-ref --verify --quiet refs/remotes/origin/dev \
    || die "la branche origin/dev est introuvable."

#
# Vérification de la synchronisation avec origin/work
#

if git show-ref --verify --quiet refs/remotes/origin/work; then
    if ! git merge-base --is-ancestor origin/work HEAD; then
        die "work locale ne contient pas les derniers commits de origin/work."
    fi
fi

#
# Il doit réellement y avoir quelque chose à proposer à dev
#

if git diff --quiet origin/dev...HEAD; then
    echo
    echo "Aucune différence entre work et dev."
    echo "Aucune pull request à créer."
    exit 0
fi

echo
echo "Différence prévue entre work et dev :"
git diff --stat origin/dev...HEAD

#
# Démarrage Docker et validation locale complète
#

echo
echo "Démarrage des services Docker..."
"${COMPOSE_CMD[@]}" up -d mysql php web mailpit

echo
echo "Exécution de la validation locale complète..."
make test-all

#
# Les tests ne doivent pas avoir produit de changement suivi par Git
#

if [[ -n "$(git status --porcelain)" ]]; then
    echo
    echo "Des fichiers ont été modifiés pendant la validation :"
    echo
    git status --short

    die "le dépôt doit rester propre après make test-all."
fi

#
# Publication de work sans force push
#

HEAD_SHA="$(git rev-parse HEAD)"

echo
echo "Publication de work..."

if git show-ref --verify --quiet refs/remotes/origin/work; then
    git push origin work
else
    git push --set-upstream origin work
fi

git fetch origin --prune

REMOTE_WORK_SHA="$(git rev-parse origin/work)"

if [[ "$REMOTE_WORK_SHA" != "$HEAD_SHA" ]]; then
    die "origin/work ne correspond pas au commit local attendu."
fi

#
# Identification du dépôt GitHub
#

REPOSITORY="$(gh repo view --json nameWithOwner --jq '.nameWithOwner')"

[[ -n "$REPOSITORY" ]] \
    || die "impossible d'identifier le dépôt GitHub."

#
# Création ou réutilisation de la PR work vers dev
#

PR_NUMBER="$(
    gh pr list \
        --repo "$REPOSITORY" \
        --state open \
        --base dev \
        --head work \
        --json number \
        --jq '.[0].number // empty'
)"

if [[ -z "$PR_NUMBER" ]]; then
    PR_TITLE="${PR_TITLE:-$(git log -1 --pretty=%s)}"

    echo
    echo "Création de la pull request work vers dev..."

    PR_URL="$(
        gh pr create \
            --repo "$REPOSITORY" \
            --base dev \
            --head work \
            --title "$PR_TITLE" \
            --body "$(cat <<'EOF'
## Validation

- Validation locale complète avec `make test-all`
- Fusion demandée avec un merge commit
- Aucun squash, rebase ou contournement administrateur
EOF
)"
    )"

    PR_NUMBER="$(
        gh pr view "$PR_URL" \
            --repo "$REPOSITORY" \
            --json number \
            --jq '.number'
    )"
else
    PR_URL="$(
        gh pr view "$PR_NUMBER" \
            --repo "$REPOSITORY" \
            --json url \
            --jq '.url'
    )"

    echo
    echo "Pull request existante réutilisée : #$PR_NUMBER"
fi

echo
echo "Pull request : $PR_URL"
echo "Commit work : $HEAD_SHA"

#
# Attente de l'apparition des vérifications GitHub
#

echo
echo "Attente du démarrage des vérifications GitHub..."

CHECKS_DISCOVERED=false

for _attempt in $(seq 1 60); do
    CHECK_OUTPUT=""
    CHECK_STATUS=0

    if CHECK_OUTPUT="$(
        gh pr checks "$PR_NUMBER" \
            --repo "$REPOSITORY" \
            --required 2>&1
    )"; then
        CHECK_STATUS=0
    else
        CHECK_STATUS=$?
    fi

    # 0 : tous les checks sont déjà terminés avec succès.
    # 8 : des checks sont présents mais encore en attente.
    if [[ "$CHECK_STATUS" -eq 0 || "$CHECK_STATUS" -eq 8 ]]; then
        CHECKS_DISCOVERED=true
        break
    fi

    if grep -qiE "no checks reported|no required checks" <<< "$CHECK_OUTPUT"; then
        sleep 5
        continue
    fi

    echo "$CHECK_OUTPUT"
    exit "$CHECK_STATUS"
done

if [[ "$CHECKS_DISCOVERED" != true ]]; then
    die "aucune vérification GitHub requise n'est apparue après cinq minutes."
fi

#
# Attente de Quality
#

echo
echo "Attente des vérifications requises..."

gh pr checks "$PR_NUMBER" \
    --repo "$REPOSITORY" \
    --required \
    --watch \
    --fail-fast

#
# Revalidation de l'identité de la PR et du commit
#

IFS=$'\t' read -r \
    PR_STATE \
    PR_BASE \
    PR_HEAD_BRANCH \
    PR_HEAD_SHA \
    <<< "$(
        gh pr view "$PR_NUMBER" \
            --repo "$REPOSITORY" \
            --json state,baseRefName,headRefName,headRefOid \
            --jq '[.state, .baseRefName, .headRefName, .headRefOid] | @tsv'
    )"

[[ "$PR_STATE" == "OPEN" ]] \
    || die "la pull request n'est plus ouverte."

[[ "$PR_BASE" == "dev" ]] \
    || die "la branche cible de la pull request n'est plus dev."

[[ "$PR_HEAD_BRANCH" == "work" ]] \
    || die "la branche source de la pull request n'est plus work."

[[ "$PR_HEAD_SHA" == "$HEAD_SHA" ]] \
    || die "work a changé pendant l'exécution. La fusion est annulée."

#
# Confirmation manuelle
#

echo
echo "Toutes les vérifications requises sont réussies."
echo "PR #$PR_NUMBER : work → dev"
echo "Commit vérifié : $HEAD_SHA"
echo

read -r -p "Fusionner maintenant avec un merge commit ? [o/N] " CONFIRMATION

case "$CONFIRMATION" in
    o|O|oui|OUI|Oui|y|Y|yes|YES|Yes)
        ;;
    *)
        echo
        echo "Fusion annulée. La pull request reste ouverte."
        exit 0
        ;;
esac

#
# Fusion GitHub avec le SHA exact
#

echo
echo "Fusion de la pull request dans dev..."

gh pr merge "$PR_NUMBER" \
    --repo "$REPOSITORY" \
    --merge \
    --match-head-commit "$HEAD_SHA"

git fetch origin dev

MERGE_SHA="$(
    gh pr view "$PR_NUMBER" \
        --repo "$REPOSITORY" \
        --json mergeCommit \
        --jq '.mergeCommit.oid // empty'
)"

echo
echo "Promotion terminée."
echo "Pull request : $PR_URL"
echo "Commit work : $HEAD_SHA"
echo "Merge commit dev : ${MERGE_SHA:-en cours de résolution}"
echo
echo "La branche locale active reste work."
echo "La promotion automatique dev vers main pourra maintenant prendre le relais."
