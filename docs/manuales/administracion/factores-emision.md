# Factores de emisión

Administración → Factores emisión está disponible para Super Administrador y Administrador / Consultora. Gestiona el catálogo determinista utilizado por la Calculadora CO₂; no es un registro de actividad de un proyecto.

## Buscar y revisar

El listado pagina 25 resultados y permite filtrar por categoría, tipo temporal, año y fuente. Las columnas muestran identificador, categoría, tipo temporal, años, valor, unidad y fuente. Pulsa una fila o su acción para abrir el detalle antes de modificarla.

## Crear un factor {toc}

Condición previa: dispone de la fuente metodológica, unidad, periodo y valor aprobados. No crees factores por aproximación.

1. Pulsa **Nuevo factor**.
2. Elige la clave de categoría e introduce un identificador único.
3. Completa los criterios en JSON válido.
4. Selecciona tipo temporal: anual, versionado, regla, compuesto o proxy LCA.
5. Introduce año de actividad y año de fuente cuando correspondan.
6. Completa valor, unidad, fuente, detalle y metadatos si existen.
7. Guarda. La aplicación abre el detalle y muestra el mensaje de creación.
8. Compara en el detalle identificador, valor, unidad, años y fuente con el documento de origen.

Un factor anual requiere el año aplicable. Los campos JSON deben contener objetos válidos. Un valor vacío y un cero tienen significados distintos; no escribas `0` para sustituir un factor desconocido.

## Editar o borrar

Desde el detalle, abre **Editar**, corrige los campos y guarda. Vuelve al detalle para comprobar el resultado. La papelera solicita confirmación y devuelve al listado.

Modificar o borrar puede cambiar cálculos posteriores que utilicen ese factor. Los registros ya calculados conservan la información aplicada en su momento; no uses esta pantalla para “arreglar” manualmente una actividad concreta.
