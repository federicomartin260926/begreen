import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

// BEGREEN #78 C1: mejora funcional. Sólo lectura / interacciones DOM, sin submit.
test('C1: país inicial válido y unidades compatibles en Transporte', async ({ page }) => {
  await openNewFormFromIndex(page, categories.transport);
  const root = page.locator('[data-controller~="transport-v20-form"]');
  await expect(root).toHaveCount(1);
  const form = root.locator('form');
  await expect(form).toHaveCount(1);
  const country = form.locator('select[name="country"]');
  await expect(country).toHaveCount(1);
  const selected = await country.inputValue();
  expect(selected, 'C1: el país del proyecto no se preseleccionó').toMatch(/^[A-Z]{3}$/);
  expect(await country.locator(`option[value="${selected}"]`).count()).toBe(1);

  const weight = form.locator('select[name="weightUnit"]');
  const activity = form.locator('select[name="activityUnit"]');
  await expect(weight).toHaveCount(1);
  await expect(activity).toHaveCount(1);
  if (selected === 'ESP') {
    await expect(weight, 'España: kg debe ser unidad inicial').toHaveValue('kg');
    // Se comprueba la opción cuando el método elegido la ofrece; no se fuerza un método inexistente.
    const method = form.locator('select[name="method"]');
    await expect(method).toHaveCount(1);
    const distanceAvailable = await method.locator('option[value="distance"]').count();
    if (distanceAvailable > 0) {
      await method.selectOption('distance');
      await method.dispatchEvent('change', { bubbles: true });
      await expect(activity.locator('option[value="km"]')).toHaveCount(1);
      await expect(activity, 'España: km por defecto en método distancia').toHaveValue('km');
      // Cambio de país reconstruye los selectores: conservar unidad manual si sigue siendo válida.
      const alternatives = await activity.locator('option').evaluateAll(options =>
        options.map(option => (option as HTMLOptionElement).value).filter(value => value && value !== 'km'));
      if (alternatives.length) {
        const manuallySelected = alternatives[0];
        await activity.selectOption(manuallySelected);
        await country.dispatchEvent('change', { bubbles: true });
        await expect(activity, 'C1: unidad manual sobrescrita al recalcular').toHaveValue(manuallySelected);
      }
    }
  }
});
