import { expect, type Page } from '@playwright/test';

const DEV_ORIGIN = 'https://begreen.tech-investments.net';
const PREVIEW_PATHS = /^\/backend\/emission\/(?:transport|energy|water|accommodation|catering|waste|material)\/preview$/;
const LOGIN_PATHS = /^\/login\/(?:es|en)$/;
const DANGEROUS_GET = /\/(?:duplicate(?:-[^/?]+)?|delete|remove|select-project)(?:\/|$|-)/i;
const P2_CREATE_PATHS = new Set([
  '/backend/emission/new-water-v1',
  '/backend/emission/new-catering-v1',
]);
const P2_OWNED_PATH = /^\/backend\/emission\/([^/]+)\/(edit-water-v1|edit-catering-v1|duplicate-water-v1|duplicate-catering-v1)$/;
const P2_DELETE_PATH = /^\/backend\/emission\/([^/]+)\/delete$/;
const P2_SELECT_PROJECT_PATH = /^\/backend\/project\/select-project\/(\d+)$/;

export type WriteSession = {
  runId: string;
  marker: string;
  createdRecordIds: Set<string>;
  deletedRecordIds: Set<string>;
  mutations: string[];
  selectedProjectId?: string;
};

/** Política por contexto: evita escrituras no autorizadas y rutas peligrosas incluso por GET. */
export async function protectPage(
  page: Page,
  mode: 'auth' | 'read-only' | 'write' = 'read-only',
  writeSession?: WriteSession,
) {
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
    const ownedPath = url.pathname.match(P2_OWNED_PATH);
    const ownedGet = mode === 'write' && method === 'GET' && ownedPath
      && writeSession?.createdRecordIds.has(ownedPath[1]);
    const selectedProject = url.pathname.match(P2_SELECT_PROJECT_PATH);
    const projectSelection = mode === 'write' && method === 'GET' && selectedProject
      && selectedProject[1] === writeSession?.selectedProjectId
      && url.searchParams.get('target') === 'emissions';
    if (isDev && method === 'GET' && DANGEROUS_GET.test(url.pathname) && !ownedGet && !projectSelection) {
      violations.push(`GET potencialmente mutante bloqueado: ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      const login = mode === 'auth' && isDev && method === 'POST' && LOGIN_PATHS.test(url.pathname);
      const preview = isDev && method === 'POST' && PREVIEW_PATHS.test(url.pathname);
      const postData = req.postData() || '';
      const create = mode === 'write' && isDev && method === 'POST'
        && P2_CREATE_PATHS.has(url.pathname)
        && Boolean(writeSession?.marker && postData.includes(writeSession.marker));
      const edit = mode === 'write' && isDev && method === 'POST' && ownedPath
        && ownedPath[1] !== undefined && ownedPath[2].startsWith('edit-')
        && Boolean(writeSession?.createdRecordIds.has(ownedPath[1]))
        && Boolean(writeSession?.marker && postData.includes(writeSession.marker));
      const deletePath = url.pathname.match(P2_DELETE_PATH);
      const remove = mode === 'write' && isDev && method === 'POST' && deletePath
        && Boolean(writeSession?.createdRecordIds.has(deletePath[1]));
      if (!login && !preview && !create && !edit && !remove) {
        violations.push(`Método no autorizado bloqueado: ${method} ${url.origin}${url.pathname}`);
        return route.abort('blockedbyclient');
      }
      if (create || edit || remove) writeSession?.mutations.push(`${method} ${url.pathname}`);
    }
    return route.continue();
  });
  return violations;
}

export function assertSafeNetwork(violations: string[]) {
  expect(violations, `La suite intentó una petición bloqueada; no fue ejecutada: ${violations.join('; ')}`).toEqual([]);
}
