import { expect, type Page } from '@playwright/test';

const DEV_ORIGIN = 'https://begreen.tech-investments.net';
const PREVIEW_PATHS = /^\/backend\/emission\/(?:transport|energy|water|accommodation|catering|waste|material)\/preview$/;
const LOGIN_PATHS = /^\/login\/(?:es|en)$/;
const DANGEROUS_GET = /\/(?:duplicate(?:-[^/?]+)?|delete|remove)(?:\/|$|-)/i;

/** Política por contexto: evita escrituras no autorizadas y rutas peligrosas incluso por GET. */
export async function protectPage(page: Page, mode: 'auth' | 'read-only' = 'read-only') {
  const violations: string[] = [];
  await page.route('**/*', async route => {
    const req = route.request();
    const method = req.method().toUpperCase();
    const url = new URL(req.url());
    const isDev = url.origin === DEV_ORIGIN;

    if (req.isNavigationRequest() && !isDev) {
      violations.push(`Navegación fuera de DEV bloqueada: ${url.origin}${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    if (isDev && method === 'GET' && DANGEROUS_GET.test(url.pathname)) {
      violations.push(`GET potencialmente mutante bloqueado: ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      const login = mode === 'auth' && isDev && method === 'POST' && LOGIN_PATHS.test(url.pathname);
      const preview = isDev && method === 'POST' && PREVIEW_PATHS.test(url.pathname);
      if (!login && !preview) {
        violations.push(`Método no autorizado bloqueado: ${method} ${url.origin}${url.pathname}`);
        return route.abort('blockedbyclient');
      }
    }
    return route.continue();
  });
  return violations;
}

export function assertSafeNetwork(violations: string[]) {
  expect(violations, `La suite intentó una petición bloqueada; no fue ejecutada: ${violations.join('; ')}`).toEqual([]);
}
