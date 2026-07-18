#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
    echo "Erreur : cette commande doit être exécutée dans le dépôt Git."
    exit 1
}

cd "$ROOT"

# Permet au Makefile de transmettre :
# COMPOSE="docker compose"
read -r -a COMPOSE_CMD <<< "${COMPOSE:-docker compose}"

DEPENDENCY_FILES=(
    composer.json
    composer.lock
    package.json
    package-lock.json
)

echo "Préparation de l'environnement Estela Exploration..."

#
# Vérification des opérations Git inachevées
#

git_operation_in_progress=false

for marker in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD; do
    marker_path="$(git rev-parse --git-path "$marker")"

    if [[ -f "$marker_path" ]]; then
        git_operation_in_progress=true
        break
    fi
done

if [[ "$git_operation_in_progress" == false ]]; then
    for directory in rebase-merge rebase-apply; do
        directory_path="$(git rev-parse --git-path "$directory")"

        if [[ -d "$directory_path" ]]; then
            git_operation_in_progress=true
            break
        fi
    done
fi

if [[ "$git_operation_in_progress" == true ]]; then
    echo
    echo "Arrêt : une opération Git est déjà en cours."
    echo "Termine ou annule cette opération avant de relancer make work-start."
    exit 1
fi

#
# Imposition de la branche work
#

CURRENT_BRANCH="$(git branch --show-current)"

if [[ "$CURRENT_BRANCH" != "work" ]]; then
    echo
    echo "Branche active : ${CURRENT_BRANCH:-HEAD détachée}"
    echo "Le développement doit être effectué sur la branche work."

    # Ne transporte jamais automatiquement des modifications
    # depuis dev, main ou une autre branche vers work.
    if [[ -n "$(git status --porcelain)" ]]; then
        echo
        echo "Passage automatique sur work impossible."
        echo "La branche actuelle contient des modifications non enregistrées."
        echo "Effectue un commit ou un stash, puis relance make work-start."
        exit 1
    fi
fi

#
# Récupération des références distantes
#

echo
echo "Récupération des références distantes..."
git fetch origin --prune

#
# Passage sur work
#

if [[ "$(git branch --show-current)" != "work" ]]; then
    if git show-ref --verify --quiet refs/heads/work; then
        echo "Passage sur la branche work..."
        git switch work
    elif git show-ref --verify --quiet refs/remotes/origin/work; then
        echo "Création de la branche locale work depuis origin/work..."
        git switch --track -c work origin/work
    else
        echo
        echo "Erreur : la branche work est introuvable localement et sur origin."
        exit 1
    fi
fi

# Garde-fou absolu avant les commandes de développement.
if [[ "$(git branch --show-current)" != "work" ]]; then
    echo
    echo "Erreur : la branche work n'est pas active."
    exit 1
fi

echo
echo "Branche active : work"

#
# Démarrage de Docker
#
# Docker doit toujours démarrer, même lorsque des fichiers de
# dépendances sont déjà modifiés.
#

echo
echo "Démarrage des services Docker..."
"${COMPOSE_CMD[@]}" up -d

#
# Contrôle des fichiers de dépendances
#

SKIP_DEPENDENCY_UPDATE=false

if ! git diff --quiet -- "${DEPENDENCY_FILES[@]}" \
    || ! git diff --cached --quiet -- "${DEPENDENCY_FILES[@]}"; then
    SKIP_DEPENDENCY_UPDATE=true

    echo
    echo "Des modifications de dépendances sont déjà en cours sur work :"
    echo

    git status --short -- "${DEPENDENCY_FILES[@]}"

    echo
    echo "Docker est démarré."
    echo "Les nouvelles mises à jour Composer et npm sont ignorées."
    echo "Valide ou commit ces fichiers avant de relancer les mises à jour."
fi

#
# Mises à jour des dépendances
#

if [[ "$SKIP_DEPENDENCY_UPDATE" == false ]]; then
    echo
    echo "Mise à jour des dépendances Composer — patch uniquement..."
    "${COMPOSE_CMD[@]}" exec -T php \
        composer update \
        --patch-only \
        --no-interaction \
        --prefer-dist

    echo
    echo "Validation de la configuration Composer..."
    "${COMPOSE_CMD[@]}" exec -T php \
        composer validate \
        --strict

    echo
    echo "Mise à jour des dépendances npm autorisées par package.json..."
    "${COMPOSE_CMD[@]}" run --rm node \
        npm update \
        --no-audit \
        --no-fund
else
    echo
    echo "Mises à jour Composer et npm non relancées."
fi

#
# État final
#

echo
echo "État des services Docker..."
"${COMPOSE_CMD[@]}" ps

echo
echo "État des fichiers de dépendances :"

if git diff --quiet -- "${DEPENDENCY_FILES[@]}" \
    && git diff --cached --quiet -- "${DEPENDENCY_FILES[@]}"; then
    echo "Aucune modification de dépendances."
else
    git status --short -- "${DEPENDENCY_FILES[@]}"

    echo
    echo "Résumé des changements :"
    git diff --stat -- "${DEPENDENCY_FILES[@]}"
fi

echo
echo "Environnement prêt."
echo "Branche active : $(git branch --show-current)"
echo
echo "Aucun commit et aucun push n'ont été effectués automatiquement."