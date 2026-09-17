#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT_UNDER_TEST="$PROJECT_ROOT/scripts/survey.sh"
FAKE_GH_SOURCE="$PROJECT_ROOT/tests/scripts/fixtures/survey-gh"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/survey-tests.XXXXXX")"
FAKE_BIN="$TEST_ROOT/bin"
FAKE_GH="$FAKE_BIN/gh"

readonly TEST_DEV_SHA='1111111111111111111111111111111111111111'
readonly TEST_MAIN_SHA='2222222222222222222222222222222222222222'

LAST_OUTPUT=''
LAST_STATUS=0
GH_LOG=''
STATE_DIR=''
PASSED=0
TOTAL_SCENARIOS=11

cleanup() {
    if [[ -n "${TEST_ROOT:-}" \
        && -d "$TEST_ROOT" \
        && "$(basename "$TEST_ROOT")" == survey-tests.* ]]; then
        rm -rf -- "$TEST_ROOT"
    fi
}

trap cleanup EXIT

fail() {
    printf 'ÉCHEC : %s\n' "$*" >&2

    if [[ -n "$LAST_OUTPUT" ]]; then
        printf '\n%s\n' "$LAST_OUTPUT" >&2
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

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    [[ "$haystack" != *"$needle"* ]] \
        || fail "$description — texte inattendu : $needle"
}

assert_success() {
    local description="$1"

    (( LAST_STATUS == 0 )) \
        || fail "$description — survey devait réussir avec le statut 0, obtenu $LAST_STATUS."
}

assert_failure() {
    local description="$1"

    (( LAST_STATUS != 0 )) \
        || fail "$description — survey devait échouer."
}

assert_no_failed_logs_call() {
    local description="$1"
    local calls=''

    [[ -f "$GH_LOG" ]] && calls="$(<"$GH_LOG")"
    assert_not_contains "$calls" '--log-failed' "$description"
}

assert_failed_logs_call() {
    local description="$1"
    local calls=''

    [[ -f "$GH_LOG" ]] && calls="$(<"$GH_LOG")"
    assert_contains "$calls" '--log-failed' "$description"
}

pass_scenario() {
    PASSED=$((PASSED + 1))
    printf 'OK %s/%s — %s\n' "$PASSED" "$TOTAL_SCENARIOS" "$1"
}

prepare_scenario() {
    local scenario="$1"

    STATE_DIR="$TEST_ROOT/state-$scenario-${MISMATCH_FIELD:-default}"
    GH_LOG="$STATE_DIR/gh.log"
    mkdir -p "$STATE_DIR"
    : > "$GH_LOG"
    LAST_OUTPUT=''
    LAST_STATUS=0
}

run_survey() {
    local scenario="$1"
    local timeout="${2:-30}"
    local mismatch_field="${3:-}"

    MISMATCH_FIELD="$mismatch_field"
    prepare_scenario "$scenario"

    if LAST_OUTPUT="$(
        cd "$PROJECT_ROOT"
        PATH="$FAKE_BIN:$PATH" \
        GH_LOG="$GH_LOG" \
        STATE_DIR="$STATE_DIR" \
        SCENARIO="$scenario" \
        MISMATCH_FIELD="$mismatch_field" \
        FAKE_DEV_SHA="$TEST_DEV_SHA" \
        FAKE_MAIN_SHA="$TEST_MAIN_SHA" \
        SURVEY_TIMEOUT="$timeout" \
        SURVEY_INTERVAL=1 \
            bash "$SCRIPT_UNDER_TEST" 2>&1
    )"; then
        LAST_STATUS=0
    else
        LAST_STATUS=$?
    fi
}

mkdir -p "$FAKE_BIN"
cp "$FAKE_GH_SOURCE" "$FAKE_GH"
chmod +x "$FAKE_GH"

# Scénario 1 : le watcher échoue, mais le run est toujours actif puis réussit.
run_survey watch_nonzero_in_progress
assert_success 'watch non-zéro suivi d’un run actif'
assert_contains "$LAST_OUTPUT" 's’est interrompu (code 1), mais le run est encore in_progress' \
    'le code de watch ne doit pas devenir une conclusion GitHub'
assert_contains "$LAST_OUTPUT" 'Production deployment succeeded' \
    'le flux complet doit reprendre après le run actif'
assert_no_failed_logs_call 'aucun log échoué ne doit être demandé pendant in_progress'
pass_scenario 'watch non-zéro, in_progress, puis success'

# Scénario 2 : queued reste un état actif.
run_survey queued
assert_success 'run queued puis réussi'
assert_contains "$LAST_OUTPUT" 'le run est encore queued' \
    'queued doit provoquer une nouvelle surveillance'
assert_no_failed_logs_call 'aucun log échoué ne doit être demandé pendant queued'
pass_scenario 'queued puis success'

# Scénario 3 : même un code zéro de watch ne remplace pas la lecture JSON finale.
run_survey watch_zero_in_progress
assert_success 'watch zéro avec état encore actif'
assert_contains "$LAST_OUTPUT" 'est encore in_progress après le suivi interactif' \
    'l’état JSON doit rester la source de vérité après watch zéro'
assert_no_failed_logs_call 'aucun log échoué ne doit être demandé avant completed'
pass_scenario 'in_progress puis completed/success'

# Scénario 4 : failure terminé produit les logs et un échec réel.
run_survey failure
assert_failure 'run completed/failure'
assert_contains "$LAST_OUTPUT" 'CI push dev terminé avec conclusion failure.' \
    'la conclusion failure doit être explicite'
assert_failed_logs_call 'les logs doivent être demandés après completed/failure'
pass_scenario 'completed/failure'

# Scénario 5 : cancelled conserve sa conclusion exacte.
run_survey cancelled
assert_failure 'run completed/cancelled'
assert_contains "$LAST_OUTPUT" 'CI push dev terminé avec conclusion cancelled.' \
    'la conclusion cancelled doit être explicite'
assert_not_contains "$LAST_OUTPUT" 'conclusion failure' \
    'cancelled ne doit pas être présenté comme failure'
assert_failed_logs_call 'les logs peuvent être demandés après completed/cancelled'
pass_scenario 'completed/cancelled'

# Scénario 6 : timed_out conserve sa conclusion exacte.
run_survey timed_out
assert_failure 'run completed/timed_out'
assert_contains "$LAST_OUTPUT" 'CI push dev terminé avec conclusion timed_out.' \
    'la conclusion timed_out doit être explicite'
assert_failed_logs_call 'les logs peuvent être demandés après completed/timed_out'
pass_scenario 'completed/timed_out'

# Scénario 7 : le timeout local ne devient jamais une conclusion GitHub.
run_survey local_timeout 2
assert_failure 'timeout local pendant in_progress'
assert_contains "$LAST_OUTPUT" 'délai maximal de 2s atteint pendant le suivi de CI push dev' \
    'le timeout local doit être diagnostiqué comme tel'
assert_not_contains "$LAST_OUTPUT" 'terminé avec conclusion' \
    'un run actif ne doit recevoir aucune conclusion inventée'
assert_no_failed_logs_call 'aucun log échoué ne doit être demandé au timeout d’un run actif'
pass_scenario 'timeout local pendant in_progress'

# Scénario 8 : l’indisponibilité des logs ne masque pas failure.
run_survey log_unavailable
assert_failure 'logs indisponibles après failure'
assert_contains "$LAST_OUTPUT" 'CI push dev terminé avec conclusion failure.' \
    'la vraie conclusion doit précéder le diagnostic secondaire'
assert_contains "$LAST_OUTPUT" 'Impossible de récupérer les logs échoués du run 101.' \
    'l’indisponibilité des logs doit être indiquée'
assert_failed_logs_call 'les logs doivent bien avoir été tentés après le véritable failure'
pass_scenario 'logs indisponibles après completed/failure'

# Scénario 9 : chaque composante de l’identité exacte reste obligatoire.
for mismatch_field in workflow event branch sha; do
    run_survey mismatch 30 "$mismatch_field"
    assert_failure "mismatch $mismatch_field"
    assert_contains "$LAST_OUTPUT" 'ne correspond plus au workflow, à l’événement, à la branche et au SHA attendus' \
        "le mismatch $mismatch_field doit être refusé"
    assert_no_failed_logs_call "aucun log ne doit être demandé après mismatch $mismatch_field"
done
pass_scenario 'mismatch workflow, event, branch et SHA'

# Scénario 10 : un watcher de checks interrompu délègue la vérité au run CI.
run_survey required_checks_nonzero
assert_success 'watch des checks non-zéro suivi d’un run CI réussi'
assert_contains "$LAST_OUTPUT" 'l’état du run CI 401 sera vérifié explicitement' \
    'le watcher des checks doit déléguer la conclusion au run exact'
assert_no_failed_logs_call 'un watcher de checks interrompu ne doit pas demander de logs avant completed'
pass_scenario 'watch des checks non-zéro, run CI success'

# Scénario 11 : SIGTERM arrête le watcher actif sans mutation GitHub.
MISMATCH_FIELD=''
prepare_scenario signal
SIGNAL_OUTPUT="$STATE_DIR/output.log"
(
    cd "$PROJECT_ROOT"
    PATH="$FAKE_BIN:$PATH" \
    GH_LOG="$GH_LOG" \
    STATE_DIR="$STATE_DIR" \
    SCENARIO=signal \
    FAKE_DEV_SHA="$TEST_DEV_SHA" \
    FAKE_MAIN_SHA="$TEST_MAIN_SHA" \
    SURVEY_TIMEOUT=30 \
    SURVEY_INTERVAL=1 \
        exec bash "$SCRIPT_UNDER_TEST"
) > "$SIGNAL_OUTPUT" 2>&1 &
survey_pid=$!
watch_started=false
for _attempt in $(seq 1 100); do
    if grep -F 'run watch 101 ' "$GH_LOG" >/dev/null 2>&1; then
        watch_started=true
        break
    fi
    sleep 0.05
done

if [[ "$watch_started" != true ]]; then
    kill -TERM "$survey_pid" 2>/dev/null || true
    wait "$survey_pid" 2>/dev/null || true
    LAST_OUTPUT="$(<"$SIGNAL_OUTPUT")"
    fail 'le watcher simulé ne s’est pas lancé avant le test du signal.'
fi

kill -TERM "$survey_pid"
if wait "$survey_pid"; then
    LAST_STATUS=0
else
    LAST_STATUS=$?
fi
LAST_OUTPUT="$(<"$SIGNAL_OUTPUT")"
assert_equal 130 "$LAST_STATUS" 'SIGTERM doit conserver le statut d’interruption existant'
assert_contains "$LAST_OUTPUT" 'Surveillance interrompue (TERM). Aucune action GitHub n’a été effectuée.' \
    'le diagnostic d’interruption doit être conservé'
assert_no_failed_logs_call 'une interruption ne doit jamais demander les logs échoués'
pass_scenario 'interruption SIGTERM'

printf '\nTous les scénarios survey sont validés : %s/%s.\n' "$PASSED" "$TOTAL_SCENARIOS"
