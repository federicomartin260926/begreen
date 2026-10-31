import { test, expect } from '../../fixtures/verifier-test';
import { loadVerifierSettings } from '../../config/verifier-qa';
import type { Page } from '@playwright/test';

const settings = loadVerifierSettings();
const ALLOWED_NAMES = ['EVENTO FRANC', 'RODAJE ELABORADO'];
const FORBIDDEN_NAME = 'Proyecto Fede';

async function projectList(page: Page) {
  const response = await page.goto('/backend/project/');
  expect(response?.ok(), 'El dashboard de proyectos debe responder 2xx').toBeTruthy();
  const cards = page.locator('.backend-project-pill');
  await expect(cards, 'Sólo deben existir los dos proyectos asignados').toHaveCount(2);
  const names = (await page.locator('.backend-project-pill__name').allTextContents()).map(s => s.trim());
  expect([...names].sort(), 'El listado debe coincidir exactamente con las dos membresías').toEqual([...ALLOWED_NAMES].sort());
  expect(names).not.toContain(FORBIDDEN_NAME);
  return cards;
}

async function projectIdFromCard(page: Page, name: string): Promise<number> {
  const card = page.locator('.backend-project-pill').filter({
    has: page.locator('.backend-project-pill__name', { hasText: new RegExp(`^${name}$`) }),
  });
  await expect(card, `Debe existir únicamente un proyecto ${name}`).toHaveCount(1);
  const links = await card.locator('a[href*="/select-project/"]').evaluateAll(elements =>
    elements.map(element => (element as HTMLAnchorElement).href),
  );
  const ids = new Set(links.map(href => new URL(href).pathname.match(/\/select-project\/(\d+)$/)?.[1]).filter(Boolean));
  expect(ids.size, `Debe encontrarse el ID único del proyecto ${name} en los enlaces reales`).toBe(1);
  return Number([...ids][0]);
}

async function projectEditUrl(page: Page, name: string): Promise<URL> {
  const id = await projectIdFromCard(page, name);
  const card = page.locator('.backend-project-pill').filter({
    has: page.locator('.backend-project-pill__name', { hasText: new RegExp(`^${name}$`) }),
  });
  const hrefs = await card.locator('a[href]').evaluateAll(elements =>
    elements.map(element => (element as HTMLAnchorElement).href),
  );
  const href = hrefs.find(value => {
    const url = new URL(value);
    return url.origin === settings.baseUrl && url.pathname.includes('/edit')
      && url.pathname.split('/').includes(String(id));
  });
  expect(href, `La tarjeta ${name} debe ofrecer un enlace de edición del proyecto`).toBeTruthy();
  return new URL(href!);
}

/** Permite exactamente una URL de selección obtenida de una tarjeta autorizada.
 * Este GET sólo cambia el proyecto activo en la SESIÓN. No permite clonar,
 * borrar, guardar formularios ni acceder a proyectos no listados.
 */
async function selectAuthorizedProject(page: Page, name: string, target: 'plan' | 'elaboration_done' | 'emissions') {
  await projectList(page);
  const card = page.locator('.backend-project-pill').filter({
    has: page.locator('.backend-project-pill__name', { hasText: new RegExp(`^${name}$`) }),
  });
  const id = await projectIdFromCard(page, name);
  expect(id).not.toBe(settings.forbiddenProjectId);
  const hrefs = await card.locator('a[href*="/select-project/"]').evaluateAll(elements =>
    elements.map(element => (element as HTMLAnchorElement).href),
  );
  const allowed = hrefs.find(href => {
    const url = new URL(href);
    return url.origin === settings.baseUrl && url.pathname === `/backend/project/select-project/${id}`
      && url.searchParams.get('target') === target;
  });
  expect(allowed, `La tarjeta ${name} debe ofrecer selección al destino ${target}`).toBeTruthy();
  const permitted = new URL(allowed!);
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.href === permitted.href) {
      return route.continue(); // Ruta prevalidada, cambio exclusivamente de sesión.
    }
    return route.fallback(); // Delega al network guard #74 cualquier otra solicitud.
  });
  const response = await page.goto(permitted.href);
  expect(response?.ok(), `La selección ${target} de ${name} debe responder 2xx`).toBeTruthy();
  return id;
}

test.beforeEach(async ({ page }) => {
  const response = await page.goto(settings.loginPath);
  expect(response?.ok(), 'Login de DEV debe responder 2xx').toBeTruthy();
  await expect(page.locator('input[name="_username"]')).toBeVisible();
  await page.locator('input[name="_username"]').fill(settings.email);
  await page.locator('input[name="_password"]').fill(settings.password);
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login\/(?:es|en)(?:\?|$)/);
});

test('VER-01 / VER-02 / VER-05 — Login y dashboard sólo con dos proyectos asignados', async ({ page }) => {
  await projectList(page);
});

test('VER-03 / VER-04 — Puede consultar los formularios de sus dos proyectos', async ({ page }) => {
  await projectList(page);
  for (const name of ALLOWED_NAMES) {
    const id = await projectIdFromCard(page, name);
    expect(id).not.toBe(settings.forbiddenProjectId);
    const url = await projectEditUrl(page, name);
    const response = await page.goto(url.href);
    expect(response?.ok(), `Proyecto asignado ${name} debe poder editarse (GET)`).toBeTruthy();
    await expect(page.locator('form').first()).toBeVisible();
    await projectList(page);
  }
});

test('VER-06 — URL directa de edición de Proyecto Fede denegada', async ({ page }) => {
  await projectList(page);
  const allowedUrl = await projectEditUrl(page, ALLOWED_NAMES[0]);
  const allowedId = await projectIdFromCard(page, ALLOWED_NAMES[0]);
  const segments = allowedUrl.pathname.split('/');
  const idIndex = segments.indexOf(String(allowedId));
  expect(idIndex, 'El enlace de edición debe contener el ID de proyecto autorizado').toBeGreaterThan(0);
  segments[idIndex] = String(settings.forbiddenProjectId);
  allowedUrl.pathname = segments.join('/');
  const response = await page.goto(allowedUrl.href);
  expect(response?.status(), 'El proyecto no asignado debe devolver HTTP 403, no login ni 404').toBe(403);
  // No intentar la ruta /clone: es un GET que escribe en base de datos.
});

test('VER-07 — No puede acceder a la administración de usuarios', async ({ page }) => {
  const response = await page.goto('/admin/users/');
  expect(response?.status(), 'La certificadora no debe poder acceder a /admin/users/').toBe(403);
});

test('VER-08 — Puede acceder al Plan desde un proyecto asignado', async ({ page }) => {
  // La fase 1 de EVENTO FRANC está completada: el dashboard enlaza a
  // target=elaboration_done, que redirige al Plan completado (backend_plan_done).
  await selectAuthorizedProject(page, ALLOWED_NAMES[0], 'elaboration_done');
  await expect(page).not.toHaveURL(/\/login\//);
  expect(new URL(page.url()).pathname).toMatch(/\/backend\/plan(?:\/|$)/);
});

test('VER-09 — Puede acceder a la Calculadora del segundo proyecto asignado', async ({ page }) => {
  await selectAuthorizedProject(page, ALLOWED_NAMES[1], 'emissions');
  const response = await page.goto('/backend/emission/records');
  expect(response?.ok(), 'Calculadora del segundo proyecto debe responder 2xx').toBeTruthy();
  await expect(page.locator('.emission-dashboard__project-name')).toHaveText(ALLOWED_NAMES[1]);
});
