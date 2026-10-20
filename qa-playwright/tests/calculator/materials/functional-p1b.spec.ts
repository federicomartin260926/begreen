import type { Page } from '@playwright/test';
import { test, expect } from '../../../fixtures/guarded-test';
import { categories, openNewFormFromIndex } from '../../../helpers/calculator-navigation';

async function chooseMaterial(page: Page, family: string, material: string) {
  await page.getByLabel('Familia *', { exact: true }).selectOption({ label: family });
  await page.getByLabel('Material *', { exact: true }).selectOption({ label: material });
}

test.beforeEach(async ({ page }) => {
  await openNewFormFromIndex(page, categories.materials);
});

test('MAT-03 — papel por paquetes muestra formato, paquetes y 500 hojas por defecto', async ({ page }) => {
  await chooseMaterial(page, 'Papel', 'Papel');
  await page.getByLabel('Método de medición *', { exact: true }).selectOption({ label: 'Por paquetes' });

  await expect(page.getByLabel('Formato de papel *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Formato de papel *', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Número de paquetes *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Hojas por paquete')).toHaveValue('500');
});

test('MAT-06 — madera por peso exige elegir peso total o peso unitario', async ({ page }) => {
  await chooseMaterial(page, 'Madera', 'Madera');
  await page.getByLabel('Método de medición *', { exact: true }).selectOption({ label: 'Por peso' });
  const weightMode = page.getByLabel('Modalidad de peso *', { exact: true });

  await expect(weightMode).toBeVisible();
  await expect(weightMode).toHaveValue('');
  await expect(weightMode.locator('option:not([value=""])')).toHaveText([
    'Peso total (kg)', 'Peso por unidad (kg) × número de unidades',
  ]);
  await weightMode.selectOption('total');
  await expect(page.getByLabel('Cantidad *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Unidad *', { exact: true })).toHaveValue('kg');

  await weightMode.selectOption('unit');
  await expect(page.getByLabel('Peso por unidad (kg) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Número de unidades *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Cantidad *', { exact: true })).toBeHidden();
});

test('MAT-07 — madera por medidas adapta especie, tablero y madera desconocida', async ({ page }) => {
  await chooseMaterial(page, 'Madera', 'Madera');
  await page.getByLabel('Método de medición *', { exact: true }).selectOption({ label: 'Por medidas' });
  const selection = page.getByLabel('Tipo de madera', { exact: true });
  const species = page.getByLabel('Tipo de madera maciza', { exact: true });

  await expect(selection).toHaveValue('');
  await selection.selectOption({ label: 'Madera maciza' });
  await expect(species).toBeVisible();
  await expect(page.getByLabel('Largo (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Ancho (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Grosor (m) *', { exact: true })).toBeVisible();

  const boardValue = await selection.locator('option:not([value=""])').evaluateAll((options) =>
    options.map((option) => (option as HTMLOptionElement).value)
      .find((value) => value !== 'Madera maciza' && value !== 'Desconocida') || '',
  );
  expect(boardValue, 'Debe existir al menos una familia de tablero').not.toBe('');
  await selection.selectOption(boardValue);
  await expect(page.getByLabel('Grosor de tablero')).toBeVisible();
  await expect(species).toBeHidden();

  await selection.selectOption({ label: 'Desconocida' });
  await expect(species).toBeHidden();
  await expect(page.getByLabel('Largo (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Ancho (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Grosor (m) *', { exact: true })).toBeVisible();
});

test('MAT-08 — madera y papel presentan sus orígenes con etiquetas específicas', async ({ page }) => {
  await chooseMaterial(page, 'Madera', 'Madera');
  const origin = page.getByLabel('Origen o estado *', { exact: true });
  await expect(origin).toHaveValue('');
  await expect(origin.locator('option:not([value=""])')).toHaveText(['Comprada', 'Reciclada', 'Reutilizada']);

  await chooseMaterial(page, 'Papel', 'Papel');
  await expect(origin).toHaveValue('');
  await expect(origin.locator('option:not([value=""])')).toHaveText([
    'Comprado', 'Comprado reciclado', 'Reutilizado',
  ]);
});

test('MAT-09 — cartón ofrece estructura y adapta peso frente a medidas', async ({ page }) => {
  await chooseMaterial(page, 'Cartón', 'Cartón');
  const structure = page.getByLabel('Estructura del cartón');
  const method = page.getByLabel('Método de medición *', { exact: true });

  await expect(structure).toBeVisible();
  await expect(structure).toHaveValue('');
  await expect(structure.locator('option:not([value=""])')).toContainText([
    'Cartón compacto / no corrugado', 'Cartón corrugado de 3 capas', 'Desconocido',
  ]);
  await method.selectOption({ label: 'Por peso' });
  await expect(page.getByLabel('Cantidad *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Tipo de caja')).toBeHidden();

  await method.selectOption({ label: 'Por medidas' });
  await expect(page.getByLabel('Tipo de caja')).toBeVisible();
  await expect(page.getByLabel('Largo (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Ancho (m) *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Número de unidades *', { exact: true })).toBeVisible();
});

test('MAT-10 — metal, plástico y ropa muestran únicamente sus dependencias propias', async ({ page }) => {
  await chooseMaterial(page, 'Metal para estructuras', 'Metal para estructuras (perfiles, rieles)');
  await expect(page.getByLabel('Material metálico')).toBeVisible();
  await expect(page.getByLabel('Forma del metal')).toBeVisible();
  await expect(page.getByLabel('Método de medición *', { exact: true }).locator('option:not([value=""])')).toHaveText([
    'Por peso', 'Por unidades o piezas',
  ]);

  await page.getByLabel('Familia *', { exact: true }).selectOption({ label: 'Plástico' });
  const plastic = page.getByLabel('Material *', { exact: true });
  await expect(plastic).toHaveValue('');
  expect(await plastic.locator('option:not([value=""])').count(), 'Plástico debe ofrecer sus tipos').toBeGreaterThan(5);
  await expect(page.getByLabel('Material metálico')).toBeHidden();

  await chooseMaterial(page, 'Ropa y accesorios', 'Ropa y accesorios');
  const group = page.getByLabel('Grupo');
  await expect(group).toBeVisible();
  await expect(group).toHaveValue('');
  await group.selectOption({ label: 'Parte de arriba' });
  await expect(page.getByLabel('Tipo o subproducto *', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Tipo o subproducto *', { exact: true })).toHaveValue('');
});

test('MAT-11 — Litio-Ion 9V permite calcular por unidades con peso aprobado', async ({ page }) => {
  await page.getByLabel('Fecha de inicio *').fill('2025-06-01');
  await page.getByLabel('Fecha de fin *').fill('2025-06-01');
  await page.getByLabel('País *').selectOption({ label: 'España' });
  await chooseMaterial(page, 'Pilas y baterías', 'Pilas, baterías');
  await page.getByLabel('Tipo o subproducto *', { exact: true }).selectOption({ label: 'Pila/batería de Ion de litio' });
  await page.getByLabel('Origen o estado *', { exact: true }).selectOption({ index: 1 });
  await page.getByLabel('Método de medición *', { exact: true }).selectOption({ label: 'Por unidades o piezas' });
  const batterySize = page.getByLabel('Formato de pila o batería *', { exact: true });
  await expect(batterySize).toBeVisible();
  await expect(batterySize.locator('option:not([value=""])')).toContainText(['9V']);
  await batterySize.selectOption({ label: '9V' });
  await page.getByLabel('Número de unidades *', { exact: true }).fill('1');
  await expect(page.locator('[data-material-v1-form-target="previewStatus"]')).toHaveText('Calculado');
  await expect(page.locator('[data-material-v1-form-target="previewEmission"]')).not.toHaveText('—');
});

test('MAT-12 — familias restantes exponen métodos representativos documentados', async ({ page }) => {
  const representativeMethods = [
    ['Placas de yeso', 'Placas de yeso', ['Por peso', 'Por unidades o piezas']],
    ['Moqueta', 'Moqueta', ['Por superficie', 'Por peso']],
    ['Pintura', 'Pintura', ['Por volumen', 'Por peso']],
    ['Disolvente / aguarrás', 'Disolvente', ['Por volumen']],
  ] as const;
  for (const [family, material, methods] of representativeMethods) {
    await chooseMaterial(page, family, material);
    await expect(page.getByLabel('Método de medición *', { exact: true }).locator('option:not([value=""])')).toHaveText(methods);
  }
});
