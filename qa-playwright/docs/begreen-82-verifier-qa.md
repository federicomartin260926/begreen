# BEGREEN #82 — QA Playwright Certificadora / Verificadora

Esta suite es independiente de #74 y no modifica su configuración ni la sesión `.auth/dev-user.json`.

## Antes de ejecutar

1. Desplegar el código de #82 en el entorno DEV, incluyendo `ROLE_VERIFIER`.
2. Cargar los fixtures actualizados **sólo con autorización expresa**. `make fixtures` PURGA la base de datos. La suite no los carga.
3. Como Super Admin, obtener el ID numérico **real** de `Proyecto Fede` desde la UI. No adivinarlo ni copiar IDs de otros entornos.
4. En el archivo privado ignorado `qa-playwright/qa.local.json`, conservar los valores de #74 y añadir:

```json
{
  "users": {
    "verifierQa": {
      "email": "verificadora.qa82@example.test",
      "password": "<CONTRASEÑA_REAL_DE_FIXTURES>"
    }
  },
  "verifierQa": {
    "forbiddenProjectId": 123
  }
}
```

**Nota:** es un fragmento; incorporar las propiedades a los objetos ya existentes. Cambiar `123` por el ID real. No subir `qa.local.json` ni comunicar contraseñas o capturas del login. Mantener `baseUrl=https://begreen.tech-investments.net` y `writeMode.enabled=false`. No cambiar `activeUser` ni `projectName=Proyecto Fede` de #74.

## Ejecución

```bash
cd /home/fede/www/begreenmyfriend || exit 1
make qa-verifier
```

Informes en `/tmp/begreen-82-playwright-*.txt` y `qa-playwright/artifacts/verifier/`. Nunca compartir storageState ni trazas sin revisarlas.

## Alcance

- Login de la cuenta fixture y lista EXACTA de dos proyectos autorizados.
- Formulario GET de ambos proyectos.
- GET de edición de Proyecto Fede: 403 esperado.
- GET a `/admin/users/`: 403 esperado.
- Plan del primer proyecto y Calculadora del segundo tras selección de proyecto autorizada.
- Guard #74: host DEV y bloqueos de escrituras. Sólo se permite adicionalmente el GET exacto de selección del proyecto mostrado en la propia UI, que modifica **la sesión** y no la base de datos.
- Ningún POST salvo login. No se ejecuta `/clone` pues es un GET con efectos de escritura. La autorización de clonación sigue cubierta por tests PHP de #82.

## Límites

Este paquete es QA E2E READ-ONLY. La persistencia de verificación de medidas, la revocación de membresías y las operaciones POST contra proyectos ajenos requieren pruebas backend o una QA con escrituras explícitamente autorizadas. Los tests no simulan que esos casos hayan quedado verificados.
