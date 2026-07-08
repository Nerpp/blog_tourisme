import { spawn } from 'node:child_process';
import {
  access,
  constants,
  copyFile,
  mkdir,
  readdir,
  readFile,
  rename,
  rm,
  writeFile,
} from 'node:fs/promises';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';

const projectDirectory = process.cwd();
const catalogPath = path.join(projectDirectory, 'config/lighthouse-pages.json');
const lighthouseDirectory = path.join(projectDirectory, 'var/lighthouse');
const latestReportRelative = 'var/lighthouse/latest-report.html';
const previousReportRelative = 'var/lighthouse/previous-report.html';
const historyRelative = 'var/lighthouse/history.json';
const latestReportPath = path.join(projectDirectory, latestReportRelative);
const previousReportPath = path.join(projectDirectory, previousReportRelative);
const historyPath = path.join(projectDirectory, historyRelative);
const categories = ['performance', 'accessibility', 'best-practices', 'seo'];
const categoryLabels = {
  performance: 'Performance',
  accessibility: 'Accessibilité',
  'best-practices': 'Bonnes pratiques',
  seo: 'SEO',
};
const metricDefinitions = {
  largestContentfulPaint: {
    auditId: 'largest-contentful-paint',
    label: 'LCP',
    threshold: 250,
    unit: 'ms',
  },
  firstContentfulPaint: {
    auditId: 'first-contentful-paint',
    label: 'FCP',
    threshold: 250,
    unit: 'ms',
  },
  totalBlockingTime: {
    auditId: 'total-blocking-time',
    label: 'TBT',
    threshold: 100,
    unit: 'ms',
  },
  cumulativeLayoutShift: {
    auditId: 'cumulative-layout-shift',
    label: 'CLS',
    threshold: 0.05,
    unit: '',
  },
};
const productionOnlyAuditIds = new Set([
  'is-on-https',
  'redirects-http',
  'has-hsts',
]);
const localIndexabilityAuditIds = new Set([
  'is-crawlable',
  'robots-txt',
]);
const requiredPublicBaseUrl = 'http://localhost:8082';
const expectedHealth = {
  environment: 'test',
  database: 'app_test',
  catalog: 'lighthouse-pages-v1',
};

const usage = () => {
  console.log(`Usage: npm run audit:lighthouse -- [options]

Options:
  --base-url=URL       Instance Lighthouse publique (doit rester http://localhost:8082)
  --devices=LIST       mobile, desktop ou mobile,desktop (défaut : les deux)
  --runs=N             Nombre de passages par page et appareil (défaut : 1)
  --page=ID            Limite l'audit à un identifiant du catalogue (répétable)
  --max-pages=N        Limite le catalogue pour une validation courte
  --keep-raw           Conserve les rapports HTML et JSON individuels dans var/lighthouse/raw/
  --help               Affiche cette aide`);
};

const parsePositiveInteger = (value, option, allowZero = false) => {
  if (!/^\d+$/.test(value)) {
    throw new Error(`${option} doit être un entier ${allowZero ? 'positif ou nul' : 'strictement positif'}.`);
  }

  const parsed = Number.parseInt(value, 10);
  if ((!allowZero && parsed < 1) || !Number.isSafeInteger(parsed)) {
    throw new Error(`${option} contient une valeur invalide.`);
  }

  return parsed;
};

const normalizeBaseUrl = (value, label) => {
  let parsed;

  try {
    parsed = new URL(value.replace(/\/$/, ''));
  } catch (error) {
    throw new Error(`${label} est invalide : ${value}.`);
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error(`${label} doit utiliser HTTP ou HTTPS.`);
  }
  if (parsed.pathname !== '/' || parsed.search || parsed.hash) {
    throw new Error(`${label} ne doit contenir ni chemin, ni query string, ni fragment.`);
  }

  return parsed.toString().replace(/\/$/, '');
};

const assertNotDevelopmentBaseUrl = (baseUrl, label) => {
  const parsed = new URL(baseUrl);
  if (parsed.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(parsed.hostname) && parsed.port === '8080') {
    throw new Error(`${label} refusée : http://localhost:8080 est l’instance de développement et ne doit jamais être auditée.`);
  }
};

const requestForProtocol = (protocol) => {
  if (protocol === 'http:') {
    return http.request;
  }
  if (protocol === 'https:') {
    return https.request;
  }

  throw new Error(`Protocole proxy non pris en charge : ${protocol}`);
};

const startLocalhostProxy = async (publicBaseUrl, targetBaseUrl) => {
  const publicUrl = new URL(publicBaseUrl);
  const targetUrl = new URL(targetBaseUrl);
  const publicPort = Number.parseInt(publicUrl.port || '80', 10);
  const upstreamRequest = requestForProtocol(targetUrl.protocol);

  const server = http.createServer((clientRequest, clientResponse) => {
    const upstreamUrl = new URL(clientRequest.url || '/', targetUrl);
    const headers = { ...clientRequest.headers, host: targetUrl.host };
    const upstream = upstreamRequest(upstreamUrl, {
      method: clientRequest.method,
      headers,
    }, (upstreamResponse) => {
      clientResponse.writeHead(
        upstreamResponse.statusCode || 502,
        upstreamResponse.statusMessage,
        upstreamResponse.headers,
      );
      upstreamResponse.pipe(clientResponse);
    });

    upstream.on('error', (error) => {
      if (!clientResponse.headersSent) {
        clientResponse.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
      }
      clientResponse.end(`Proxy Lighthouse indisponible : ${error.message}\n`);
    });

    clientRequest.pipe(upstream);
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(publicPort, () => {
      server.off('error', reject);
      resolve();
    });
  });

  return {
    close: () => new Promise((resolve, reject) => {
      server.close((error) => {
        if (error) {
          reject(error);
          return;
        }
        resolve();
      });
    }),
  };
};

const parseOptions = () => {
  const options = {
    baseUrl: process.env.LIGHTHOUSE_BASE_URL || 'http://localhost:8082',
    auditBaseUrl: process.env.LIGHTHOUSE_AUDIT_BASE_URL || '',
    devices: ['mobile', 'desktop'],
    runs: 1,
    pageIds: [],
    maxPages: 0,
    keepRaw: false,
  };

  for (const argument of process.argv.slice(2)) {
    if (argument === '--help') {
      usage();
      process.exit(0);
    }
    if (argument.startsWith('--base-url=')) {
      options.baseUrl = argument.slice('--base-url='.length);
      continue;
    }
    if (argument.startsWith('--devices=')) {
      options.devices = argument.slice('--devices='.length).split(',').filter(Boolean);
      continue;
    }
    if (argument.startsWith('--runs=')) {
      options.runs = parsePositiveInteger(argument.slice('--runs='.length), '--runs');
      continue;
    }
    if (argument.startsWith('--page=')) {
      options.pageIds.push(argument.slice('--page='.length));
      continue;
    }
    if (argument.startsWith('--max-pages=')) {
      options.maxPages = parsePositiveInteger(argument.slice('--max-pages='.length), '--max-pages', true);
      continue;
    }
    if (argument === '--keep-raw') {
      options.keepRaw = true;
      continue;
    }

    throw new Error(`Option Lighthouse inconnue : ${argument}`);
  }

  options.baseUrl = normalizeBaseUrl(options.baseUrl, 'L’URL publique Lighthouse');
  assertNotDevelopmentBaseUrl(options.baseUrl, 'URL publique Lighthouse');
  if (options.baseUrl !== requiredPublicBaseUrl) {
    throw new Error(`L’URL publique Lighthouse doit être ${requiredPublicBaseUrl}. Reçu : ${options.baseUrl}.`);
  }
  options.auditBaseUrl = options.auditBaseUrl
    ? normalizeBaseUrl(options.auditBaseUrl, 'L’URL interne Lighthouse')
    : options.baseUrl;
  assertNotDevelopmentBaseUrl(options.auditBaseUrl, 'URL interne Lighthouse');

  const supportedDevices = new Set(['mobile', 'desktop']);
  if (options.devices.length === 0 || options.devices.some((device) => !supportedDevices.has(device))) {
    throw new Error('--devices accepte uniquement mobile, desktop ou mobile,desktop.');
  }
  if (new Set(options.devices).size !== options.devices.length) {
    throw new Error('--devices contient un appareil dupliqué.');
  }
  if (new Set(options.pageIds).size !== options.pageIds.length) {
    throw new Error('--page contient un identifiant dupliqué.');
  }

  return options;
};

const loadCatalog = async () => {
  const decoded = JSON.parse(await readFile(catalogPath, 'utf8'));
  if (!Array.isArray(decoded) || decoded.length === 0) {
    throw new Error('Le catalogue config/lighthouse-pages.json doit être une liste non vide.');
  }

  const ids = new Set();
  const paths = new Set();

  for (const [index, page] of decoded.entries()) {
    if (!page || typeof page !== 'object') {
      throw new Error(`Entrée Lighthouse #${index + 1} invalide.`);
    }
    for (const property of ['id', 'label', 'type', 'path']) {
      if (typeof page[property] !== 'string' || page[property].trim() === '') {
        throw new Error(`La propriété "${property}" manque dans l’entrée Lighthouse #${index + 1}.`);
      }
    }
    if (!page.path.startsWith('/') || page.path.startsWith('//')) {
      throw new Error(`Le chemin Lighthouse doit être relatif : ${page.path}`);
    }
    if (ids.has(page.id)) {
      throw new Error(`Identifiant Lighthouse dupliqué : ${page.id}`);
    }
    if (paths.has(page.path)) {
      throw new Error(`Chemin Lighthouse dupliqué : ${page.path}`);
    }

    ids.add(page.id);
    paths.add(page.path);
  }

  return decoded;
};

const fetchChecked = async (url, label) => {
  let response;

  try {
    response = await fetch(url, {
      redirect: 'manual',
      signal: AbortSignal.timeout(15_000),
    });
  } catch (error) {
    throw new Error(`${label} est indisponible (${url}) : ${error.message}`);
  }

  if (response.status !== 200) {
    throw new Error(`${label} doit répondre 200 sans redirection : ${url} répond ${response.status}.`);
  }

  return response;
};

const assertDedicatedEnvironment = async (baseUrl) => {
  const healthUrl = `${baseUrl}/_lighthouse/health`;
  const response = await fetchChecked(healthUrl, 'L’environnement Lighthouse dédié');
  const payload = await response.json();

  for (const [key, expectedValue] of Object.entries(expectedHealth)) {
    if (payload[key] !== expectedValue) {
      throw new Error(`Environnement Lighthouse refusé : ${key} vaut "${payload[key] ?? '(absent)'}", attendu "${expectedValue}".`);
    }
  }
};

const normalizeReport = async (outputBase, extension) => {
  const destination = `${outputBase}.${extension}`;
  const candidates = [`${outputBase}.report.${extension}`, destination];

  for (const candidate of candidates) {
    try {
      await access(candidate, constants.F_OK);
      if (candidate !== destination) {
        await rename(candidate, destination);
      }

      return destination;
    } catch (error) {
      if (error?.code !== 'ENOENT') {
        throw error;
      }
    }
  }

  throw new Error(`Rapport Lighthouse ${extension.toUpperCase()} introuvable pour ${outputBase}.`);
};

const fileExists = async (file) => {
  try {
    await access(file, constants.F_OK);
    return true;
  } catch (error) {
    if (error?.code === 'ENOENT') {
      return false;
    }

    throw error;
  }
};

const readHistory = async () => {
  try {
    const decoded = JSON.parse(await readFile(historyPath, 'utf8'));
    if (Array.isArray(decoded?.entries)) {
      return decoded.entries.filter((entry) => entry && typeof entry === 'object');
    }
    if (Array.isArray(decoded)) {
      return decoded.filter((entry) => entry && typeof entry === 'object');
    }

    return [];
  } catch (error) {
    if (error?.code === 'ENOENT') {
      return [];
    }

    throw new Error(`Historique Lighthouse illisible : ${error.message}`);
  }
};

const median = (values) => {
  const numericValues = values.filter((value) => Number.isFinite(value));
  if (numericValues.length === 0) {
    return null;
  }

  const sorted = [...numericValues].sort((first, second) => first - second);
  const middle = Math.floor(sorted.length / 2);
  const value = sorted.length % 2 === 0
    ? (sorted[middle - 1] + sorted[middle]) / 2
    : sorted[middle];

  return Math.round(value * 10) / 10;
};

const average = (values) => {
  const numericValues = values.filter((value) => Number.isFinite(value));
  if (numericValues.length === 0) {
    return null;
  }

  return Math.round((numericValues.reduce((sum, value) => sum + value, 0) / numericValues.length) * 10) / 10;
};

const compactText = (value, maxLength = 700) => {
  if (typeof value !== 'string') {
    return null;
  }

  const normalized = value.replace(/\s+/g, ' ').trim();
  if (normalized === '') {
    return null;
  }

  return normalized.length > maxLength
    ? `${normalized.slice(0, maxLength - 1)}…`
    : normalized;
};

const summarizeScores = (results) => ({
  minimums: Object.fromEntries(categories.map((category) => [
    category,
    Math.min(...results.map((result) => result.scores[category])),
  ])),
  averages: Object.fromEntries(categories.map((category) => [
    category,
    average(results.map((result) => result.scores[category])),
  ])),
  auditsUnder100: Object.fromEntries(categories.map((category) => [
    category,
    results.filter((result) => result.scores[category] < 100).length,
  ])),
});

const baselineKey = (item) => `${item.id}|${item.device}`;

const buildPageDeviceBaselines = (results) => {
  const groups = new Map();

  for (const result of results) {
    const key = baselineKey(result);
    const group = groups.get(key) ?? {
      id: result.id,
      label: result.label,
      type: result.type,
      path: result.path,
      url: result.url,
      device: result.device,
      runs: [],
      failingAudits: new Map(),
    };

    group.runs.push(result);
    for (const audit of result.failingAudits) {
      group.failingAudits.set(`${audit.category}|${audit.id}`, audit);
    }

    groups.set(key, group);
  }

  return [...groups.values()].map((group) => ({
    id: group.id,
    label: group.label,
    type: group.type,
    path: group.path,
    url: group.url,
    device: group.device,
    completedRuns: group.runs.length,
    scores: Object.fromEntries(categories.map((category) => [
      category,
      median(group.runs.map((run) => run.scores[category])),
    ])),
    metrics: Object.fromEntries(Object.entries(metricDefinitions).map(([key]) => [
      key,
      median(group.runs.map((run) => run.metrics[key])),
    ])),
    failingAudits: [...group.failingAudits.values()]
      .map((audit) => ({
        category: audit.category,
        id: audit.id,
        title: audit.title,
        score: audit.score,
      }))
      .sort((first, second) => `${first.category}:${first.id}`.localeCompare(`${second.category}:${second.id}`)),
  })).sort((first, second) => (
    first.path.localeCompare(second.path, 'fr')
    || first.device.localeCompare(second.device, 'fr')
  ));
};

const lowestPerformanceTarget = (baselines) => {
  const sorted = [...baselines].sort((first, second) => (
    (first.scores.performance ?? 101) - (second.scores.performance ?? 101)
    || first.path.localeCompare(second.path, 'fr')
    || first.device.localeCompare(second.device, 'fr')
  ));
  const lowest = sorted[0] ?? null;

  return lowest ? {
    pageId: lowest.id,
    label: lowest.label,
    path: lowest.path,
    device: lowest.device,
    score: lowest.scores.performance,
  } : null;
};

const buildHistoryEntry = ({
  generatedAt,
  options,
  pages,
  results,
  baselines,
  rawDirectoryRelative,
}) => {
  const scores = summarizeScores(results);

  return {
    generatedAt,
    publicBaseUrl: options.baseUrl,
    catalog: expectedHealth.catalog,
    pageCount: pages.length,
    auditCount: results.length,
    scoreMinimums: scores.minimums,
    scoreAverages: scores.averages,
    auditsUnder100: scores.auditsUnder100,
    lowestPerformance: lowestPerformanceTarget(baselines),
    reportPath: latestReportRelative,
    rawDirectory: rawDirectoryRelative,
    baselines,
  };
};

const metricValue = (value, unit) => {
  if (!Number.isFinite(value)) {
    return 'n/a';
  }
  if (unit === 'ms') {
    return `${Math.round(value)} ms`;
  }

  return String(Math.round(value * 1000) / 1000);
};

const metricDelta = (value, unit) => {
  if (!Number.isFinite(value)) {
    return 'n/a';
  }
  if (unit === 'ms') {
    return `${value > 0 ? '+' : ''}${Math.round(value)} ms`;
  }

  return `${value > 0 ? '+' : ''}${Math.round(value * 1000) / 1000}`;
};

const compareCampaigns = (previousEntry, currentEntry) => {
  if (!previousEntry?.scoreMinimums) {
    return null;
  }

  const previousBaselines = new Map((previousEntry.baselines ?? []).map((baseline) => [baselineKey(baseline), baseline]));
  const categoryEvolution = categories.map((category) => {
    const previous = previousEntry.scoreMinimums?.[category];
    const current = currentEntry.scoreMinimums?.[category];
    const difference = Number.isFinite(previous) && Number.isFinite(current)
      ? current - previous
      : null;

    return {
      category,
      previous,
      current,
      difference,
      indicator: difference === null
        ? 'non comparable'
        : difference > 0
          ? 'amelioration'
          : difference < 0
            ? 'regression'
            : 'stable',
    };
  });

  const detailedComparisonAvailable = previousBaselines.size > 0;
  const pageScoreRegressions = [];
  const pageScoreDrops = [];
  const newAuditFailures = [];
  const auditScoreDrops = [];
  const metricRegressions = [];
  const missingPreviousBaselines = [];

  for (const currentBaseline of currentEntry.baselines ?? []) {
    const previousBaseline = previousBaselines.get(baselineKey(currentBaseline));
    if (!previousBaseline) {
      missingPreviousBaselines.push({
        page: currentBaseline.label,
        path: currentBaseline.path,
        device: currentBaseline.device,
      });
      continue;
    }

    for (const category of categories) {
      if (
        Number.isFinite(previousBaseline.scores?.[category])
        && Number.isFinite(currentBaseline.scores?.[category])
        && currentBaseline.scores[category] < previousBaseline.scores[category]
      ) {
        pageScoreDrops.push({
          page: currentBaseline.label,
          path: currentBaseline.path,
          device: currentBaseline.device,
          category,
          previous: previousBaseline.scores[category],
          current: currentBaseline.scores[category],
          difference: currentBaseline.scores[category] - previousBaseline.scores[category],
        });
      }
      if (previousBaseline.scores?.[category] === 100 && currentBaseline.scores?.[category] < 100) {
        pageScoreRegressions.push({
          page: currentBaseline.label,
          path: currentBaseline.path,
          device: currentBaseline.device,
          category,
          previous: previousBaseline.scores[category],
          current: currentBaseline.scores[category],
        });
      }
    }

    const previousFailures = new Map((previousBaseline.failingAudits ?? [])
      .map((audit) => [`${audit.category}|${audit.id}`, audit]));
    for (const audit of currentBaseline.failingAudits ?? []) {
      const previousAudit = previousFailures.get(`${audit.category}|${audit.id}`);
      if (!previousAudit) {
        newAuditFailures.push({
          page: currentBaseline.label,
          path: currentBaseline.path,
          device: currentBaseline.device,
          category: audit.category,
          id: audit.id,
          title: audit.title,
        });
      } else if (
        Number.isFinite(previousAudit.score)
        && Number.isFinite(audit.score)
        && audit.score < previousAudit.score
      ) {
        auditScoreDrops.push({
          page: currentBaseline.label,
          path: currentBaseline.path,
          device: currentBaseline.device,
          category: audit.category,
          id: audit.id,
          title: audit.title,
          previous: previousAudit.score,
          current: audit.score,
          difference: audit.score - previousAudit.score,
        });
      }
    }

    for (const [key, definition] of Object.entries(metricDefinitions)) {
      const previous = previousBaseline.metrics?.[key];
      const current = currentBaseline.metrics?.[key];
      if (!Number.isFinite(previous) || !Number.isFinite(current)) {
        continue;
      }

      const difference = current - previous;
      if (difference > definition.threshold) {
        metricRegressions.push({
          page: currentBaseline.label,
          path: currentBaseline.path,
          device: currentBaseline.device,
          metric: definition.label,
          previous,
          current,
          difference,
          unit: definition.unit,
        });
      }
    }
  }

  return {
    categoryEvolution,
    detailedComparisonAvailable,
    pageScoreRegressions,
    pageScoreDrops,
    newAuditFailures,
    auditScoreDrops,
    metricRegressions,
    missingPreviousBaselines,
    hasRegression: categoryEvolution.some((item) => item.difference < 0)
      || pageScoreDrops.length > 0
      || pageScoreRegressions.length > 0
      || newAuditFailures.length > 0
      || auditScoreDrops.length > 0
      || metricRegressions.length > 0,
  };
};

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const scoreText = (value) => Number.isFinite(value) ? String(value) : '-';

const signedScore = (value) => {
  if (!Number.isFinite(value)) {
    return 'n/a';
  }

  return value > 0 ? `+${value}` : String(value);
};

const indicatorLabel = (indicator) => ({
  amelioration: 'amélioration',
  stable: 'stable',
  regression: 'régression',
  'non comparable': 'non comparable',
}[indicator] ?? indicator);

const htmlList = (items, renderer, emptyText) => {
  if (items.length === 0) {
    return `<p class="muted">${escapeHtml(emptyText)}</p>`;
  }

  return `<ul>${items.map((item) => `<li>${renderer(item)}</li>`).join('')}</ul>`;
};

const priorityOrder = { haute: 0, moyenne: 1, basse: 2 };

const buildAccessibilityIssueGroups = (results) => {
  const groups = new Map();

  for (const result of results) {
    for (const issue of result.accessibilityIssues ?? []) {
      const key = `${issue.priority}|${issue.template}|${issue.id}`;
      const group = groups.get(key) ?? {
        priority: issue.priority,
        template: issue.template,
        id: issue.id,
        title: issue.title,
        description: issue.description,
        occurrences: [],
      };

      group.occurrences.push({
        page: result.label,
        path: result.path,
        device: result.device,
        score: issue.score,
        displayValue: issue.displayValue,
        items: issue.items,
      });
      groups.set(key, group);
    }
  }

  return [...groups.values()].sort((first, second) => (
    priorityOrder[first.priority] - priorityOrder[second.priority]
    || first.template.localeCompare(second.template, 'fr')
    || first.id.localeCompare(second.id, 'fr')
  ));
};

const renderIssueTargets = (items) => {
  const nodes = items.flatMap((item) => [
    item.node,
    ...item.relatedNodes,
  ]).filter(Boolean);
  const targets = items.flatMap((item) => item.targets);

  if (nodes.length === 0 && targets.length === 0) {
    return '<p class="muted">Aucun élément précis fourni par Lighthouse.</p>';
  }

  return `<ul class="issue-targets">
    ${nodes.map((node) => `
      <li>
        ${node.selector ? `<div><strong>Sélecteur</strong> <code>${escapeHtml(node.selector)}</code></div>` : ''}
        ${node.snippet ? `<div><strong>Extrait</strong> <code>${escapeHtml(node.snippet)}</code></div>` : ''}
        ${node.label ? `<div><strong>Libellé</strong> ${escapeHtml(node.label)}</div>` : ''}
        ${node.explanation ? `<div><strong>Diagnostic</strong> ${escapeHtml(node.explanation)}</div>` : ''}
        ${node.boundingRect ? `<div><strong>Taille</strong> ${node.boundingRect.width} x ${node.boundingRect.height}px</div>` : ''}
      </li>
    `).join('')}
    ${[...new Set(targets)].map((target) => `<li><code>${escapeHtml(target)}</code></li>`).join('')}
  </ul>`;
};

const renderAccessibilityIssues = (groups) => {
  if (groups.length === 0) {
    return `
      <section>
        <h2>Problèmes d’accessibilité à corriger</h2>
        <p class="muted">Aucun audit d’accessibilité sous 100 n’a été détecté.</p>
      </section>
    `;
  }

  return `
    <section>
      <h2>Problèmes d’accessibilité à corriger</h2>
      ${groups.map((group) => `
        <article class="issue-group priority-${escapeHtml(group.priority)}">
          <h3>${escapeHtml(group.title)} <code>${escapeHtml(group.id)}</code></h3>
          <p><strong>Priorité :</strong> ${escapeHtml(group.priority)} · <strong>Template probable :</strong> ${escapeHtml(group.template)}</p>
          ${group.description ? `<p>${escapeHtml(group.description)}</p>` : ''}
          ${group.occurrences.map((occurrence) => `
            <div class="issue-occurrence">
              <p><strong>${escapeHtml(occurrence.page)}</strong> <code>${escapeHtml(occurrence.path)}</code> · ${escapeHtml(occurrence.device)} · score audit ${scoreText(occurrence.score)}${occurrence.displayValue ? ` · ${escapeHtml(occurrence.displayValue)}` : ''}</p>
              ${renderIssueTargets(occurrence.items)}
            </div>
          `).join('')}
        </article>
      `).join('')}
    </section>
  `;
};

const reportRelativeHref = (file) => path
  .relative(path.dirname(latestReportRelative), file)
  .split(path.sep)
  .join('/');

const buildReportHtml = ({
  entry,
  comparison,
  results,
  rawDirectoryRelative,
}) => {
  const accessibilityIssueGroups = buildAccessibilityIssueGroups(results);
  const evolutionHtml = comparison ? `
    <section>
      <h2>Évolution depuis le précédent audit</h2>
      <table>
        <thead>
          <tr>
            <th>Catégorie</th>
            <th>Minimum précédent</th>
            <th>Minimum actuel</th>
            <th>Différence</th>
            <th>Indicateur</th>
          </tr>
        </thead>
        <tbody>
          ${comparison.categoryEvolution.map((item) => `
            <tr>
              <td>${escapeHtml(categoryLabels[item.category])}</td>
              <td>${scoreText(item.previous)}</td>
              <td>${scoreText(item.current)}</td>
              <td>${signedScore(item.difference)}</td>
              <td><span class="badge ${escapeHtml(item.indicator)}">${escapeHtml(indicatorLabel(item.indicator))}</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>

      <h3>Nouvelles régressions détectées</h3>
      ${comparison.detailedComparisonAvailable ? `
        ${htmlList(comparison.pageScoreDrops, (item) => (
    `${escapeHtml(item.page)} (${escapeHtml(item.device)}, ${escapeHtml(categoryLabels[item.category])}) : ${item.previous} -> ${item.current} (${signedScore(item.difference)})`
  ), 'Aucune baisse de score par page et mode.')}
        ${htmlList(comparison.pageScoreRegressions, (item) => (
    `${escapeHtml(item.page)} (${escapeHtml(item.device)}, ${escapeHtml(categoryLabels[item.category])}) : ${item.previous} -> ${item.current}`
  ), 'Aucune page ni mode passé de 100 à moins de 100.')}
        ${htmlList(comparison.newAuditFailures, (item) => (
    `${escapeHtml(item.page)} (${escapeHtml(item.device)}) : ${escapeHtml(item.title)} (${escapeHtml(item.id)}, ${escapeHtml(categoryLabels[item.category])})`
  ), 'Aucun nouvel audit Lighthouse en échec.')}
        ${htmlList(comparison.auditScoreDrops, (item) => (
    `${escapeHtml(item.page)} (${escapeHtml(item.device)}) : ${escapeHtml(item.title)} (${escapeHtml(item.id)}) ${scoreText(item.previous)} -> ${scoreText(item.current)} (${signedScore(item.difference)})`
  ), 'Aucune baisse d’un audit Lighthouse déjà en échec.')}
        ${htmlList(comparison.metricRegressions, (item) => (
    `${escapeHtml(item.page)} (${escapeHtml(item.device)}) : ${escapeHtml(item.metric)} ${metricValue(item.previous, item.unit)} -> ${metricValue(item.current, item.unit)} (${metricDelta(item.difference, item.unit)})`
  ), 'Aucune dégradation notable de LCP, FCP, TBT ou CLS.')}
        ${comparison.missingPreviousBaselines.length > 0 ? `<p class="muted">Comparaison détaillée indisponible pour ${comparison.missingPreviousBaselines.length} page(s)/mode(s) absent(s) du rapport précédent.</p>` : ''}
      ` : '<p class="muted">Comparaison détaillée par page indisponible pour le rapport précédent.</p>'}
    </section>
  ` : '';

  const rawHtml = rawDirectoryRelative ? `
    <section>
      <h2>Rapports détaillés conservés</h2>
      <p><code>${escapeHtml(rawDirectoryRelative)}</code></p>
    </section>
  ` : '';

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rapport Lighthouse</title>
  <style>
    :root {
      color-scheme: light;
      --background: #f7f7f4;
      --surface: #ffffff;
      --text: #1f2933;
      --muted: #657180;
      --border: #d6dbe1;
      --good: #0f7b4f;
      --bad: #b42318;
      --neutral: #596579;
    }
    body {
      margin: 0;
      background: var(--background);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
      line-height: 1.5;
    }
    main {
      max-width: 1180px;
      margin: 0 auto;
      padding: 32px 20px 48px;
    }
    header,
    section {
      margin-bottom: 24px;
    }
    h1 {
      margin: 0 0 8px;
      font-size: 32px;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 22px;
    }
    h3 {
      margin: 18px 0 8px;
      font-size: 17px;
    }
    .meta,
    .muted {
      color: var(--muted);
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: var(--surface);
      border: 1px solid var(--border);
    }
    th,
    td {
      padding: 9px 10px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      vertical-align: top;
    }
    th {
      background: #eef1f4;
      font-size: 13px;
      text-transform: uppercase;
    }
    code {
      font-family: "SFMono-Regular", Consolas, monospace;
      font-size: 0.95em;
    }
    a {
      color: #0b5cab;
    }
    .badge {
      display: inline-block;
      min-width: 88px;
      padding: 2px 8px;
      border-radius: 999px;
      color: #ffffff;
      text-align: center;
      font-size: 12px;
    }
    .amelioration {
      background: var(--good);
    }
    .regression {
      background: var(--bad);
    }
    .stable,
    .non {
      background: var(--neutral);
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
    }
    .summary {
      padding: 14px;
      background: var(--surface);
      border: 1px solid var(--border);
    }
    .score {
      display: block;
      margin-top: 4px;
      font-size: 26px;
      font-weight: 700;
    }
    .issue-group {
      margin: 14px 0;
      padding: 14px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-left: 5px solid var(--neutral);
    }
    .issue-group h3 {
      margin-top: 0;
    }
    .priority-haute {
      border-left-color: var(--bad);
    }
    .priority-moyenne {
      border-left-color: #9a6700;
    }
    .priority-basse {
      border-left-color: var(--neutral);
    }
    .issue-occurrence {
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px solid var(--border);
    }
    .issue-targets {
      display: grid;
      gap: 8px;
      margin: 8px 0 0;
      padding-left: 20px;
    }
    .issue-targets code {
      white-space: normal;
      overflow-wrap: anywhere;
    }
  </style>
</head>
<body>
  <main>
    <header>
      <h1>Rapport Lighthouse</h1>
      <p class="meta">Campagne terminée le ${escapeHtml(entry.generatedAt)} - URL publique : <code>${escapeHtml(entry.publicBaseUrl)}</code> - Catalogue : <code>${escapeHtml(entry.catalog)}</code></p>
      <p class="meta">Rapport logique : <code>${escapeHtml(entry.reportPath)}</code></p>
    </header>

    <section>
      <h2>Scores minimums</h2>
      <div class="grid">
        ${categories.map((category) => `
          <div class="summary">
            ${escapeHtml(categoryLabels[category])}
            <span class="score">${scoreText(entry.scoreMinimums[category])}</span>
            <span class="muted">Moyenne ${scoreText(entry.scoreAverages[category])} - audits sous 100 : ${entry.auditsUnder100[category]}</span>
          </div>
        `).join('')}
      </div>
    </section>

    ${evolutionHtml}

    ${renderAccessibilityIssues(accessibilityIssueGroups)}

    <section>
      <h2>Page Performance la plus faible</h2>
      <p>${entry.lowestPerformance
    ? `${escapeHtml(entry.lowestPerformance.label)} - ${escapeHtml(entry.lowestPerformance.device)} - score ${scoreText(entry.lowestPerformance.score)} - <code>${escapeHtml(entry.lowestPerformance.path)}</code>`
    : 'Aucune page mesuree.'}</p>
    </section>

    ${rawHtml}

    <section>
      <h2>Audits par page et mode</h2>
      <table>
        <thead>
          <tr>
            <th>Page</th>
            <th>Mode</th>
            <th>Passage</th>
            <th>Performance</th>
            <th>Accessibilité</th>
            <th>Bonnes pratiques</th>
            <th>SEO</th>
            <th>Web Vitals</th>
            <th>Rapports détaillés</th>
          </tr>
        </thead>
        <tbody>
          ${results.map((result) => `
            <tr>
              <td>${escapeHtml(result.label)}<br><code>${escapeHtml(result.path)}</code></td>
              <td>${escapeHtml(result.device)}</td>
              <td>${result.run}</td>
              <td>${result.scores.performance}</td>
              <td>${result.scores.accessibility}</td>
              <td>${result.scores['best-practices']}</td>
              <td>${result.scores.seo}</td>
              <td>${Object.entries(metricDefinitions).map(([key, definition]) => `${definition.label} ${metricValue(result.metrics[key], definition.unit)}`).join('<br>')}</td>
              <td>${rawDirectoryRelative
    ? `<a href="${escapeHtml(reportRelativeHref(result.reports.html))}">HTML</a> - <a href="${escapeHtml(reportRelativeHref(result.reports.json))}">JSON</a>`
    : '<span class="muted">non conservé en mode normal</span>'}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </section>
  </main>
</body>
</html>
`;
};

const stageSuccessfulCampaign = async ({
  entry,
  comparison,
  historyEntries,
  results,
  workDirectory,
  rawDirectoryRelative,
}) => {
  await mkdir(lighthouseDirectory, { recursive: true });
  const stagedReport = path.join(workDirectory, 'latest-report.html');
  const stagedHistory = path.join(workDirectory, 'history.json');
  const nextHistory = {
    schemaVersion: 1,
    updatedAt: entry.generatedAt,
    maxEntries: 20,
    entries: [entry, ...historyEntries].slice(0, 20),
  };

  await writeFile(stagedReport, buildReportHtml({
    entry,
    comparison,
    results,
    rawDirectoryRelative,
  }), 'utf8');
  await writeFile(stagedHistory, `${JSON.stringify(nextHistory, null, 2)}\n`, 'utf8');

  const hadLatestReport = await fileExists(latestReportPath);
  if (hadLatestReport) {
    await copyFile(latestReportPath, previousReportPath);
  }
  await rename(stagedReport, latestReportPath);
  await rename(stagedHistory, historyPath);

  return {
    hadLatestReport,
    historyCount: nextHistory.entries.length,
  };
};

const cleanupNormalLighthouseDirectory = async () => {
  const keep = new Set([
    path.basename(latestReportPath),
    path.basename(previousReportPath),
    path.basename(historyPath),
  ]);

  try {
    const entries = await readdir(lighthouseDirectory);
    await Promise.all(entries
      .filter((entry) => !keep.has(entry))
      .map((entry) => rm(path.join(lighthouseDirectory, entry), { recursive: true, force: true })));
  } catch (error) {
    if (error?.code !== 'ENOENT') {
      throw error;
    }
  }
};

const printCampaignSummary = async ({
  entry,
  comparison,
  publishResult,
  rawDirectoryRelative,
}) => {
  console.log(`Audit Lighthouse terminé : ${entry.auditCount} audits.`);
  console.log('');
  console.log('Scores minimums :');
  console.log('');
  for (const category of categories) {
    console.log(`${categoryLabels[category]} : ${entry.scoreMinimums[category]}`);
  }
  console.log('');
  console.log('Rapport actuel :');
  console.log(entry.reportPath);
  console.log('');
  console.log('Rapport précédent :');
  console.log(publishResult.hadLatestReport
    ? previousReportRelative
    : 'aucun rapport précédent valide');
  console.log('');
  console.log('Historique :');
  console.log(historyRelative);
  console.log(`(${publishResult.historyCount} campagnes conservées sur 20 maximum)`);

  if (rawDirectoryRelative) {
    console.log('');
    console.log('Rapports détaillés conservés :');
    console.log(rawDirectoryRelative);
  }

  if (comparison?.hasRegression) {
    console.log('');
    console.log('Régression détectée depuis le précédent audit :');
    for (const item of comparison.categoryEvolution.filter((evolution) => evolution.difference < 0)) {
      console.log(`- ${categoryLabels[item.category]} : ${item.previous} -> ${item.current} (${signedScore(item.difference)})`);
    }
    if (!comparison.detailedComparisonAvailable) {
      console.log('- Comparaison détaillée par page indisponible pour le rapport précédent.');
    } else {
      for (const item of comparison.pageScoreDrops) {
        console.log(`- Baisse page/mode : ${item.page} (${item.device}, ${categoryLabels[item.category]}) ${item.previous} -> ${item.current} (${signedScore(item.difference)})`);
      }
      for (const item of comparison.pageScoreRegressions) {
        console.log(`- Passage sous 100 : ${item.page} (${item.device}, ${categoryLabels[item.category]}) ${item.previous} -> ${item.current}`);
      }
      for (const item of comparison.newAuditFailures) {
        console.log(`- Nouvel audit en échec : ${item.page} (${item.device}) - ${item.title} (${item.id})`);
      }
      for (const item of comparison.auditScoreDrops) {
        console.log(`- Audit déjà en échec dégradé : ${item.page} (${item.device}) - ${item.title} (${item.id}) ${scoreText(item.previous)} -> ${scoreText(item.current)} (${signedScore(item.difference)})`);
      }
      for (const item of comparison.metricRegressions) {
        console.log(`- ${item.metric} dégradé : ${item.page} (${item.device}) ${metricValue(item.previous, item.unit)} -> ${metricValue(item.current, item.unit)} (${metricDelta(item.difference, item.unit)})`);
      }
    }
  }
};

const announcePreservedLatestReport = async () => {
  if (await fileExists(latestReportPath)) {
    console.error(`Le dernier rapport valide est conservé : ${latestReportRelative}`);
  } else {
    console.error('Aucun rapport valide precedent n’existe encore.');
  }
  console.error('Le rapport précédent n’a pas été modifié.');
};

const describeAuditItem = (item) => {
  if (!item || typeof item !== 'object') {
    return String(item);
  }

  const parts = [];
  if (typeof item.url === 'string' && item.url !== '') {
    parts.push(item.url);
  }
  if (typeof item.source === 'string' && item.source !== '') {
    parts.push(item.source);
  }
  if (item.source && typeof item.source === 'object') {
    const sourceUrl = item.source.url || item.source.urlProvider || '';
    const line = Number.isInteger(item.source.line) ? item.source.line + 1 : null;
    const column = Number.isInteger(item.source.column) ? item.source.column + 1 : null;
    if (sourceUrl) {
      parts.push(`${sourceUrl}${line !== null ? `:${line}${column !== null ? `:${column}` : ''}` : ''}`);
    }
  }
  if (item.node?.snippet) {
    parts.push(item.node.snippet);
  }
  if (typeof item.value === 'string' && item.value !== '') {
    parts.push(item.value);
  }
  if (typeof item.description === 'string' && item.description !== '') {
    parts.push(item.description);
  }
  if (typeof item.reason === 'string' && item.reason !== '') {
    parts.push(item.reason);
  }
  if (typeof item.issueType === 'string' && item.issueType !== '') {
    parts.push(item.issueType);
  }

  return parts.length > 0
    ? parts.join(' — ')
    : JSON.stringify(item);
};

const extractCategoryIssues = (report, categoryId) => {
  const auditRefs = report.categories?.[categoryId]?.auditRefs ?? [];
  const issues = [];

  for (const ref of auditRefs) {
    const audit = report.audits?.[ref.id];
    if (!audit) {
      continue;
    }

    const warnings = Array.isArray(audit.warnings) ? audit.warnings.map(String) : [];
    const hasFailure = typeof audit.score === 'number' && audit.score < 1;
    if (!hasFailure && warnings.length === 0) {
      continue;
    }

    issues.push({
      id: ref.id,
      title: audit.title || ref.id,
      score: audit.score,
      scoreDisplayMode: audit.scoreDisplayMode || null,
      displayValue: audit.displayValue || null,
      warnings,
      details: Array.isArray(audit.details?.items)
        ? audit.details.items.map(describeAuditItem)
        : [],
    });
  }

  return issues;
};

const isIgnoredLocalAudit = (auditId, baseUrl) => productionOnlyAuditIds.has(auditId)
  || (baseUrl.includes('localhost') && localIndexabilityAuditIds.has(auditId));

const compactNode = (node) => {
  if (!node || typeof node !== 'object') {
    return null;
  }

  return {
    selector: compactText(node.selector, 500),
    snippet: compactText(node.snippet, 700),
    label: compactText(node.nodeLabel, 300),
    path: compactText(node.path, 500),
    explanation: compactText(node.explanation, 700),
    boundingRect: node.boundingRect && typeof node.boundingRect === 'object'
      ? {
        width: Math.round(Number(node.boundingRect.width) || 0),
        height: Math.round(Number(node.boundingRect.height) || 0),
      }
      : null,
  };
};

const compactAuditItem = (item) => {
  const node = compactNode(item?.node);
  const relatedNodes = Array.isArray(item?.subItems?.items)
    ? item.subItems.items
      .map((subItem) => compactNode(subItem.relatedNode))
      .filter(Boolean)
    : [];
  const directTargets = ['url', 'source', 'selector', 'snippet', 'description', 'reason', 'value']
    .map((key) => compactText(item?.[key], 700))
    .filter(Boolean);

  return {
    node,
    relatedNodes,
    targets: [...new Set(directTargets)],
  };
};

const issuePriority = (auditId) => {
  if (['color-contrast', 'label-content-name-mismatch', 'button-name', 'link-name', 'image-alt'].includes(auditId)) {
    return 'haute';
  }
  if (['link-in-text-block', 'target-size', 'label', 'aria-input-field-name'].includes(auditId)) {
    return 'moyenne';
  }

  return 'basse';
};

const issueTemplate = (page, issue) => {
  const selectors = issue.items
    .flatMap((item) => [
      item.node?.selector,
      item.node?.snippet,
      ...item.relatedNodes.flatMap((node) => [node.selector, node.snippet]),
      ...item.targets,
    ])
    .filter(Boolean)
    .join(' ');

  if (/comment-|login-callout|comments/.test(selectors)) {
    return 'Commentaires publics (templates/partials/_comment_*.html.twig, assets/styles/comments.css)';
  }
  if (/public-point-gps|public-route-gps|data-hike-map|public-route-map/.test(selectors)) {
    return 'Itinéraire public (templates/public_detail/_media_sections.html.twig, assets/styles/public-route-map.css)';
  }
  if (page.id === 'places-index' && issue.id === 'heading-order') {
    return 'Cartes de lieux (templates/place/index.html.twig, templates/partials/_place_card.html.twig)';
  }

  return `${page.label} (${page.path})`;
};

const extractAuditIssues = (report, category, baseUrl, page) => {
  const issues = [];

  for (const reference of report.categories?.[category]?.auditRefs ?? []) {
    const audit = report.audits?.[reference.id];
    if (!audit || isIgnoredLocalAudit(reference.id, baseUrl)) {
      continue;
    }
    if (audit.scoreDisplayMode === 'notApplicable' || audit.scoreDisplayMode === 'manual') {
      continue;
    }
    if (typeof audit.score !== 'number' || audit.score >= 1) {
      continue;
    }

    const issue = {
      category,
      id: reference.id,
      title: audit.title || reference.id,
      score: Math.round(audit.score * 100),
      rawScore: audit.score,
      displayValue: compactText(audit.displayValue, 300),
      description: compactText(audit.description, 900),
      priority: issuePriority(reference.id),
      items: Array.isArray(audit.details?.items)
        ? audit.details.items.map(compactAuditItem)
        : [],
    };

    issue.template = issueTemplate(page, issue);
    issues.push(issue);
  }

  return issues.sort((first, second) => (
    priorityOrder[first.priority] - priorityOrder[second.priority]
    || first.template.localeCompare(second.template, 'fr')
    || first.id.localeCompare(second.id, 'fr')
  ));
};

const extractFailingAudits = (report, baseUrl) => {
  const failures = new Map();

  for (const category of categories) {
    for (const reference of report.categories?.[category]?.auditRefs ?? []) {
      const audit = report.audits?.[reference.id];
      if (!audit || category === 'performance' || isIgnoredLocalAudit(reference.id, baseUrl)) {
        continue;
      }
      if (audit.scoreDisplayMode === 'notApplicable' || audit.scoreDisplayMode === 'manual') {
        continue;
      }
      if (typeof audit.score === 'number' && audit.score < 1) {
        failures.set(`${category}|${reference.id}`, {
          category,
          id: reference.id,
          title: audit.title || reference.id,
          score: audit.score,
        });
      }
    }
  }

  return [...failures.values()].sort((first, second) => (
    `${first.category}:${first.id}`.localeCompare(`${second.category}:${second.id}`)
  ));
};

const extractMetrics = (report) => Object.fromEntries(Object.entries(metricDefinitions).map(([key, definition]) => {
  const value = report.audits?.[definition.auditId]?.numericValue;
  return [key, Number.isFinite(value) ? value : null];
}));

const printBestPracticesIssues = (results) => {
  const affected = results.filter((result) => result.scores['best-practices'] < 100 || result.bestPracticesIssues.length > 0);

  if (affected.length === 0) {
    console.log('Best Practices < 100 : aucune page concernée.');
    return;
  }

  console.log('Best Practices < 100 :');
  for (const result of affected) {
    console.log(`- ${result.path} — ${result.device} — ${result.scores['best-practices']}`);
    if (result.bestPracticesIssues.length === 0) {
      console.log('  - Aucun audit pondéré en échec dans le rapport.');
      continue;
    }

    for (const issue of result.bestPracticesIssues) {
      const status = typeof issue.score === 'number' && issue.score < 1 ? 'échec' : 'avertissement';
      const display = issue.displayValue ? ` — ${issue.displayValue}` : '';
      console.log(`  - ${issue.title} (${issue.id}) : ${status}${display}`);
      for (const warning of issue.warnings) {
        console.log(`    - Avertissement : ${warning}`);
      }
      for (const detail of issue.details) {
        console.log(`    - ${detail}`);
      }
    }
  }
};

const runLighthouse = async ({ page, device, run, absoluteUrl, publicUrl, workDirectory, baseUrl }) => {
  const suffix = run > 1 ? `-run-${run}` : '';
  const outputBase = path.join(workDirectory, `${page.id}-${device}${suffix}`);
  const chromePath = process.env.CHROME_PATH || '/usr/bin/chromium';
  const argumentsList = [
    absoluteUrl,
    `--chrome-path=${chromePath}`,
    '--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu',
    '--only-categories=performance,accessibility,best-practices,seo',
    '--output=html',
    '--output=json',
    `--output-path=${outputBase}`,
    '--quiet',
  ];

  if (device === 'desktop') {
    argumentsList.push('--preset=desktop');
  }

  const exitCode = await new Promise((resolve, reject) => {
    const child = spawn('lighthouse', argumentsList, { env: process.env, stdio: 'inherit' });
    child.once('error', (error) => reject(new Error(`Impossible de lancer Lighthouse : ${error.message}`)));
    child.once('close', resolve);
  });

  if (exitCode !== 0) {
    throw new Error(`Lighthouse a échoué pour ${page.id} (${device}), code ${exitCode}.`);
  }

  const htmlReport = await normalizeReport(outputBase, 'html');
  const jsonReport = await normalizeReport(outputBase, 'json');
  const report = JSON.parse(await readFile(jsonReport, 'utf8'));
  const scores = {};

  for (const category of categories) {
    const score = report.categories?.[category]?.score;
    if (typeof score !== 'number') {
      throw new Error(`La catégorie Lighthouse "${category}" manque pour ${page.id}.`);
    }
    scores[category] = Math.round(score * 100);
  }
  const bestPracticesIssues = extractCategoryIssues(report, 'best-practices');
  const failingAudits = extractFailingAudits(report, baseUrl);
  const accessibilityIssues = extractAuditIssues(report, 'accessibility', baseUrl, page);
  const metrics = extractMetrics(report);

  return {
    id: page.id,
    label: page.label,
    type: page.type,
    path: page.path,
    url: publicUrl,
    auditedUrl: absoluteUrl,
    device,
    run,
    scores,
    reports: {
      html: path.relative(projectDirectory, htmlReport),
      json: path.relative(projectDirectory, jsonReport),
    },
    bestPracticesIssues,
    failingAudits,
    accessibilityIssues,
    metrics,
  };
};

const main = async () => {
  const options = parseOptions();
  let proxy = null;
  let workDirectory = null;
  let pages = await loadCatalog();

  if (options.pageIds.length > 0) {
    const requested = new Set(options.pageIds);
    pages = pages.filter((page) => requested.has(page.id));
    const missing = options.pageIds.filter((id) => !pages.some((page) => page.id === id));
    if (missing.length > 0) {
      throw new Error(`Identifiant(s) absent(s) du catalogue : ${missing.join(', ')}.`);
    }
  }
  if (options.maxPages > 0) {
    pages = pages.slice(0, options.maxPages);
  }

  try {
    if (options.auditBaseUrl !== options.baseUrl) {
      console.log(`Instance publique Lighthouse : ${options.baseUrl}`);
      console.log(`Cible interne Docker : ${options.auditBaseUrl}`);
      proxy = await startLocalhostProxy(options.baseUrl, options.auditBaseUrl);
      console.log(`Proxy local Lighthouse : ${options.baseUrl} -> ${options.auditBaseUrl}`);
    }

    await assertDedicatedEnvironment(options.baseUrl);
    for (const page of pages) {
      await fetchChecked(`${options.baseUrl}${page.path}`, `La page fixture "${page.label}"`);
    }

    const historyEntries = await readHistory();
    const generatedAt = new Date().toISOString();
    const timestamp = generatedAt.replace(/\.\d{3}Z$/, '').replaceAll(':', '-');
    const workRoot = options.keepRaw
      ? path.join(lighthouseDirectory, 'raw')
      : path.join(lighthouseDirectory, 'tmp');
    workDirectory = path.join(workRoot, timestamp);
    await mkdir(workRoot, { recursive: true });
    await mkdir(workDirectory, { recursive: false });
    const rawDirectoryRelative = options.keepRaw
      ? path.relative(projectDirectory, workDirectory).split(path.sep).join('/')
      : null;

    const results = [];
    const total = pages.length * options.devices.length * options.runs;
    let progress = 0;

    for (const page of pages) {
      const absoluteUrl = `${options.baseUrl}${page.path}`;
      const publicUrl = `${options.baseUrl}${page.path}`;
      for (const device of options.devices) {
        for (let run = 1; run <= options.runs; run += 1) {
          progress += 1;
          console.log(`[${progress}/${total}] ${page.label} — ${device} — passage ${run}/${options.runs}`);
          const result = await runLighthouse({
            page,
            device,
            run,
            absoluteUrl,
            publicUrl,
            workDirectory,
            baseUrl: options.baseUrl,
          });
          results.push(result);
          console.log(`  Performance ${result.scores.performance} · Accessibilité ${result.scores.accessibility} · Bonnes pratiques ${result.scores['best-practices']} · SEO ${result.scores.seo}`);
          if (options.keepRaw) {
            console.log(`  HTML ${result.reports.html}`);
            console.log(`  JSON ${result.reports.json}`);
          }
        }
      }
    }

    const baselines = buildPageDeviceBaselines(results);
    const entry = buildHistoryEntry({
      generatedAt,
      options,
      pages,
      results,
      baselines,
      rawDirectoryRelative,
    });
    const comparison = compareCampaigns(historyEntries[0], entry);
    const publishResult = await stageSuccessfulCampaign({
      entry,
      comparison,
      historyEntries,
      results,
      workDirectory,
      rawDirectoryRelative,
    });

    if (!options.keepRaw) {
      await cleanupNormalLighthouseDirectory();
      workDirectory = null;
    }

    await printCampaignSummary({
      entry,
      comparison,
      publishResult,
      rawDirectoryRelative,
    });
  } finally {
    if (proxy) {
      await proxy.close();
    }
    if (!options.keepRaw && workDirectory) {
      await rm(workDirectory, { recursive: true, force: true });
    }
  }
};

main().catch(async (error) => {
  console.error(`Erreur Lighthouse : ${error.message}`);
  await announcePreservedLatestReport();
  process.exitCode = 1;
});
