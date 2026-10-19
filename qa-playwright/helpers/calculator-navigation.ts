import { expect, type Page } from '@playwright/test';

export type Category = {
  code: string;
  title: string;
  newPath: string;
};

export const categories: Record<string, Category> = {
  transport: { code: 'TRA', title: 'Transporte', newPath: '/new-transport' },
  energy: { code: 'ENE', title: 'Energía', newPath: '/new-energy-v1' },
  water: { code: 'AGU', title: 'Agua', newPath: '/new-water-v1' },
  accommodation: { code: 'ALO', title: 'Alojamiento', newPath: '/new-accommodation-v1' },
  catering: { code: 'CAT', title: 'Catering', newPath: '/new-catering-v1' },
  waste: { code: 'RES', title: 'Residuos', newPath: '/new-waste-v1' },
  materials: { code: 'MAT', title: 'Materiales', newPath: '/new-material-v1' },
};

export async function openCalculatorIndex(page: Page) {
  const res = await page.goto('/backend/emission/records');
  expect(res?.ok(), 'El listado de la Calculadora debe devolver HTTP 2xx').toBeTruthy();
  await expect(page).toHaveURL(/\/backend\/emission\/records/);
}

export async function openNewFormFromIndex(page: Page, category: Category) {
  await openCalculatorIndex(page);
  // Contrato estable del backend. Si no hay enlaces, FAIL: no inventar categoryId efímeros.
  // Nota: se utilizan href de rutas Symfony (no clases CSS frágiles); el enlace puede vivir en menú colapsado.
  const routeLinks = page.locator(`a[href*="${category.newPath}"]`);
  const n = await routeLinks.count();
  expect(n, `No se encontró enlace de alta para ${category.title} (${category.newPath}). Revisar navegación real.`).toBeGreaterThan(0);
  const href = await routeLinks.first().getAttribute('href');
  expect(href).toBeTruthy();
  const target = new URL(href!, page.url());
  expect(target.origin).toBe('https://begreen.tech-investments.net');
  expect(target.pathname).toContain(category.newPath);
  const response = await page.goto(target.toString());
  expect(response?.ok(), `El alta de ${category.title} debe cargar sin error`).toBeTruthy();
  await expect(page).toHaveURL(new RegExp(category.newPath.replace(/\//g, '\\/')));
  await expect(page.locator('form').first(), `El alta ${category.title} debe contener un formulario visible`).toBeVisible();
  const visibleFields = await page.locator('form input:visible, form select:visible, form textarea:visible').count();
  expect(visibleFields, `El formulario de ${category.title} debe mostrar campos editables`).toBeGreaterThan(0);
}
