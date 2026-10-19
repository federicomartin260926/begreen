# BEGREEN #74 — Playwright de Calculadora CO₂

## Alcance y estado

**Fase 3 / bloques P0–P1:** además de la infraestructura E2E y el smoke de conexión, autenticación, lista de 7 categorías y carga de formularios, la suite cubre regresiones funcionales de Transporte, Energía, Agua, Materiales, Alojamiento, Catering y Residuos. La matriz completa de 51 grupos permanece en `docs/begreen-74-matriz-v2.md`.

Entorno fijado: `https://begreen.tech-investments.net` exclusivamente. No hay accesos a producción. Navegador Chrome instalado en PC local. No se requiere servidor adicional.

## Configuración persistente

1. Copia `qa.local.example.json` a `qa.local.json` (el instalador ya lo hace si no existe).
2. Edita `qa.local.json` y rellena `users.calculatorQa.email`, `users.calculatorQa.password`; `activeUser` indica cuál usar, y el proyecto QA será `Fede`. Puedes añadir otras cuentas bajo `users` en el futuro.
3. Este archivo está ignorado por Git. Los datos se conservan entre ejecuciones y pueden cambiarse cuando sea necesario. `chmod 600 qa.local.json`.
4. No enviar este JSON, archivos `.auth/` ni las trazas sin revisión. Nunca almacenar secretos en README, capturas o commits.

`qa.local.json` permite registrar de forma estable URL, login, proyecto, múltiples cuentas y opciones de navegador. No almacena identificadores efímeros de emisiones o categorías. Para varias cuentas futuras se puede ampliar el esquema sin alterar los tests.

## Instalación

Desde el repositorio local:

```bash
cd /home/fede/www/begreenmyfriend || exit 1
bash /tmp/begreen-74-phase2/install-local.sh
```

El instalador copia los ficheros, añade targets al Makefile y ejecuta `npm install --save-dev @playwright/test@latest`. No ejecuta Compose, migraciones, fixtures ni despliegues.

## Ejecución

```bash
cd /home/fede/www/begreenmyfriend || exit 1
make qa-smoke
make qa-calculator
make qa-calculator-transport
make qa-calculator-materials
make qa-failed
make qa-report
```

`qa-smoke` conserva **8 controles** (categorías y formularios), más el setup de login. `qa-calculator` ejecuta además las regresiones funcionales P0. Los comandos de Transporte y Materiales incluyen ahora sus casos funcionales P0.

Informes: `qa-playwright/artifacts/html-report/index.html`, `results.json`, capturas y trazas de fallos en `artifacts/test-results/`, logs resumidos en `artifacts/logs/`. Se conservan los logs durante 14 días, y Playwright actualiza evidencias de la última ejecución.

## Seguridad

- URL exacta obligatoria: solo DEV. Un cambio accidental a producción impide arrancar.
- Guard por navegador: se bloquean navegaciones fuera de DEV, GET a URLs peligrosas (duplicación/eliminación) y métodos mutantes salvo POST de login o previsualización explícitamente enumerada.
- Los siete métodos de preview se revisaron en #74 y no muestran persistencia directa. Verificar servicios internos antes de incorporar tests que dependan de previews.
- NO hay tests de guardar/editar/duplicar/eliminar en fase 2. Aunque DEV admite datos persistentes, las escrituras explícitas se añadirán por separado. Nunca producción.
- Los errores JS no controlados provocan FAIL. No se utiliza `test.skip` para excepciones pendientes de Franc.

## Diagnóstico de fallos

Si falla `auth-setup`, comprobar credenciales, permisos para la calculadora y que el proyecto activo del usuario sea Fede. Las páginas requieren `ProjectVoter::EDIT` para preview. No guardar ID numéricos de categoría ni reutilizar sesiones de producción. Si falla un enlace de alta, el test lo indica sin adivinar el ID; revisar el HTML Twig local y actualizar el helper de navegación. No se deben transformar estos fallos en PASS.

La selección activa del proyecto Fede no se puede comprobar de manera inequívoca con los fragmentos documentales disponibles. **Antes de ejecutar pruebas que escriban, se debe implementar una comprobación de identidad del proyecto desde una señal fiable del backend.**

## Referencias y ampliación

- Autoridad: decisiones de Franc aprobadas posteriores > reglas/contratos > Base Maestra vigente > especificaciones > wireframes UI.
- Fuente cerrada: `Begreen_71_fuentes_referencia_2026-10-09.zip`; mismo SHA de Base Maestra y entrada v8 que en #71.
- EX-01..EX-06 permanecen pendientes, no convertirlos en conformidad literal.
- Nueva regresión: añadir spec en la carpeta de la categoría con `getByRole/getByLabel` y la decisión documental correspondiente.
- No reauditar factores. No ampliar a otras funcionalidades BEGREEN en #74.
