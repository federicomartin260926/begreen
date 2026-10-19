import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const qaRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const authFile = path.join(qaRoot, '.auth', 'dev-user.json');
const qaConfigPath = path.join(qaRoot, 'qa.local.json');

export type QaSettings = {
  baseUrl: string;
  loginPath: string;
  projectName: string;
  activeUser: string;
  auth: { email: string; password: string };
  browser: { channel: 'chrome'; headless: boolean };
};

export function loadQaSettings(): QaSettings {
  if (!existsSync(qaConfigPath)) {
    throw new Error(`Falta ${qaConfigPath}. Copia qa.local.example.json y rellena tus credenciales DEV.`);
  }
  let user: any;
  try {
    user = JSON.parse(readFileSync(qaConfigPath, 'utf8'));
  } catch {
    throw new Error('qa.local.json no contiene JSON válido (no se muestran valores por seguridad).');
  }
  const baseUrl = String(user?.baseUrl || '');
  let parsed: URL;
  try {
    parsed = new URL(baseUrl);
  } catch {
    throw new Error('baseUrl de qa.local.json no es una URL válida.');
  }
  // Fase #74: único host autorizado, incluso si se cambia accidentalmente qa.local.json.
  if (parsed.protocol !== 'https:' || parsed.hostname !== 'begreen.tech-investments.net'
      || parsed.port || parsed.username || parsed.password || parsed.pathname !== '/'
      || parsed.search || parsed.hash) {
    throw new Error('Bloqueado: QA #74 admite SOLO https://begreen.tech-investments.net/');
  }
  const activeUser = String(user?.activeUser || '');
  if (!/^[a-zA-Z0-9_-]{1,40}$/.test(activeUser)) {
    throw new Error('activeUser no es una clave de usuario válida.');
  }
  const selectedUser = user?.users?.[activeUser];
  const email = selectedUser?.email;
  const password = selectedUser?.password;
  if (typeof email !== 'string' || !email.includes('@') || email.includes('SUSTITUIR')
      || typeof password !== 'string' || password.length === 0 || password.includes('SUSTITUIR')) {
    throw new Error('Rellena users[activeUser].email y .password en qa.local.json (privado y fuera de Git).');
  }
  if (user?.projectName !== 'Fede') {
    throw new Error('Bloqueado: esta suite #74 está preparada solo para el proyecto QA Fede.');
  }
  const loginPath = String(user?.loginPath || '/login/es');
  if (!/^\/login\/(es|en)$/.test(loginPath)) {
    throw new Error('Solo se admite /login/es o /login/en.');
  }
  if (user?.browser?.channel && user.browser.channel !== 'chrome') {
    throw new Error('Fase 2: solo Google Chrome instalado, sin descargas adicionales.');
  }
  return {
    baseUrl: parsed.origin,
    loginPath,
    projectName: 'Fede',
    activeUser,
    auth: { email, password },
    browser: { channel: 'chrome', headless: user?.browser?.headless !== false },
  };
}
