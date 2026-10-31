import { readFileSync } from 'node:fs';
import path from 'node:path';
import { qaRoot } from './qa';

export type VerifierSettings = {
  baseUrl: string;
  loginPath: string;
  email: string;
  password: string;
  forbiddenProjectId: number;
  browser: { channel: 'chrome'; headless: boolean };
};

const ORIGIN = 'https://begreen.tech-investments.net';
const USER_EMAIL = 'verificadora.qa82@example.test';

export function loadVerifierSettings(): VerifierSettings {
  const file = path.join(qaRoot, 'qa.local.json');
  let data: any;
  try {
    data = JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    throw new Error('QA #82: qa.local.json ausente o JSON inválido (credenciales no impresas).');
  }
  if (data?.baseUrl !== ORIGIN) {
    throw new Error('QA #82: SOLO se permite https://begreen.tech-investments.net');
  }
  if (data?.writeMode?.enabled !== false) {
    throw new Error('QA #82: writeMode.enabled debe ser false.');
  }
  const loginPath = data?.loginPath || '/login/es';
  if (loginPath !== '/login/es' && loginPath !== '/login/en') {
    throw new Error('QA #82: ruta de login no autorizada.');
  }
  const account = data?.users?.verifierQa;
  if (account?.email !== USER_EMAIL || typeof account?.password !== 'string'
      || account.password.length < 1 || account.password.includes('SUSTITUIR')) {
    throw new Error('QA #82: configurar users.verifierQa con la cuenta fixture verificada y contraseña privada.');
  }
  const id = data?.verifierQa?.forbiddenProjectId;
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new Error('QA #82: configurar verifierQa.forbiddenProjectId (ID real de Proyecto Fede visto como Super Admin).');
  }
  if (data?.browser?.channel && data.browser.channel !== 'chrome') {
    throw new Error('QA #82: solo Chrome local.');
  }
  return {
    baseUrl: ORIGIN,
    loginPath,
    email: USER_EMAIL,
    password: account.password,
    forbiddenProjectId: id,
    browser: { channel: 'chrome', headless: data?.browser?.headless !== false },
  };
}
