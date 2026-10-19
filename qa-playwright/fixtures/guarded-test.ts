import { test as base, expect } from '@playwright/test';
import { protectPage, assertSafeNetwork } from '../helpers/network-guard';

export const test = base.extend({
  page: async ({ page }, use, testInfo) => {
    const errors: string[] = [];
    const violations = await protectPage(page, 'read-only');
    page.on('pageerror', error => errors.push(error.message));
    await use(page);
    if (errors.length || violations.length) {
      await testInfo.attach('browser-and-guard-errors.txt', {
        body: [...errors.map(e => `JS: ${e}`), ...violations.map(e => `BLOQUEADO: ${e}`)].join('\n'),
        contentType: 'text/plain',
      });
    }
    assertSafeNetwork(violations);
    expect(errors, `Errores JavaScript inesperados: ${errors.join('; ')}`).toEqual([]);
  },
});
export { expect };
