#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT_UNDER_TEST="$PROJECT_ROOT/scripts/work-start.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/work-start-tests.XXXXXX")"

CASE_ROOT=""
ORIGIN=""
SEED=""
REPOSITORY=""
COMPOSE_LOG=""
LAST_OUTPUT=""
PASSED=0
TOTAL_SCENARIOS=10

cleanup() {
    if [[ -n "${TEST_ROOT:-}" \
        && -d "$TEST_ROOT" \
        && "$(basename "$TEST_ROOT")" == work-start-tests.* ]]; then
        rm -rf -- "$TEST_ROOT"
    fi
}

trap cleanup EXIT

fail() {
    echo "ÉCHEC : $*" >&2

    if [[ -n "$LAST_OUTPUT" ]]; then
        echo >&2
        echo "$LAST_OUTPUT" >&2
    fi

    exit 1
}

assert_equal() {
    local expected="$1"
    local actual="$2"
    local description="$3"

    [[ "$actual" == "$expected" ]] \
        || fail "$description — attendu '$expected', obtenu '$actual'."
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    [[ "$haystack" == *"$needle"* ]] \
        || fail "$description — texte absent : $needle"
}

assert_file_empty() {
    local file="$1"
    local description="$2"

    [[ ! -s "$file" ]] \
        || fail "$description — le fichier $file n’est pas vide."
}

write_file() {
    local file="$1"
    local content="$2"

    printf '%s\n' "$content" > "$file"
}

configure_repository() {
    local repository="$1"

    git -C "$repository" config user.name "Work Start Tests"
    git -C "$repository" config user.email "work-start-tests@example.test"
    git -C "$repository" config commit.gpgsign false
}

create_fake_compose() {
    local fake_compose="$TEST_ROOT/fake-compose"

    printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -Eeuo pipefail' \
        'printf "%s\\n" "$*" >> "${FAKE_COMPOSE_LOG:?}"' \
        > "$fake_compose"
    chmod +x "$fake_compose"
    printf '%s\n' "$fake_compose"
}

FAKE_COMPOSE="$(create_fake_compose)"

new_fixture() {
    local name="$1"
    local create_remote_work="$2"

    CASE_ROOT="$TEST_ROOT/$name"
    ORIGIN="$CASE_ROOT/origin.git"
    SEED="$CASE_ROOT/seed"
    REPOSITORY="$CASE_ROOT/repository"
    COMPOSE_LOG="$CASE_ROOT/compose.log"

    mkdir -p "$CASE_ROOT" "$SEED/scripts"
    git init --bare --quiet "$ORIGIN"
    git init --quiet --initial-branch=dev "$SEED"
    configure_repository "$SEED"

    cp "$SCRIPT_UNDER_TEST" "$SEED/scripts/work-start.sh"
    chmod +x "$SEED/scripts/work-start.sh"
    write_file "$SEED/tracked.txt" "base tracked"
    write_file "$SEED/conflict.txt" "base conflict"
    write_file "$SEED/composer.json" '{}'
    write_file "$SEED/composer.lock" '{}'
    write_file "$SEED/package.json" '{}'
    write_file "$SEED/package-lock.json" '{}'

    git -C "$SEED" add .
    git -C "$SEED" commit --quiet -m "Initial dev"
    git -C "$SEED" remote add origin "$ORIGIN"
    git -C "$SEED" push --quiet --set-upstream origin dev
    git --git-dir="$ORIGIN" symbolic-ref HEAD refs/heads/dev

    if [[ "$create_remote_work" == true ]]; then
        git -C "$SEED" switch --quiet -c work
        write_file "$SEED/work-marker.txt" "remote work"
        git -C "$SEED" add work-marker.txt
        git -C "$SEED" commit --quiet -m "Remote work"
        git -C "$SEED" push --quiet --set-upstream origin work
        git -C "$SEED" switch --quiet dev
    fi

    git clone --quiet --branch dev "$ORIGIN" "$REPOSITORY"
    configure_repository "$REPOSITORY"
    : > "$COMPOSE_LOG"
    LAST_OUTPUT=""
}

advance_remote_dev() {
    local file="$1"
    local content="$2"

    git -C "$SEED" switch --quiet dev
    write_file "$SEED/$file" "$content"
    git -C "$SEED" add "$file"
    git -C "$SEED" commit --quiet -m "Advance dev"
    git -C "$SEED" push --quiet origin dev
}

advance_remote_work() {
    local file="$1"
    local content="$2"

    git -C "$SEED" switch --quiet work
    write_file "$SEED/$file" "$content"
    git -C "$SEED" add "$file"
    git -C "$SEED" commit --quiet -m "Advance work"
    git -C "$SEED" push --quiet origin work
    git -C "$SEED" switch --quiet dev
}

run_successfully() {
    if ! LAST_OUTPUT="$(
        cd "$REPOSITORY"
        COMPOSE="$FAKE_COMPOSE" \
        FAKE_COMPOSE_LOG="$COMPOSE_LOG" \
            scripts/work-start.sh 2>&1
    )"; then
        fail "work-start devait réussir."
    fi
}

run_with_expected_failure() {
    if LAST_OUTPUT="$(
        cd "$REPOSITORY"
        COMPOSE="$FAKE_COMPOSE" \
        FAKE_COMPOSE_LOG="$COMPOSE_LOG" \
            scripts/work-start.sh 2>&1
    )"; then
        fail "work-start devait échouer."
    fi
}

assert_common_success() {
    local compose_calls

    assert_equal "work" "$(git -C "$REPOSITORY" branch --show-current)" \
        "la branche finale doit être work"
    git -C "$REPOSITORY" merge-base --is-ancestor origin/dev HEAD \
        || fail "work doit contenir origin/dev."
    [[ -s "$COMPOSE_LOG" ]] \
        || fail "la préparation Docker simulée devait être lancée."
    assert_contains "$LAST_OUTPUT" "Source intégrée : origin/dev" \
        "le résumé doit indiquer la source intégrée"

    compose_calls="$(<"$COMPOSE_LOG")"
    assert_contains "$compose_calls" "stop node" \
        "le service Node doit être arrêté avant npm ci"
    assert_contains "$compose_calls" "up -d mysql php web phpmyadmin mailpit" \
        "les services Docker hors Node doivent démarrer"
    assert_contains "$compose_calls" "exec -T php composer install --no-interaction --prefer-dist" \
        "Composer doit installer le lockfile"
    assert_contains "$compose_calls" "exec -T php composer validate --strict" \
        "Composer doit être validé"
    assert_contains "$compose_calls" "run --rm node npm ci --no-audit --no-fund" \
        "npm ci doit installer le lockfile"
    assert_contains "$compose_calls" "up -d node" \
        "Node doit démarrer après npm ci"
    assert_contains "$compose_calls" "ps" \
        "l’état Docker final doit être affiché"
}

pass_scenario() {
    PASSED=$((PASSED + 1))
    echo "OK $PASSED/$TOTAL_SCENARIOS — $1"
}

# Scénario 1 : dev propre avec branche work locale existante.
new_fixture "01-dev-local-work" true
git -C "$REPOSITORY" switch --quiet --track origin/work
git -C "$REPOSITORY" switch --quiet dev
advance_remote_dev "dev-one.txt" "dev one"
run_successfully
assert_common_success
assert_contains "$LAST_OUTPUT" "Branche work locale trouvée." \
    "la branche locale devait être sélectionnée"
pass_scenario "dev propre, work locale"

# Scénario 2 : dev propre, work uniquement distante.
new_fixture "02-dev-remote-work" true
advance_remote_dev "dev-two.txt" "dev two"
run_successfully
assert_common_success
assert_equal "origin/work" "$(git -C "$REPOSITORY" rev-parse --abbrev-ref work@{upstream})" \
    "work doit suivre origin/work"
assert_contains "$LAST_OUTPUT" "Création de la branche locale work avec suivi de origin/work" \
    "le suivi distant devait être annoncé"
pass_scenario "dev propre, work distante"

# Scénario 3 : aucune branche work locale ou distante.
new_fixture "03-create-work-from-dev" false
advance_remote_dev "dev-three.txt" "dev three"
run_successfully
assert_common_success
assert_equal "" "$(git --git-dir="$ORIGIN" for-each-ref --format='%(refname)' refs/heads/work)" \
    "aucune branche work distante ne doit être créée"
assert_contains "$LAST_OUTPUT" "Création de la branche locale work depuis origin/dev, sans push" \
    "la création locale devait être annoncée"
pass_scenario "création locale de work depuis origin/dev"

# Scénario 4 : work propre.
new_fixture "04-clean-work" true
git -C "$REPOSITORY" switch --quiet --track origin/work
advance_remote_dev "dev-four.txt" "dev four"
run_successfully
assert_common_success
assert_contains "$LAST_OUTPUT" "Branche work déjà active." \
    "work déjà active devait être reconnue"
pass_scenario "work propre"

# Scénario 5 : work avec fichier modifié, non suivi et stash préexistant.
new_fixture "05-dirty-work" true
git -C "$REPOSITORY" switch --quiet --track origin/work
write_file "$REPOSITORY/tracked.txt" "stash utilisateur"
git -C "$REPOSITORY" stash push --quiet -m "stash utilisateur préexistant"
USER_STASH_OID="$(git -C "$REPOSITORY" rev-parse refs/stash)"
write_file "$REPOSITORY/tracked.txt" "modification locale"
write_file "$REPOSITORY/untracked.txt" "fichier non suivi"
STATUS_BEFORE="$(git -C "$REPOSITORY" status --porcelain)"
advance_remote_dev "dev-five.txt" "dev five"
run_successfully
assert_common_success
assert_equal "modification locale" "$(<"$REPOSITORY/tracked.txt")" \
    "le fichier modifié doit être restauré"
assert_equal "fichier non suivi" "$(<"$REPOSITORY/untracked.txt")" \
    "le fichier non suivi doit être restauré"
assert_equal "$STATUS_BEFORE" "$(git -C "$REPOSITORY" status --porcelain)" \
    "le statut local doit être restauré"
assert_equal "$USER_STASH_OID" "$(git -C "$REPOSITORY" rev-parse refs/stash)" \
    "le stash utilisateur préexistant doit rester intact"
pass_scenario "modifications suivies et non suivies"

# Scénario 6 : work avec modification indexée.
new_fixture "06-staged-work" true
git -C "$REPOSITORY" switch --quiet --track origin/work
write_file "$REPOSITORY/tracked.txt" "modification indexée"
git -C "$REPOSITORY" add tracked.txt
INDEX_BEFORE="$(git -C "$REPOSITORY" diff --cached --binary)"
advance_remote_dev "dev-six.txt" "dev six"
run_successfully
assert_common_success
assert_equal "$INDEX_BEFORE" "$(git -C "$REPOSITORY" diff --cached --binary)" \
    "la modification indexée doit rester indexée"
assert_equal "" "$(git -C "$REPOSITORY" diff -- tracked.txt)" \
    "aucune copie non indexée supplémentaire ne doit apparaître"
pass_scenario "modification indexée"

# Scénario 7 : conflit pendant la fusion de origin/dev.
new_fixture "07-merge-conflict" true
advance_remote_work "conflict.txt" "version work"
git -C "$REPOSITORY" fetch --quiet origin work
git -C "$REPOSITORY" switch --quiet --track origin/work
write_file "$REPOSITORY/local-before-conflict.txt" "à préserver"
advance_remote_dev "conflict.txt" "version dev"
run_with_expected_failure
assert_equal "work" "$(git -C "$REPOSITORY" branch --show-current)" \
    "la branche doit rester work après le conflit"
assert_equal "version work" "$(<"$REPOSITORY/conflict.txt")" \
    "git merge --abort doit restaurer la version work"
assert_equal "à préserver" "$(<"$REPOSITORY/local-before-conflict.txt")" \
    "les changements locaux doivent être restaurés après git merge --abort"
[[ -n "$(git -C "$REPOSITORY" stash list)" ]] \
    || fail "le stash temporaire doit rester comme copie de sécurité après le conflit de fusion."
[[ ! -f "$(git -C "$REPOSITORY" rev-parse --git-path MERGE_HEAD)" ]] \
    || fail "aucune fusion ne doit rester en cours."
assert_file_empty "$COMPOSE_LOG" "Docker ne doit pas démarrer après un conflit de fusion"
assert_contains "$LAST_OUTPUT" "Fichiers en conflit détectés avant annulation" \
    "les conflits de fusion doivent être indiqués"
pass_scenario "conflit de fusion"

# Scénario 8 : conflit pendant la restauration du stash.
new_fixture "08-stash-conflict" true
git -C "$REPOSITORY" switch --quiet --track origin/work
write_file "$REPOSITORY/conflict.txt" "modification locale conflictuelle"
advance_remote_dev "conflict.txt" "version dev conflictuelle"
run_with_expected_failure
assert_equal "work" "$(git -C "$REPOSITORY" branch --show-current)" \
    "la branche doit rester work après le conflit du stash"
[[ -n "$(git -C "$REPOSITORY" stash list)" ]] \
    || fail "le stash temporaire doit être conservé."
[[ -n "$(git -C "$REPOSITORY" diff --name-only --diff-filter=U)" ]] \
    || fail "le conflit de restauration doit être visible."
assert_file_empty "$COMPOSE_LOG" "Docker ne doit pas démarrer après un conflit de stash"
assert_contains "$LAST_OUTPUT" "la restauration des modifications locales a rencontré des conflits" \
    "le conflit de stash doit être expliqué"
pass_scenario "conflit de restauration du stash"

# Scénario 9 : dev avec modifications locales.
new_fixture "09-dirty-dev" true
write_file "$REPOSITORY/tracked.txt" "modification sur dev"
write_file "$REPOSITORY/untracked-dev.txt" "non suivi sur dev"
STATUS_BEFORE="$(git -C "$REPOSITORY" status --porcelain)"
run_with_expected_failure
assert_equal "dev" "$(git -C "$REPOSITORY" branch --show-current)" \
    "aucun changement de branche ne doit avoir lieu"
assert_equal "$STATUS_BEFORE" "$(git -C "$REPOSITORY" status --porcelain)" \
    "les modifications de dev doivent rester intactes"
assert_file_empty "$COMPOSE_LOG" "Docker ne doit pas démarrer depuis dev modifiée"
assert_contains "$LAST_OUTPUT" "aucune modification n’a été perdue" \
    "le diagnostic doit confirmer l’absence de perte"
pass_scenario "dev avec modifications locales"

# Scénario complémentaire : autre branche propre.
new_fixture "10-clean-feature" true
git -C "$REPOSITORY" switch --quiet -c feature/example
write_file "$REPOSITORY/feature-only.txt" "branche feature"
git -C "$REPOSITORY" add feature-only.txt
git -C "$REPOSITORY" commit --quiet -m "Feature locale"
FEATURE_SHA="$(git -C "$REPOSITORY" rev-parse HEAD)"
advance_remote_dev "dev-ten.txt" "dev ten"
run_successfully
assert_common_success
assert_equal "$FEATURE_SHA" "$(git -C "$REPOSITORY" rev-parse feature/example)" \
    "la branche feature ne doit être ni fusionnée ni réécrite"
assert_contains "$LAST_OUTPUT" "Avertissement : la branche initiale est feature/example" \
    "le changement depuis une autre branche doit être annoncé"
pass_scenario "autre branche propre"

echo
echo "Tous les scénarios work-start sont validés : $PASSED/$TOTAL_SCENARIOS."
