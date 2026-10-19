import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.catering);
});

test('COM-02 — el alta limpia de Catering no preselecciona selectores principales', async ({ page }) => {
  await expect(page.getByLabel('Fecha de inicio *')).toHaveValue('');
  await expect(page.getByLabel('Fecha final *')).toHaveValue('');
  await expect(page.getByLabel('País *')).toHaveValue('');
  await expect(page.getByLabel('Actividad *')).toHaveValue('');

  for (const name of ['people', 'menuVariant[]', 'tablewareType', 'sandwichType', 'containerVolumeLiters', 'serviceCount', 'gasType']) {
    await expect(page.locator(`[name="${name}"]`).first()).toBeHidden();
    await expect(page.locator(`[name="${name}"]`).first()).toBeDisabled();
  }
});

test('CAT-01 — ofrece las nueve actividades cerradas en #71', async ({ page }) => {
  await expect(page.getByLabel('Actividad *').locator('option')).toHaveText([
    'Seleccione…',
    'Desayuno',
    'Coffee break',
    'Picoteo / refrigerio',
    'Menú',
    'Bocadillo',
    'Agua',
    'Otras bebidas',
    'Café / bebida caliente',
    'Gas de catering',
  ]);
});

test('CAT-01 / CAT-02 — Menú muestra variantes, cantidades y vajilla sin preselección', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha final *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await page.getByLabel('Actividad *').selectOption({ label: 'Menú' });
  const variant = page.locator('select[name="menuVariant[]"]').first();
  const prepared = page.locator('input[name="preparedCount[]"]').first();
  const consumed = page.locator('input[name="consumedCount[]"]').first();
  const tableware = page.locator('select[name="tablewareType"]');

  await expect(variant.locator('option')).toHaveText([
    'Seleccione…', 'Vacuno', 'Cordero', 'Pollo', 'Cerdo', 'Pescado', 'Vegetariano', 'Vegano',
  ]);
  await expect(tableware.locator('option')).toHaveText([
    'Seleccione…', 'Reutilizable', 'Compostable de un solo uso', 'Otra', 'Desconocida',
  ]);
  for (const field of [variant, prepared, consumed, tableware]) {
    await expect(field).toBeVisible();
    await expect(field).toBeEnabled();
    await expect(field).toHaveValue('');
  }

  await expect(page.getByText('Selecciona un tipo de vajilla válido.', { exact: true })).toBeVisible();

  await tableware.selectOption({ label: 'Reutilizable' });
  await expect(page.getByText('Indica al menos un menú preparado.', { exact: true })).toBeVisible();

  await prepared.fill('2');
  await expect(page.getByText('Preparados y consumidos deben ser enteros no negativos.', { exact: true })).toBeVisible();

  await consumed.fill('0');
  await expect(page.getByText('Selecciona un tipo de menú válido.', { exact: true })).toBeVisible();
});

test('CAT-01 / CAT-02 — un Menú completo obtiene preview sin guardar', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha final *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await page.getByLabel('Actividad *').selectOption({ label: 'Menú' });
  await page.locator('select[name="menuVariant[]"]').selectOption({ label: 'Vegetariano' });
  await page.locator('input[name="preparedCount[]"]').fill('10');
  await page.locator('input[name="consumedCount[]"]').fill('8');
  await page.locator('select[name="tablewareType"]').selectOption({ label: 'Reutilizable' });

  await expect(page.getByText('Calculado', { exact: true })).toBeVisible();
  await expect(page.locator('[data-catering-v1-form-target="previewEmission"]')).not.toHaveText('—');
});

test('CAT-03 / EX-03 — una actividad no Menú nunca se presenta como cero calculado', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha final *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await page.getByLabel('Actividad *').selectOption({ label: 'Desayuno' });
  await page.locator('input[name="people"]').fill('25');

  await expect(page.getByText('No calculable automáticamente', { exact: true })).toBeVisible();
  await expect(page.locator('[data-catering-v1-form-target="previewEmission"]')).toHaveText('—');
  await expect(page.getByText('Esta actividad no dispone de factor automático.', { exact: true })).toBeVisible();
});
