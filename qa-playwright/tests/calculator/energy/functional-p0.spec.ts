import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.energy);
});

test('COM-02 — el alta limpia de Energía no preselecciona selectores principales', async ({ page }) => {
  await expect(page.getByLabel('País general')).toHaveValue('');
  await expect(page.locator('input[name="family"]:checked')).toHaveCount(0);
  await expect(page.locator('select[name="origin"]')).toHaveValue('');
});

test('ENE-01 / ENE-02 — electricidad España con origen desconocido adapta comercializadora y no muestra GdO', async ({ page }) => {
  const country = page.getByLabel('País general');
  const electricity = page.getByRole('radio', { name: 'Electricidad' });
  const origin = page.locator('select[name="origin"]');
  const supplierKnown = page.locator('select[name="electricitySupplierKnown"]');
  const supplier = page.locator('input[name="electricitySupplier"][list="energy-suppliers"]');
  const renewableCertificate = page.locator('select[name="renewableCertificate"]');

  await expect(country).toHaveValue('');
  await expect(electricity).not.toBeChecked();
  await expect(origin).toBeHidden();
  await expect(origin).toBeDisabled();

  await country.selectOption({ label: 'España' });
  await page.locator('label[for="energy-family-electricity"]').click();
  await origin.selectOption({ label: 'No lo sé' });

  await expect(supplierKnown).toBeVisible();
  await expect(supplierKnown).toBeEnabled();
  await expect(supplierKnown).toHaveValue('');
  await expect(renewableCertificate).toBeHidden();
  await expect(renewableCertificate).toBeDisabled();

  await supplierKnown.selectOption({ label: 'Sí' });

  await expect(supplier).toBeVisible();
  await expect(supplier).toBeEnabled();
  await expect(supplier).toHaveAttribute('list', 'energy-suppliers');
  expect(await page.locator('datalist#energy-suppliers option').count(), 'El datalist debe ofrecer comercializadoras').toBeGreaterThan(0);

  await supplierKnown.selectOption({ label: 'No' });

  await expect(supplier).toBeHidden();
  await expect(supplier).toBeDisabled();
  expect(await supplier.evaluate(field => field.willValidate), 'El proveedor oculto no debe bloquear la validación').toBeFalsy();
  await expect(renewableCertificate).toBeHidden();
  await expect(renewableCertificate).toBeDisabled();
});
