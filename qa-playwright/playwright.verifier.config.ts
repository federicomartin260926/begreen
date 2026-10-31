import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { qaRoot } from './config/qa';
import { loadVerifierSettings } from './config/verifier-qa';

const settings = loadVerifierSettings();

export default defineConfig({
  testDir: path.join(qaRoot, 'tests', 'verifier'),
  testMatch: '**/*.spec.ts',
  timeout: 40_000,
  expect: { timeout: 8_000 },
  workers: 1,
  retries: 0,
  fullyParallel: false,
  forbidOnly: true,
  outputDir: path.join(qaRoot, 'artifacts', 'verifier', 'test-results'),
  reporter: [
    ['line'],
    ['html', { outputFolder: path.join(qaRoot, 'artifacts', 'verifier', 'html-report'), open: 'never' }],
    ['json', { outputFile: path.join(qaRoot, 'artifacts', 'verifier', 'results.json') }],
  ],
  use: {
    baseURL: settings.baseUrl,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
    browserName: 'chromium',
    channel: settings.browser.channel,
    headless: settings.browser.headless,
    storageState: { cookies: [], origins: [] }, // No reutilizar la sesión QA #74.
  },
});
