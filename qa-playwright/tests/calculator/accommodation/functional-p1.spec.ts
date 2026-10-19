import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.accommodation);
});

test('COM-02 — el alta limpia de Alojamiento no preselecciona selectores principales', async ({ page }) => {
  await expect(page.getByLabel('Fecha de inicio *')).toHaveValue('');
  await expect(page.getByLabel('Fecha final *')).toHaveValue('');
  await expect(page.getByLabel('País *')).toHaveValue('');
  await expect(page.getByLabel('Tipo de alojamiento *')).toHaveValue('');

  for (const label of ['Estrellas *', 'Habitaciones ocupadas', 'Personas', 'Noches *']) {
    await expect(page.getByLabel(label)).toBeHidden();
    await expect(page.getByLabel(label)).toBeDisabled();
  }
});

test('ALO-01 — ofrece los cuatro tipos documentados sin elección arbitraria', async ({ page }) => {
  const type = page.getByLabel('Tipo de alojamiento *');

  await expect(type.locator('option')).toHaveText([
    'Seleccione…',
    'Hotel',
    'Hostal / pensión',
    'Apartamento / vivienda',
    'Otro',
  ]);
  await expect(type).toHaveValue('');
});

test('ALO-01 / ALO-03 — cada tipo muestra y exige únicamente sus cantidades aplicables', async ({ page }) => {
  const type = page.getByLabel('Tipo de alojamiento *');
  const stars = page.getByLabel('Estrellas *');
  const rooms = page.getByLabel('Habitaciones ocupadas');
  const people = page.getByLabel('Personas');
  const nights = page.getByLabel('Noches *');

  await type.selectOption({ label: 'Hotel' });
  for (const field of [stars, rooms, nights]) {
    await expect(field).toBeVisible();
    await expect(field).toBeEnabled();
    await expect(field).toHaveAttribute('required', '');
  }
  await expect(people).toBeHidden();
  await expect(people).toBeDisabled();

  await type.selectOption({ label: 'Hostal / pensión' });
  for (const field of [people, nights]) {
    await expect(field).toBeVisible();
    await expect(field).toBeEnabled();
    await expect(field).toHaveAttribute('required', '');
  }
  await expect(stars).toBeHidden();
  await expect(rooms).toBeHidden();

  await type.selectOption({ label: 'Apartamento / vivienda' });
  await expect(people).toBeVisible();
  await expect(nights).toBeVisible();

  await type.selectOption({ label: 'Otro' });
  for (const field of [stars, rooms, people, nights]) {
    await expect(field).toBeHidden();
    await expect(field).toBeDisabled();
    await expect(field).not.toHaveAttribute('required', '');
  }
});

test('ALO-02 — Hostal / pensión produce una vista previa funcional huésped-noche', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha final *').fill('2025-06-02');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await page.getByLabel('Tipo de alojamiento *').selectOption({ label: 'Hostal / pensión' });
  await page.getByLabel('Personas').fill('2');
  await page.getByLabel('Noches *').fill('3');

  await expect(page.getByText('Calculado', { exact: true })).toBeVisible();
  await expect(page.getByText(/6 huésped-noche/)).toBeVisible();
  await expect(page.locator('[data-accommodation-v1-form-target="previewEmission"]')).not.toHaveText('—');
});
