import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const EXPECTED_DEV_SHA = 'a'.repeat(40);
const OTHER_DEV_SHA = 'b'.repeat(40);
const MERGE_COMMIT_SHA = 'c'.repeat(40);
const REPOSITORY = 'Nerpp/blog_tourisme';
const STEP_NAME = 'Create or reuse promotion PR and merge safely';

const workflow = await readFile(
  new URL('../workflows/promote-dev-to-main.yml', import.meta.url),
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

const promotionScript = extractStepScript(workflow, STEP_NAME);
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const executePromotionScript = new AsyncFunction(
  'github',
  'core',
  'context',
  'process',
  'setTimeout',
  promotionScript,
);

function promotionPull(overrides = {}) {
  const pull = {
    number: 34,
    node_id: 'PR_node_id',
    state: 'open',
    draft: false,
    merged: false,
    merge_commit_sha: null,
    mergeable: true,
    mergeable_state: 'blocked',
    auto_merge: null,
    base: {ref: 'main', repo: {full_name: REPOSITORY}},
    head: {ref: 'dev', sha: EXPECTED_DEV_SHA, repo: {full_name: REPOSITORY}},
  };
  return {
    ...pull,
    ...overrides,
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
  pulls = [promotionPull()],
  listedPulls,
  devShas = [],
  mergeResponse = {merged: true, sha: MERGE_COMMIT_SHA},
  mergedPull,
  autoMergeResponse,
} = {}) {
  const pullQueue = [...pulls];
  const devShaQueue = [...devShas];
  const lastPull = pulls.at(-1) ?? promotionPull();
  const calls = {
    create: [],
    get: [],
    getRef: [],
    graphql: [],
    list: [],
    merge: [],
  };
  const failures = [];
  const notices = [];
  let mergeCalled = false;

  const github = {
    rest: {
      git: {
        async getRef(parameters) {
          calls.getRef.push(parameters);
          const sha = devShaQueue.length > 0 ? devShaQueue.shift() : EXPECTED_DEV_SHA;
          return {data: {object: {sha}}};
        },
      },
      pulls: {
        async list(parameters) {
          calls.list.push(parameters);
          return {data: listedPulls ?? [{number: lastPull.number}]};
        },
        async create(parameters) {
          calls.create.push(parameters);
          return {data: {number: lastPull.number}};
        },
        async get(parameters) {
          calls.get.push(parameters);
          if (mergeCalled) {
            return {data: mergedPull ?? promotionPull({
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
  const context = {repo: {owner: 'Nerpp', repo: 'blog_tourisme'}};

  await executePromotionScript(
    github,
    core,
    context,
    {env: {EXPECTED_DEV_SHA}},
    (callback) => callback(),
  );

  return {calls, failures, notices};
}

test('clean PR is merged immediately with merge method and validated dev SHA', async () => {
  const result = await runScenario({pulls: [promotionPull({mergeable_state: 'clean'})]});

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.graphql.length, 0);
  assert.equal(result.calls.merge.length, 1);
  assert.equal(result.calls.merge[0].merge_method, 'merge');
  assert.equal(result.calls.merge[0].sha, EXPECTED_DEV_SHA);
  assert.equal(result.calls.get.length, 2, 'the merged PR must be re-read to verify merge_commit_sha');
  assert.match(result.notices[0], new RegExp(MERGE_COMMIT_SHA));
});

test('blocked PR enables MERGE auto-merge', async () => {
  const result = await runScenario({listedPulls: []});

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.create.length, 1);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 1);
  assert.match(result.calls.graphql[0].query, /enablePullRequestAutoMerge/);
  assert.match(result.calls.graphql[0].query, /mergeMethod: MERGE/);
  assert.deepEqual(result.calls.graphql[0].variables, {pullRequestId: 'PR_node_id'});
});

test('already enabled auto-merge succeeds idempotently', async () => {
  const result = await runScenario({
    pulls: [promotionPull({auto_merge: {enabled_at: '2026-07-17T00:00:00Z'}})],
  });

  assert.deepEqual(result.failures, []);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
  assert.match(result.notices[0], /already enabled/);
});

test('PR head SHA different from validated dev SHA fails closed', async () => {
  const result = await runScenario({
    pulls: [promotionPull({head: {sha: OTHER_DEV_SHA}})],
  });

  assert.match(result.failures[0], /PR or dev SHA changed/);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
});

test('dev advancing after preflight fails before the merge decision', async () => {
  const result = await runScenario({devShas: [EXPECTED_DEV_SHA, OTHER_DEV_SHA]});

  assert.match(result.failures[0], /PR or dev SHA changed/);
  assert.equal(result.calls.get.length, 1);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
});

test('dirty PR fails closed', async () => {
  const result = await runScenario({
    pulls: [promotionPull({mergeable: false, mergeable_state: 'dirty'})],
  });

  assert.match(result.failures[0], /\(dirty\)/);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
});

test('persistently unknown mergeability fails after bounded retries', async () => {
  const unknown = promotionPull({mergeable: null, mergeable_state: 'unknown'});
  const result = await runScenario({pulls: Array.from({length: 5}, () => unknown)});

  assert.equal(result.calls.get.length, 5);
  assert.match(result.failures[0], /\(unknown\)/);
  assert.equal(result.calls.merge.length, 0);
  assert.equal(result.calls.graphql.length, 0);
});

test('merged false response fails explicitly', async () => {
  const result = await runScenario({
    pulls: [promotionPull({mergeable_state: 'clean'})],
    mergeResponse: {merged: false, sha: ''},
  });

  assert.match(result.failures[0], /did not confirm the merge/);
  assert.equal(result.calls.merge.length, 1);
  assert.equal(result.calls.graphql.length, 0);
});

test('missing merge SHA response fails explicitly', async () => {
  const result = await runScenario({
    pulls: [promotionPull({mergeable_state: 'clean'})],
    mergeResponse: {merged: true, sha: ''},
  });

  assert.match(result.failures[0], /return a merge commit SHA/);
  assert.equal(result.calls.merge.length, 1);
});

test('missing merge_commit_sha on the merged PR fails explicitly', async () => {
  const result = await runScenario({
    pulls: [promotionPull({mergeable_state: 'clean'})],
    mergedPull: promotionPull({state: 'closed', merged: true, merge_commit_sha: null}),
  });

  assert.match(result.failures[0], /did not confirm the expected merge commit SHA/);
  assert.equal(result.calls.merge.length, 1);
});

test('unconfirmed auto-merge response fails explicitly', async () => {
  const result = await runScenario({autoMergeResponse: {enablePullRequestAutoMerge: null}});

  assert.match(result.failures[0], /did not confirm the auto-merge request/);
  assert.equal(result.calls.graphql.length, 1);
});

for (const mergeabilityState of ['behind', 'unstable']) {
  test(`${mergeabilityState} PR fails closed`, async () => {
    const result = await runScenario({pulls: [promotionPull({mergeable_state: mergeabilityState})]});

    assert.match(result.failures[0], new RegExp(`\\(${mergeabilityState}\\)`));
    assert.equal(result.calls.merge.length, 0);
    assert.equal(result.calls.graphql.length, 0);
  });
}

for (const [description, pull] of [
  ['closed PR', promotionPull({state: 'closed'})],
  ['draft PR', promotionPull({draft: true})],
  ['wrong base', promotionPull({base: {ref: 'dev'}})],
  ['wrong source', promotionPull({head: {ref: 'work'}})],
  ['foreign source repository', promotionPull({head: {repo: {full_name: 'Nerpp/other'}}})],
]) {
  test(`${description} fails identity validation`, async () => {
    const result = await runScenario({pulls: [pull]});

    assert.match(result.failures[0], /PR or dev SHA changed/);
    assert.equal(result.calls.merge.length, 0);
    assert.equal(result.calls.graphql.length, 0);
  });
}
