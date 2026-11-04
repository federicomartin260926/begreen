import { test, expect } from '../../../fixtures/guarded-test';

// #78, bug independiente de Administración: AJAX no debe reducir una selección múltiple.
// READ-ONLY: se visita el formulario existente, pero no se envía ni se guarda.
test('Administración medida 5611: conserva 22 departamentos después de AJAX', async ({ page }) => {
  const departmentsAjax = page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === '/admin/departments/by-protocol'
      && response.request().method() === 'GET';
  }, { timeout: 15000 });
  const response = await page.goto('/admin/measures/5611/edit');
  expect(response?.ok(), 'La cuenta QA debe tener acceso de Super Admin al formulario').toBeTruthy();
  await expect(page).toHaveURL(/\/admin\/measures\/5611\/edit(?:\?|$)/);

  const select = page.locator('select[name="measure[departments][]"]');
  await expect(select).toHaveCount(1);
  await expect(select).toHaveJSProperty('multiple', true);

  // Esperar la respuesta real del endpoint antes de contar las opciones.
  const ajaxResponse = await departmentsAjax;
  expect(ajaxResponse.ok(), 'La petición AJAX de departamentos debe responder correctamente').toBeTruthy();
  // El controlador Stimulus recarga opciones por AJAX de manera asíncrona.
  await expect.poll(async () => select.locator('option:checked').count(), {
    message: 'La recarga AJAX debe conservar las 22 selecciones existentes',
    timeout: 15000,
  }).toBe(22);
  await expect(select.locator('option:checked[value="634"]')).toHaveCount(1);
  const ids = await select.locator('option:checked').evaluateAll(options =>
    options.map(option => (option as HTMLOptionElement).value));
  expect(new Set(ids).size, 'No debe haber IDs duplicados').toBe(22);
});
