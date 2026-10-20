import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.energy);
});

test('ENE-03 — electricidad fuera de España usa proveedor genérico y certificado, no flujo español', async ({ page }) => {
  await page.getByLabel('País general').selectOption({ label: 'Francia' });
  await page.locator('label[for="energy-family-electricity"]').click();
  await page.locator('select[name="origin"]').selectOption({ label: 'Red eléctrica' });

  await expect(page.locator('select[name="electricitySupplierKnown"]')).toBeHidden();
  await expect(page.locator('input[name="electricitySupplier"][list="energy-suppliers"]')).toBeHidden();
  await expect(page.locator('input[name="electricitySupplier"]:not([list])')).toBeVisible();
  await expect(page.locator('select[name="renewableCertificate"]')).toBeVisible();
  await expect(page.locator('select[name="renewableCertificate"]')).toHaveValue('');
});

test('ENE-04 — certificado renovable muestra cobertura y evidencia sin asumir reducción matemática', async ({ page }) => {
  await page.getByLabel('País general').selectOption({ label: 'Francia' });
  await page.locator('label[for="energy-family-electricity"]').click();
  await page.locator('select[name="origin"]').selectOption({ label: 'No lo sé' });
  const certificate = page.locator('select[name="renewableCertificate"]');

  await certificate.selectOption({ label: 'Sí' });
  await expect(page.locator('input[name="certifiedKwh"]')).toBeVisible();
  await expect(page.getByLabel('Evidencia documental del certificado')).toBeVisible();

  await certificate.selectOption({ label: 'No' });
  await expect(page.locator('input[name="certifiedKwh"]')).toBeHidden();
  await expect(page.getByLabel('Evidencia documental del certificado')).toBeHidden();
});

test('ENE-05 — equipos filtran combustibles y butano/propano habilitan sus bombonas', async ({ page }) => {
  await page.getByLabel('País general').selectOption({ label: 'España' });
  await page.locator('label[for="energy-family-equipment"]').click();
  const equipment = page.locator('select[name="equipmentType"]');
  const fuel = page.locator('select[name="fuel"]');
  const mode = page.locator('select[name="mode"]');
  const bottleSize = page.locator('select[name="bottleSizeKg"]');

  await equipment.selectOption({ label: 'Grupo electrógeno / generador' });
  await expect(fuel.locator('option:not([value=""])')).not.toContainText(['Butano', 'Propano']);

  await equipment.selectOption({ label: 'Calefacción' });
  await expect(fuel.locator('option:not([value=""])')).toContainText(['Propano', 'Butano']);
  await fuel.selectOption({ label: 'Butano' });
  await expect(mode).toBeVisible();
  await expect(mode.locator('option:not([value=""])')).toHaveText(['Cantidad directa', 'Bombonas']);
  await mode.selectOption({ label: 'Bombonas' });
  await expect(bottleSize.locator('option:not([value=""])')).toHaveText(['6', '12.5']);
  await expect(page.locator('input[name="bottleCount"]')).toBeVisible();

  await fuel.selectOption({ label: 'Propano' });
  await mode.selectOption({ label: 'Bombonas' });
  await expect(bottleSize.locator('option:not([value=""])')).toHaveText(['11', '35']);
});

test('ENE-06 — tecnología digital ofrece los siete subtipos documentados sin preselección', async ({ page }) => {
  await page.getByLabel('País general').selectOption({ label: 'España' });
  await page.locator('label[for="energy-family-digital"]').click();
  const technology = page.locator('select[name="digitalType"]');

  await expect(technology).toBeVisible();
  await expect(technology).toHaveValue('');
  await expect(technology.locator('option:not([value=""])')).toHaveText([
    'Ordenador / estación de trabajo', 'Servidor propio', 'Nube / cloud', 'Granja de render',
    'Inteligencia artificial', 'Almacenamiento', 'Otro',
  ]);
});

test('ENE-07 — país digital prevalece y sin kWh no inventa una estimación automática', async ({ page }) => {
  await page.getByLabel('Fecha de inicio').fill('2025-06-01');
  await page.getByLabel('Fecha final').fill('2025-06-01');
  await page.getByLabel('País general').selectOption({ label: 'España' });
  await page.locator('label[for="energy-family-digital"]').click();
  await page.locator('select[name="digitalType"]').selectOption({ label: 'Inteligencia artificial' });
  await page.locator('select[name="digitalCountry"]').selectOption({ label: 'Francia' });
  await page.locator('input[name="hours"]').fill('2');
  await page.locator('input[name="units"]').fill('1');
  await page.locator('input[name="gpu"]').fill('A100');

  await expect(page.locator('[data-energy-v1-form-target="previewStatus"]')).toHaveText('No calculable automáticamente');
  await expect(page.locator('[data-energy-v1-form-target="previewEmission"]')).toHaveText('—');
  await expect(page.locator('[data-energy-v1-form-target="previewMessages"]')).toContainText(
    'No existe una metodología defendible para estimar este uso sin kWh conocidos.',
  );

  await page.locator('input[name="knownKwh"]').fill('1');
  await expect(page.locator('[data-energy-v1-form-target="previewStatus"]')).toHaveText('Calculado');
  await expect(page.locator('[data-energy-v1-form-target="previewTrace"]')).toContainText('Proxy geográfico');
});
