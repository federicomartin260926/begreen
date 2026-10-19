import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.materials);
});

test('COM-02 / MAT-01 — familia, material y subproducto son dependientes y no se preseleccionan', async ({ page }) => {
  const family = page.getByLabel('Familia *', { exact: true });
  const material = page.getByLabel('Material *', { exact: true });
  const subproduct = page.getByLabel('Tipo o subproducto *', { exact: true });
  const method = page.getByLabel('Método de medición *', { exact: true });

  await expect(family).toHaveValue('');
  await expect(material).toHaveValue('');
  await expect(material.locator('option')).toHaveText(['Seleccione…']);
  await expect(subproduct).toHaveValue('');
  await expect(method).toHaveValue('');

  await family.selectOption({ label: 'Pilas y baterías' });

  await expect(material).toHaveValue('');
  await expect(material.locator('option:not([value=""])')).toHaveText(['Pilas, baterías']);
  await expect(subproduct).toHaveValue('');

  await material.selectOption({ label: 'Pilas, baterías' });

  await expect(subproduct).toBeVisible();
  await expect(subproduct).toBeEnabled();
  await expect(subproduct).toHaveValue('');
  expect(await subproduct.locator('option:not([value=""])').count(), 'La actividad debe cargar sus subproductos').toBeGreaterThan(1);
});

test('MAT-02 — Papel presenta el método antes del formato y adapta controles por peso o paquetes', async ({ page }) => {
  await page.getByLabel('Familia *', { exact: true }).selectOption({ label: 'Papel' });
  await page.getByLabel('Material *', { exact: true }).selectOption({ label: 'Papel' });

  const method = page.getByLabel('Método de medición *', { exact: true });
  const format = page.getByLabel('Formato de papel *', { exact: true });
  const quantity = page.locator('[name="inputQuantity"]');
  const unit = page.getByLabel('Unidad *', { exact: true });
  const sheetsPerPackage = page.getByLabel('Hojas por paquete');

  await expect(method).toHaveValue('');
  await expect(format).toBeHidden();
  await expect(format).toBeDisabled();

  const methodPrecedesFormat = await method.evaluate((methodField) => {
    const formatField = methodField.form?.elements.namedItem('paperFormat');
    return formatField instanceof Element
      && Boolean(methodField.compareDocumentPosition(formatField) & Node.DOCUMENT_POSITION_FOLLOWING);
  });
  expect(methodPrecedesFormat, 'El método debe preceder al formato de papel').toBeTruthy();

  await method.selectOption({ label: 'Por peso' });

  await expect(format).toBeHidden();
  await expect(format).toBeDisabled();
  await expect(quantity).toBeVisible();
  await expect(unit).toBeVisible();
  await expect(unit.locator('option:not([value=""])')).toHaveText(['kg']);

  await method.selectOption({ label: 'Por paquetes' });

  await expect(format).toBeVisible();
  await expect(format).toBeEnabled();
  await expect(page.getByLabel('Número de paquetes *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Número de paquetes *', { exact: true })).toBeEnabled();
  await expect(sheetsPerPackage).toBeVisible();
  await expect(sheetsPerPackage).toBeEnabled();
});
