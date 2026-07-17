import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const EXPECTED_HEAD_SHA = 'a'.repeat(40);
const OTHER_HEAD_SHA = 'b'.repeat(40);
const MERGE_COMMIT_SHA = 'c'.repeat(40);
const REPOSITORY = 'Nerpp/blog_tourisme';
const STEP_NAME = 'Merge eligible Dependabot patch safely';

const workflow = await readFile(
  new URL('../workflows/dependabot-automerge.yml', import.meta.url),
  'utf8',
);

function extractStepScript(source, stepName) {
  const lines = source.split('\n');
  const stepStart = lines.findIndex((line) => line.trim() === `- name: ${stepName}`);
  assert.notEqual(stepStart, -1, `workflow step not found: ${stepName}`);

  const stepIndent = lines[stepStart].match(/^\s*/)[0].length;
  const stepEnd = lines.findIndex((line, index) => index > stepStart
    && line.trim().startsWith('- name: ')
    && line.match(/^\s*/)[0].length === stepIndent);
  const effectiveEnd = stepEnd === -1 ? lines.length : stepEnd;
  const scriptHeader = lines.findIndex((line, index) => index > stepStart
    && index < effectiveEnd
    && line.trim() === 'script: |');
  assert.notEqual(scriptHeader, -1, `github-script body not found: ${stepName}`);

  const scriptIndent = lines[scriptHeader].match(/^\s*/)[0].length + 2;
  return lines
    .slice(scriptHeader + 1, effectiveEnd)
    .map((line) => line.trim() === '' ? '' : line.slice(scriptIndent))
    .join('\n');
}

const mergeScript = extractStepScript(workflow, STEP_NAME);
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const executeMergeScript = new AsyncFunction(
  'github',
  'core',
  'context',
  'process',
  'setTimeout',
  mergeScript,
);

function dependabotPull(overrides = {}) {
  const pull = {
    number: 42,
    node_id: 'PR_dependabot_node_id',
    state: 'open',
    draft: false,
    merged: false,
    merge_commit_sha: null,
    mergeable: true,
    mergeable_state: 'blocked',
    auto_merge: null,
    user: {login: 'dependabot[bot]'},
    base: {ref: 'dev', repo: {full_name: REPOSITORY}},
    head: {
      ref: 'dependabot/composer/dev/vendor-package',
      sha: EXPECTED_HEAD_SHA,
      repo: {full_name: REPOSITORY},
    },
  };
  return {
    ...pull,
    ...overrides,
    user: {...pull.user, ...overrides.user},
    base: {
      ...pull.base,
      ...overrides.base,
      repo: {...pull.base.repo, ...overrides.base?.repo},
    },
    head: {
      ...pull.head,
      ...overrides.head,
      repo: {...pull.head.repo, ...overrides.head?.repo},
    },
  };
}

async function runScenario({
  pulls = [dependabotPull()],
  mergeResponse = {merged: true, sha: MERGE_COMMIT_SHA},
  mergedPull,
  autoMergeResponse,
} = {}) {
  const pullQueue = [...pulls];
  const lastPull = pulls.at(-1) ?? dependabotPull();
  const calls = {get: [], graphql: [], merge: []};
  const failures = [];
  const notices = [];
  let mergeCalled = false;

  const github = {
    rest: {
      pulls: {
        async get(parameters) {
          calls.get.push(parameters);
          if (mergeCalled) {
            return {data: mergedPull ?? dependabotPull({
              state: 'closed',
              merged: true,
              merge_commit_sha: MERGE_COMMIT_SHA,
              mergeable_state: 'unknown',
            })};
          }
          return {data: pullQueue.length > 0 ? pullQueue.shift() : lastPull};
        },
        async merge(parameters) {
          calls.merge.push(parameters);
          mergeCalled = true;
          return {data: mergeResponse};
        },
      },
    },
    async graphql(query, variables) {
      calls.graphql.push({query, variables});
      return autoMergeResponse ?? {
        enablePullRequestAutoMerge: {pullRequest: {number: lastPull.number}},
      };
    },
  };
  const core = {
    setFailed(message) {
      failures.push(message);
    },
    notice(message) {
      notices.push(message);
    },
  };
  const context = {
    repo: {owner: 'Nerpp', repo: 'blog_tourisme'},
    payload: {pull_request: {number: lastPull.number}},
  };

  await executeMergeScript(
    github,
    core,
    context,
    {env: {EXPECTED_HEAD_SHA}},
    (callback) => callback(),
  );

  return {calls, failures, notices};
}

test('workflow declares every classification, identity, activation, and Quality SHA gate', () => {
  for (const expected of [
    "needs.classify.outputs.policyValid == 'true'",
    "needs.classify.outputs.globalUpdateType == 'patch'",
    "needs.classify.outputs.autoMergeEligible == 'true'",
    "needs.classify.outputs.manualReviewRequired == 'false'",
    "needs.classify.outputs.maintainerChanges == 'false'",
    "vars.DEPENDABOT_AUTOMERGE_ENABLED == 'true'",
    "github.event.pull_request.user.login == 'dependabot[bot]'",
    "startsWith(github.event.pull_request.head.ref, 'dependabot/')",
    "github.event.pull_request.head.repo.full_name == github.repository",
    "github.event.pull_request.base.ref == 'dev'",
    "github.event.pull_request.base.repo.full_name == github.repository",
    "github.event.pull_request.state == 'open'",
    'QUALITY_HEAD_SHA: ${{ steps.immutable.outputs.quality-head-sha }}',
  ]) {
    assert.ok(workflow.includes(expected), `missing workflow gate: ${expected}`);
  }
});

test('clean patch is merged immediately with merge method and exact SHA', async () => {
  const result = await runScenario({
    pulls: [dependabotPull({mergeable_state: 'clean'})],
  });

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.graphql.length, 0);
  assert.equal(result.calls.merge.length, 1);
  assert.equal(result.calls.merge[0].merge_method, 'merge');
  assert.equal(result.calls.merge[0].sha, EXPECTED_HEAD_SHA);
  assert.equal(result.calls.get.length, 2, 'the merged PR must be re-read to verify merge_commit_sha');
  assert.match(result.notices[0], new RegExp(MERGE_COMMIT_SHA));
});

test('blocked patch enables MERGE auto-merge', async () => {
  const result = await runScenario();

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 1);
  assert.match(result.calls.graphql[0].query, /enablePullRequestAutoMerge/);
  assert.match(result.calls.graphql[0].query, /mergeMethod: MERGE/);
  assert.deepEqual(result.calls.graphql[0].variables, {pullRequestId: 'PR_dependabot_node_id'});
});

test('already enabled auto-merge succeeds idempotently', async () => {
  const result = await runScenario({
    pulls: [dependabotPull({auto_merge: {enabled_at: '2026-07-17T00:00:00Z'}})],
  });

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
  assert.match(result.notices[0], /already enabled/);
});

for (const [description, pull] of [
  ['changed SHA', dependabotPull({head: {sha: OTHER_HEAD_SHA}})],
  ['wrong author', dependabotPull({user: {login: 'Nerpp'}})],
  ['wrong target branch', dependabotPull({base: {ref: 'main'}})],
  ['wrong target repository', dependabotPull({base: {repo: {full_name: 'Nerpp/other'}}})],
  ['wrong source branch', dependabotPull({head: {ref: 'work'}})],
  ['wrong source repository', dependabotPull({head: {repo: {full_name: 'Nerpp/other'}}})],
  ['draft PR', dependabotPull({draft: true})],
  ['closed PR', dependabotPull({state: 'closed'})],
]) {
  test(`${description} fails final identity validation`, async () => {
    const result = await runScenario({pulls: [pull]});

    assert.match(result.failures[0], /identity or SHA changed/);
    assert.equal(result.calls.merge.length, 0);
    assert.equal(result.calls.graphql.length, 0);
  });
}

for (const mergeabilityState of ['dirty', 'behind', 'unstable']) {
  test(`${mergeabilityState} patch fails closed`, async () => {
    const result = await runScenario({
      pulls: [dependabotPull({
        mergeable: mergeabilityState === 'dirty' ? false : true,
        mergeable_state: mergeabilityState,
      })],
    });

    assert.match(result.failures[0], new RegExp(`\\(${mergeabilityState}\\)`));
    assert.equal(result.calls.merge.length, 0);
    assert.equal(result.calls.graphql.length, 0);
  });
}

test('persistently unknown mergeability fails after bounded retries', async () => {
  const unknown = dependabotPull({mergeable: null, mergeable_state: 'unknown'});
  const result = await runScenario({pulls: Array.from({length: 5}, () => unknown)});

  assert.equal(result.calls.get.length, 5);
  assert.match(result.failures[0], /\(unknown\)/);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
});

test('merged false response fails explicitly', async () => {
  const result = await runScenario({
    pulls: [dependabotPull({mergeable_state: 'clean'})],
    mergeResponse: {merged: false, sha: ''},
  });

  assert.match(result.failures[0], /did not confirm the Dependabot merge/);
  assert.equal(result.calls.merge.length, 1);
  assert.equal(result.calls.graphql.length, 0);
});

test('missing merge SHA response fails explicitly', async () => {
  const result = await runScenario({
    pulls: [dependabotPull({mergeable_state: 'clean'})],
    mergeResponse: {merged: true, sha: ''},
  });

  assert.match(result.failures[0], /return a merge commit SHA/);
  assert.equal(result.calls.merge.length, 1);
});

test('missing merge_commit_sha on merged PR fails explicitly', async () => {
  const result = await runScenario({
    pulls: [dependabotPull({mergeable_state: 'clean'})],
    mergedPull: dependabotPull({state: 'closed', merged: true, merge_commit_sha: null}),
  });

  assert.match(result.failures[0], /did not confirm the expected merge commit SHA/);
  assert.equal(result.calls.merge.length, 1);
});

test('unconfirmed auto-merge response fails explicitly', async () => {
  const result = await runScenario({autoMergeResponse: {enablePullRequestAutoMerge: null}});

  assert.match(result.failures[0], /did not confirm the Dependabot auto-merge request/);
  assert.equal(result.calls.graphql.length, 1);
});
