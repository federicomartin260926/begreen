import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.transport);
});

test('COM-02 — el alta limpia de Transporte no preselecciona selectores principales', async ({ page }) => {
  await expect(page.getByLabel('País')).toHaveValue('');
  await expect(page.locator('input[name="category"]:checked')).toHaveCount(0);
  await expect(page.getByLabel('¿Cómo te has desplazado?')).toHaveValue('');
  await expect(page.getByLabel('¿Qué dato tienes?')).toHaveValue('');
});

test('TRA-01 — cantidad y unidad dependen de elegir un método', async ({ page }) => {
  const mode = page.getByLabel('¿Cómo te has desplazado?');
  const method = page.getByLabel('¿Qué dato tienes?');
  const quantity = page.locator('[name="activityValue"]');
  const unit = page.locator('[name="activityUnit"]');

  await expect(mode).toHaveValue('');
  await expect(method).toHaveValue('');
  await expect(quantity).toBeHidden();
  await expect(quantity).toBeDisabled();
  await expect(unit).toBeHidden();
  await expect(unit).toBeDisabled();

  await page.locator('label[for="transport-category-local"]').click();
  await mode.selectOption({ label: 'Autobús urbano / lanzadera' });

  await expect(method).toHaveValue('');
  await expect(quantity).toBeHidden();
  await expect(quantity).toBeDisabled();
  await expect(unit).toBeHidden();
  await expect(unit).toBeDisabled();

  await method.selectOption({ label: 'Kilómetros recorridos' });

  await expect(quantity).toBeVisible();
  await expect(quantity).toBeEnabled();
  await expect(unit).toBeVisible();
  await expect(unit).toBeEnabled();
  await expect(unit.locator('option')).toHaveText(['Seleccione…', 'km', 'mi']);
});

test('TRA-05 — avión pregunta primero la clase y ofrece cuatro opciones sin preselección', async ({ page }) => {
  await page.locator('label[for="transport-category-travel"]').click();
  await page.getByLabel('¿Cómo has viajado?').selectOption({ label: 'Avión' });

  const travelClass = page.getByLabel('¿En qué clase viajaste?');
  const method = page.getByLabel('¿Qué dato tienes?');

  await expect(travelClass).toBeVisible();
  await expect(travelClass).toBeEnabled();
  await expect(travelClass).toHaveValue('');
  await expect(travelClass.locator('option:not([value=""])')).toHaveText([
    'Economy',
    'Premium Economy',
    'Business',
    'First',
  ]);
  await expect(method).toHaveValue('');

  const classPrecedesMethod = await travelClass.evaluate((classField) => {
    const methodField = classField.form?.elements.namedItem('method');
    return methodField instanceof Element
      && Boolean(classField.compareDocumentPosition(methodField) & Node.DOCUMENT_POSITION_FOLLOWING);
  });
  expect(classPrecedesMethod, 'La pregunta de clase debe preceder al método en el formulario').toBeTruthy();
});
