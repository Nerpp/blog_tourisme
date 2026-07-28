#!/usr/bin/env bash

set -Eeuo pipefail

TEMP_STASH_OID=""
TEMP_STASH_MESSAGE=""
TEMP_STASH_ACTIVE=false
TEMP_STASH_RESTORE_ATTEMPTED=false
INITIAL_BRANCH=""
CURRENT_PHASE="initialisation"

die() {
    echo
    echo "Erreur : $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "la commande '$1' est introuvable."
}

report_failure_context() {
    local status=$?
    local active_branch

    if [[ "$status" -eq 0 ]]; then
        return
    fi

    set +e

    active_branch="$(git branch --show-current 2>/dev/null)"

    echo >&2
    echo "État de récupération :" >&2
    echo "  Phase interrompue : $CURRENT_PHASE" >&2
    echo "  Branche active : ${active_branch:-HEAD détachée}" >&2

    if [[ "$TEMP_STASH_ACTIVE" == true ]]; then
        echo "  Sauvegarde temporaire conservée : $TEMP_STASH_OID" >&2

        if [[ "$TEMP_STASH_RESTORE_ATTEMPTED" == true ]]; then
            echo "  Une restauration a été tentée ; inspecte git status avant toute nouvelle application." >&2
        else
            echo "  Pour restaurer cette sauvegarde : git stash apply --index $TEMP_STASH_OID" >&2
        fi
    fi

    if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "  État du worktree :" >&2
        git status --short >&2
    fi

    echo "  Aucune commande push, reset --hard ou clean n’a été exécutée." >&2
}

trap report_failure_context EXIT

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || die "cette commande doit être exécutée dans le dépôt Git."

cd "$ROOT"

# Permet au Makefile de transmettre COMPOSE="docker compose".
read -r -a COMPOSE_CMD <<< "${COMPOSE:-docker compose}"

DEPENDENCY_FILES=(
    composer.json
    composer.lock
    package.json
    package-lock.json
)

require_command git
require_command "${COMPOSE_CMD[0]}"

find_stash_ref_by_oid() {
    local expected_oid="$1"
    local entry_oid
    local entry_ref

    while IFS=$'\t' read -r entry_oid entry_ref; do
        if [[ "$entry_oid" == "$expected_oid" ]]; then
            printf '%s\n' "$entry_ref"
            return 0
        fi
    done < <(git stash list --format='%H%x09%gd')

    return 1
}

find_stash_oid_by_message() {
    local expected_message="$1"
    local entry_oid
    local entry_ref
    local entry_subject

    while IFS=$'\t' read -r entry_oid entry_ref entry_subject; do
        if [[ "$entry_subject" == *"$expected_message"* ]]; then
            printf '%s\n' "$entry_oid"
            return 0
        fi
    done < <(git stash list --format='%H%x09%gd%x09%gs')

    return 1
}

create_temporary_stash() {
    local stash_output=""
    local created_stash_oid=""

    TEMP_STASH_MESSAGE="work-start temporary backup $(date -u +%Y%m%dT%H%M%SZ) pid $$"

    echo
    echo "Modifications locales détectées sur work."
    echo "Création d’une sauvegarde temporaire incluant les fichiers non suivis..."

    if ! stash_output="$(
        git stash push \
            --include-untracked \
            -m "$TEMP_STASH_MESSAGE" \
            2>&1
    )"; then
        echo "$stash_output" >&2

        if created_stash_oid="$(find_stash_oid_by_message "$TEMP_STASH_MESSAGE")"; then
            TEMP_STASH_OID="$created_stash_oid"
            TEMP_STASH_ACTIVE=true
        fi

        die "la création de la sauvegarde temporaire a échoué."
    fi

    echo "$stash_output"

    created_stash_oid="$(find_stash_oid_by_message "$TEMP_STASH_MESSAGE")" \
        || die "Git n’a créé aucun stash portant l’identifiant temporaire attendu."

    TEMP_STASH_OID="$created_stash_oid"
    TEMP_STASH_ACTIVE=true

    if [[ -n "$(git status --porcelain)" ]]; then
        echo
        echo "Le stash temporaire n’a pas pu isoler tous les changements :" >&2
        git status --short >&2
        die "l’intégration est annulée ; la sauvegarde $TEMP_STASH_OID est conservée."
    fi

    echo "Sauvegarde temporaire créée : $TEMP_STASH_OID"
}

drop_temporary_stash() {
    local stash_ref
    local resolved_oid

    if ! stash_ref="$(find_stash_ref_by_oid "$TEMP_STASH_OID")"; then
        die "les modifications sont restaurées, mais la sauvegarde $TEMP_STASH_OID est introuvable dans la liste des stashes ; aucune suppression n’a été tentée."
    fi

    resolved_oid="$(git rev-parse "$stash_ref")"

    [[ "$resolved_oid" == "$TEMP_STASH_OID" ]] \
        || die "la référence $stash_ref ne pointe plus vers la sauvegarde attendue ; elle est conservée."

    if ! git stash drop "$stash_ref"; then
        die "les modifications sont restaurées, mais la sauvegarde $TEMP_STASH_OID n’a pas pu être supprimée."
    fi

    TEMP_STASH_ACTIVE=false
    echo "Sauvegarde temporaire supprimée après vérification de la restauration."
}

show_conflicted_files() {
    local conflicted_files

    conflicted_files="$(git diff --name-only --diff-filter=U)"

    if [[ -n "$conflicted_files" ]]; then
        echo "$conflicted_files"
    else
        git status --short
    fi
}

restore_temporary_stash() {
    echo
    echo "Restauration des modifications locales depuis $TEMP_STASH_OID..."
    TEMP_STASH_RESTORE_ATTEMPTED=true

    if ! git stash apply --index "$TEMP_STASH_OID"; then
        echo
        echo "Arrêt : la restauration des modifications locales a rencontré des conflits." >&2
        echo "Fichiers concernés :" >&2
        show_conflicted_files >&2
        echo >&2
        echo "La sauvegarde temporaire est conservée : $TEMP_STASH_OID" >&2
        echo "Inspecte git status et résous les conflits avant de supprimer manuellement ce stash." >&2
        die "Docker et les installations de dépendances n’ont pas été lancés."
    fi

    if [[ -n "$(git diff --name-only --diff-filter=U)" ]]; then
        echo "Fichiers encore en conflit :" >&2
        show_conflicted_files >&2
        die "la sauvegarde $TEMP_STASH_OID est conservée et Docker n’a pas été lancé."
    fi

    echo "Modifications locales restaurées avec succès."
    drop_temporary_stash
}

restore_stash_after_failed_merge() {
    if [[ "$TEMP_STASH_ACTIVE" != true ]]; then
        return
    fi

    echo
    echo "Restauration de l’état local antérieur tout en conservant la sauvegarde $TEMP_STASH_OID..."
    TEMP_STASH_RESTORE_ATTEMPTED=true

    if git stash apply --index "$TEMP_STASH_OID"; then
        echo "Les modifications locales sont de nouveau présentes dans le worktree."
        echo "Le stash est volontairement conservé comme copie de sécurité."
    else
        echo "La restauration automatique a elle-même rencontré un problème." >&2
        echo "Le stash $TEMP_STASH_OID reste disponible ; inspecte git status." >&2
    fi
}

capture_dependency_state() {
    local file
    local worktree_hash
    local index_state

    for file in "${DEPENDENCY_FILES[@]}"; do
        if [[ -e "$file" ]]; then
            worktree_hash="$(git hash-object -- "$file")"
        else
            worktree_hash="absent"
        fi

        index_state="$(git ls-files --stage -- "$file")"
        printf 'worktree\t%s\t%s\n' "$file" "$worktree_hash"
        printf 'index\t%s\t%s\n' "$file" "$index_state"
    done
}

echo "Préparation de l’environnement Estela Exploration..."

#
# Vérification des opérations Git inachevées
#

CURRENT_PHASE="vérification des opérations Git"

for marker in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD; do
    if [[ -f "$(git rev-parse --git-path "$marker")" ]]; then
        die "une opération Git est déjà en cours : $marker. Termine-la ou annule-la avant de relancer make work-start."
    fi
done

for directory in rebase-merge rebase-apply; do
    if [[ -d "$(git rev-parse --git-path "$directory")" ]]; then
        die "un rebase Git est déjà en cours. Termine-le ou annule-le avant de relancer make work-start."
    fi
done

#
# État initial et protection des changements présents hors de work
#

INITIAL_BRANCH="$(git branch --show-current)"
INITIAL_BRANCH_LABEL="${INITIAL_BRANCH:-HEAD détachée}"
INITIAL_STATUS="$(git status --porcelain)"

echo
echo "Branche initiale : $INITIAL_BRANCH_LABEL"

if [[ -z "$INITIAL_STATUS" ]]; then
    echo "Worktree propre : oui"
else
    echo "Worktree propre : non"
fi

if [[ "$INITIAL_BRANCH" != "work" && -n "$INITIAL_STATUS" ]]; then
    echo
    echo "Arrêt : la branche $INITIAL_BRANCH_LABEL contient des modifications locales :" >&2
    echo >&2
    git status --short >&2
    echo >&2
    echo "Enregistre, stash ou déplace volontairement ces fichiers avant de relancer make work-start." >&2
    echo "Aucun changement de branche n’a été effectué et aucune modification n’a été perdue." >&2
    die "les modifications d’une branche autre que work ne sont jamais déplacées automatiquement."
fi

#
# Actualisation des références avant le choix ou la création de work
#

CURRENT_PHASE="récupération des références distantes"
echo
echo "Récupération des références distantes..."
git fetch origin --prune

git show-ref --verify --quiet refs/remotes/origin/dev \
    || die "la branche distante origin/dev est introuvable."

#
# Préparation automatique de la branche work
#

CURRENT_PHASE="préparation de la branche work"

if [[ "$INITIAL_BRANCH" == "work" ]]; then
    echo "Branche work déjà active."
else
    if [[ "$INITIAL_BRANCH" != "dev" ]]; then
        echo
        echo "Avertissement : la branche initiale est $INITIAL_BRANCH_LABEL." >&2
        echo "Elle ne sera ni fusionnée, ni réécrite ; passage prudent vers work." >&2
    fi

    if git show-ref --verify --quiet refs/heads/work; then
        echo "Branche work locale trouvée."
        echo "Passage sur la branche work..."
        git switch work \
            || die "Git a refusé le passage vers work ; aucune option de forçage n’a été utilisée."
    elif git show-ref --verify --quiet refs/remotes/origin/work; then
        echo "Branche distante origin/work trouvée."
        echo "Création de la branche locale work avec suivi de origin/work..."
        git switch --track origin/work \
            || die "Git a refusé la création de work depuis origin/work."
    else
        echo "Aucune branche work locale ou distante n’a été trouvée."
        echo "Création de la branche locale work depuis origin/dev, sans push..."
        git switch -c work origin/dev \
            || die "Git a refusé la création de work depuis origin/dev."
    fi
fi

[[ "$(git branch --show-current)" == "work" ]] \
    || die "la branche active n’est pas work après sa préparation."

#
# Sauvegarde des modifications locales autorisées sur work
#

LOCAL_CHANGES_PRESERVED=false

if [[ -n "$(git status --porcelain)" ]]; then
    CURRENT_PHASE="sauvegarde des modifications locales de work"
    create_temporary_stash
    LOCAL_CHANGES_PRESERVED=true
fi

#
# Intégration non destructive de origin/dev dans work
#

CURRENT_PHASE="intégration de origin/dev dans work"
echo
echo "Intégration de origin/dev dans work..."

MERGE_OUTPUT=""

if ! MERGE_OUTPUT="$(git merge --no-edit origin/dev 2>&1)"; then
    MERGE_CONFLICTS="$(git diff --name-only --diff-filter=U)"
    echo "$MERGE_OUTPUT" >&2

    if [[ -f "$(git rev-parse --git-path MERGE_HEAD)" ]]; then
        echo
        echo "Annulation de la fusion en conflit..."

        if ! git merge --abort; then
            echo "Git n’a pas pu annuler automatiquement la fusion." >&2
            echo "La sauvegarde temporaire éventuelle reste intacte." >&2
            die "inspecte git status avant toute autre action."
        fi
    fi

    restore_stash_after_failed_merge

    echo
    echo "Arrêt : la fusion de origin/dev dans work a échoué." >&2

    if [[ -n "$MERGE_CONFLICTS" ]]; then
        echo "Fichiers en conflit détectés avant annulation :" >&2
        echo "$MERGE_CONFLICTS" >&2
    fi

    echo "La branche work a été restaurée à son état précédant la fusion." >&2
    die "Docker et les installations de dépendances n’ont pas été lancés."
fi

echo "$MERGE_OUTPUT"
echo "Branche work actualisée depuis origin/dev."

if [[ "$TEMP_STASH_ACTIVE" == true ]]; then
    CURRENT_PHASE="restauration des modifications locales de work"
    restore_temporary_stash
fi

[[ "$(git branch --show-current)" == "work" ]] \
    || die "la branche active a changé de manière inattendue."

if [[ -n "$(git diff --name-only --diff-filter=U)" ]]; then
    echo "Fichiers en conflit :" >&2
    show_conflicted_files >&2
    die "Docker et les installations de dépendances n’ont pas été lancés."
fi

DEPENDENCY_STATE_BEFORE="$(capture_dependency_state)"

#
# Démarrage de Docker
#

CURRENT_PHASE="préparation Docker"
echo
echo "Démarrage de l’environnement Docker..."
echo "Arrêt du service Node avant l’installation des dépendances npm..."
"${COMPOSE_CMD[@]}" stop node

echo
echo "Démarrage des services Docker hors Node..."
"${COMPOSE_CMD[@]}" up -d mysql php web phpmyadmin mailpit

#
# Installation stricte des dépendances verrouillées
#

CURRENT_PHASE="installation des dépendances"
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
    echo "Erreur : npm ci a échoué." >&2
    echo "Le service Node reste arrêté afin de ne pas utiliser un node_modules incomplet." >&2
    die "corrige la cause de l’échec, puis relance make work-start."
fi

#
# Vérification que les installations n’ont modifié aucun verrou
#

CURRENT_PHASE="vérification des fichiers de dépendances"
DEPENDENCY_STATE_AFTER="$(capture_dependency_state)"

if [[ "$DEPENDENCY_STATE_AFTER" != "$DEPENDENCY_STATE_BEFORE" ]]; then
    echo
    echo "Erreur : l’installation a modifié un fichier de dépendances :" >&2
    git status --short -- "${DEPENDENCY_FILES[@]}" >&2
    die "les fichiers de dépendances doivent rester identiques à leur état antérieur à l’installation."
fi

echo
echo "Démarrage du service Node après la réussite de npm ci..."
"${COMPOSE_CMD[@]}" up -d node

#
# État final
#

CURRENT_PHASE="état final"
echo
echo "État des services Docker..."
"${COMPOSE_CMD[@]}" ps

echo
echo "Environnement Estela Exploration prêt."
echo
echo "Branche active : $(git branch --show-current)"
echo "Source intégrée : origin/dev"

if [[ "$LOCAL_CHANGES_PRESERVED" == true ]]; then
    echo "Modifications locales préservées : restaurées avec succès"
else
    echo "Modifications locales préservées : aucune"
fi

echo "Aucun push n’a été effectué automatiquement."
