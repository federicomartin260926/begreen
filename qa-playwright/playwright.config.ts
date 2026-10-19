import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { authFile, loadQaSettings, qaRoot } from './config/qa';

const settings = loadQaSettings();

export default defineConfig({
  testDir: path.join(qaRoot, 'tests'),
  timeout: 30_000,
  expect: { timeout: 8_000 },
  workers: 1, // Evitar concurrencia sobre la misma cuenta/proyecto QA.
  retries: 0, // No ocultar regresiones.
  fullyParallel: false,
  forbidOnly: true,
  outputDir: path.join(qaRoot, 'artifacts', 'test-results'),
  reporter: [
    ['line'],
    ['html', { outputFolder: path.join(qaRoot, 'artifacts', 'html-report'), open: 'never' }],
    ['json', { outputFile: path.join(qaRoot, 'artifacts', 'results.json') }],
  ],
  use: {
    baseURL: settings.baseUrl,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
  },
  projects: [
    {
      name: 'auth-setup',
      testMatch: /.*\.setup\.ts/,
      use: { browserName: 'chromium', channel: settings.browser.channel, headless: settings.browser.headless },
    },
    {
      name: 'chrome-dev',
      dependencies: ['auth-setup'],
      testMatch: /.*\.spec\.ts/,
      use: {
        browserName: 'chromium',
        channel: settings.browser.channel,
        headless: settings.browser.headless,
        storageState: authFile,
      },
    },
  ],
});
