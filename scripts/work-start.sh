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
# Vérification de la branche et du worktree avant toute synchronisation
#

CURRENT_BRANCH="$(git branch --show-current)"

if [[ "$CURRENT_BRANCH" != "work" ]]; then
    echo
    echo "Branche active : ${CURRENT_BRANCH:-HEAD détachée}"
    echo "Arrêt : make work-start doit être exécuté depuis la branche work."
    echo "Aucun changement de branche automatique n'est effectué."
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo
    echo "Arrêt : le worktree contient des modifications locales :"
    echo
    git status --short
    echo
    echo "Enregistre ou mets de côté ces modifications manuellement avant de relancer make work-start."
    echo "Aucun fetch, merge, démarrage Docker ou changement de dépendances n'a été effectué."
    exit 1
fi

#
# Récupération des références distantes
#

echo
echo "Récupération des références distantes..."
git fetch origin

#
# Synchronisation de work depuis origin/dev
#

if ! git show-ref --verify --quiet refs/remotes/origin/dev; then
    echo
    echo "Erreur : la branche distante origin/dev est introuvable."
    exit 1
fi

echo
echo "Intégration de origin/dev dans work..."

if ! git merge --no-edit origin/dev; then
    echo
    echo "Arrêt : la fusion de origin/dev dans work a rencontré des conflits."
    echo "Inspecte les fichiers concernés avec :"
    echo "  git status"
    echo "  git diff --name-only --diff-filter=U"
    echo
    echo "Résous les conflits, ajoute les fichiers corrigés avec git add, puis termine avec git commit."
    echo "Pour annuler cette fusion manuellement : git merge --abort"
    echo "Docker et les installations de dépendances n'ont pas été lancés."
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo
    echo "Arrêt : le worktree n'est plus propre après la synchronisation Git."
    echo "Docker et les installations de dépendances n'ont pas été lancés."
    exit 1
fi

echo
echo "Branche active : work"

#
# Démarrage de Docker
#
echo
echo "Arrêt du service Node avant l'installation des dépendances npm..."
"${COMPOSE_CMD[@]}" stop node

echo
echo "Démarrage des services Docker hors Node..."
"${COMPOSE_CMD[@]}" up -d mysql php web phpmyadmin mailpit

#
# Installation stricte des dépendances verrouillées
#

echo
echo "Installation des dépendances Composer depuis composer.lock..."
"${COMPOSE_CMD[@]}" exec -T php \
    composer install \
        --no-interaction \
        --prefer-dist

echo
echo "Validation de la configuration Composer..."
"${COMPOSE_CMD[@]}" exec -T php \
    composer validate \
        --strict

echo
echo "Installation des dépendances npm depuis package-lock.json..."
if ! "${COMPOSE_CMD[@]}" run --rm node \
    npm ci \
        --no-audit \
        --no-fund; then
    echo
    echo "Erreur : npm ci a échoué."
    echo "Le service Node reste arrêté afin de ne pas utiliser un node_modules incomplet."
    echo "Corrige la cause de l'échec, puis relance make work-start."
    exit 1
fi

#
# Vérification que les installations n'ont modifié aucun verrou
#

if ! git diff --quiet -- "${DEPENDENCY_FILES[@]}" \
    || ! git diff --cached --quiet -- "${DEPENDENCY_FILES[@]}"; then
    echo
    echo "Erreur : l'installation a modifié un fichier de dépendances suivi par Git :"
    echo
    git status --short -- "${DEPENDENCY_FILES[@]}"
    echo
    echo "composer.lock et package-lock.json doivent rester identiques aux versions enregistrées sur Git."
    exit 1
fi

echo
echo "Démarrage du service Node après la réussite de npm ci..."
"${COMPOSE_CMD[@]}" up -d node

#
# État final
#

echo
echo "État des services Docker..."
"${COMPOSE_CMD[@]}" ps

echo
echo "État des fichiers de dépendances :"

echo "composer.lock et package-lock.json sont identiques aux versions enregistrées sur Git."

echo
echo "Environnement prêt."
echo "Branche active : $(git branch --show-current)"
echo
echo "Aucun commit et aucun push n'ont été effectués automatiquement."
