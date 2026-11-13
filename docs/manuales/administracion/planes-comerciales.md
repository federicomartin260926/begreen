# Planes comerciales

Administración → Planes comerciales está reservado al Super Administrador. Muestra **tres niveles comerciales por proyecto**: Basic, Standard y Pro. El nivel contratado se guarda en el proyecto; la pantalla no vende ni factura por fases.

## Consultar precios

La tabla muestra los importes **por proyecto**, calculados por la regla de precios según su tipo/tamaño: Basic 0 €, Standard 249/499/749 € y Pro 499/999/1.499 € (bajo, medio y alto). Estos importes son informativos y no se editan desde la tabla o el formulario de capacidades.

## Editar un nivel

1. Abre el lápiz de Basic, Standard o Pro.
2. Revisa **nombre, estado y orden**: son valores comunes para ambas fases.
3. En **Elaboración**, ajusta descripción, límite de evidencias, marca de agua, puntuaciones permitidas y funcionalidades.
4. En **Implementación**, revisa y ajusta sus capacidades por separado. Son dos configuraciones internas, no dos productos a contratar.
5. Guarda una sola vez. Los cambios se aplican a las dos configuraciones de fase del mismo nivel.

Las funciones incluyen PDF por departamentos, exportaciones avanzadas, email, notas internas, responsables, checklist y medidas personalizadas. Si una opción se marca como futura, no la ofrezcas como disponible.

## Impacto y límites

La edición altera las capacidades globales del nivel y puede afectar a proyectos existentes que tienen contratado ese nivel; no cambia el **nivel comercial** guardado en ningún proyecto. Comprueba el efecto con proyectos de validación antes de habilitar nuevas funcionalidades.

Los precios de `ProjectCommercialPricing`, los importes de checkout y los identificadores Stripe no se alteran mediante este formulario. Los antiguos campos monetarios y Stripe de cada registro interno permanecen almacenados, pero ya no se ofrecen como precio comercial editable por fase. No pegues claves secretas en formularios.

La base conserva seis registros técnicos (dos fases × tres niveles) para resolver permisos; la administración muestra tres niveles de producto.
