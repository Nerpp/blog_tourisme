import assert from 'node:assert/strict';
import test from 'node:test';

import {classifyDependabotPolicy} from './dependabot-policy.mjs';

const versionUpdate = (name, previousVersion, newVersion, level, dependencyType = 'direct') => ({
  dependencyName: name,
  dependencyType,
  prevVersion: previousVersion,
  newVersion,
  updateType: `version-update:semver-${level}`,
});

function emptyFiles(ecosystem) {
  if (ecosystem === 'composer') {
    return {
      base: {'composer.json': '{}', 'composer.lock': '{"packages":[],"packages-dev":[]}'},
      head: {'composer.json': '{}', 'composer.lock': '{"packages":[],"packages-dev":[]}'},
    };
  }
  if (ecosystem === 'npm') {
    return {
      base: {'package.json': '{}', 'package-lock.json': '{"packages":{}}'},
      head: {'package.json': '{}', 'package-lock.json': '{"packages":{}}'},
    };
  }
  return {base: {}, head: {}};
}

function defaultChangedFile(ecosystem) {
  if (ecosystem === 'composer') return {filename: 'composer.lock', patch: '@@'};
  if (ecosystem === 'npm') return {filename: 'package-lock.json', patch: '@@'};
  if (ecosystem === 'docker') {
    return {filename: 'Dockerfile', patch: '@@\n-FROM php:8.4.1\n+FROM php:8.4.2'};
  }
  return {
    filename: '.github/workflows/ci.yml',
    patch: '@@\n-      uses: actions/checkout@v4.1.0\n+      uses: actions/checkout@v4.1.1',
  };
}

function makeInput({
  ecosystem = 'composer',
  level = 'patch',
  dependencies = [versionUpdate('vendor/package', '1.2.3', '1.2.4', level)],
  metadata = {},
  pull = {},
  changedFiles,
  files,
} = {}) {
  return {
    pull: {
      actor: 'dependabot[bot]',
      author: 'dependabot[bot]',
      repository: 'Nerpp/blog_tourisme',
      baseRef: 'dev',
      baseRepository: 'Nerpp/blog_tourisme',
      headRef: `dependabot/${ecosystem}/dev/group`,
      headRepository: 'Nerpp/blog_tourisme',
      draft: false,
      ...pull,
    },
    metadata: {
      dependencyNames: dependencies.map((dependency) => dependency.dependencyName).join(','),
      directory: '/',
      packageEcosystem: ecosystem,
      maintainerChanges: false,
      targetBranch: 'dev',
      updateType: `version-update:semver-${level}`,
      updatedDependencies: dependencies,
      ...metadata,
    },
    changedFiles: changedFiles ?? [defaultChangedFile(ecosystem)],
    files: files ?? emptyFiles(ecosystem),
  };
}

function expectLevel(input, level, eligible, valid = true) {
  const result = classifyDependabotPolicy(input);
  assert.equal(result.semverLevel, level, result.reason);
  assert.equal(result.autoMergeEligible, eligible, result.reason);
  assert.equal(result.policyValid, valid, result.reason);
  assert.equal(result.autoMergeEligible, result.policyValid && result.semverLevel === 'patch');
  return result;
}

test('Composer patch is eligible', () => {
  expectLevel(makeInput({
    dependencies: [versionUpdate('symfony/console', '7.3.1', '7.3.2', 'patch')],
  }), 'patch', true);
});

test('Composer Symfony minor is visible but manual', () => {
  expectLevel(makeInput({
    level: 'minor',
    dependencies: [versionUpdate('symfony/console', '7.3.2', '7.4.0', 'minor')],
  }), 'minor', false);
});

test('Composer non-Symfony minor is visible but manual', () => {
  expectLevel(makeInput({
    level: 'minor',
    dependencies: [versionUpdate('doctrine/dbal', '4.3.0', '4.4.0', 'minor')],
  }), 'minor', false);
});

test('Composer transitive minor elevates patch metadata to minor', () => {
  const files = {
    base: {
      'composer.json': '{"require":{"vendor/direct":"^1.0"}}',
      'composer.lock': '{"packages":[{"name":"vendor/direct","version":"1.0.1"},{"name":"vendor/transitive","version":"2.3.4"}]}',
    },
    head: {
      'composer.json': '{"require":{"vendor/direct":"^1.0"}}',
      'composer.lock': '{"packages":[{"name":"vendor/direct","version":"1.0.2"},{"name":"vendor/transitive","version":"2.4.0"}]}',
    },
  };
  const result = expectLevel(makeInput({files}), 'minor', false);
  assert(result.changes.some((change) => change.name === 'vendor/transitive'
    && change.dependencyType === 'transitive' && change.level === 'minor'));
});

test('npm patch is eligible', () => {
  expectLevel(makeInput({
    ecosystem: 'npm',
    dependencies: [versionUpdate('vite', '6.1.1', '6.1.2', 'patch')],
  }), 'patch', true);
});

test('npm minor is visible but manual', () => {
  expectLevel(makeInput({
    ecosystem: 'npm',
    level: 'minor',
    dependencies: [versionUpdate('vite', '6.1.2', '6.2.0', 'minor')],
  }), 'minor', false);
});

test('npm transitive minor elevates patch metadata to minor', () => {
  const files = {
    base: {
      'package.json': '{"dependencies":{"vite":"^6.0.0"}}',
      'package-lock.json': '{"packages":{"":{"dependencies":{"vite":"^6.0.0"}},"node_modules/vite":{"version":"6.1.1"},"node_modules/transitive":{"version":"3.2.1"}}}',
    },
    head: {
      'package.json': '{"dependencies":{"vite":"^6.0.0"}}',
      'package-lock.json': '{"packages":{"":{"dependencies":{"vite":"^6.0.0"}},"node_modules/vite":{"version":"6.1.2"},"node_modules/transitive":{"version":"3.3.0"}}}',
    },
  };
  expectLevel(makeInput({ecosystem: 'npm', files}), 'minor', false);
});

test('Docker patch is eligible', () => {
  expectLevel(makeInput({ecosystem: 'docker'}), 'patch', true);
});

test('Docker minor is visible but manual', () => {
  expectLevel(makeInput({
    ecosystem: 'docker',
    level: 'minor',
    dependencies: [versionUpdate('php', '8.4.1', '8.5.0', 'minor')],
  }), 'minor', false);
});

test('GitHub Actions patch is eligible', () => {
  expectLevel(makeInput({ecosystem: 'github-actions'}), 'patch', true);
});

test('GitHub Actions minor is visible but manual', () => {
  expectLevel(makeInput({
    ecosystem: 'github-actions',
    level: 'minor',
    dependencies: [versionUpdate('actions/checkout', '4.1.0', '4.2.0', 'minor')],
  }), 'minor', false);
});

test('direct major is never eligible', () => {
  expectLevel(makeInput({
    level: 'major',
    dependencies: [versionUpdate('vendor/package', '1.9.0', '2.0.0', 'major')],
  }), 'major', false);
});

test('transitive major elevates a patch group', () => {
  const files = {
    base: {
      'composer.json': '{"require":{"vendor/direct":"^1.0"}}',
      'composer.lock': '{"packages":[{"name":"vendor/direct","version":"1.0.1"},{"name":"vendor/transitive","version":"2.9.0"}]}',
    },
    head: {
      'composer.json': '{"require":{"vendor/direct":"^1.0"}}',
      'composer.lock': '{"packages":[{"name":"vendor/direct","version":"1.0.2"},{"name":"vendor/transitive","version":"3.0.0"}]}',
    },
  };
  expectLevel(makeInput({files}), 'major', false);
});

test('mixed patch and minor is globally minor', () => {
  expectLevel(makeInput({
    level: 'minor',
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.1.0', '2.2.0', 'minor'),
    ],
  }), 'minor', false);
});

test('mixed patch, minor and major is globally major', () => {
  expectLevel(makeInput({
    level: 'major',
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.1.0', '2.2.0', 'minor'),
      versionUpdate('vendor/c', '3.1.0', '4.0.0', 'major'),
    ],
  }), 'major', false);
});

test('unknown versions fail safely as major', () => {
  const dependency = {
    dependencyName: 'vendor/package',
    dependencyType: 'direct',
    prevVersion: 'dev-main',
    newVersion: 'dev-next',
  };
  expectLevel(makeInput({dependencies: [dependency]}), 'major', false, false);
});

test('maintainer changes are rejected', () => {
  expectLevel(makeInput({metadata: {maintainerChanges: true}}), 'patch', false, false);
});

test('unexpected files are rejected', () => {
  expectLevel(makeInput({
    changedFiles: [defaultChangedFile('composer'), {filename: 'src/Kernel.php', patch: '@@'}],
  }), 'patch', false, false);
});

test('non-Dependabot author is rejected', () => {
  expectLevel(makeInput({pull: {author: 'maintainer'}}), 'patch', false, false);
});

test('base other than dev is rejected', () => {
  expectLevel(makeInput({pull: {baseRef: 'main'}}), 'patch', false, false);
});

test('draft PR is rejected', () => {
  expectLevel(makeInput({pull: {draft: true}}), 'patch', false, false);
});

test('aggregate and structured metadata disagreement fails safely', () => {
  expectLevel(makeInput({
    level: 'patch',
    dependencies: [versionUpdate('vendor/package', '1.0.0', '1.1.0', 'minor')],
  }), 'minor', false, false);
});

test('minor and major classifications can never become eligible', () => {
  for (const level of ['minor', 'major']) {
    const result = classifyDependabotPolicy(makeInput({
      level,
      dependencies: [versionUpdate('vendor/package', '1.0.0', level === 'minor' ? '1.1.0' : '2.0.0', level)],
    }));
    assert.equal(result.autoMergeEligible, false);
  }
});
