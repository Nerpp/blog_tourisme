import assert from 'node:assert/strict';
import test from 'node:test';

import {
  classifyDependabotPolicy,
  evaluateAutoMergeGate,
} from './dependabot-policy.mjs';

const BASE_SHA = 'a'.repeat(40);
const HEAD_SHA = 'b'.repeat(40);

const versionUpdate = (
  name,
  previousVersion,
  newVersion,
  level,
  dependencyType = 'direct:production',
) => ({
  dependencyName: name,
  dependencyType,
  prevVersion: previousVersion,
  newVersion,
  updateType: `version-update:semver-${level}`,
});

function composerFiles(directDependencies, transitiveDependencies = []) {
  const manifest = Object.fromEntries(directDependencies.map((dependency) => [dependency.dependencyName, '*']));
  const packages = (dependencies, versionKey) => dependencies.map((dependency) => ({
    name: dependency.dependencyName,
    version: dependency[versionKey],
  }));
  return {
    base: {
      'composer.json': JSON.stringify({require: manifest}),
      'composer.lock': JSON.stringify({
        packages: packages([...directDependencies, ...transitiveDependencies], 'prevVersion'),
        'packages-dev': [],
      }),
    },
    head: {
      'composer.json': JSON.stringify({require: manifest}),
      'composer.lock': JSON.stringify({
        packages: packages([...directDependencies, ...transitiveDependencies], 'newVersion'),
        'packages-dev': [],
      }),
    },
  };
}

function npmFiles(directDependencies, transitiveDependencies = []) {
  const manifest = Object.fromEntries(directDependencies.map((dependency) => [dependency.dependencyName, '*']));
  const packages = (dependencies, versionKey) => Object.fromEntries(dependencies.map((dependency) => [
    `node_modules/${dependency.dependencyName}`,
    {version: dependency[versionKey]},
  ]));
  return {
    base: {
      'package.json': JSON.stringify({dependencies: manifest}),
      'package-lock.json': JSON.stringify({
        packages: {'': {dependencies: manifest}, ...packages([...directDependencies, ...transitiveDependencies], 'prevVersion')},
      }),
    },
    head: {
      'package.json': JSON.stringify({dependencies: manifest}),
      'package-lock.json': JSON.stringify({
        packages: {'': {dependencies: manifest}, ...packages([...directDependencies, ...transitiveDependencies], 'newVersion')},
      }),
    },
  };
}

function defaultChangedFiles(ecosystem, dependencies) {
  if (ecosystem === 'composer') return [{filename: 'composer.lock', patch: '@@'}];
  if (ecosystem === 'npm' || ecosystem === 'npm_and_yarn') {
    return [{filename: 'package-lock.json', patch: '@@'}];
  }
  const dependency = dependencies[0];
  if (ecosystem === 'docker') {
    return [{
      filename: 'Dockerfile',
      patch: `@@\n-FROM ${dependency.dependencyName}:${dependency.prevVersion}\n+FROM ${dependency.dependencyName}:${dependency.newVersion}`,
    }];
  }
  return [{
    filename: '.github/workflows/ci.yml',
    patch: `@@\n-      uses: ${dependency.dependencyName}@v${dependency.prevVersion}\n+      uses: ${dependency.dependencyName}@v${dependency.newVersion}`,
  }];
}

function branchEcosystem(ecosystem) {
  if (ecosystem === 'npm') return 'npm_and_yarn';
  if (ecosystem === 'github-actions') return 'github_actions';
  return ecosystem;
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
  const dependencyGroup = dependencies.length > 1 ? 'test-group' : '';
  const normalizedDependencies = dependencies.map((dependency) => ({
    directory: '/',
    packageEcosystem: ecosystem,
    targetBranch: 'dev',
    ...(dependencyGroup ? {dependencyGroup} : {}),
    ...dependency,
  }));
  const generatedFiles = ecosystem === 'composer'
    ? composerFiles(dependencies)
    : ecosystem === 'npm' || ecosystem === 'npm_and_yarn'
      ? npmFiles(dependencies)
      : {base: {}, head: {}};
  return {
    pull: {
      actor: 'dependabot[bot]',
      author: 'dependabot[bot]',
      repository: 'Nerpp/blog_tourisme',
      baseRef: 'dev',
      baseRepository: 'Nerpp/blog_tourisme',
      baseSha: BASE_SHA,
      headRef: `dependabot/${branchEcosystem(ecosystem)}/dev/group`,
      headRepository: 'Nerpp/blog_tourisme',
      headSha: HEAD_SHA,
      draft: false,
      body: '',
      ...pull,
    },
    metadata: {
      dependencyNames: normalizedDependencies.map((dependency) => dependency.dependencyName).join(', '),
      dependencyGroup,
      directory: '/',
      packageEcosystem: ecosystem,
      maintainerChanges: false,
      verified: true,
      targetBranch: 'dev',
      updateType: `version-update:semver-${level}`,
      updatedDependencies: normalizedDependencies,
      ...metadata,
    },
    changedFiles: changedFiles ?? defaultChangedFiles(ecosystem, dependencies),
    files: files ?? generatedFiles,
  };
}

function expectClassification(input, {
  globalUpdateType,
  autoMergeEligible,
  policyValid = true,
  manualReviewRequired = policyValid && !autoMergeEligible,
  reason,
}) {
  const result = classifyDependabotPolicy(input);
  assert.equal(result.policyValid, policyValid, result.reason);
  assert.equal(result.autoMergeEligible, autoMergeEligible, result.reason);
  assert.equal(result.manualReviewRequired, manualReviewRequired, result.reason);
  assert.equal(result.globalUpdateType, globalUpdateType, result.reason);
  assert.equal(result.semverLevel, globalUpdateType, result.reason);
  assert.equal(result.autoMergeEligible, result.policyValid
    && result.globalUpdateType === 'patch'
    && !result.maintainerChanges
    && !result.breakingChanges);
  assert.ok(result.reason.length > 0);
  if (reason) assert.match(result.reason, reason);
  return result;
}

test('direct Composer patch is valid and auto-merge eligible', () => {
  expectClassification(makeInput({
    dependencies: [versionUpdate('symfony/console', '7.3.1', '7.3.2', 'patch')],
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('direct npm patch is valid and auto-merge eligible', () => {
  expectClassification(makeInput({
    ecosystem: 'npm_and_yarn',
    dependencies: [versionUpdate('vite', '6.1.1', '6.1.2', 'patch')],
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('Docker patch is valid and auto-merge eligible', () => {
  expectClassification(makeInput({
    ecosystem: 'docker',
    dependencies: [versionUpdate('php', '8.4.1', '8.4.2', 'patch')],
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('GitHub Actions patch is valid and auto-merge eligible', () => {
  expectClassification(makeInput({
    ecosystem: 'github_actions',
    dependencies: [versionUpdate('actions/checkout', '5.0.0', '5.0.1', 'patch')],
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('patch-only group is auto-merge eligible', () => {
  expectClassification(makeInput({
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.0.0', '2.0.1', 'patch'),
    ],
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('direct patch plus transitive patch is auto-merge eligible', () => {
  const direct = versionUpdate('vendor/direct', '1.0.1', '1.0.2', 'patch');
  const transitive = versionUpdate('vendor/transitive', '2.3.4', '2.3.5', 'patch', 'transitive');
  expectClassification(makeInput({
    dependencies: [direct],
    files: composerFiles([direct], [transitive]),
  }), {globalUpdateType: 'patch', autoMergeEligible: true});
});

test('direct patch plus transitive minor is valid but manual', () => {
  const direct = versionUpdate('vendor/direct', '1.0.1', '1.0.2', 'patch');
  const transitive = versionUpdate('vendor/transitive', '2.3.4', '2.4.0', 'minor', 'transitive');
  expectClassification(makeInput({
    dependencies: [direct],
    files: composerFiles([direct], [transitive]),
  }), {globalUpdateType: 'minor', autoMergeEligible: false, reason: /global update type is minor/});
});

test('direct patch plus transitive major is valid but manual', () => {
  const direct = versionUpdate('vite', '6.1.1', '6.1.2', 'patch');
  const transitive = versionUpdate('three', '0.179.1', '0.184.0', 'minor', 'transitive');
  const major = versionUpdate('transitive-major', '2.9.0', '3.0.0', 'major', 'transitive');
  expectClassification(makeInput({
    ecosystem: 'npm_and_yarn',
    dependencies: [direct],
    files: npmFiles([direct], [transitive, major]),
  }), {globalUpdateType: 'major', autoMergeEligible: false});
});

test('direct minor is valid but manual', () => {
  expectClassification(makeInput({
    level: 'minor',
    dependencies: [versionUpdate('doctrine/dbal', '4.3.0', '4.4.0', 'minor')],
  }), {globalUpdateType: 'minor', autoMergeEligible: false});
});

test('direct major is valid but manual', () => {
  expectClassification(makeInput({
    level: 'major',
    dependencies: [versionUpdate('vendor/package', '1.9.0', '2.0.0', 'major')],
  }), {globalUpdateType: 'major', autoMergeEligible: false});
});

test('publisher change is valid but manual', () => {
  const result = expectClassification(makeInput({metadata: {maintainerChanges: true}}), {
    globalUpdateType: 'patch',
    autoMergeEligible: false,
    reason: /publisher change/,
  });
  assert.equal(result.maintainerChanges, true);
});

test('maintainer change from structured metadata is valid but manual', () => {
  const dependency = {...versionUpdate('vendor/package', '1.2.3', '1.2.4', 'patch'), maintainerChanges: true};
  expectClassification(makeInput({dependencies: [dependency]}), {
    globalUpdateType: 'patch',
    autoMergeEligible: false,
  });
});

test('documented breaking change is valid but manual', () => {
  expectClassification(makeInput({pull: {body: '## BREAKING CHANGES\nA migration is required.'}}), {
    globalUpdateType: 'patch',
    autoMergeEligible: false,
    reason: /breaking changes/,
  });
});

test('PR #27 real characteristics are valid but manual', () => {
  const direct = [
    versionUpdate('@photo-sphere-viewer/core', '5.14.1', '5.14.3', 'patch'),
    versionUpdate('vue', '3.5.34', '3.5.39', 'patch'),
  ];
  const transitives = [
    versionUpdate('@babel/helper-string-parser', '7.27.1', '7.29.7', 'minor', 'transitive'),
    versionUpdate('@babel/helper-validator-identifier', '7.28.5', '7.29.7', 'minor', 'transitive'),
    versionUpdate('@babel/parser', '7.29.3', '7.29.7', 'patch', 'transitive'),
    versionUpdate('@babel/types', '7.29.0', '7.29.7', 'patch', 'transitive'),
    versionUpdate('@vue/compiler-core', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/compiler-dom', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/compiler-sfc', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/compiler-ssr', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/reactivity', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/runtime-core', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/runtime-dom', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/server-renderer', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('@vue/shared', '3.5.34', '3.5.39', 'patch', 'transitive'),
    versionUpdate('postcss', '8.5.14', '8.5.19', 'patch', 'transitive'),
    versionUpdate('three', '0.179.1', '0.184.0', 'minor', 'transitive'),
  ];
  const input = makeInput({
    ecosystem: 'npm_and_yarn',
    dependencies: direct,
    files: npmFiles(direct, transitives),
    pull: {
      headRef: 'dependabot/npm_and_yarn/dev/npm-patches-2f73c23d2f',
      headSha: '00582eeae4ce4ec703983c83c3068e1e53821e81',
    },
    metadata: {directory: '/dev', maintainerChanges: true, dependencyGroup: 'npm-patches'},
  });
  input.metadata.updatedDependencies = input.metadata.updatedDependencies.map((dependency) => ({
    ...dependency,
    directory: '/dev',
    dependencyGroup: 'npm-patches',
    maintainerChanges: true,
  }));
  expectClassification(input, {
    globalUpdateType: 'minor',
    autoMergeEligible: false,
    reason: /global update type is minor; a maintainer or publisher change/,
  });
});

test('PR #26 major GitHub Actions update is valid but manual', () => {
  const dependency = versionUpdate('actions/create-github-app-token', '2', '3', 'major');
  const input = makeInput({
    ecosystem: 'github_actions',
    level: 'major',
    dependencies: [dependency],
    pull: {
      headRef: 'dependabot/github_actions/dev/actions/create-github-app-token-3',
      headSha: '1e6f5dba399ca07adda5129201e5c99e568121eb',
      body: '### BREAKING CHANGES\nRequires a recent Actions runner.',
    },
    changedFiles: [
      {
        filename: '.github/workflows/dependabot-automerge.yml',
        patch: '@@\n-        uses: actions/create-github-app-token@v2\n+        uses: actions/create-github-app-token@v3',
      },
      {
        filename: '.github/workflows/promote-dev-to-main.yml',
        patch: '@@\n-        uses: actions/create-github-app-token@v2\n+        uses: actions/create-github-app-token@v3',
      },
    ],
  });
  expectClassification(input, {globalUpdateType: 'major', autoMergeEligible: false});
});

test('mixed patch/minor group is valid but manual', () => {
  expectClassification(makeInput({
    level: 'minor',
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.1.0', '2.2.0', 'minor'),
    ],
  }), {globalUpdateType: 'minor', autoMergeEligible: false});
});

test('mixed patch/major group is valid but manual', () => {
  expectClassification(makeInput({
    level: 'major',
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.1.0', '3.0.0', 'major'),
    ],
  }), {globalUpdateType: 'major', autoMergeEligible: false});
});

test('unexpected application file is invalid', () => {
  expectClassification(makeInput({
    changedFiles: [
      {filename: 'composer.lock', patch: '@@'},
      {filename: 'src/Kernel.php', patch: '@@'},
    ],
  }), {globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false, reason: /unexpected file/});
});

test('wrong actor is invalid', () => {
  expectClassification(makeInput({pull: {actor: 'Nerpp'}}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('wrong author is invalid', () => {
  expectClassification(makeInput({pull: {author: 'Nerpp'}}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('wrong base is invalid', () => {
  expectClassification(makeInput({pull: {baseRef: 'main'}}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('wrong head branch is invalid', () => {
  expectClassification(makeInput({pull: {headRef: 'dependabot/composer/main/group'}}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('unsupported ecosystem is invalid', () => {
  expectClassification(makeInput({ecosystem: 'bundler'}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('incorrect Dependabot target is invalid', () => {
  const input = makeInput();
  input.metadata.targetBranch = 'main';
  input.metadata.updatedDependencies[0].targetBranch = 'main';
  expectClassification(input, {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('unknown update type is invalid', () => {
  const input = makeInput();
  input.metadata.updateType = 'version-update:semver-unknown';
  input.metadata.updatedDependencies[0].updateType = 'version-update:semver-unknown';
  expectClassification(input, {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('unclassifiable versions are invalid', () => {
  const dependency = {
    dependencyName: 'vendor/package',
    dependencyType: 'direct:production',
    prevVersion: 'dev-main',
    newVersion: 'dev-next',
    updateType: 'version-update:semver-patch',
  };
  expectClassification(makeInput({dependencies: [dependency]}), {
    globalUpdateType: 'major', autoMergeEligible: false, policyValid: false,
  });
});

test('manifest and metadata version disagreement is invalid', () => {
  const dependency = versionUpdate('vendor/package', '1.2.3', '1.2.4', 'patch');
  const realDependency = versionUpdate('vendor/package', '1.2.3', '1.2.5', 'patch');
  expectClassification(makeInput({
    dependencies: [dependency],
    files: composerFiles([realDependency]),
  }), {globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false, reason: /disagree/});
});

test('aggregate and structured update types disagreement is invalid', () => {
  expectClassification(makeInput({
    level: 'patch',
    dependencies: [versionUpdate('vendor/package', '1.0.0', '1.1.0', 'minor')],
  }), {globalUpdateType: 'minor', autoMergeEligible: false, policyValid: false});
});

test('incoherent dependency group metadata is invalid', () => {
  const input = makeInput({
    dependencies: [
      versionUpdate('vendor/a', '1.0.0', '1.0.1', 'patch'),
      versionUpdate('vendor/b', '2.0.0', '2.0.1', 'patch'),
    ],
  });
  input.metadata.updatedDependencies[1].dependencyGroup = 'other-group';
  expectClassification(input, {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('real nested Dependabot directory is invalid', () => {
  const input = makeInput();
  input.metadata.directory = '/packages/app';
  input.metadata.updatedDependencies[0].directory = '/packages/app';
  expectClassification(input, {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

test('malformed SHA is invalid', () => {
  expectClassification(makeInput({pull: {headSha: 'not-a-sha'}}), {
    globalUpdateType: 'patch', autoMergeEligible: false, policyValid: false,
  });
});

function eligibleGate(overrides = {}) {
  return evaluateAutoMergeGate({
    policyValid: true,
    autoMergeEligible: true,
    activationValue: 'true',
    appId: '12345',
    privateKey: 'private-key',
    expectedHeadSha: HEAD_SHA,
    currentHeadSha: HEAD_SHA,
    qualityConclusion: 'success',
    ...overrides,
  });
}

test('activation variable absent keeps auto-merge inactive', () => {
  const result = eligibleGate({activationValue: undefined});
  assert.equal(result.autoMergeAllowed, false);
  assert.match(result.reason, /DEPENDABOT_AUTOMERGE_ENABLED/);
});

test('GitHub App secret absent keeps auto-merge inactive', () => {
  const result = eligibleGate({privateKey: ''});
  assert.equal(result.autoMergeAllowed, false);
  assert.match(result.reason, /private key/);
});

test('changed head SHA keeps auto-merge inactive', () => {
  const result = eligibleGate({currentHeadSha: 'c'.repeat(40)});
  assert.equal(result.autoMergeAllowed, false);
  assert.match(result.reason, /head SHA changed/);
});

test('non-successful Quality keeps auto-merge inactive', () => {
  const result = eligibleGate({qualityConclusion: 'failure'});
  assert.equal(result.autoMergeAllowed, false);
  assert.match(result.reason, /Quality is not successful/);
});

test('manual classification can never pass the auto-merge gate', () => {
  const result = eligibleGate({autoMergeEligible: false});
  assert.equal(result.autoMergeAllowed, false);
  assert.match(result.reason, /not auto-merge eligible/);
});

test('all explicit auto-merge gates allow a request', () => {
  const result = eligibleGate();
  assert.equal(result.autoMergeAllowed, true);
  assert.deepEqual(result.blockers, []);
});
