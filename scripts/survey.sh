#!/usr/bin/env bash

set -Eeuo pipefail

readonly CI_WORKFLOW='CI'
readonly PROMOTION_WORKFLOW='Promote dev to main'
readonly PRODUCTION_URL='https://estela-exploration.fr'

SURVEY_TIMEOUT="${SURVEY_TIMEOUT:-10800}"
SURVEY_INTERVAL="${SURVEY_INTERVAL:-10}"
REQUESTED_DEV_SHA="${DEV_SHA:-}"
ACTIVE_PID=''

fail() {
    printf 'Erreur : %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || fail "la commande '$1' est requise."
}

validate_positive_integer() {
    local name="$1"
    local value="$2"

    [[ "$value" =~ ^[1-9][0-9]*$ ]] \
        || fail "$name doit être un nombre entier strictement positif."
}

validate_sha() {
    local name="$1"
    local value="$2"

    [[ "$value" =~ ^[0-9a-f]{40}$ ]] \
        || fail "$name doit être un SHA Git complet de 40 caractères hexadécimaux."
}

handle_signal() {
    local signal_name="$1"

    trap - INT TERM

    if [[ -n "$ACTIVE_PID" ]] && kill -0 "$ACTIVE_PID" 2>/dev/null; then
        kill -TERM "$ACTIVE_PID" 2>/dev/null || true
        wait "$ACTIVE_PID" 2>/dev/null || true
    fi

    printf '\nSurveillance interrompue (%s). Aucune action GitHub n’a été effectuée.\n' \
        "$signal_name" >&2
    exit 130
}

trap 'handle_signal INT' INT
trap 'handle_signal TERM' TERM

validate_positive_integer SURVEY_TIMEOUT "$SURVEY_TIMEOUT"
validate_positive_integer SURVEY_INTERVAL "$SURVEY_INTERVAL"

readonly SURVEY_STARTED_AT=$SECONDS
readonly SURVEY_DEADLINE=$((SURVEY_STARTED_AT + SURVEY_TIMEOUT))

ensure_time_remaining() {
    local description="$1"

    (( SECONDS < SURVEY_DEADLINE )) \
        || fail "délai maximal de ${SURVEY_TIMEOUT}s atteint pendant $description."
}

poll_sleep() {
    local description="$1"
    local remaining
    local duration="$SURVEY_INTERVAL"

    ensure_time_remaining "$description"
    remaining=$((SURVEY_DEADLINE - SECONDS))
    if (( duration > remaining )); then
        duration="$remaining"
    fi

    sleep "$duration"
}

run_with_deadline() {
    local description="$1"
    local command_status
    local remaining

    shift
    ensure_time_remaining "$description"

    "$@" &
    ACTIVE_PID=$!

    while kill -0 "$ACTIVE_PID" 2>/dev/null; do
        remaining=$((SURVEY_DEADLINE - SECONDS))
        if (( remaining <= 0 )); then
            kill -TERM "$ACTIVE_PID" 2>/dev/null || true
            wait "$ACTIVE_PID" 2>/dev/null || true
            ACTIVE_PID=''
            return 124
        fi
        sleep 1
    done

    if wait "$ACTIVE_PID"; then
        command_status=0
    else
        command_status=$?
    fi
    ACTIVE_PID=''

    return "$command_status"
}

show_failed_logs() {
    local run_id="$1"

    printf '\nLogs des étapes en échec pour le run %s :\n' "$run_id" >&2
    if ! gh run view "$run_id" \
        --repo "$REPOSITORY" \
        --log-failed
    then
        printf 'Impossible de récupérer les logs échoués du run %s.\n' "$run_id" >&2
    fi
}

FOUND_RUN_ID=''
FOUND_RUN_URL=''

wait_for_run() {
    local workflow="$1"
    local event="$2"
    local branch="$3"
    local sha="$4"
    local description="$5"
    local ignore_skipped="${6:-false}"
    local records
    local candidate_count
    local candidate_id
    local candidate_url
    local active_candidate_count
    local active_candidate_id
    local active_candidate_url
    local completed_candidate_count
    local completed_candidate_id
    local completed_candidate_url
    local run_id
    local run_workflow
    local run_event
    local run_branch
    local run_sha
    local run_url
    local run_status
    local run_conclusion

    printf '\n%s\n' "$description"
    printf 'Recherche exacte : workflow=%s, event=%s, branche=%s, SHA=%s\n' \
        "$workflow" "$event" "$branch" "$sha"

    while true; do
        ensure_time_remaining "$description"

        if ! records="$(
            gh run list \
                --repo "$REPOSITORY" \
                --workflow "$workflow" \
                --event "$event" \
                --branch "$branch" \
                --commit "$sha" \
                --limit 100 \
                --json databaseId,workflowName,event,headBranch,headSha,url,status,conclusion \
                --jq '.[] | [.databaseId, .workflowName, .event, .headBranch, .headSha, .url, .status, (.conclusion // "")] | @tsv'
        )"; then
            fail "impossible de rechercher $description."
        fi

        candidate_count=0
        candidate_id=''
        candidate_url=''
        active_candidate_count=0
        active_candidate_id=''
        active_candidate_url=''
        completed_candidate_count=0
        completed_candidate_id=''
        completed_candidate_url=''

        while IFS=$'\t' read -r \
            run_id \
            run_workflow \
            run_event \
            run_branch \
            run_sha \
            run_url \
            run_status \
            run_conclusion
        do
            [[ -n "$run_id" ]] || continue
            [[ "$run_workflow" == "$workflow" ]] || continue
            [[ "$run_event" == "$event" ]] || continue
            [[ "$run_branch" == "$branch" ]] || continue
            [[ "$run_sha" == "$sha" ]] || continue

            if [[ "$ignore_skipped" == true \
                && "$run_status" == completed \
                && "$run_conclusion" == skipped ]]; then
                continue
            fi

            if [[ "$ignore_skipped" == true ]]; then
                if [[ "$run_status" == completed ]]; then
                    completed_candidate_count=$((completed_candidate_count + 1))
                    completed_candidate_id="$run_id"
                    completed_candidate_url="$run_url"
                else
                    active_candidate_count=$((active_candidate_count + 1))
                    active_candidate_id="$run_id"
                    active_candidate_url="$run_url"
                fi
            else
                candidate_count=$((candidate_count + 1))
                candidate_id="$run_id"
                candidate_url="$run_url"
            fi
        done <<< "$records"

        if [[ "$ignore_skipped" == true ]]; then
            if (( completed_candidate_count > 1 )); then
                fail "plusieurs runs terminés correspondent exactement à $description ; sélection refusée."
            fi

            if (( completed_candidate_count == 1 )); then
                candidate_count=1
                candidate_id="$completed_candidate_id"
                candidate_url="$completed_candidate_url"
            else
                candidate_count="$active_candidate_count"
                candidate_id="$active_candidate_id"
                candidate_url="$active_candidate_url"
            fi
        fi

        if (( candidate_count > 1 )); then
            fail "plusieurs runs correspondent exactement à $description ; sélection refusée."
        fi

        if (( candidate_count == 1 )); then
            FOUND_RUN_ID="$candidate_id"
            FOUND_RUN_URL="$candidate_url"
            printf 'Run trouvé : %s\n' "$FOUND_RUN_URL"
            return 0
        fi

        poll_sleep "l’apparition de $description"
    done
}

verify_run_success() {
    local run_id="$1"
    local workflow="$2"
    local event="$3"
    local branch="$4"
    local sha="$5"
    local description="$6"
    local record
    local actual_workflow
    local actual_event
    local actual_branch
    local actual_sha
    local actual_url
    local actual_status
    local actual_conclusion

    if ! record="$(
        gh run view "$run_id" \
            --repo "$REPOSITORY" \
            --json workflowName,event,headBranch,headSha,url,status,conclusion \
            --jq '[.workflowName, .event, .headBranch, .headSha, .url, .status, (.conclusion // "")] | @tsv'
    )"; then
        fail "impossible de vérifier $description."
    fi

    IFS=$'\t' read -r \
        actual_workflow \
        actual_event \
        actual_branch \
        actual_sha \
        actual_url \
        actual_status \
        actual_conclusion <<< "$record"

    [[ "$actual_workflow" == "$workflow" \
        && "$actual_event" == "$event" \
        && "$actual_branch" == "$branch" \
        && "$actual_sha" == "$sha" ]] \
        || fail "$description ne correspond plus au workflow, à l’événement, à la branche et au SHA attendus."

    [[ "$actual_status" == completed && "$actual_conclusion" == success ]] \
        || fail "$description n’est pas terminé avec succès (${actual_status}:${actual_conclusion:-inconnue})."

    printf '%s réussi : %s\n' "$description" "$actual_url"
}

watch_run() {
    local run_id="$1"
    local workflow="$2"
    local event="$3"
    local branch="$4"
    local sha="$5"
    local description="$6"
    local watch_status

    if run_with_deadline \
        "le suivi de $description" \
        gh run watch "$run_id" \
            --repo "$REPOSITORY" \
            --exit-status \
            --compact \
            --interval "$SURVEY_INTERVAL"
    then
        watch_status=0
    else
        watch_status=$?
    fi

    if (( watch_status == 124 )); then
        fail "délai maximal de ${SURVEY_TIMEOUT}s atteint pendant le suivi de $description."
    fi

    if (( watch_status != 0 )); then
        show_failed_logs "$run_id"
        fail "$description a échoué."
    fi

    verify_run_success "$run_id" "$workflow" "$event" "$branch" "$sha" "$description"
}

PROMOTION_PR_NUMBER=''
PROMOTION_PR_URL=''

wait_for_promotion_pr() {
    local dev_sha="$1"
    local records
    local candidate_count
    local pr_number
    local pr_url
    local pr_state
    local pr_is_draft
    local pr_base
    local pr_head
    local pr_head_sha
    local pr_is_cross_repository

    printf '\n3/7 — Attente de la PR de promotion dev vers main...\n'

    while true; do
        ensure_time_remaining 'l’apparition de la PR de promotion'

        if ! records="$(
            gh pr list \
                --repo "$REPOSITORY" \
                --state all \
                --base main \
                --head dev \
                --limit 100 \
                --json number,url,state,isDraft,baseRefName,headRefName,headRefOid,isCrossRepository \
                --jq '.[] | [.number, .url, .state, (.isDraft | tostring), .baseRefName, .headRefName, .headRefOid, (.isCrossRepository | tostring)] | @tsv'
        )"; then
            fail 'impossible de rechercher la PR de promotion.'
        fi

        candidate_count=0

        while IFS=$'\t' read -r \
            pr_number \
            pr_url \
            pr_state \
            pr_is_draft \
            pr_base \
            pr_head \
            pr_head_sha \
            pr_is_cross_repository
        do
            [[ -n "$pr_number" ]] || continue
            [[ "$pr_base" == main ]] || continue
            [[ "$pr_head" == dev ]] || continue
            [[ "$pr_head_sha" == "$dev_sha" ]] || continue
            [[ "$pr_is_cross_repository" == false ]] || continue

            candidate_count=$((candidate_count + 1))
            PROMOTION_PR_NUMBER="$pr_number"
            PROMOTION_PR_URL="$pr_url"

            [[ "$pr_is_draft" == false ]] \
                || fail "la PR de promotion #$pr_number est en brouillon."
            [[ "$pr_state" != CLOSED ]] \
                || fail "la PR de promotion #$pr_number a été fermée sans fusion."
        done <<< "$records"

        if (( candidate_count > 1 )); then
            fail 'plusieurs PR dev vers main correspondent au SHA surveillé ; sélection refusée.'
        fi

        if (( candidate_count == 1 )); then
            printf 'PR de promotion #%s : %s\n' "$PROMOTION_PR_NUMBER" "$PROMOTION_PR_URL"
            return 0
        fi

        poll_sleep 'l’apparition de la PR de promotion'
    done
}

verify_pr_identity() {
    local record
    local pr_number
    local pr_url
    local pr_state
    local pr_is_draft
    local pr_base
    local pr_head
    local pr_head_sha
    local pr_is_cross_repository

    if ! record="$(
        gh pr view "$PROMOTION_PR_NUMBER" \
            --repo "$REPOSITORY" \
            --json number,url,state,isDraft,baseRefName,headRefName,headRefOid,isCrossRepository \
            --jq '[.number, .url, .state, (.isDraft | tostring), .baseRefName, .headRefName, .headRefOid, (.isCrossRepository | tostring)] | @tsv'
    )"; then
        fail "impossible de vérifier la PR #$PROMOTION_PR_NUMBER."
    fi

    IFS=$'\t' read -r \
        pr_number \
        pr_url \
        pr_state \
        pr_is_draft \
        pr_base \
        pr_head \
        pr_head_sha \
        pr_is_cross_repository <<< "$record"

    [[ "$pr_number" == "$PROMOTION_PR_NUMBER" \
        && "$pr_url" == "$PROMOTION_PR_URL" \
        && "$pr_is_draft" == false \
        && "$pr_base" == main \
        && "$pr_head" == dev \
        && "$pr_head_sha" == "$DEV_SHA" \
        && "$pr_is_cross_repository" == false ]] \
        || fail "l’identité ou le SHA de la PR #$PROMOTION_PR_NUMBER a changé."

    [[ "$pr_state" != CLOSED ]] \
        || fail "la PR #$PROMOTION_PR_NUMBER a été fermée sans fusion."

}

wait_for_required_checks() {
    local pr_ci_run_id="$1"
    local checks_response
    local checks_status
    local watch_status
    local lower_response

    printf '\nAttente des contrôles obligatoires de la PR #%s...\n' "$PROMOTION_PR_NUMBER"

    while true; do
        ensure_time_remaining 'l’apparition des contrôles obligatoires'

        if checks_response="$(
            gh pr checks "$PROMOTION_PR_NUMBER" \
                --repo "$REPOSITORY" \
                --required \
                --json name \
                --jq 'length' 2>&1
        )"; then
            checks_status=0
        else
            checks_status=$?
        fi

        if [[ "$checks_status" -eq 0 || "$checks_status" -eq 8 ]]; then
            if [[ "$checks_response" =~ ^[1-9][0-9]*$ ]]; then
                break
            fi
        else
            lower_response="${checks_response,,}"
            if [[ "$lower_response" != *'no checks reported'* \
                && "$lower_response" != *'no required checks'* ]]; then
                printf '%s\n' "$checks_response" >&2
                fail 'impossible de lire les contrôles obligatoires de la PR.'
            fi
        fi

        poll_sleep 'l’apparition des contrôles obligatoires'
    done

    verify_pr_identity

    if run_with_deadline \
        'le suivi des contrôles obligatoires' \
        gh pr checks "$PROMOTION_PR_NUMBER" \
            --repo "$REPOSITORY" \
            --watch \
            --required \
            --fail-fast \
            --interval "$SURVEY_INTERVAL"
    then
        watch_status=0
    else
        watch_status=$?
    fi

    if (( watch_status == 124 )); then
        fail "délai maximal de ${SURVEY_TIMEOUT}s atteint pendant le suivi des contrôles obligatoires."
    fi

    if (( watch_status != 0 )); then
        show_failed_logs "$pr_ci_run_id"
        fail "un contrôle obligatoire de la PR #$PROMOTION_PR_NUMBER a échoué."
    fi

    verify_pr_identity
    printf 'Contrôles obligatoires réussis pour %s\n' "$PROMOTION_PR_URL"
}

MAIN_MERGE_SHA=''

wait_for_merge() {
    local record
    local pr_state
    local pr_base
    local pr_head
    local pr_head_sha
    local merge_sha

    printf '\n5/7 — Attente de la fusion automatique de la PR #%s...\n' \
        "$PROMOTION_PR_NUMBER"

    while true; do
        ensure_time_remaining 'la fusion automatique de la PR'

        if ! record="$(
            gh pr view "$PROMOTION_PR_NUMBER" \
                --repo "$REPOSITORY" \
                --json state,baseRefName,headRefName,headRefOid,mergeCommit \
                --jq '[.state, .baseRefName, .headRefName, .headRefOid, (.mergeCommit.oid // "-")] | @tsv'
        )"; then
            fail "impossible de suivre la fusion de la PR #$PROMOTION_PR_NUMBER."
        fi

        IFS=$'\t' read -r \
            pr_state \
            pr_base \
            pr_head \
            pr_head_sha \
            merge_sha <<< "$record"

        [[ "$pr_base" == main && "$pr_head" == dev && "$pr_head_sha" == "$DEV_SHA" ]] \
            || fail "la PR #$PROMOTION_PR_NUMBER ne correspond plus à dev@$DEV_SHA vers main."

        case "$pr_state" in
            MERGED)
                [[ "$merge_sha" != '-' ]] \
                    || fail "GitHub ne fournit pas le commit de fusion de la PR #$PROMOTION_PR_NUMBER."
                validate_sha 'merge commit main' "$merge_sha"
                MAIN_MERGE_SHA="$merge_sha"
                printf 'Fusion automatique confirmée : %s\n' "$MAIN_MERGE_SHA"
                return 0
                ;;
            OPEN)
                poll_sleep 'la fusion automatique de la PR'
                ;;
            *)
                fail "la PR #$PROMOTION_PR_NUMBER est dans l’état inattendu '$pr_state'."
                ;;
        esac
    done
}

DEPLOY_JOB_ID=''
DEPLOY_JOB_URL=''

verify_deployment_job() {
    local main_run_id="$1"
    local records
    local candidate_count=0
    local job_id
    local job_name
    local job_status
    local job_conclusion
    local job_url

    if ! records="$(
        gh run view "$main_run_id" \
            --repo "$REPOSITORY" \
            --json jobs \
            --jq '.jobs[] | [.databaseId, .name, .status, (.conclusion // ""), .url] | @tsv'
    )"; then
        fail 'impossible de vérifier le job Deploy production.'
    fi

    while IFS=$'\t' read -r \
        job_id \
        job_name \
        job_status \
        job_conclusion \
        job_url
    do
        [[ -n "$job_id" ]] || continue
        [[ "$job_name" == 'Deploy production' ]] || continue

        candidate_count=$((candidate_count + 1))
        DEPLOY_JOB_ID="$job_id"
        DEPLOY_JOB_URL="$job_url"

        [[ "$job_status" == completed && "$job_conclusion" == success ]] \
            || fail "le job Deploy production n’a pas réussi (${job_status}:${job_conclusion:-inconnue})."
    done <<< "$records"

    (( candidate_count == 1 )) \
        || fail "le run main doit contenir exactement un job 'Deploy production'."

    printf 'Job Deploy production réussi : %s\n' "$DEPLOY_JOB_URL"
}

require_command git
require_command gh

PROJECT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || fail 'cette commande doit être exécutée dans un dépôt Git.'
cd "$PROJECT_ROOT"

gh auth status >/dev/null 2>&1 \
    || fail "GitHub CLI n’est pas authentifié. Lancez 'gh auth login'."

REPOSITORY="$(gh repo view --json nameWithOwner --jq '.nameWithOwner')" \
    || fail 'impossible de déterminer automatiquement le dépôt GitHub.'
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] \
    || fail 'le nom du dépôt GitHub détecté est invalide.'
readonly REPOSITORY

REMOTE_DEV_SHA="$(
    gh api "repos/$REPOSITORY/git/ref/heads/dev" --jq '.object.sha'
)" || fail 'impossible de récupérer le SHA distant actuel de dev.'
REMOTE_DEV_SHA="${REMOTE_DEV_SHA,,}"
validate_sha 'SHA distant de dev' "$REMOTE_DEV_SHA"
readonly REMOTE_DEV_SHA

if [[ -n "$REQUESTED_DEV_SHA" ]]; then
    [[ "$REQUESTED_DEV_SHA" =~ ^[0-9a-fA-F]{7,40}$ ]] \
        || fail 'DEV_SHA doit contenir entre 7 et 40 caractères hexadécimaux.'

    DEV_SHA="$(
        gh api "repos/$REPOSITORY/commits/$REQUESTED_DEV_SHA" --jq '.sha'
    )" || fail "impossible de résoudre DEV_SHA=$REQUESTED_DEV_SHA."
    DEV_SHA="${DEV_SHA,,}"
else
    DEV_SHA="$REMOTE_DEV_SHA"
fi
validate_sha 'SHA dev surveillé' "$DEV_SHA"
readonly DEV_SHA

printf 'Dépôt : %s\n' "$REPOSITORY"
printf 'SHA distant actuel de dev : %s\n' "$REMOTE_DEV_SHA"
if [[ "$DEV_SHA" != "$REMOTE_DEV_SHA" ]]; then
    printf 'DEV_SHA historique explicitement surveillé : %s\n' "$DEV_SHA"
fi
printf 'Délai maximal global : %ss ; intervalle : %ss\n' \
    "$SURVEY_TIMEOUT" "$SURVEY_INTERVAL"

wait_for_run "$CI_WORKFLOW" push dev "$DEV_SHA" \
    '1/7 — CI déclenchée par push sur dev'
DEV_CI_RUN_ID="$FOUND_RUN_ID"
DEV_CI_RUN_URL="$FOUND_RUN_URL"
watch_run "$DEV_CI_RUN_ID" "$CI_WORKFLOW" push dev "$DEV_SHA" \
    'CI push dev'

wait_for_run "$PROMOTION_WORKFLOW" workflow_run dev "$DEV_SHA" \
    '2/7 — Workflow Promote dev to main' true
PROMOTION_RUN_ID="$FOUND_RUN_ID"
PROMOTION_RUN_URL="$FOUND_RUN_URL"
watch_run "$PROMOTION_RUN_ID" "$PROMOTION_WORKFLOW" workflow_run dev "$DEV_SHA" \
    'workflow Promote dev to main'

wait_for_promotion_pr "$DEV_SHA"

wait_for_run "$CI_WORKFLOW" pull_request dev "$DEV_SHA" \
    '4/7 — CI de la PR dev vers main'
PR_CI_RUN_ID="$FOUND_RUN_ID"
PR_CI_RUN_URL="$FOUND_RUN_URL"
wait_for_required_checks "$PR_CI_RUN_ID"
watch_run "$PR_CI_RUN_ID" "$CI_WORKFLOW" pull_request dev "$DEV_SHA" \
    'CI pull_request dev vers main'

wait_for_merge

wait_for_run "$CI_WORKFLOW" push main "$MAIN_MERGE_SHA" \
    '6/7 — CI déclenchée par push sur main'
MAIN_CI_RUN_ID="$FOUND_RUN_ID"
MAIN_CI_RUN_URL="$FOUND_RUN_URL"
watch_run "$MAIN_CI_RUN_ID" "$CI_WORKFLOW" push main "$MAIN_MERGE_SHA" \
    'CI push main et déploiement'

printf '\n7/7 — Vérification du job Deploy production\n'
verify_deployment_job "$MAIN_CI_RUN_ID"

printf '\nRésumé de la chaîne CI/CD\n'
printf 'SHA dev surveillé : %s\n' "$DEV_SHA"
printf 'CI dev : %s\n' "$DEV_CI_RUN_URL"
printf 'Promotion : %s\n' "$PROMOTION_RUN_URL"
printf 'PR de promotion : #%s — %s\n' "$PROMOTION_PR_NUMBER" "$PROMOTION_PR_URL"
printf 'CI de PR : %s\n' "$PR_CI_RUN_URL"
printf 'Merge commit main : %s\n' "$MAIN_MERGE_SHA"
printf 'Run de déploiement : %s — %s\n' "$MAIN_CI_RUN_ID" "$MAIN_CI_RUN_URL"
printf 'Job Deploy production : %s — %s\n' "$DEPLOY_JOB_ID" "$DEPLOY_JOB_URL"
printf 'Production : %s\n' "$PRODUCTION_URL"
printf 'Production deployment succeeded\n'
