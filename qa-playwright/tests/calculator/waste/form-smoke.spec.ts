import { test } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test('SMOKE — carga del alta de residuos desde la Calculadora', async ({ page }) => {
  await openNewFormFromIndex(page, categories.waste);
});
