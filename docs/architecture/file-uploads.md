# Subidas de archivos

## Configuración Docker propuesta aplicada SOLO EN LOCAL (26/10/2026)

**Estado:** archivos modificados localmente, sin commit, sin rebuild y sin despliegue.
Los valores efectivos en DEV/VPS siguen siendo los observados anteriormente hasta desplegar
y verificar PHP-FPM y Nginx. La auditoría original de las secciones siguientes
describe el estado *anterior* y sus discrepancias.

| Capa | Configuración versionada LOCAL | Archivo fuente |
| --- | --- | --- |
| PHP: archivo individual | `upload_max_filesize=8M` | `app/docker/php/conf.d/zz-upload.ini` |
| PHP: cuerpo POST completo | `post_max_size=48M` | mismo archivo, copiado por `app/Dockerfile` |
| PHP: número de archivos por petición | `max_file_uploads=20` | mismo archivo; requiere validar conteo en UX/backend |
| Nginx: cuerpo HTTP | `client_max_body_size=64m` | `app/nginx/default.conf`, montado desde Compose base |
| Proyecto: cuerpo multipart real | **32 MiB**, incluye campos y overhead | `app/src/Service/ProjectUploadPolicy.php` + frontend existente |
| Logo de proyecto | **2 MiB**, formatos actuales | formulario/servicio de proyecto |
| Documento de proyecto | **4 MiB**, formatos actuales | formulario/servicio de proyecto |

Estas cifras son una **propuesta técnica provisional**, no una decisión comercial
ni una aprobación de despliegue. El límite de 32 MiB es por **petición**,
no un máximo acumulado de documentos guardados en un proyecto.
No se impone por esta intervención una cantidad máxima de documentos o empresas
por proyecto, pero PHP admite como máximo 20 archivos **en una petición**;
la aplicación debe prevenir ese exceso antes de considerarse totalmente conforme.

**Pendiente de cierre:** verificar en el contenedor tras el futuro despliegue los
valores de Nginx (`nginx -T`) y PHP-FPM (no solo CLI), validar expresamente
la cantidad de archivos, las subidas de adjuntos de emisiones (hasta 16 MiB),
los imports de 5 MiB, evidencias y logos administrativos y los topes exactos.
No cambiar configuración del proxy anfitrión sin identificar antes sus límites.

---

## Auditoría original previa a la alineación Docker


## Arquitectura y límites efectivos

La aplicación se sirve como `begreen-nginx -> begreen-php:9000` dentro de la red Docker. Nginx entrega PHP a FPM y, por tanto, una petición multipart debe superar en orden los límites de Nginx, PHP-FPM y la aplicación.

Valores observados el 23/10/2026:

- `begreen-nginx`: `client_max_body_size 32m`.
- `begreen-php`, consulta CLI: `upload_max_filesize=4M`, `post_max_size=8M`, `max_file_uploads=20`.
- PHP-FPM: pendiente de confirmar. La configuración CLI no demuestra la configuración cargada por el pool FPM.
- Tres `POST /backend/project/new` rechazados por Nginx con HTTP 413 y cuerpo aproximado de 65,4 MiB.

No se elevan estos límites en #78. El asistente de proyecto usa 8 MiB como capacidad multipart local definida, mide el cuerpo completo antes del envío y el backend rechaza por `Content-Length` cualquier petición mayor. Esto evita solicitudes que PHP no podría procesar con el `post_max_size` observado, pero no sustituye la verificación de FPM.

## Política de validación

Toda subida debe tener dos capas:

1. Navegador: `accept` como ayuda, validación inmediata de extensión/tipo/tamaño y error visible junto al campo. En formularios con varios archivos debe validarse también el cuerpo multipart completo antes de enviar.
2. Backend: Symfony/servicio de almacenamiento valida de nuevo tamaño, formato/MIME y validez de `UploadedFile`. La validación cliente nunca es una frontera de seguridad.

En el asistente de proyecto:

- Logo de empresa: máximo 2 MiB; PNG, JPG/JPEG o WebP.
- Documento: máximo 4 MiB; PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, RTF, CSV, JPG/JPEG, PNG, WebP o ZIP.
- Total multipart: máximo 8 MiB en LOCAL, incluyendo campos y overhead multipart.
- Las colecciones de empresas y documentos no tienen actualmente una cardinalidad máxima. Por ello no existe una capacidad máxima teórica finita basada en el modelo funcional.

## Matriz de entradas reales

El inventario inicial contenía 48 archivos candidatos. Agrupados por entrada funcional real, el resultado es:

| Funcionalidad | Individual | Formatos | Navegador | Backend | Agregado | Discrepancia/riesgo |
|---|---:|---|---|---|---:|---|
| Asistente de proyecto: logos | 2 MiB | PNG, JPG/JPEG, WebP | `accept` + tamaño/formato inmediato | `File` + MIME/extensión en storage | 8 MiB multipart | Corregido en #78; cantidad de logos sin máximo |
| Asistente de proyecto: documentos | 4 MiB | PDF, Office, texto/CSV/RTF, imágenes, ZIP | `accept` + tamaño/formato inmediato | `File` + tamaño/MIME/extensión en storage | 8 MiB multipart | Corregido en #78; cantidad de documentos sin máximo |
| Evidencias de implementación | 4 MiB; 4 por petición | No restringidos actualmente | Sin validación de formato/tamaño previa | Tamaño y cantidad; no MIME/formato | Sin límite propio | Riesgo alto: admite tipos arbitrarios; definir formatos antes de restringir |
| Adjuntos de emisiones | 4 MiB; 4 por petición | PDF, JPG/JPEG, PNG, WebP | `accept`; control variable por formulario | Servicio común valida cantidad, tamaño, MIME y extensión | Máximo nominal 16 MiB | Incompatible con `post_max_size=8M` si se usan máximos simultáneos |
| Fotos de informe final | 4 MiB | JPG/JPEG, PNG, WebP | `accept`; sin tamaño inmediato | Editor + storage validan imagen, tamaño, MIME/extensión | Una foto por petición | Sin discrepancia agregada relevante |
| Importación de equipo | 2 MiB | XLS/XLSX | Selector/flujo de importación; sin tamaño inmediato común | Constraint `File` | Un archivo | Falta feedback preventivo uniforme |
| Importación de factores Excel | 5 MiB | XLS/XLSX | Sin validación inmediata uniforme | Constraint `File` | Un archivo | Límite individual supera `upload_max_filesize=4M` observado |
| Importación de medidas | 5 MiB | XLS/XLSX | `accept`; sin tamaño inmediato | Constraint `File` | Un archivo | Límite individual supera `upload_max_filesize=4M` observado |
| Archivos de comunicaciones | 5 MiB | PDF, DOC/DOCX, XLS/XLSX | Sin validación inmediata uniforme | Constraint `File` | Un archivo | Límite individual supera `upload_max_filesize=4M` observado |
| Logos de administración | 3 MiB | UI indica PNG/JPG/SVG | Sin restricción preventiva fiable | Solo tamaño | Un archivo | Riesgo: formatos no validados en backend |

## Configuración recomendada, pendiente de aprobación

Antes de cambiar infraestructura hay que aprobar un máximo de archivos por proyecto/petición. Con `D` documentos de 4 MiB y `L` logos de 2 MiB, la capacidad mínima es:

`post_max_size > (D × 4 MiB) + (L × 2 MiB) + campos + overhead multipart`

Además:

- `upload_max_filesize` debe seguir siendo 4 MiB si no cambian los límites individuales.
- `max_file_uploads >= D + L`.
- `client_max_body_size >= post_max_size`, dejando un margen explícito y documentado.
- Nginx y PHP-FPM deben configurarse desde los artefactos de producción, no desde overrides de desarrollo.

Con cardinalidad ilimitada no existe un valor coherente de Nginx/PHP que cubra todos los casos. La propuesta es decidir primero `D` y `L`, calcular la fórmula anterior y alinear Nginx, FPM, validación frontend y defensa backend en un cambio separado. Hasta entonces se mantiene el techo conservador de 8 MiB para el asistente.

## Pruebas y riesgos restantes

- Confirmar los valores reales de FPM, no solo CLI.
- Probar límites exactos: 2 MiB, 4 MiB, 8 MiB multipart y un byte por encima.
- Probar mezcla de documentos/logos y errores en el paso 2 sin emitir la petición.
- Probar que subir evidencia actualiza solo su bloque y conserva todos los campos de ejecución sin guardar.
- Pendiente: formatos de evidencias y logos administrativos, límites de imports de 5 MiB frente a PHP 4 MiB y agregado de adjuntos de emisiones (hasta 16 MiB nominales frente a PHP 8 MiB).

## #78 — Validación preventiva transversal (27/10/2026)

- Validador compartido `assets/lib/file_upload_validation.js`: valida **todos** los archivos seleccionados, tamaño individual, extensión, MIME declarado cuando está disponible y cantidad por campo. La integración global en `assets/app.js` escucha `change` y `submit` para los campos anotados; impide enviar el formulario si falla una regla cliente. PHP-FPM permanece como frontera para `max_file_uploads=20`.
- Emisiones (7 categorías): adjuntos PDF/JPG/PNG/WebP, **4 MiB/archivo, 4 archivos por subida**, acorde al servicio `EmissionRecordAttachmentStorage`.
- Importaciones de medidas/factores/comunicaciones: 5 MiB por archivo, con formatos existentes. Equipo: XLS/XLSX 2 MiB; se retira `.pdf` del selector porque no está permitido por su FormType.
- Informe final: fotos JPG/PNG/WebP, 4 MiB por archivo.
- Logos de administración: PNG/JPG/SVG, 3 MiB; se añade restricción de extensiones a `LogoType`. **Pendiente revisión de seguridad del almacenamiento y exposición pública de SVG** (no se transforma ni sanitiza en este parche).
- **Pendientes:** evidencias del plan no tienen allowlist de formatos backend; definirla con el responsable antes de bloquearlos. Inspeccionar el caso particular de certificado de energía y posibles subidas AJAX que no pasen por un evento `submit`; comprobar con QA focal. Revisar que todos los entrypoints relevantes cargan `app.js` y que los campos de importación de factores muestran la ayuda del `FormType` cuando la plantilla usa widgets personalizados.
- Esta prevención no cambia la validación de contenido real del servidor. Los valores Docker y el tamaño multipart de proyecto deben verificarse en runtime tras el despliegue autorizado.


### #78 — Endurecimiento de evidencias y logos (LOCAL, pendiente de despliegue)

- Nuevas evidencias: PDF, JPG/JPEG, PNG y WebP, hasta 4 MiB. El servidor
  valida tanto la extensión como el MIME detectado de cada archivo **antes**
  de almacenar ninguno; el navegador solo proporciona prevención UX.
- Logos administrativos nuevos: PNG o JPG/JPEG, 3 MiB. Se suspende la
  aceptación de SVG porque se publican sin sanitización en `/uploads/logos/`.
- Los archivos previamente subidos no se borran, ni se modifican referencias.
  **Pendiente antes de producción**: inventario focal de SVG históricos y de
  otras extensiones activas almacenadas en las carpetas públicas; decidir una
  migración segura o entrega restringida, no borrar archivos a ciegas.
- Nginx tiene una regla versionada para impedir ejecutar extensiones PHP
  dentro de `/uploads/`, incluidas las preexistentes.
- Las restricciones de formatos para evidencias son una política nueva de
  seguridad; confirmar con Franc si deben soportarse otros documentos antes
  de desplegar, sin ampliar tipos ejecutables.
- Las cifras de 8 MiB indicadas antes en este documento son **históricas**;
  la configuración LOCAL versionada propuesta actualmente es 8M por archivo
  en PHP, POST 48M, Nginx 64m y multipart de proyecto 32 MiB. Aún es
  necesaria la verificación del runtime FPM/Nginx antes del despliegue.
