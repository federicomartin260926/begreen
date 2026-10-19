import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.waste);
});

test('COM-02 — el alta limpia de Residuos no preselecciona selectores principales', async ({ page }) => {
  await expect(page.getByLabel('Fecha de inicio *')).toHaveValue('');
  await expect(page.getByLabel('Fecha de fin *')).toHaveValue('');
  await expect(page.getByLabel('País *')).toHaveValue('');
  await expect(page.getByLabel('Tipo de residuo *')).toHaveValue('');
  await expect(page.getByLabel('Destino / tratamiento *')).toHaveValue('');
  await expect(page.getByLabel('Unidad *')).toHaveValue('');
  await expect(page.getByLabel('Subtipo *')).toBeHidden();
  await expect(page.getByLabel('Subtipo *')).toBeDisabled();
});

test('RES-01 / RES-02 — España encadena tipo, subtipo y tratamientos sin preselección', async ({ page }) => {
  await page.getByLabel('País *').selectOption({ label: 'España' });
  const type = page.getByLabel('Tipo de residuo *');
  const subtype = page.getByLabel('Subtipo *');
  const treatment = page.getByLabel('Destino / tratamiento *');

  expect(await type.locator('option').count(), 'España debe ofrecer 25 tipos más el placeholder').toBe(26);
  await expect(type).toHaveValue('');
  await type.selectOption({ label: 'Residuos plásticos' });
  await expect(subtype).toBeVisible();
  await expect(subtype).toBeEnabled();
  await expect(subtype).toHaveAttribute('required', '');
  await expect(subtype).toHaveValue('');
  await subtype.selectOption({ label: 'Cintas LTO' });
  await expect(treatment).toHaveValue('');
  expect(await treatment.locator('option').count(), 'El subtipo debe habilitar sus destinos documentados').toBeGreaterThan(1);
});

test('RES-01 / RES-02 — fuera de España usa su catálogo y oculta el subtipo cuando no aplica', async ({ page }) => {
  await page.getByLabel('País *').selectOption({ label: 'Francia' });
  const type = page.getByLabel('Tipo de residuo *');
  const subtype = page.getByLabel('Subtipo *');
  const treatment = page.getByLabel('Destino / tratamiento *');

  expect(await type.locator('option').count(), 'Fuera de España debe ofrecer 14 tipos más el placeholder').toBe(15);
  await type.selectOption({ label: 'Madera' });
  await expect(subtype).toBeHidden();
  await expect(subtype).toBeDisabled();
  await expect(subtype).not.toHaveAttribute('required', '');
  await expect(treatment).toHaveValue('');
  await expect(treatment.locator('option')).toContainText(['Compostaje', 'Desconocido', 'Vertedero']);
});

test('RES-03 — kg y toneladas permanecen explícitos y una ruta representativa obtiene preview', async ({ page }) => {
  const unit = page.getByLabel('Unidad *');
  await expect(unit.locator('option')).toHaveText(['Seleccione…', 'kg', 't']);
  await expect(unit).toHaveValue('');

  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha de fin *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'Francia' });
  await page.getByLabel('Tipo de residuo *').selectOption({ label: 'Madera' });
  await page.getByLabel('Destino / tratamiento *').selectOption({ label: 'Compostaje' });
  await page.getByLabel('Peso *').fill('10');
  await unit.selectOption('kg');

  await expect(page.getByText('Calculado', { exact: true })).toBeVisible();
  await expect(page.locator('[data-waste-v1-form-target="previewEmission"]')).not.toHaveText('—');
});
