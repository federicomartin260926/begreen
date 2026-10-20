import { test as base, expect } from '@playwright/test';
import { loadQaSettings } from '../config/qa';
import { assertSafeNetwork, protectPage, type WriteSession } from '../helpers/network-guard';

type PersistenceFixtures = {
  writeSession: WriteSession;
};

function runId() {
  const configured = process.env.QA74_RUN_ID || '';
  if (configured && !/^QA74-[A-Za-z0-9_-]{6,80}$/.test(configured)) {
    throw new Error('QA74_RUN_ID no cumple el formato seguro esperado.');
  }
  return configured || `QA74-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}-${process.pid}`;
}

export const test = base.extend<PersistenceFixtures>({
  writeSession: async ({}, use) => {
    const id = runId();
    await use({
      runId: id,
      marker: id,
      createdRecordIds: new Set<string>(),
      deletedRecordIds: new Set<string>(),
      mutations: [],
    });
  },
  page: async ({ page, writeSession }, use, testInfo) => {
    const settings = loadQaSettings();
    expect(settings.writeMode.enabled, 'P2 bloqueado: activa writeMode.enabled=true únicamente durante la ejecución autorizada.').toBeTruthy();

    const errors: string[] = [];
    const violations = await protectPage(page, 'write', writeSession);
    page.on('pageerror', error => errors.push(error.message));

    const listing = await page.goto('/backend/emission/records');
    expect(listing?.ok(), 'La cuenta debe acceder al listado antes de habilitar escrituras').toBeTruthy();
    if (await page.locator('.emission-dashboard__project-name').textContent() !== settings.projectName) {
      const projects = await page.goto(`/backend/project/?name=${encodeURIComponent(settings.projectName)}`);
      expect(projects?.ok(), 'Debe poder abrirse el selector de proyectos').toBeTruthy();
      const projectCard = page.locator('.backend-project-pill').filter({
        has: page.locator('.backend-project-pill__name', { hasText: new RegExp(`^${settings.projectName}$`) }),
      });
      await expect(projectCard, `Debe existir exactamente un proyecto ${settings.projectName}`).toHaveCount(1);
      const selectLink = projectCard.locator('a[href*="/select-project/"][href*="target=emissions"]').first();
      const href = await selectLink.getAttribute('href');
      expect(href, `El proyecto ${settings.projectName} debe tener acceso a Emisiones`).toBeTruthy();
      const target = new URL(href!, page.url());
      const match = target.pathname.match(/\/select-project\/(\d+)$/);
      expect(match, 'La ruta de selección debe contener un ID numérico resuelto desde la UI').toBeTruthy();
      writeSession.selectedProjectId = match![1];
      await selectLink.click();
    }
    await expect(page.locator('.emission-dashboard__project-name')).toHaveText(settings.projectName);

    await use(page);

    await testInfo.attach('p2-write-registry.txt', {
      body: [
        `RUN_ID=${writeSession.runId}`,
        `CREATED=${[...writeSession.createdRecordIds].join(',') || 'none'}`,
        `DELETED=${[...writeSession.deletedRecordIds].join(',') || 'none'}`,
        ...writeSession.mutations.map(value => `MUTATION=${value}`),
        ...errors.map(value => `JS=${value}`),
        ...violations.map(value => `BLOCKED=${value}`),
      ].join('\n'),
      contentType: 'text/plain',
    });
    assertSafeNetwork(violations);
    expect(errors, `Errores JavaScript inesperados: ${errors.join('; ')}`).toEqual([]);
  },
});

export { expect };
