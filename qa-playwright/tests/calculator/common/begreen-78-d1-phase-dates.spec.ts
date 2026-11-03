import { test, expect } from '../../../fixtures/guarded-test';

// BEGREEN #78 D1: alta de proyecto SIN guardar. El guard impide POST mutantes.
test('D1: fecha final propone inicio siguiente y respeta inicio manual', async ({ page }) => {
  const response = await page.goto('/backend/project/new');
  expect(response?.ok(), 'No se puede abrir alta de proyecto').toBeTruthy();
  const form = page.locator('form[data-controller~="project"]');
  await expect(form).toHaveCount(1);
  const rows = form.locator('[data-project-target="list"] [data-collection-id]');
  expect(await rows.count(), 'Se esperan al menos dos fases de proyecto').toBeGreaterThanOrEqual(2);

  const phases = await rows.evaluateAll(items => items.map(item => ({
    phase: (item.querySelector('input[type="hidden"][name$="[phase]"]') as HTMLInputElement | null)?.value || '',
  })));
  const sequence = ['preproduccion', 'montaje', 'actividad', 'postproduccion', 'desmontaje'];
  const ordered = phases.map((item, index) => ({ ...item, index }))
    .sort((a, b) => sequence.indexOf(a.phase) - sequence.indexOf(b.phase));
  expect(ordered[0].phase).toBeTruthy();
  expect(ordered[1].phase).toBeTruthy();
  const prev = rows.nth(ordered[0].index).locator('input[type="date"][name$="[endDate]"]');
  const next = rows.nth(ordered[1].index).locator('input[type="date"][name$="[startDate]"]');
  await expect(prev).toHaveCount(1);
  await expect(next).toHaveCount(1);
  // Ajustar DOM, disparando eventos nativos sin submit ni escritura en backend.
  await next.evaluate((element: HTMLInputElement) => { element.value = ''; });
  await prev.evaluate((element: HTMLInputElement) => {
    element.value = '2026-12-10';
    element.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect(next, 'Inicio vacío debe heredar el fin anterior').toHaveValue('2026-12-10');

  await next.evaluate((element: HTMLInputElement) => { element.value = '2026-12-15'; });
  await prev.evaluate((element: HTMLInputElement) => {
    element.value = '2026-12-12';
    element.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect(next, 'No sobrescribir fecha inicial manual').toHaveValue('2026-12-15');
});
