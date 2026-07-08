import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const [campaignArgument] = process.argv.slice(2);
if (!campaignArgument) {
  console.error('Usage: node tools/lighthouse/analyze-reports.mjs <campaign-directory>');
  process.exit(2);
}

const campaignDirectory = path.resolve(campaignArgument);
const categories = ['performance', 'accessibility', 'best-practices', 'seo'];
const categoryLabels = {
  performance: 'Performance',
  accessibility: 'Accessibility',
  'best-practices': 'Best Practices',
  seo: 'SEO',
};
const devices = ['mobile', 'desktop'];
const productionOnlyAuditIds = new Set([
  'is-on-https',
  'redirects-http',
  'has-hsts',
]);
const localIndexabilityAuditIds = new Set([
  'is-crawlable',
  'robots-txt',
]);

const readJson = async (file) => JSON.parse(await readFile(file, 'utf8'));

const parseKeyValueFile = (contents) => Object.fromEntries(
  contents.split(/\r?\n/)
    .filter(Boolean)
    .map((line) => {
      const separator = line.indexOf('=');
      return separator === -1 ? [line, ''] : [line.slice(0, separator), line.slice(separator + 1)];
    }),
);

const parseTsv = (contents) => {
  const lines = contents.split(/\r?\n/).filter(Boolean);
  if (lines.length === 0) return [];
  const headers = lines[0].split('\t');
  return lines.slice(1).map((line) => Object.fromEntries(
    headers.map((header, index) => [header, line.split('\t')[index] ?? '']),
  ));
};

const median = (values) => {
  if (values.length === 0) return null;
  const sorted = [...values].sort((first, second) => first - second);
  const middle = Math.floor(sorted.length / 2);
  const value = sorted.length % 2 === 0
    ? (sorted[middle - 1] + sorted[middle]) / 2
    : sorted[middle];
  return Math.round(value * 10) / 10;
};

const mean = (values) => values.length === 0
  ? null
  : Math.round((values.reduce((sum, value) => sum + value, 0) / values.length) * 10) / 10;

const score = (report, category) => Math.round((report.categories?.[category]?.score ?? 0) * 100);

const summarizeValues = (values) => values.length === 0 ? null : {
  min: Math.min(...values),
  median: median(values),
  mean: mean(values),
};

const medianSavings = (values) => median(values.filter((value) => Number.isFinite(value))) ?? 0;

const sumItemMetric = (items, key) => {
  if (!Array.isArray(items)) return 0;
  return items.reduce((total, item) => total + (Number(item?.[key]) || 0), 0);
};

const auditSavings = (audit) => {
  const details = audit?.details ?? {};
  let milliseconds = Number(details.overallSavingsMs) || 0;
  let bytes = Number(details.overallSavingsBytes) || 0;

  if (milliseconds === 0) milliseconds = sumItemMetric(details.items, 'wastedMs');
  if (bytes === 0) bytes = sumItemMetric(details.items, 'wastedBytes');

  if (milliseconds === 0 && details.metricSavings && typeof details.metricSavings === 'object') {
    milliseconds = Math.max(
      0,
      ...Object.values(details.metricSavings).map((value) => Number(value) || 0),
    );
  }

  return { milliseconds, bytes };
};

const collectTargets = (value, targets, depth = 0) => {
  if (targets.size >= 12 || depth > 5 || value === null || value === undefined) return;
  if (Array.isArray(value)) {
    for (const item of value) collectTargets(item, targets, depth + 1);
    return;
  }
  if (typeof value !== 'object') return;

  for (const [key, child] of Object.entries(value)) {
    if (typeof child === 'string' && ['url', 'selector', 'snippet', 'source'].includes(key) && child.trim() !== '') {
      targets.add(child.trim());
    } else {
      collectTargets(child, targets, depth + 1);
    }
  }
};

const recommendationFor = (id) => {
  const recommendations = {
    'errors-in-console': 'Corriger l’erreur JavaScript ou réseau à sa source, puis rejouer la page avec la console vide.',
    'image-delivery-insight': 'Vérifier le srcset et la variante réellement choisie sans réduire la qualité visuelle ni la couverture LCP.',
    'uses-responsive-images': 'Servir une variante dimensionnée au rendu réel et conserver une densité adaptée aux écrans Retina.',
    'modern-image-formats': 'Comparer le gain réel avec les variantes WebP existantes avant toute conversion supplémentaire.',
    'offscreen-images': 'Différer uniquement les images réellement hors écran, sans appliquer lazy-loading à l’image LCP.',
    'unused-javascript': 'Découper le bundle par fonctionnalité après vérification des interactions clavier, mobile et sans JavaScript.',
    'unused-css-rules': 'Retirer ou scinder les styles uniquement après une validation visuelle sur tous les gabarits concernés.',
    'render-blocking-resources': 'Réduire les ressources bloquantes mesurées sans dupliquer ni injecter inutilement les styles critiques.',
    'render-blocking-insight': 'Réduire les ressources bloquantes mesurées sans dupliquer ni injecter inutilement les styles critiques.',
    'color-contrast': 'Ajuster les couleurs des éléments signalés et valider les états hover, focus et disabled.',
    'image-alt': 'Ajouter un texte alternatif utile ou marquer l’image comme décorative selon son rôle réel.',
    'button-name': 'Donner un nom accessible stable au bouton sans modifier son libellé visuel si celui-ci est déjà pertinent.',
    'link-name': 'Donner au lien un nom accessible décrivant sa destination.',
    'aria-input-field-name': 'Donner un nom accessible au composant listbox, cohérent avec le champ de recherche qu’il complète.',
    'label-content-name-mismatch': 'Aligner le nom accessible sur le texte visible afin de préserver la commande vocale.',
    'target-size': 'Agrandir la zone cliquable ou son espacement sans densifier excessivement la navigation mobile.',
    'document-title': 'Ajouter un titre de document unique et cohérent avec le contenu canonique.',
    'meta-description': 'Ajouter une méta-description spécifique à la page publiée.',
  };

  return recommendations[id]
    ?? 'Inspecter les ressources ou éléments signalés, corriger la cause commune, puis mesurer à nouveau avant généralisation.';
};

const riskFor = (id) => {
  if (/image|offscreen|responsive|render-blocking|unused-(?:css|javascript)/.test(id)) return 'moyen';
  if (/third-party|csp|trusted-types|inspector|console/.test(id)) return 'moyen';
  return 'faible';
};

const priorityFor = (category, id, savingsMs, savingsBytes) => {
  if (category === 'accessibility' || id === 'errors-in-console') return 'haute';
  if (category === 'best-practices' || category === 'seo') return 'haute';
  if (savingsMs >= 500 || savingsBytes >= 500000) return 'haute';
  if (savingsMs >= 100 || savingsBytes >= 100000) return 'moyenne';
  return 'basse';
};

const formatBytes = (bytes) => {
  if (!bytes) return '—';
  if (bytes >= 1000000) return `${Math.round(bytes / 100000) / 10} Mo`;
  return `${Math.round(bytes / 1000)} Ko`;
};

const formatMs = (milliseconds) => milliseconds ? `${Math.round(milliseconds)} ms` : '—';

const csvValue = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;

const relativeLink = (file) => file ? file.split(path.sep).join('/') : null;

const urlsPayload = await readJson(path.join(campaignDirectory, 'urls.json'));
const campaignMetadata = parseKeyValueFile(await readFile(path.join(campaignDirectory, 'campaign.env'), 'utf8'));
const attempts = parseTsv(await readFile(path.join(campaignDirectory, 'attempts.tsv'), 'utf8'));
const urlEntries = Array.isArray(urlsPayload.urls) ? urlsPayload.urls : [];
const requestedDevices = (campaignMetadata.devices || '').split(',').filter((device) => devices.includes(device));
const requestedRuns = Number.parseInt(campaignMetadata.runs || '1', 10);

const attemptsByPage = new Map();
for (const attempt of attempts) {
  const key = `${attempt.id}|${attempt.device}`;
  if (!attemptsByPage.has(key)) attemptsByPage.set(key, []);
  attemptsByPage.get(key).push(attempt);
}

const reports = [];
const errors = [];
for (const attempt of attempts) {
  if (attempt.status !== 'ok') {
    let message = 'Échec Lighthouse sans journal disponible.';
    try {
      const lines = (await readFile(path.join(campaignDirectory, attempt.logPath), 'utf8'))
        .split(/\r?\n/).filter(Boolean);
      message = lines.slice(-8).join('\n').slice(0, 2000) || message;
    } catch {
      // Le chemin du journal reste présent dans le rapport d’erreur.
    }
    errors.push({
      id: attempt.id,
      type: attempt.type,
      title: attempt.title,
      url: attempt.url,
      absoluteUrl: attempt.absoluteUrl,
      device: attempt.device,
      run: Number(attempt.run),
      exitCode: Number(attempt.exitCode),
      command: attempt.command,
      logPath: attempt.logPath,
      message,
    });
    continue;
  }

  try {
    const report = await readJson(path.join(campaignDirectory, attempt.jsonPath));
    reports.push({ attempt, report });
  } catch (error) {
    errors.push({
      id: attempt.id,
      type: attempt.type,
      title: attempt.title,
      url: attempt.url,
      absoluteUrl: attempt.absoluteUrl,
      device: attempt.device,
      run: Number(attempt.run),
      exitCode: Number(attempt.exitCode) || 1,
      command: attempt.command,
      logPath: attempt.logPath,
      message: `Rapport JSON illisible : ${error.message}`,
    });
  }
}

const pageSummaries = urlEntries.map((entry) => {
  const page = {
    id: entry.id,
    type: entry.type,
    title: entry.title,
    url: entry.url,
    status: 'OK',
  };

  for (const device of requestedDevices) {
    const pageAttempts = attemptsByPage.get(`${entry.id}|${device}`) ?? [];
    const deviceReports = reports
      .filter(({ attempt }) => attempt.id === entry.id && attempt.device === device)
      .sort((first, second) => Number(first.attempt.run) - Number(second.attempt.run));
    const runDetails = deviceReports.map(({ attempt, report }) => ({
      run: Number(attempt.run),
      scores: Object.fromEntries(categories.map((category) => [category, score(report, category)])),
      htmlReport: relativeLink(attempt.htmlPath),
      jsonReport: relativeLink(attempt.jsonPath),
    }));
    const deviceErrors = errors.filter((error) => error.id === entry.id && error.device === device);
    const medianScores = Object.fromEntries(categories.map((category) => [
      category,
      median(runDetails.map((run) => run.scores[category])),
    ]));
    const variation = Object.fromEntries(categories.map((category) => {
      const values = runDetails.map((run) => run.scores[category]);
      return [category, values.length < 2 ? 0 : Math.max(...values) - Math.min(...values)];
    }));
    const stronglyVariable = Object.values(variation).some((range) => range >= 10);
    const actionableAuditFailures = new Set();
    for (const { report } of deviceReports) {
      for (const category of ['accessibility', 'best-practices', 'seo']) {
        for (const reference of report.categories?.[category]?.auditRefs ?? []) {
          const audit = report.audits?.[reference.id];
          const ignoredLocally = productionOnlyAuditIds.has(reference.id)
            || (campaignMetadata.baseUrl?.includes('localhost') && localIndexabilityAuditIds.has(reference.id));
          if (!ignoredLocally && typeof audit?.score === 'number' && audit.score < 1) {
            actionableAuditFailures.add(reference.id);
          }
        }
      }
    }

    page[device] = {
      scores: medianScores,
      runs: runDetails,
      errors: deviceErrors,
      variation,
      stronglyVariable,
      actionableAuditFailures: [...actionableAuditFailures].sort(),
      completedRuns: runDetails.length,
      expectedRuns: requestedRuns,
    };

    if (deviceErrors.length > 0 || runDetails.length !== requestedRuns || pageAttempts.length !== requestedRuns) {
      page.status = 'erreur';
    } else if (stronglyVariable
      || (medianScores.performance !== null && medianScores.performance < 90)
      || actionableAuditFailures.size > 0
    ) {
      if (page.status !== 'erreur') page.status = 'attention';
    }
  }

  return page;
});

const categoryByAudit = (report) => {
  const mapping = new Map();
  for (const category of categories) {
    for (const reference of report.categories?.[category]?.auditRefs ?? []) {
      if (!mapping.has(reference.id) || reference.weight > 0) mapping.set(reference.id, category);
    }
  }
  return mapping;
};

const evolutionGroups = new Map();
const productionChecks = new Map();

for (const { attempt, report } of reports) {
  const auditCategories = categoryByAudit(report);
  for (const [id, audit] of Object.entries(report.audits ?? {})) {
    const category = auditCategories.get(id);
    if (!category || audit.scoreDisplayMode === 'notApplicable' || audit.scoreDisplayMode === 'manual') continue;

    const failed = typeof audit.score === 'number' && audit.score < 1;
    const savings = auditSavings(audit);
    const localOnly = productionOnlyAuditIds.has(id)
      || (campaignMetadata.baseUrl?.includes('localhost') && localIndexabilityAuditIds.has(id));

    if (localOnly && failed) {
      const key = id;
      const existing = productionChecks.get(key) ?? {
        id,
        title: audit.title,
        devices: new Set(),
        pages: new Set(),
        reason: productionOnlyAuditIds.has(id)
          ? 'Le contexte HTTP local ne permet pas de qualifier la configuration HTTPS de production.'
          : 'L’indexabilité locale peut différer de la préproduction ou de la production.',
      };
      existing.devices.add(attempt.device);
      existing.pages.add(attempt.url);
      productionChecks.set(key, existing);
      continue;
    }

    const measurable = savings.milliseconds >= 50 || savings.bytes >= 10000;
    const actionableFailure = failed && ['accessibility', 'best-practices', 'seo'].includes(category);
    const consoleError = id === 'errors-in-console' && failed;
    if (!measurable && !actionableFailure && !consoleError) continue;

    const key = `${id}|${category}`;
    const group = evolutionGroups.get(key) ?? {
      id,
      title: audit.title,
      category,
      observations: new Map(),
      pages: new Map(),
      devices: new Set(),
      targets: new Set(),
    };
    const observationKey = `${attempt.id}|${attempt.device}`;
    const observation = group.observations.get(observationKey) ?? { milliseconds: [], bytes: [] };
    observation.milliseconds.push(savings.milliseconds);
    observation.bytes.push(savings.bytes);
    group.observations.set(observationKey, observation);
    group.pages.set(attempt.url, attempt.title);
    group.devices.add(attempt.device);
    collectTargets(audit.details, group.targets);
    evolutionGroups.set(key, group);
  }
}

const evolutionPoints = [...evolutionGroups.values()].map((group) => {
  let savingsMs = 0;
  let savingsBytes = 0;
  for (const observation of group.observations.values()) {
    savingsMs += medianSavings(observation.milliseconds);
    savingsBytes += medianSavings(observation.bytes);
  }

  return {
    id: group.id,
    title: group.title,
    category: group.category,
    pages: [...group.pages.entries()].map(([url, title]) => ({ url, title })),
    devices: [...group.devices].sort(),
    estimatedSavingsMs: Math.round(savingsMs),
    estimatedSavingsBytes: Math.round(savingsBytes),
    targets: [...group.targets],
    priority: priorityFor(group.category, group.id, savingsMs, savingsBytes),
    regressionRisk: riskFor(group.id),
    recommendation: recommendationFor(group.id),
  };
}).sort((first, second) => {
  const order = { haute: 0, moyenne: 1, basse: 2 };
  return order[first.priority] - order[second.priority]
    || second.estimatedSavingsMs - first.estimatedSavingsMs
    || second.estimatedSavingsBytes - first.estimatedSavingsBytes
    || first.title.localeCompare(second.title, 'fr');
});

const productionItems = [...productionChecks.values()].map((item) => ({
  id: item.id,
  title: item.title,
  devices: [...item.devices].sort(),
  pages: [...item.pages].sort(),
  reason: item.reason,
}));

if (campaignMetadata.baseUrl?.startsWith('http://localhost')) {
  productionItems.push({
    id: 'production-https-context',
    title: 'HTTPS, redirection HTTP et HSTS',
    devices: requestedDevices,
    pages: [],
    reason: 'Les audits localhost ne valident pas la terminaison TLS, les redirections ni HSTS en préproduction ou production.',
  });
}

const globalScores = Object.fromEntries(requestedDevices.map((device) => [
  device,
  Object.fromEntries(categories.map((category) => [
    category,
    summarizeValues(pageSummaries
      .map((page) => page[device]?.scores?.[category])
      .filter((value) => typeof value === 'number')),
  ])),
]));

const errorPageIds = new Set(errors.map((error) => error.id));
const successfulPages = pageSummaries.filter((page) => page.status !== 'erreur').length;
const summary = {
  schemaVersion: 1,
  generatedAt: new Date().toISOString(),
  campaign: {
    baseUrl: campaignMetadata.baseUrl,
    devices: requestedDevices,
    runs: requestedRuns,
    plannedUrls: urlEntries.length,
    successfulUrls: successfulPages,
    errorUrls: errorPageIds.size,
    successfulAudits: reports.length,
    errorAudits: errors.length,
  },
  scores: globalScores,
  pages: pageSummaries,
  evolutionPoints,
  productionChecks: productionItems,
  errors,
};

await writeFile(
  path.join(campaignDirectory, 'summary.json'),
  `${JSON.stringify(summary, null, 2)}\n`,
  'utf8',
);

const csvHeaders = [
  'type', 'title', 'url', 'status',
  ...devices.flatMap((device) => categories.map((category) => `${device}_${category}`)),
  'mobile_html', 'desktop_html', 'errors',
];
const csvRows = pageSummaries.map((page) => {
  const values = [page.type, page.title, page.url, page.status];
  for (const device of devices) {
    for (const category of categories) values.push(page[device]?.scores?.[category] ?? '');
  }
  for (const device of devices) {
    values.push((page[device]?.runs ?? []).map((run) => run.htmlReport).join(' | '));
  }
  values.push(requestedDevices.flatMap((device) => page[device]?.errors ?? []).map((error) => error.message).join(' | '));
  return values.map(csvValue).join(',');
});
await writeFile(
  path.join(campaignDirectory, 'summary.csv'),
  `${csvHeaders.map(csvValue).join(',')}\n${csvRows.join('\n')}\n`,
  'utf8',
);

const scoreCell = (deviceSummary) => categories
  .map((category) => deviceSummary?.scores?.[category] ?? '—')
  .join(' / ');
const reportLinks = (deviceSummary, prefix) => (deviceSummary?.runs ?? [])
  .map((run) => `[${prefix}${run.run}](${run.htmlReport})`)
  .join(' ')
  || '—';

const markdown = [];
markdown.push('# Rapport Lighthouse global', '');
markdown.push(`- Campagne : \`${campaignMetadata.createdAt ?? ''}\``);
markdown.push(`- URL de base : \`${campaignMetadata.baseUrl ?? ''}\``);
markdown.push(`- URLs prévues : **${urlEntries.length}**`);
markdown.push(`- URLs réussies : **${successfulPages}**`);
markdown.push(`- URLs en erreur : **${errorPageIds.size}**`);
markdown.push(`- Audits réussis : **${reports.length}**`);
markdown.push(`- Audits en erreur : **${errors.length}**`);
markdown.push(`- Appareils : **${requestedDevices.join(', ')}** — passages par page : **${requestedRuns}**`, '');

markdown.push('## Scores globaux', '');
markdown.push('| Appareil | Catégorie | Minimum | Médiane | Moyenne |', '|---|---|---:|---:|---:|');
for (const device of requestedDevices) {
  for (const category of categories) {
    const values = globalScores[device]?.[category];
    markdown.push(`| ${device} | ${categoryLabels[category]} | ${values?.min ?? '—'} | ${values?.median ?? '—'} | ${values?.mean ?? '—'} |`);
  }
}

markdown.push('', '## Pages', '');
markdown.push('| Type | Titre | URL | Mobile P/A/BP/SEO | Desktop P/A/BP/SEO | Rapports | Statut |');
markdown.push('|---|---|---|---:|---:|---|---|');
for (const page of pageSummaries) {
  const links = [reportLinks(page.mobile, 'M'), reportLinks(page.desktop, 'D')].join('<br>');
  markdown.push(`| ${page.type} | ${page.title.replaceAll('|', '\\|')} | \`${page.url}\` | ${scoreCell(page.mobile)} | ${scoreCell(page.desktop)} | ${links} | ${page.status} |`);
}

markdown.push('', '## Points d’évolution utiles', '');
if (evolutionPoints.length === 0) {
  markdown.push('Aucun point mesurable et actionnable n’a été extrait des rapports.', '');
} else {
  for (const point of evolutionPoints) {
    markdown.push(`### ${point.title} (\`${point.id}\`)`, '');
    markdown.push(`- Priorité : **${point.priority}**`);
    markdown.push(`- Catégorie : ${categoryLabels[point.category] ?? point.category}`);
    markdown.push(`- Risque de régression : **${point.regressionRisk}**`);
    markdown.push(`- Appareils : ${point.devices.join(', ')}`);
    markdown.push(`- Pages : ${point.pages.map((page) => `${page.title} (\`${page.url}\`)`).join(', ')}`);
    markdown.push(`- Gain cumulé estimé : ${formatMs(point.estimatedSavingsMs)} / ${formatBytes(point.estimatedSavingsBytes)}`);
    if (point.targets.length > 0) markdown.push(`- Ressources ou éléments : ${point.targets.map((target) => `\`${target}\``).join(', ')}`);
    markdown.push(`- Recommandation : ${point.recommendation}`, '');
  }
}

markdown.push('## Variabilité', '');
const variablePages = pageSummaries.flatMap((page) => requestedDevices
  .filter((device) => page[device]?.stronglyVariable)
  .map((device) => ({ page, device, variation: page[device].variation })));
if (variablePages.length === 0) {
  markdown.push('Aucune variation forte détectée (écart inférieur à 10 points).', '');
} else {
  for (const item of variablePages) {
    markdown.push(`- ${item.page.title} — ${item.device} : ${JSON.stringify(item.variation)}`);
  }
  markdown.push('');
}

markdown.push('## À vérifier en préproduction / production HTTPS', '');
for (const item of productionItems) {
  const pages = item.pages.length > 0 ? ` Pages : ${item.pages.join(', ')}.` : '';
  markdown.push(`- **${item.title}** (\`${item.id}\`) : ${item.reason}${pages}`);
}
markdown.push('');

markdown.push('## Erreurs de campagne', '');
if (errors.length === 0) {
  markdown.push('Aucune erreur Lighthouse.', '');
} else {
  for (const error of errors) {
    markdown.push(`### ${error.title} — ${error.device} — passage ${error.run}`, '');
    markdown.push(`- URL : \`${error.absoluteUrl}\``);
    markdown.push(`- Code de sortie : **${error.exitCode}**`);
    markdown.push(`- Commande : \`${error.command.replaceAll('`', '\\`')}\``);
    markdown.push(`- Journal : [${error.logPath}](${error.logPath})`);
    markdown.push('', '```text', error.message, '```', '');
  }
}

markdown.push('## Méthode et limites', '');
markdown.push('- Les scores multi-passages sont des médianes par catégorie, jamais des moyennes naïves.');
markdown.push('- Les gains sont regroupés par audit et additionnés après calcul de la médiane par page et appareil.');
markdown.push('- Les audits HTTPS et l’indexabilité propres à l’environnement local sont séparés des régressions applicatives.');
markdown.push('- Toute optimisation d’image, de LCP ou de chargement différé doit être validée visuellement avant application.', '');

await writeFile(path.join(campaignDirectory, 'summary.md'), `${markdown.join('\n')}\n`, 'utf8');

console.log(`Synthèse JSON : ${path.join(campaignDirectory, 'summary.json')}`);
console.log(`Synthèse CSV : ${path.join(campaignDirectory, 'summary.csv')}`);
console.log(`Synthèse Markdown : ${path.join(campaignDirectory, 'summary.md')}`);
