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
  return {
    name: raw.dependencyName ?? raw['dependency-name'] ?? raw.name ?? 'unknown dependency',
    dependencyType: raw.dependencyType ?? raw['dependency-type'] ?? 'unknown',
    previousVersion,
    newVersion,
    level: declaredLevel ?? levelFromVersions(previousVersion, newVersion),
    source: 'metadata',
  };
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

  for (const [name, previousVersion] of basePackages) {
    if (!headPackages.has(name)) continue;
    const newVersion = headPackages.get(name);
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

  for (const [path, previousEntry] of Object.entries(baseLock.packages ?? {})) {
    if (!path || !headLock.packages?.[path]) continue;
    const nextEntry = headLock.packages[path];
    const level = levelFromVersions(previousEntry.version, nextEntry.version);
    if (!level) continue;
    const name = npmPackageName(path, nextEntry);
    changes.push({
      name,
      dependencyType: direct.has(name) ? 'direct' : 'transitive',
      previousVersion: previousEntry.version,
      newVersion: nextEntry.version,
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

  if (pull.actor !== 'dependabot[bot]' || pull.author !== 'dependabot[bot]') {
    errors.push('actor and PR author must both be dependabot[bot]');
  }
  if (pull.baseRef !== 'dev' || pull.baseRepository !== repository) {
    errors.push('base must be dev in this repository');
  }
  if (!String(pull.headRef ?? '').startsWith('dependabot/') || pull.headRepository !== repository) {
    errors.push('head must be an internal dependabot branch');
  }
  if (pull.draft === true) errors.push('draft PRs are not eligible');
  if (!SUPPORTED_ECOSYSTEMS.has(ecosystem)) errors.push(`unsupported ecosystem: ${ecosystem ?? 'missing'}`);
  if (metadata.directory !== '/') errors.push('Dependabot directory must be /');
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
  const maintainerChanges = metadata.maintainerChanges === true
    || metadata.maintainerChanges === 'true'
    || metadataChanges.some((change, index) => {
      const raw = rawDependencies[index] ?? {};
      return raw.maintainerChanges === true
        || raw.maintainerChanges === 'true'
        || raw['maintainer-changes'] === true
        || raw['maintainer-changes'] === 'true';
    });
  if (maintainerChanges) errors.push('maintainer changes are forbidden');

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

  const aggregateLevel = normalizeLevel(metadata.updateType);
  const structuredLevels = metadataChanges.map((change) => change.level).filter((level) => LEVEL_RANK[level]);
  const lockLevels = lockChanges.map((change) => change.level).filter((level) => LEVEL_RANK[level]);
  const unknownVersion = [...metadataChanges, ...lockChanges].some((change) => change.level === 'unknown');
  if (structuredLevels.length > 0 && aggregateLevel
      && highestLevel(structuredLevels) !== aggregateLevel) {
    errors.push('aggregate update type disagrees with updated-dependencies-json');
  }

  const knownLevels = [...structuredLevels, ...lockLevels];
  if (aggregateLevel) knownLevels.push(aggregateLevel);
  if (!aggregateLevel) errors.push('aggregate update type is missing or unsupported');
  if (unknownVersion) errors.push('at least one changed version is not semver-compatible');
  if (knownLevels.length === 0) errors.push('dependency level cannot be determined');

  const semverLevel = unknownVersion || knownLevels.length === 0
    ? 'major'
    : highestLevel(knownLevels);
  const policyValid = errors.length === 0;
  const autoMergeEligible = policyValid && semverLevel === 'patch';
  const allChanges = [...metadataChanges, ...lockChanges];
  const reason = policyValid
    ? `Global dependency level is ${semverLevel}; ${autoMergeEligible ? 'patch auto-merge may be activated' : 'manual merge is required'}.`
    : `Policy rejected: ${[...new Set(errors)].join('; ')}.`;

  return {
    semverLevel,
    semverLabels: SEMVER_LABELS,
    autoMergeEligible,
    policyValid,
    reason,
    dependencySummary: summarizeDependencies(allChanges),
    changes: allChanges,
    errors: [...new Set(errors)],
  };
}

async function runCli() {
  const snapshot = JSON.parse(await readFile(process.env.POLICY_INPUT_PATH, 'utf8'));
  snapshot.metadata = {
    dependencyNames: process.env.DEPENDENCY_NAMES,
    directory: process.env.DIRECTORY,
    packageEcosystem: process.env.ECOSYSTEM,
    maintainerChanges: process.env.MAINTAINER_CHANGES,
    targetBranch: process.env.TARGET_BRANCH,
    updateType: process.env.UPDATE_TYPE,
    updatedDependencies: process.env.UPDATED_DEPENDENCIES_JSON,
  };
  const result = classifyDependabotPolicy(snapshot);
  const outputs = {
    'semver-level': result.semverLevel,
    'auto-merge-eligible': String(result.autoMergeEligible),
    'policy-valid': String(result.policyValid),
    reason: result.reason,
    'dependency-summary': result.dependencySummary,
  };
  const sanitize = (value) => String(value).replace(/[\r\n]+/g, ' ');
  await appendFile(
    process.env.GITHUB_OUTPUT,
    Object.entries(outputs).map(([key, value]) => `${key}=${sanitize(value)}\n`).join(''),
  );
  console.log(JSON.stringify(result, null, 2));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await runCli();
}
