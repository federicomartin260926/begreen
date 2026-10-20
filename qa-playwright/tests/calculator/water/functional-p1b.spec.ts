import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.water);
});

test('AGU-02 — el volumen admite únicamente litros y metros cúbicos, sin preselección', async ({ page }) => {
  const unit = page.getByLabel('Unidad');
  await expect(unit).toHaveValue('');
  await expect(unit.locator('option:not([value=""])')).toHaveText(['Litros', 'm³']);
});

test('AGU-03 — tipo de uso y destino exponen los catálogos documentados e independientes', async ({ page }) => {
  const useType = page.getByLabel('Tipo de uso');
  const destination = page.getByLabel('Destino');

  await expect(useType.locator('option:not([value=""])')).toHaveText([
    'Sanitarios', 'Duchas', 'Limpieza', 'Efectos especiales (FX)', 'Piscina o tanque', 'Riego', 'Proceso', 'Otros',
  ]);
  await expect(destination.locator('option:not([value=""])')).toHaveText([
    'Alcantarillado / red de saneamiento', 'Riego / sin alcantarillado', 'Desconocido',
  ]);
  await useType.selectOption({ label: 'Limpieza' });
  await expect(destination).toHaveValue('');
});

test('AGU-04 — el preview calcula una entrada válida y rechaza volumen negativo', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha final *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await page.getByLabel('Tipo de uso').selectOption({ label: 'Limpieza' });
  await page.getByLabel('Volumen').fill('100');
  await page.getByLabel('Unidad').selectOption('L');
  await page.getByLabel('Destino').selectOption({ label: 'Alcantarillado / red de saneamiento' });

  await expect(page.locator('[data-water-v1-form-target="previewStatus"]')).toHaveText('Calculado');
  await expect(page.locator('[data-water-v1-form-target="previewEmission"]')).not.toHaveText('—');

  await page.getByLabel('Volumen').fill('-1');
  await expect(page.locator('[data-water-v1-form-target="previewStatus"]')).toHaveText('Pendiente');
  await expect(page.locator('[data-water-v1-form-target="previewMessages"]')).toContainText(
    'Indica un volumen válido y no negativo.',
  );
});
