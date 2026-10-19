import { test } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test('SMOKE — carga del alta de agua desde la Calculadora', async ({ page }) => {
  await openNewFormFromIndex(page, categories.water);
});
