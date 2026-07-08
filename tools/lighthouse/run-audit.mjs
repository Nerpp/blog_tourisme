import { spawn } from 'node:child_process';
import { access, constants, mkdir, readFile, rename } from 'node:fs/promises';
import path from 'node:path';

const [device, target, requestedOutputBase] = process.argv.slice(2);
const supportedDevices = new Set(['mobile', 'desktop']);

if (!supportedDevices.has(device) || !target || !requestedOutputBase) {
  console.error('Usage: node tools/lighthouse/run-audit.mjs <mobile|desktop> <url> <output-base>');
  process.exit(2);
}

let targetUrl;
try {
  targetUrl = new URL(target);
} catch {
  console.error(`URL Lighthouse invalide : ${target}`);
  process.exit(2);
}

if (!['http:', 'https:'].includes(targetUrl.protocol)) {
  console.error('Lighthouse accepte uniquement une URL HTTP ou HTTPS.');
  process.exit(2);
}

const outputBase = path.resolve(requestedOutputBase);
const htmlReport = `${outputBase}.html`;
const jsonReport = `${outputBase}.json`;
const chromePath = process.env.CHROME_PATH || '/usr/bin/chromium';

await mkdir(path.dirname(outputBase), { recursive: true });

for (const reportPath of [htmlReport, jsonReport]) {
  try {
    await access(reportPath, constants.F_OK);
    console.error(`Le rapport existe déjà : ${reportPath}`);
    process.exit(2);
  } catch (error) {
    if (error?.code !== 'ENOENT') {
      throw error;
    }
  }
}

const lighthouseArguments = [
  targetUrl.toString(),
  `--chrome-path=${chromePath}`,
  '--chrome-flags=--headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu',
  '--only-categories=performance,accessibility,best-practices,seo',
  '--output=html',
  '--output=json',
  `--output-path=${outputBase}`,
];

if (device === 'desktop') {
  lighthouseArguments.push('--preset=desktop');
}

const exitCode = await new Promise((resolve, reject) => {
  const child = spawn('lighthouse', lighthouseArguments, {
    env: process.env,
    stdio: 'inherit',
  });

  child.once('error', reject);
  child.once('close', resolve);
});

if (exitCode !== 0) {
  process.exit(typeof exitCode === 'number' ? exitCode : 1);
}

const normalizeReport = async (extension, destination) => {
  for (const candidate of [`${outputBase}.report.${extension}`, `${outputBase}.${extension}`]) {
    try {
      await access(candidate, constants.F_OK);
      if (candidate !== destination) {
        await rename(candidate, destination);
      }

      return;
    } catch (error) {
      if (error?.code !== 'ENOENT') {
        throw error;
      }
    }
  }

  throw new Error(`Rapport Lighthouse ${extension.toUpperCase()} introuvable.`);
};

await normalizeReport('html', htmlReport);
await normalizeReport('json', jsonReport);

const report = JSON.parse(await readFile(jsonReport, 'utf8'));
const categories = ['performance', 'accessibility', 'best-practices', 'seo'];
for (const category of categories) {
  if (typeof report.categories?.[category]?.score !== 'number') {
    throw new Error(`La catégorie Lighthouse "${category}" est absente du rapport.`);
  }
}

console.log(`Audit ${device} terminé : ${targetUrl.toString()}`);
for (const category of categories) {
  console.log(`${category}: ${Math.round(report.categories[category].score * 100)}`);
}
