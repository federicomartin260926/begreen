# Manuales de usuario

Este árbol contiene las fuentes versionadas de los manuales de Begreen. Hay composiciones distintas para **Administrador / Consultora** y **Super Administrador**. Los PDFs se guardan en `app/resources/manuales/`, fuera del árbol público y dentro de la imagen de la aplicación.

## Jerarquía y reglas de edición

1. El comportamiento vigente de la aplicación y las decisiones funcionales aprobadas tienen prioridad.
2. Los documentos de `docs/` citados en la matriz interna concretan contratos y límites.
3. `base/` contiene introducciones específicas por perfil y recorridos comunes; `funcionalidades/`, instrucciones de módulos; y `roles/`, la composición exacta de cada manual.
4. `interno/` registra evidencias, dudas y QA. Nunca se incorpora al PDF de usuario.
5. No se documentan como disponibles funciones futuras, pantallas meramente visuales ni permisos de otro perfil.
6. Las capturas deben proceder de DEV y reflejar el estado real. No se admiten wireframes como prueba del producto actual.

## Regeneración

Desde la raíz del repositorio:

`make manual-pdfs`

El target usa la imagen PHP del proyecto, Dompdf 3.1.0 ya fijado en Composer, `pdftotext` incluido en la imagen y un montaje de solo lectura de estas fuentes. No instala herramientas en el host ni crea servicios permanentes. El generador compone los capítulos, calcula la página física de cada título en varias pasadas y vuelve a renderizar el índice con esos números.

Las capturas usan la sintaxis Markdown `![Texto alternativo](capturas/archivo.png)`. Solo se admiten PNG y JPEG existentes dentro de `docs/manuales/`; se rechazan rutas absolutas, `..`, recursos remotos y formatos no permitidos.

## QA de contenido

Cada revisión debe actualizar `interno/matriz-qa.md` con la fuente comprobada, el estado y la fecha. Antes de publicar se verifica que el PDF tiene texto extraíble, índice, paginación, caracteres españoles correctos y que no atribuye al Administrador acciones de Super Administrador.
