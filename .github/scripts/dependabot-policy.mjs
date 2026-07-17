import {appendFile, readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';

const LEVEL_RANK = {patch: 1, minor: 2, major: 3};
const SEMVER_LABELS = ['patch', 'minor', 'major'];
const SUPPORTED_ECOSYSTEMS = new Set([
  'composer',
  'npm',
  'npm_and_yarn',
  'docker',
  'github-actions',
  'github_actions',
]);

const BRANCH_ECOSYSTEM = {
  composer: 'composer',
  npm: 'npm_and_yarn',
  npm_and_yarn: 'npm_and_yarn',
  docker: 'docker',
  'github-actions': 'github_actions',
  github_actions: 'github_actions',
};

function normalizeLevel(value) {
  const match = String(value ?? '').match(/(?:semver-)?(patch|minor|major)$/);
  return match?.[1] ?? null;
}

function highestLevel(levels) {
  return levels.reduce(
    (highest, level) => LEVEL_RANK[level] > LEVEL_RANK[highest] ? level : highest,
    'patch',
  );
}

function parseSemver(value) {
  const normalized = String(value ?? '').trim().replace(/^v(?=\d)/, '');
  const match = normalized.match(/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:[-+].*)?$/);
  return match ? match.slice(1, 4).map((part) => Number(part ?? 0)) : null;
}

function levelFromVersions(previousVersion, newVersion) {
  if (!previousVersion || !newVersion || previousVersion === newVersion) return null;
  const previous = parseSemver(previousVersion);
  const next = parseSemver(newVersion);
  if (!previous || !next) return 'unknown';
  if (previous[0] !== next[0]) return 'major';
  if (previous[1] !== next[1]) return 'minor';
  return 'patch';
}

function parseJson(value, fallback, errors, description) {
  if (value === undefined || value === null || value === '') return fallback;
  if (typeof value === 'object') return value;
  try {
    return JSON.parse(value);
  } catch {
    errors.push(`${description} is not valid JSON`);
    return fallback;
  }
}

function normalizeDependency(raw) {
  const previousVersion = raw.prevVersion ?? raw['previous-version'] ?? raw.previousVersion ?? null;
  const newVersion = raw.newVersion ?? raw['new-version'] ?? null;
  const declaredLevel = normalizeLevel(raw.updateType ?? raw['update-type']);
  const versionLevel = levelFromVersions(previousVersion, newVersion);
  return {
    name: raw.dependencyName ?? raw['dependency-name'] ?? raw.name ?? 'unknown dependency',
    dependencyType: raw.dependencyType ?? raw['dependency-type'] ?? 'unknown',
    directory: raw.directory ?? null,
    packageEcosystem: raw.packageEcosystem ?? raw['package-ecosystem'] ?? null,
    targetBranch: raw.targetBranch ?? raw['target-branch'] ?? null,
    dependencyGroup: raw.dependencyGroup ?? raw['dependency-group'] ?? null,
    previousVersion,
    newVersion,
    declaredLevel,
    versionLevel,
    level: versionLevel,
    source: 'metadata',
  };
}

function isExpectedHeadRef(headRef, ecosystem) {
  const branchEcosystem = BRANCH_ECOSYSTEM[ecosystem];
  if (!branchEcosystem) return false;
  return new RegExp(`^dependabot/${branchEcosystem}/dev/[A-Za-z0-9@._/-]+$`).test(String(headRef ?? ''))
    && !String(headRef).includes('..');
}

function isTrustedRootDirectory(directory, targetBranch, ecosystem, headRef) {
  if (directory === '/') return true;
  // fetch-metadata@v3 derives the directory from the branch name. When a
  // non-default target branch is embedded after the ecosystem, a real root
  // directory is reported as "/<target>". Accept that alias only when every
  // immutable branch component agrees; a real nested directory retains an
  // additional path component and is rejected.
  return targetBranch === 'dev'
    && directory === `/${targetBranch}`
    && isExpectedHeadRef(headRef, ecosystem);
}

function dependencyIsDirect(dependencyType) {
  return String(dependencyType ?? '').startsWith('direct');
}

function composerLockChanges(files, errors) {
  const baseManifest = parseJson(files?.base?.['composer.json'], {}, errors, 'base composer.json');
  const headManifest = parseJson(files?.head?.['composer.json'], {}, errors, 'head composer.json');
  const baseLock = parseJson(files?.base?.['composer.lock'], {}, errors, 'base composer.lock');
  const headLock = parseJson(files?.head?.['composer.lock'], {}, errors, 'head composer.lock');
  const direct = new Set([
    ...Object.keys(baseManifest.require ?? {}),
    ...Object.keys(baseManifest['require-dev'] ?? {}),
    ...Object.keys(headManifest.require ?? {}),
    ...Object.keys(headManifest['require-dev'] ?? {}),
  ]);
  const packages = (lock) => [...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])];
  const basePackages = new Map(packages(baseLock).map((dependency) => [dependency.name, dependency.version]));
  const headPackages = new Map(packages(headLock).map((dependency) => [dependency.name, dependency.version]));
  const changes = [];

  const packageNames = new Set([...basePackages.keys(), ...headPackages.keys()]);
  for (const name of packageNames) {
    const previousVersion = basePackages.get(name) ?? null;
    const newVersion = headPackages.get(name) ?? null;
    const level = levelFromVersions(previousVersion, newVersion);
    if (level) {
      changes.push({
        name,
        dependencyType: direct.has(name) ? 'direct' : 'transitive',
        previousVersion,
        newVersion,
        level,
        source: 'composer.lock',
      });
    }
  }
  return changes;
}

function npmPackageName(path, entry) {
  if (entry?.name) return entry.name;
  const marker = 'node_modules/';
  const position = path.lastIndexOf(marker);
  return position >= 0 ? path.slice(position + marker.length) : path;
}

function npmLockChanges(files, errors) {
  const baseManifest = parseJson(files?.base?.['package.json'], {}, errors, 'base package.json');
  const headManifest = parseJson(files?.head?.['package.json'], {}, errors, 'head package.json');
  const lockName = ['package-lock.json', 'npm-shrinkwrap.json']
    .find((name) => files?.base?.[name] || files?.head?.[name]);
  if (!lockName) return [];
  const baseLock = parseJson(files?.base?.[lockName], {}, errors, `base ${lockName}`);
  const headLock = parseJson(files?.head?.[lockName], {}, errors, `head ${lockName}`);
  const dependencySections = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];
  const direct = new Set(dependencySections.flatMap((section) => [
    ...Object.keys(baseManifest[section] ?? {}),
    ...Object.keys(headManifest[section] ?? {}),
  ]));
  const changes = [];

  const packagePaths = new Set([
    ...Object.keys(baseLock.packages ?? {}),
    ...Object.keys(headLock.packages ?? {}),
  ]);
  for (const path of packagePaths) {
    if (!path) continue;
    const previousEntry = baseLock.packages?.[path] ?? {};
    const nextEntry = headLock.packages?.[path] ?? {};
    const level = levelFromVersions(previousEntry.version, nextEntry.version);
    if (!level) continue;
    const name = npmPackageName(path, nextEntry.name ? nextEntry : previousEntry);
    changes.push({
      name,
      dependencyType: direct.has(name) ? 'direct' : 'transitive',
      previousVersion: previousEntry.version ?? null,
      newVersion: nextEntry.version ?? null,
      level,
      source: lockName,
    });
  }
  return changes;
}

function allowedPath(ecosystem, path) {
  switch (ecosystem) {
    case 'composer':
      return path === 'composer.json' || path === 'composer.lock';
    case 'npm':
    case 'npm_and_yarn':
      return ['package.json', 'package-lock.json', 'npm-shrinkwrap.json'].includes(path);
    case 'docker':
      return /(^|\/)(Dockerfile|[^/]+\.Dockerfile)$/.test(path)
        || /(^|\/)docker-compose[^/]*\.ya?ml$/.test(path);
    case 'github-actions':
    case 'github_actions':
      return /^\.github\/(workflows\/[^/]+\.ya?ml|actions\/.+\/action\.ya?ml)$/.test(path);
    default:
      return false;
  }
}

function inspectDeclarativePatch(ecosystem, file, errors) {
  if (!['docker', 'github-actions', 'github_actions'].includes(ecosystem)) return;
  if (!file.patch) {
    errors.push(`complete patch unavailable for ${file.filename}`);
    return;
  }
  const changedLines = file.patch
    .split('\n')
    .filter((line) => /^[+-]/.test(line) && !/^(---|\+\+\+)/.test(line))
    .map((line) => line.slice(1).trim())
    .filter(Boolean);
  const matcher = ecosystem === 'docker'
    ? /^(FROM|image:)\s+/i
    : /^(?:-\s*)?uses:\s*[^\s]+@[^\s#]+(?:\s+#.*)?$/;
  if (changedLines.some((line) => !matcher.test(line))) {
    errors.push(`${file.filename} contains changes outside dependency references`);
  }
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function verifyDeclarativeVersions(ecosystem, changedFiles, metadataChanges, errors) {
  if (!['docker', 'github-actions', 'github_actions'].includes(ecosystem)) return;
  const patch = changedFiles.map((file) => file.patch ?? '').join('\n');
  for (const change of metadataChanges) {
    let previousReference;
    let nextReference;
    if (ecosystem === 'docker') {
      previousReference = new RegExp(`${escapeRegExp(change.name)}:${escapeRegExp(change.previousVersion)}(?:\\s|$)`);
      nextReference = new RegExp(`${escapeRegExp(change.name)}:${escapeRegExp(change.newVersion)}(?:\\s|$)`);
    } else {
      previousReference = new RegExp(`uses:\\s*${escapeRegExp(change.name)}@v?${escapeRegExp(change.previousVersion)}(?:\\s|$)`);
      nextReference = new RegExp(`uses:\\s*${escapeRegExp(change.name)}@v?${escapeRegExp(change.newVersion)}(?:\\s|$)`);
    }
    if (!previousReference.test(patch) || !nextReference.test(patch)) {
      errors.push(`real dependency references disagree with metadata for ${change.name}`);
    }
  }
}

function verifyLockedVersions(metadataChanges, lockChanges, errors) {
  for (const metadataChange of metadataChanges) {
    const exactMatches = lockChanges.filter((lockChange) => lockChange.name === metadataChange.name
      && lockChange.previousVersion === metadataChange.previousVersion
      && lockChange.newVersion === metadataChange.newVersion);
    if (exactMatches.length === 0) {
      errors.push(`manifest or lockfile versions disagree with metadata for ${metadataChange.name}`);
      continue;
    }
    if (dependencyIsDirect(metadataChange.dependencyType)
        && !exactMatches.some((change) => change.dependencyType === 'direct')) {
      errors.push(`manifest dependency type disagrees with metadata for ${metadataChange.name}`);
    }
  }
}

function compareDependencyNames(dependencyNames, metadataChanges, errors) {
  const announced = String(dependencyNames ?? '')
    .split(',')
    .map((name) => name.trim())
    .filter(Boolean)
    .sort();
  const structured = metadataChanges.map((change) => change.name).sort();
  if (announced.length !== structured.length
      || announced.some((name, index) => name !== structured[index])) {
    errors.push('dependency names disagree with updated-dependencies-json');
  }
}

function validateDependencyGroup(metadata, metadataChanges, errors) {
  const announcedGroup = String(metadata.dependencyGroup ?? '').trim();
  const structuredGroups = new Set(metadataChanges
    .map((change) => String(change.dependencyGroup ?? '').trim())
    .filter(Boolean));
  if (structuredGroups.size > 1
      || (announcedGroup && structuredGroups.size === 1 && !structuredGroups.has(announcedGroup))
      || (!announcedGroup && structuredGroups.size > 0)) {
    errors.push('dependency group metadata is incoherent');
  }
  if (metadataChanges.length > 1 && !announcedGroup) {
    errors.push('a grouped update must announce its dependency group');
  }
}

function summarizeDependencies(changes) {
  const unique = new Map();
  for (const change of changes) {
    const key = [change.name, change.previousVersion, change.newVersion, change.level].join('|');
    unique.set(key, change);
  }
  return [...unique.values()]
    .sort((left, right) => left.name.localeCompare(right.name))
    .map((change) => {
      const versions = change.previousVersion && change.newVersion
        ? ` ${change.previousVersion}→${change.newVersion}`
        : '';
      return `${change.name} (${change.dependencyType}${versions}: ${change.level ?? 'unknown'})`;
    })
    .join(', ') || 'No dependency change could be enumerated';
}

export function classifyDependabotPolicy(input) {
  const errors = [];
  const pull = input.pull ?? {};
  const metadata = input.metadata ?? {};
  const ecosystem = metadata.packageEcosystem;
  const repository = pull.repository;

  if (metadata.verified !== true && metadata.verified !== 'true') {
    errors.push('Dependabot metadata verification did not succeed');
  }
  if (pull.actor !== 'dependabot[bot]' || pull.author !== 'dependabot[bot]') {
    errors.push('actor and PR author must both be dependabot[bot]');
  }
  if (pull.baseRef !== 'dev' || pull.baseRepository !== repository) {
    errors.push('base must be dev in this repository');
  }
  if (!isExpectedHeadRef(pull.headRef, ecosystem) || pull.headRepository !== repository) {
    errors.push('head must be the expected internal dependabot branch for this ecosystem');
  }
  if (!/^[0-9a-f]{40}$/.test(String(pull.headSha ?? ''))) errors.push('head SHA is missing or malformed');
  if (!/^[0-9a-f]{40}$/.test(String(pull.baseSha ?? ''))) errors.push('base SHA is missing or malformed');
  if (pull.draft === true) errors.push('draft PRs are not eligible');
  if (!SUPPORTED_ECOSYSTEMS.has(ecosystem)) errors.push(`unsupported ecosystem: ${ecosystem ?? 'missing'}`);
  if (!isTrustedRootDirectory(metadata.directory, metadata.targetBranch, ecosystem, pull.headRef)) {
    errors.push('Dependabot directory must resolve exactly to /');
  }
  if (metadata.targetBranch !== 'dev') errors.push('Dependabot target branch must be dev');
  if (!String(metadata.dependencyNames ?? '').trim()) errors.push('dependency names are missing');

  const rawDependencies = parseJson(
    metadata.updatedDependencies,
    [],
    errors,
    'updated-dependencies-json',
  );
  const metadataChanges = Array.isArray(rawDependencies)
    ? rawDependencies.map((dependency) => {
      if (!dependency || typeof dependency !== 'object' || Array.isArray(dependency)) {
        errors.push('updated-dependencies-json contains a malformed entry');
        return {
          name: 'unknown dependency',
          dependencyType: 'unknown',
          previousVersion: null,
          newVersion: null,
          level: 'unknown',
          source: 'metadata',
        };
      }
      return normalizeDependency(dependency);
    })
    : [];
  if (!Array.isArray(rawDependencies)) errors.push('updated-dependencies-json must be an array');
  if (metadataChanges.length === 0) errors.push('updated-dependencies-json contains no dependency');
  compareDependencyNames(metadata.dependencyNames, metadataChanges, errors);
  validateDependencyGroup(metadata, metadataChanges, errors);
  for (const change of metadataChanges) {
    if (change.directory
        && !isTrustedRootDirectory(change.directory, change.targetBranch, change.packageEcosystem, pull.headRef)) {
      errors.push(`dependency metadata directory must resolve exactly to / for ${change.name}`);
    }
    if (change.packageEcosystem && change.packageEcosystem !== ecosystem) {
      errors.push(`dependency ecosystem mismatch for ${change.name}`);
    }
    if (change.targetBranch && change.targetBranch !== 'dev') {
      errors.push(`dependency target branch mismatch for ${change.name}`);
    }
    if (!dependencyIsDirect(change.dependencyType)) {
      errors.push(`dependency type is missing or unsupported for ${change.name}`);
    }
    if (!change.declaredLevel) errors.push(`update type is missing or unsupported for ${change.name}`);
    if (!change.versionLevel || change.versionLevel === 'unknown') {
      errors.push(`version cannot be classified for ${change.name}`);
    }
    if (change.declaredLevel && change.versionLevel && change.versionLevel !== 'unknown'
        && change.declaredLevel !== change.versionLevel) {
      errors.push(`declared update type disagrees with versions for ${change.name}`);
    }
  }
  const maintainerChanges = metadata.maintainerChanges === true
    || metadata.maintainerChanges === 'true'
    || metadataChanges.some((change, index) => {
      const raw = rawDependencies[index] ?? {};
      return raw.maintainerChanges === true
        || raw.maintainerChanges === 'true'
        || raw['maintainer-changes'] === true
        || raw['maintainer-changes'] === 'true';
    });
  const breakingChanges = /\bbreaking[ -]changes?\b/i.test(String(pull.body ?? ''));

  const changedFiles = input.changedFiles ?? [];
  if (changedFiles.length === 0) errors.push('at least one dependency file must change');
  for (const file of changedFiles) {
    if (!allowedPath(ecosystem, file.filename)) errors.push(`unexpected file: ${file.filename}`);
    inspectDeclarativePatch(ecosystem, file, errors);
  }

  let lockChanges = [];
  if (ecosystem === 'composer') lockChanges = composerLockChanges(input.files, errors);
  if (ecosystem === 'npm' || ecosystem === 'npm_and_yarn') {
    lockChanges = npmLockChanges(input.files, errors);
  }
  if (ecosystem === 'composer' || ecosystem === 'npm' || ecosystem === 'npm_and_yarn') {
    verifyLockedVersions(metadataChanges, lockChanges, errors);
  }
  verifyDeclarativeVersions(ecosystem, changedFiles, metadataChanges, errors);

  const aggregateLevel = normalizeLevel(metadata.updateType);
  const structuredLevels = metadataChanges.map((change) => change.versionLevel).filter((level) => LEVEL_RANK[level]);
  const lockLevels = lockChanges.map((change) => change.level).filter((level) => LEVEL_RANK[level]);
  const unknownVersion = [...metadataChanges, ...lockChanges]
    .some((change) => !change.level || change.level === 'unknown');
  if (structuredLevels.length > 0 && aggregateLevel
      && highestLevel(structuredLevels) !== aggregateLevel) {
    errors.push('aggregate update type disagrees with updated-dependencies-json');
  }

  const knownLevels = [...structuredLevels, ...lockLevels];
  if (aggregateLevel) knownLevels.push(aggregateLevel);
  if (!aggregateLevel) errors.push('aggregate update type is missing or unsupported');
  if (unknownVersion) errors.push('at least one changed version is missing or not semver-compatible');
  if (knownLevels.length === 0) errors.push('dependency level cannot be determined');

  const globalUpdateType = unknownVersion || knownLevels.length === 0
    ? 'major'
    : highestLevel(knownLevels);
  const policyValid = errors.length === 0;
  const autoMergeEligible = policyValid
    && globalUpdateType === 'patch'
    && !maintainerChanges
    && !breakingChanges;
  const manualReviewRequired = policyValid && !autoMergeEligible;
  const allChanges = [...metadataChanges, ...lockChanges];
  const manualReasons = [];
  if (globalUpdateType !== 'patch') manualReasons.push(`global update type is ${globalUpdateType}`);
  if (maintainerChanges) manualReasons.push('a maintainer or publisher change was reported');
  if (breakingChanges) manualReasons.push('documented breaking changes require human validation');
  const reason = !policyValid
    ? `Policy rejected: ${[...new Set(errors)].join('; ')}.`
    : autoMergeEligible
      ? 'Policy valid: every direct and transitive update is patch-level and no human-review signal was found.'
      : `Policy valid; manual review required: ${manualReasons.join('; ')}.`;

  return {
    globalUpdateType,
    semverLevel: globalUpdateType,
    semverLabels: SEMVER_LABELS,
    autoMergeEligible,
    manualReviewRequired,
    maintainerChanges,
    breakingChanges,
    policyValid,
    reason,
    dependencySummary: summarizeDependencies(allChanges),
    changes: allChanges,
    errors: [...new Set(errors)],
  };
}

function isPresent(value) {
  return value === true || (typeof value === 'string' && value.trim() !== '');
}

function isTrue(value) {
  return value === true || value === 'true';
}

function isFalse(value) {
  return value === false || value === 'false';
}

export function evaluateAutoMergeGate(input) {
  const blockers = [];
  if (!isTrue(input.policyValid)) blockers.push('classification policy is invalid');
  if (input.globalUpdateType !== 'patch') blockers.push('the global update type is not patch');
  if (!isTrue(input.autoMergeEligible)) blockers.push('the update is not auto-merge eligible');
  if (!isFalse(input.manualReviewRequired)) blockers.push('manual review is required');
  if (!isFalse(input.maintainerChanges)) blockers.push('a maintainer or publisher change was reported');
  if (input.activationValue !== 'true') blockers.push('DEPENDABOT_AUTOMERGE_ENABLED is not exactly true');
  if (!isPresent(input.appId)) blockers.push('the GitHub App id is unavailable');
  if (!isPresent(input.privateKey)) blockers.push('the GitHub App private key is unavailable');
  if (!/^[0-9a-f]{40}$/.test(String(input.expectedHeadSha ?? ''))
      || !/^[0-9a-f]{40}$/.test(String(input.currentHeadSha ?? ''))
      || input.expectedHeadSha !== input.currentHeadSha) {
    blockers.push('the pull request head SHA changed or is malformed');
  }
  if (input.qualityConclusion !== 'success') blockers.push('Quality is not successful for the expected SHA');
  if (!/^[0-9a-f]{40}$/.test(String(input.qualityHeadSha ?? ''))
      || input.qualityHeadSha !== input.expectedHeadSha) {
    blockers.push('Quality does not target the expected pull request SHA');
  }
  return {
    autoMergeAllowed: blockers.length === 0,
    reason: blockers.length === 0
      ? 'All automatic merge gates are satisfied.'
      : `Automatic merge remains inactive: ${blockers.join('; ')}.`,
    blockers,
  };
}

async function writeOutputs(outputs) {
  const sanitize = (value) => String(value).replace(/[\r\n]+/g, ' ');
  await appendFile(
    process.env.GITHUB_OUTPUT,
    Object.entries(outputs).map(([key, value]) => `${key}=${sanitize(value)}\n`).join(''),
  );
}

async function runCli() {
  const snapshot = JSON.parse(await readFile(process.env.POLICY_INPUT_PATH, 'utf8'));
  snapshot.metadata = {
    dependencyNames: process.env.DEPENDENCY_NAMES,
    directory: process.env.DIRECTORY,
    packageEcosystem: process.env.ECOSYSTEM,
    maintainerChanges: process.env.MAINTAINER_CHANGES,
    dependencyGroup: process.env.DEPENDENCY_GROUP,
    verified: process.env.METADATA_VERIFIED,
    targetBranch: process.env.TARGET_BRANCH,
    updateType: process.env.UPDATE_TYPE,
    updatedDependencies: process.env.UPDATED_DEPENDENCIES_JSON,
  };
  const result = classifyDependabotPolicy(snapshot);
  const outputs = {
    globalUpdateType: result.globalUpdateType,
    autoMergeEligible: String(result.autoMergeEligible),
    manualReviewRequired: String(result.manualReviewRequired),
    maintainerChanges: String(result.maintainerChanges),
    policyValid: String(result.policyValid),
    'global-update-type': result.globalUpdateType,
    'semver-level': result.globalUpdateType,
    'auto-merge-eligible': String(result.autoMergeEligible),
    'manual-review-required': String(result.manualReviewRequired),
    'maintainer-changes': String(result.maintainerChanges),
    'policy-valid': String(result.policyValid),
    reason: result.reason,
    'dependency-summary': result.dependencySummary,
  };
  await writeOutputs(outputs);
  console.log(JSON.stringify(result, null, 2));
}

async function runAutoMergeGateCli() {
  const result = evaluateAutoMergeGate({
    policyValid: process.env.POLICY_VALID,
    globalUpdateType: process.env.GLOBAL_UPDATE_TYPE,
    autoMergeEligible: process.env.AUTO_MERGE_ELIGIBLE,
    manualReviewRequired: process.env.MANUAL_REVIEW_REQUIRED,
    maintainerChanges: process.env.MAINTAINER_CHANGES,
    activationValue: process.env.ACTIVATION_VALUE,
    appId: process.env.GITHUB_APP_ID,
    privateKey: process.env.GITHUB_APP_PRIVATE_KEY,
    expectedHeadSha: process.env.EXPECTED_HEAD_SHA,
    currentHeadSha: process.env.CURRENT_HEAD_SHA,
    qualityConclusion: process.env.QUALITY_CONCLUSION,
    qualityHeadSha: process.env.QUALITY_HEAD_SHA,
  });
  await writeOutputs({
    'auto-merge-allowed': String(result.autoMergeAllowed),
    reason: result.reason,
  });
  console.log(JSON.stringify(result, null, 2));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  if (process.argv[2] === '--auto-merge-gate') {
    await runAutoMergeGateCli();
  } else {
    await runCli();
  }
}
