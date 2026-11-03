import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

// BEGREEN #78 C2. Sólo interacciones de navegador; nunca submit ni persistencia.
// No se salta silenciosamente ninguna categoría si falta un campo o la navegación.
for (const [key, category] of Object.entries(categories)) {
  test(`C2 ${key}: inicio copia fin vacío y respeta fin manual`, async ({ page }) => {
    await openNewFormFromIndex(page, category);
    const form = page.locator('form[data-emission-date-default]');
    await expect(form, `Marcador de C2 ausente en ${key}`).toHaveCount(1);
    const start = form.locator('input[type="date"][name="startDate"]');
    const end = form.locator('input[type="date"][name="endDate"]');
    await expect(start).toHaveCount(1);
    await expect(end).toHaveCount(1);
    await expect(start).toBeVisible();
    await expect(end).toBeVisible();
    await expect(end).toBeEnabled();

    // Establecer estado inicial conocido sin guardar registro.
    await end.fill('');
    await start.fill('2026-10-01');
    await start.dispatchEvent('change', { bubbles: true });
    await expect(end, `C2 no copia fecha en ${key}`).toHaveValue('2026-10-01');

    // No sobrescribir la decisión manual, incluso tras un nuevo cambio del inicio.
    await end.fill('2026-10-03');
    await start.fill('2026-10-02');
    await start.dispatchEvent('change', { bubbles: true });
    await expect(end, `C2 sobrescribe fecha manual en ${key}`).toHaveValue('2026-10-03');
  });
}
