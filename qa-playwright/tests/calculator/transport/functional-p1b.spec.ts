import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.transport);
});

test('TRA-02 — turismo pide tamaño y motorización antes del método, sin preselecciones', async ({ page }) => {
  await page.getByLabel('País').selectOption({ label: 'España' });
  await page.locator('label[for="transport-category-local"]').click();
  await page.getByLabel('¿Cómo te has desplazado?').selectOption({ label: 'Coche / turismo' });

  const size = page.getByLabel('¿Sabes el tamaño del coche?');
  const motor = page.getByLabel('¿Qué tipo?');
  const method = page.getByLabel('¿Qué dato tienes?');

  await expect(size).toBeVisible();
  await expect(size).toHaveValue('');
  await expect(size.locator('option:not([value=""])')).toHaveText([
    'Pequeño', 'Mediano', 'Grande', 'No lo sé / usar promedio',
  ]);
  await expect(motor).toBeVisible();
  await expect(motor).toHaveValue('');
  await expect(method).toHaveValue('');
  await motor.selectOption({ label: 'Gasolina' });
  await expect(method.locator('option:not([value=""])')).toHaveText([
    'Distancia recorrida', 'Combustible consumido',
  ]);

  const fieldsPrecedeMethod = await method.evaluate((methodField) => {
    const form = methodField.form;
    const sizeField = form?.elements.namedItem('carSize');
    const motorField = form?.elements.namedItem('vehicleType');
    return sizeField instanceof Element && motorField instanceof Element
      && Boolean(sizeField.compareDocumentPosition(methodField) & Node.DOCUMENT_POSITION_FOLLOWING)
      && Boolean(motorField.compareDocumentPosition(methodField) & Node.DOCUMENT_POSITION_FOLLOWING);
  });
  expect(fieldsPrecedeMethod, 'Tamaño y motorización deben preceder al método').toBeTruthy();
});

test('TRA-03 — ocupantes y acompañamiento aparecen solo donde aplican y siguen opcionales', async ({ page }) => {
  await page.getByLabel('País').selectOption({ label: 'España' });
  await page.locator('label[for="transport-category-local"]').click();
  await page.getByLabel('¿Cómo te has desplazado?').selectOption({ label: 'Coche / turismo' });
  await page.getByLabel('¿Qué tipo?').selectOption({ label: 'Gasolina' });
  await page.getByLabel('¿Qué dato tienes?').selectOption({ label: 'Distancia recorrida' });

  const passengers = page.getByLabel('¿Cuántas personas del proyecto viajaban?');
  const accompaniment = page.getByLabel('¿Vas solo o acompañado?');
  await expect(passengers).toBeVisible();
  await expect(passengers).not.toHaveAttribute('required', '');
  await expect(accompaniment).toBeVisible();
  await expect(accompaniment).toHaveValue('');
  await expect(accompaniment).not.toHaveAttribute('required', '');

  await page.getByLabel('¿Cómo te has desplazado?').selectOption({ label: 'Motocicleta (> 50 cc)' });
  await expect(passengers).toBeVisible();
  await expect(passengers).not.toHaveAttribute('required', '');
  await expect(accompaniment).toBeHidden();
  await expect(accompaniment).toBeDisabled();
});

test('TRA-04 — furgoneta de mercancías limita métodos por país y ofrece unidades compatibles', async ({ page }) => {
  const country = page.getByLabel('País');
  const mode = page.getByLabel('¿Cómo se transportó la mercancía?');
  const method = page.getByLabel('¿Qué dato tienes?');

  await country.selectOption({ label: 'España' });
  await page.locator('label[for="transport-category-freight"]').click();
  await mode.selectOption({ label: 'Furgoneta de mercancías' });
  await expect(method.locator('option:not([value=""])')).not.toContainText(['Peso de la carga + distancia']);
  await expect(method.locator('option:not([value=""])')).not.toContainText(['Tonelada-kilómetro']);

  await country.selectOption({ label: 'Francia' });
  await mode.selectOption({ label: 'Furgoneta de mercancías' });
  await expect(method.locator('option:not([value=""])')).toContainText(['Peso de la carga + distancia']);
  await expect(method.locator('option:not([value=""])')).not.toContainText(['Tonelada-kilómetro']);
  await method.selectOption({ label: 'Peso de la carga + distancia' });

  await expect(page.locator('[name="activityUnit"] option:not([value=""])')).toHaveText(['km', 'mi']);
  await expect(page.locator('[name="weightUnit"] option:not([value=""])')).toHaveText([
    'kg', 't', 'lb', 'short ton', 'long ton',
  ]);
});

test('TRA-06 — motocicleta > 50 cc en España obtiene preview calculado con distancia válida', async ({ page }) => {
  await page.getByLabel('Fecha de inicio').fill('2025-06-01');
  await page.getByLabel('Fecha final').fill('2025-06-01');
  await page.getByLabel('País').selectOption({ label: 'España' });
  await page.locator('label[for="transport-category-local"]').click();
  await page.getByLabel('¿Cómo te has desplazado?').selectOption({ label: 'Motocicleta (> 50 cc)' });
  await page.getByLabel('¿Qué dato tienes?').selectOption({ label: 'Kilómetros recorridos' });
  await page.locator('[name="activityValue"]').fill('10');
  await page.locator('[name="activityUnit"]').selectOption('km');
  await page.getByLabel('¿Cuántas personas del proyecto viajaban?').fill('1');
  await page.getByLabel('¿Cuántos desplazamientos como este?').fill('1');

  await expect(page.locator('[data-transport-v20-form-target="previewStatus"]')).toHaveText('Calculado');
  await expect(page.locator('[data-transport-v20-form-target="previewEmission"]')).not.toHaveText('—');
});
