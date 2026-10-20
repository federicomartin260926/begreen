import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/persistence-test';
import type { WriteSession } from '../../helpers/network-guard';

type CategoryContext = {
  newUrl: string;
  listingUrl: string;
  editSuffix: string;
  duplicateSuffix: string;
};

async function categoryContext(page: Page, newPath: string, editSuffix: string, duplicateSuffix: string): Promise<CategoryContext> {
  await page.goto('/backend/emission/records');
  const href = await page.locator(`a[href*="${newPath}"]`).first().getAttribute('href');
  expect(href, `Debe existir el enlace de alta ${newPath}`).toBeTruthy();
  const newUrl = new URL(href!, page.url());
  const categoryId = newUrl.searchParams.get('categoryId');
  expect(categoryId, 'El enlace debe conservar el contexto de categoría').toBeTruthy();
  const listing = new URL('/backend/emission/records', page.url());
  listing.searchParams.set('categoryId', categoryId!);
  listing.searchParams.set('showAll', '1');
  return { newUrl: newUrl.toString(), listingUrl: listing.toString(), editSuffix, duplicateSuffix };
}

async function recordIds(page: Page, context: CategoryContext): Promise<Set<string>> {
  const response = await page.goto(context.listingUrl);
  expect(response?.ok(), 'El listado completo de la categoría debe cargar').toBeTruthy();
  const hrefs = await page.locator(`a[href*="/${context.editSuffix}"]`).evaluateAll(links =>
    links.map(link => (link as HTMLAnchorElement).href),
  );
  return new Set(hrefs.map(href => new URL(href).pathname.split('/').at(-2)!).filter(Boolean));
}

async function registerNewRecord(
  page: Page,
  context: CategoryContext,
  previousIds: Set<string>,
  session: WriteSession,
  label: string,
  expectedNote: string,
): Promise<string> {
  const currentIds = await recordIds(page, context);
  const candidates = [...currentIds].filter(id => !previousIds.has(id));
  const owned: string[] = [];
  for (const id of candidates) {
    await page.goto(context.listingUrl);
    const href = await page.locator(`a[href*="/${id}/${context.editSuffix}"]`).getAttribute('href');
    if (!href) continue;
    await page.goto(href);
    if (await page.getByLabel('Observaciones').inputValue() === expectedNote) owned.push(id);
  }
  owned.forEach(id => session.createdRecordIds.add(id));
  expect(owned, `${label}: debe existir exactamente un ID nuevo con el marcador de esta ejecución`).toHaveLength(1);
  console.log(`QA74_CREATED ${label} ${owned[0]}`);
  return owned[0];
}

async function openOwnedEdit(page: Page, context: CategoryContext, id: string, marker: string) {
  await page.goto(context.listingUrl);
  const edit = page.locator(`a[href*="/${id}/${context.editSuffix}"]`);
  await expect(edit.first(), `El registro QA ${id} debe aparecer en el listado`).toBeAttached();
  const editHref = await edit.first().getAttribute('href');
  expect(editHref, 'Debe existir la URL de edición propia').toBeTruthy();
  await page.goto(editHref!);
  await expect(page.getByLabel('Observaciones')).toHaveValue(new RegExp(marker));
}

async function deleteOwned(page: Page, context: CategoryContext, id: string, session: WriteSession) {
  await openOwnedEdit(page, context, id, session.marker);
  await page.goto(context.listingUrl);
  const form = page.locator(`form[action*="/${id}/delete"]`);
  await expect(form, `Solo se elimina el registro QA verificado ${id}`).toHaveCount(1);
  const menu = form.locator(
    'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " emission-dashboard-record__menu ")][1]'
  );
  await expect(menu).toHaveCount(1);
  await menu.locator(':scope > button').click();
  page.once('dialog', dialog => dialog.accept());
  await form.getByRole('button', { name: 'Eliminar' }).click();
  await expect(page).toHaveURL(/\/backend\/emission\/records/);
  await page.goto(context.listingUrl);
  await expect(page.locator(`a[href*="/${id}/${context.editSuffix}"]`)).toHaveCount(0);
  session.deletedRecordIds.add(id);
  console.log(`QA74_DELETED ${id}`);
}

test('P2 — ciclo controlado Agua y persistencia estructurada de Catering', async ({ page, writeSession }) => {
  test.setTimeout(240_000);
  const water = await categoryContext(page, '/new-water-v1', 'edit-water-v1', 'duplicate-water-v1');
  const catering = await categoryContext(page, '/new-catering-v1', 'edit-catering-v1', 'duplicate-catering-v1');

  try {
    let waterId = '';
    const originalWaterNote = `${writeSession.marker}-WATER-ORIGINAL`;
    const editedWaterNote = `${writeSession.marker}-WATER-EDITED`;
    const duplicateWaterNote = `${writeSession.marker}-WATER-DUPLICATE`;

    await test.step('WRT-01 — crear Agua y comprobar listado', async () => {
      const before = await recordIds(page, water);
      await page.goto(water.newUrl);
      await page.getByLabel('Fecha de inicio *').fill('2026-10-10');
      await page.getByLabel('Fecha final *').fill('2026-10-10');
      await page.getByLabel('País *').selectOption({ label: 'España' });
      await page.getByLabel('Tipo de uso').selectOption({ label: 'Limpieza' });
      await page.getByLabel('Volumen').fill('74001');
      await page.getByLabel('Unidad').selectOption('L');
      await page.getByLabel('Destino').selectOption({ label: 'Alcantarillado / red de saneamiento' });
      await page.getByLabel('Observaciones').fill(originalWaterNote);
      await page.getByRole('button', { name: 'Guardar' }).click();
      await expect(page).toHaveURL(/\/backend\/emission\/records/);
      waterId = await registerNewRecord(page, water, before, writeSession, 'water-original', originalWaterNote);
      await expect(page.locator(`a[href*="/${waterId}/edit-water-v1"]`).first()).toBeAttached();
    });

    await test.step('WRT-02 — editar solo el Agua propia y verificar reapertura', async () => {
      await openOwnedEdit(page, water, waterId, originalWaterNote);
      await page.getByLabel('Volumen').fill('74002');
      await page.getByLabel('Observaciones').fill(editedWaterNote);
      await page.getByRole('button', { name: 'Guardar' }).click();
      await expect(page).toHaveURL(/\/backend\/emission\/records/);
      await openOwnedEdit(page, water, waterId, editedWaterNote);
      await expect(page.getByLabel('Volumen')).toHaveValue('74002');
    });

    await test.step('WRT-03 — duplicar Agua propia sin modificar el original', async () => {
      const before = await recordIds(page, water);
      const duplicate = page.locator(`a[href*="/${waterId}/${water.duplicateSuffix}"]`);
      await expect(duplicate.first()).toBeAttached();
      const duplicateHref = await duplicate.first().getAttribute('href');
      expect(duplicateHref, 'Debe existir la URL de duplicación').toBeTruthy();
      await page.goto(duplicateHref!);
      await expect(page.getByRole('heading', { name: /Duplicar registro de Agua/ })).toBeVisible();
      await expect(page.getByLabel('Volumen')).toHaveValue('74002');
      await page.getByLabel('Observaciones').fill(duplicateWaterNote);
      await page.getByRole('button', { name: 'Guardar' }).click();
      const duplicateId = await registerNewRecord(page, water, before, writeSession, 'water-duplicate', duplicateWaterNote);

      await openOwnedEdit(page, water, waterId, editedWaterNote);
      await expect(page.getByLabel('Volumen')).toHaveValue('74002');
      await openOwnedEdit(page, water, duplicateId, duplicateWaterNote);
      await expect(page.getByLabel('Volumen')).toHaveValue('74002');

      await test.step('Eliminar exclusivamente el duplicado QA de esta ejecución', async () => {
        await deleteOwned(page, water, duplicateId, writeSession);
      });
    });

    await test.step('Catering — crear Menú y releer campos estructurados', async () => {
      const before = await recordIds(page, catering);
      await page.goto(catering.newUrl);
      await page.getByLabel('Fecha de inicio *').fill('2026-10-10');
      await page.getByLabel('Fecha final *').fill('2026-10-10');
      await page.getByLabel('País *').selectOption({ label: 'España' });
      await page.getByLabel('Actividad *').selectOption({ label: 'Menú' });
      await page.locator('select[name="menuVariant[]"]').selectOption({ label: 'Vegetariano' });
      await page.locator('input[name="preparedCount[]"]').fill('10');
      await page.locator('input[name="consumedCount[]"]').fill('8');
      await page.locator('select[name="tablewareType"]').selectOption({ label: 'Reutilizable' });
      const cateringNote = `${writeSession.marker}-CATERING-MENU`;
      await page.getByLabel('Observaciones').fill(cateringNote);
      await page.getByRole('button', { name: 'Guardar' }).click();
      const cateringId = await registerNewRecord(page, catering, before, writeSession, 'catering-menu', cateringNote);

      await openOwnedEdit(page, catering, cateringId, writeSession.marker);
      await expect(page.getByLabel('Actividad *').locator('option:checked')).toHaveText('Menú');
      await expect(page.locator('select[name="menuVariant[]"] option:checked')).toHaveText('Vegetariano');
      await expect(page.locator('input[name="preparedCount[]"]')).toHaveValue('10');
      await expect(page.locator('input[name="consumedCount[]"]')).toHaveValue('8');
      await expect(page.locator('select[name="tablewareType"] option:checked')).toHaveText('Reutilizable');
    });
  } finally {
    const retained = [...writeSession.createdRecordIds].filter(
      id => !writeSession.deletedRecordIds.has(id)
    );
    console.log(`QA74_RETAINED=${retained.join(',') || 'none'}`);
  }
});
