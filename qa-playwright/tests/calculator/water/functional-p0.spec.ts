import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test('COM-02 / AGU-01 — el alta nueva de Agua no preselecciona unidad ni selectores principales', async ({ page }) => {
  await openNewFormFromIndex(page, categories.water);

  await expect(page.getByLabel('País *', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Tipo de uso')).toHaveValue('');
  await expect(page.getByLabel('Unidad')).toHaveValue('');
  await expect(page.getByLabel('Unidad')).not.toHaveValue('m3');
  await expect(page.getByLabel('Destino')).toHaveValue('');
});
