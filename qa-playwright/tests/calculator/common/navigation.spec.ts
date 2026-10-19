import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openCalculatorIndex } from '../../../helpers/calculator-navigation';

test('COM-01 / SMOKE — Listado de Calculadora y siete categorías', async ({ page }) => {
  await openCalculatorIndex(page);
  for (const category of Object.values(categories)) {
    // Verificamos presencia real en DOM; la inspección de cada formulario se realiza por separado.
    await expect(page.locator('body'), `Falta categoría ${category.title}`).toContainText(category.title);
  }
});
