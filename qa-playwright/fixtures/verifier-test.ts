import { test as base, expect } from '@playwright/test';
import { protectPage, assertSafeNetwork } from '../helpers/network-guard';

/** GET sólo de lectura y POST exclusivamente para autenticación.
 * La única excepción GET de selección de proyecto (sesión, NO DB) se autoriza
 * explícitamente en el test tras verificar su ID en el listado permitido.
 */
export const test = base.extend({
  page: async ({ page }, use, testInfo) => {
    const errors: string[] = [];
    const violations = await protectPage(page, 'auth');
    page.on('pageerror', error => errors.push(error.message));
    await use(page);
    if (errors.length || violations.length) {
      await testInfo.attach('verifier-browser-network.txt', {
        body: [...errors.map(e => `JS=${e}`), ...violations.map(e => `GUARD=${e}`)].join('\n'),
        contentType: 'text/plain',
      });
    }
    assertSafeNetwork(violations);
    expect(errors, `Errores JS: ${errors.join('; ')}`).toEqual([]);
  },
});
export { expect };
