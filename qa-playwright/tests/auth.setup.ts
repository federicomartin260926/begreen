import { test, expect } from '@playwright/test';
import { chmodSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { authFile, loadQaSettings } from '../config/qa';
import { protectPage, assertSafeNetwork } from '../helpers/network-guard';

test('autenticación QA en DEV, sin persistencia de emisiones', async ({ page }) => {
  const settings = loadQaSettings();
  const violations = await protectPage(page, 'auth');
  const response = await page.goto(settings.loginPath);
  expect(response?.ok(), 'No se pudo cargar el login DEV').toBeTruthy();
  await expect(page.locator('input[name="_username"]')).toBeVisible();
  await expect(page.locator('input[name="_password"]')).toBeVisible();
  await page.locator('input[name="_username"]').fill(settings.auth.email);
  await page.locator('input[name="_password"]').fill(settings.auth.password);
  // CSRF proviene del formulario real. No se registra ni se envía manualmente.
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login\/(es|en)(?:\?|$)/);

  const listing = await page.goto('/backend/emission/records');
  expect(listing?.ok(), 'La cuenta debe acceder al listado de emisiones del proyecto activo').toBeTruthy();
  await expect(page).toHaveURL(/\/backend\/emission\/records/);
  assertSafeNetwork(violations);

  mkdirSync(path.dirname(authFile), { recursive: true, mode: 0o700 });
  await page.context().storageState({ path: authFile });
  chmodSync(authFile, 0o600);
});
