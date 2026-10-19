# BEGREEN #74 — Matriz funcional inicial de QA Playwright

**Versión:** 19/10/2026 (v2) · **Estado:** matriz de diseño tras auditoría técnica READ-ONLY sobre HEAD `661e26d`; la conformidad UX de cada escenario sigue pendiente de su ejecución Playwright.

## Criterios de autoridad

- Fuentes: paquete cerrado `Begreen_71_fuentes_referencia_2026-10-09.zip` (ubicado en PC, contenido listado; hashes de Base Maestra y entrada v8 concordantes), `BGMF_Base_Maestra_Comun_7_Categorias_VIGENTE.xlsx` de 07/09, `informe-qa71.md`, `matriz-qa71.csv`, cierre #71 del 10/10 y `Begreen_71_informe_excepciones_para_Franc_2026-10-09.pdf`.
- Prioridad de autoridad: decisiones posteriores expresas > reglas/contratos > bases > especificaciones > wireframes (para UX).
- Convención fuente: `ENT-v8` = wireframe entrada; `TRA`, `ENE`, `AGU`, `ALO`, `CAT`, `RES`, `MAT` = HTML de `01_WIREFRAMES` y especificaciones de `03_ESPECIFICACIONES`; `COM` = reglas comunes; `C71` = cierre técnico #71; `EX-nn` = informe de excepciones.
- `R*` = candidato de lectura con POST de preview: los 7 controladores fueron contrastados y no contienen operaciones directas de persistencia; antes de ejecutar se deben verificar los servicios involucrados y activar una lista blanca limitada de estos endpoints. `R` = lectura UI/navegación; `W` = escritura real, **no ejecutar** sin aislamiento y autorización. `PEND` = no convertir en conformidad definitiva sin respuesta de Franc o evidencia documental.
- Evitar verificar matemáticas de factores en #74; las previsualizaciones se comprobarán como estado/capacidad/errores, usando pocas muestras de smoke cuando estén justificadas.

| ID | Categoría | Wireframe / fuente | Comportamiento esperado | Excepción #71 | Tipo de test | R/W | Prioridad |
|---|---|---|---|---|---|---|---|
| COM-01 | Común | ENT-v8; C71 | Acceso a Calculadora, siete categorías reconocibles y apertura/cierre | — | Navegación | R | P0 |
| COM-02 | Común | ENT-v8; C71 | Alta limpia sin valores arbitrarios en selectores principales | — | Estado inicial | R | P0 |
| COM-03 | Común | COM/paises_e_imputacion; C71 | Catálogo de países vigente, sin sustituir arbitrariamente la fuente del 07/09 | — | Catálogo | R | P1 |
| COM-04 | Común | ENT-v8; C71 | Detalle de registro existente y opciones visibles de menú sin ejecutarlas | — | Navegación | R | P1 |
| COM-05 | Común | Documento marco; C71 | Sin errores JavaScript inesperados en flujos probados | Última QA no pudo inspeccionar consola | Observabilidad | R* | P0 |
| TRA-01 | Transporte | TRA; C71 | Método sin seleccionar: cantidad/unidad ocultas y deshabilitadas; aparecen tras elegir método | Corrección final #71 | Visibilidad/dinámica | R | P0 |
| TRA-02 | Transporte | TRA; C71 | Turismo: tamaño visible, combustible/motorización y método con preguntas en orden definido | Aclaraciones T1 #71 | Formulario | R | P1 |
| TRA-03 | Transporte | TRA; C71 | Ocupantes/acompañamiento conservan carácter condicional y opcional; sin imputaciones nuevas | Aclaraciones T1 #71 | Visibilidad/validación | R | P1 |
| TRA-04 | Transporte | TRA; C71 | Furgoneta mercancías: unidad/método coherentes y `tonne_km` no visible donde se excluyó | Aclaraciones T1 #71 | Catálogo/unidades | R | P1 |
| TRA-05 | Transporte | TRA; C71 | Avión: «¿En qué clase viajaste?» antes del método; cuatro clases, ninguna preseleccionada | Decisión final #71; no inferir FE por clase | Orden/opciones | R | P0 |
| TRA-06 | Transporte | TRA; C71 | Moto >50 cc en España: opciones confirmadas y previsualización accesible | Corrección #71; EX-04 no cerrada para otras ramas | Catálogo/preview | R* | P1 |
| TRA-07 | Transporte | TRA; EX-04 | Minibús, ciclomotor, PHEV/BEV, Cargo bike, Courier: inventariar restricciones actuales, sin inventar combinaciones calculables | EX-04 PENDIENTE FRANC | Contrato pendiente | R | PEND |
| TRA-08 | Transporte | TRA; EX-05 | Origen/destino: documentar captura real y no exigir cálculo de ruta sin servicio externo | EX-05 PENDIENTE FRANC | Contrato pendiente | R | PEND |
| ENE-01 | Energía | ENE; aclaración Franc 14/09; C71 | España: origen «No lo sé» muestra pregunta de comercializadora; cálculo usa mix nacional | Aprobado #71 | Dinámica/preview | R* | P0 |
| ENE-02 | Energía | ENE; C71 | Comercializadora Sí muestra datalist; No lo oculta y no deja campo obligatorio erróneo | Aprobado #71 | Dinámica/datalist | R | P0 |
| ENE-03 | Energía | ENE; C71 | Fuera de España no se ofrecen comercializadoras exclusivas españolas ni GdO español | Aclaración #71 | Geografía/visibilidad | R | P1 |
| ENE-04 | Energía | ENE; C71 | GdO/certificados internacionales informativos sin reducción de emisiones inventada | Diferencia explicada #71 | Campos/preview | R* | P1 |
| ENE-05 | Energía | ENE; begreen-71-ene04-cierre | Combustibles dependientes del tipo de generador/equipo; bombona solo Butano/Propano | Aclaración Franc 14/09 | Catálogo/dependencias | R | P1 |
| ENE-06 | Energía | ENE; COM/tecnologia_digital; C71 | Siete subtipos de Tecnología Digital dentro de Energía, no octava categoría | Aprobado #71 | Catálogo/dinámica | R | P1 |
| ENE-07 | Energía | ENE; COM/tecnologia_digital; C71 | País digital prevalece sobre país general; ausencia de kWh no implica inventar estimaciones | Criterio #71 | Dependencias/preview | R* | P1 |
| ENE-08 | Energía | ENE; EX-06 | Baterías: no imponer «peor de cuatro» sin identificación autorizada de alternativas | EX-06 PENDIENTE FRANC, solo si sigue vigente | Contrato pendiente | R | PEND |
| AGU-01 | Agua | AGU; C71 | Unidad inicial sin preselección m³ en alta realmente nueva | Corrección #71 posterior a QA inicial | Estado inicial | R | P0 |
| AGU-02 | Agua | AGU; informe-qa71 | L/m³ y campos de cantidad compatibles con selección, sin conversión arbitraria en UI | — | Unidades/validación | R | P1 |
| AGU-03 | Agua | AGU; informe-qa71 | Tipo/destino de uso despliega opciones y campos apropiados | — | Dinámica | R | P1 |
| AGU-04 | Agua | AGU; C71 | Previsualización de una entrada válida/no válida con feedback entendible | No reauditar factores | Preview/validación | R* | P1 |
| ALO-01 | Alojamiento | ALO; C71 | Tipo y país condicionan opciones; unidad noches/estancias coherente | — | Catálogo/unidades | R | P1 |
| ALO-02 | Alojamiento | ALO; informe-qa71; C71 | Hostal/Pensión España muestra entrada y preview funcional, sin reconstruir factores derivados | Corrección #71 | Preview/validación | R* | P1 |
| ALO-03 | Alojamiento | ALO; informe-qa71 | Hotel/apartamento: huéspedes/noches y campos requeridos en las ramas representativas | — | Dinámica/validación | R* | P1 |
| CAT-01 | Catering | CAT; informe-qa71 | Menú permite selección de alimento/variante, raciones y estado preparado/consumido donde procede | — | Catálogo/dependencias | R* | P1 |
| CAT-02 | Catering | CAT; informe-qa71 | Vajilla/modalidad: opciones y estados coherentes sin valores preseleccionados arbitrarios | — | Opciones/preview | R* | P1 |
| CAT-03 | Catering | CAT; EX-03 | Actividades fuera de Menú sin FE autorizados no presentan cero ficticio como resultado calculado | EX-03 PENDIENTE FRANC | Estado no calculable | R* | P1 provisional |
| CAT-04 | Catering | CAT; EX-03 | Visibilidad final de las ocho actividades no se fija como PASS/FAIL literal | EX-03 PENDIENTE FRANC | Contrato pendiente | R | PEND |
| RES-01 | Residuos | RES; informe-qa71 | Catálogo de residuo y tratamiento dependiente de país | — | Catálogo | R | P1 |
| RES-02 | Residuos | RES; informe-qa71 | Ramas España/exterior muestran sus alternativas correctas y proxy etiquetado | — | Geografía/dependencias | R | P1 |
| RES-03 | Residuos | RES; informe-qa71 | Reutilización y desconocido: estados/salida de preview coherentes, sin usar sustituciones hipotéticas | — | Preview/validación | R* | P1 |
| RES-04 | Residuos | RES; informe-qa71 | Año, unidades y condiciones de entrada cambian según tratamiento sin campos ocultos obligatorios | — | Dinámica/validación | R | P1 |
| MAT-01 | Materiales | MAT; C71 | Catálogo de familias, subproductos y opciones dependientes sin preselección arbitraria | — | Catálogo | R | P0 |
| MAT-02 | Materiales | MAT; C71 | Papel: selección de método antes del formato; «Por peso» oculta formato | Aprobado #71 | Orden/visibilidad | R | P0 |
| MAT-03 | Materiales | MAT; C71 | Papel «Por paquetes»: etiqueta «Número de paquetes» y hojas/500 personalizables | Aprobado #71 | Labels/unidades | R | P1 |
| MAT-04 | Materiales | MAT; EX-01 | «Por gramaje»: registrar estado actual (gramaje exacto + hojas); no exigir rangos hasta definición | EX-01 PENDIENTE FRANC | Contrato pendiente | R | PEND |
| MAT-05 | Materiales | MAT; EX-02 | Origen Papel: Comprado/Comprado reciclado/Reutilizado; no inventar «Desconocido» | EX-02 PENDIENTE FRANC | Catálogo pendiente | R | PEND |
| MAT-06 | Materiales | MAT; C71 | Madera: peso total y peso por unidad disponibles y mutuamente coherentes | Diferencia explicada #71 | Método/dinámica | R | P1 |
| MAT-07 | Materiales | MAT; C71 | Madera: tableros, medidas, especie conocida/desconocida y origen adaptan campos sin confundir ramas | Diferencia explicada #71 | Catálogo/dependencias | R* | P1 |
| MAT-08 | Materiales | MAT; C71 | Etiquetas de origen Papel/Madera legibles; valores técnicos no se verifican a través de textos visibles | Decisión #71 | Labels/opciones | R | P1 |
| MAT-09 | Materiales | MAT; C71 | Cartón: tipo/estructura y método muestran controles en orden correcto | Corrección #71 | Campos/dinámica | R | P1 |
| MAT-10 | Materiales | MAT; C71 | Metales, ropa, plástico: grupos, composición, métodos y unidades visibles coherentes | Correcciones #71 | Matriz de catálogos | R | P1 |
| MAT-11 | Materiales | MAT; C71 | Baterías: opciones corregidas, incluida variante Litio-Ion 9 V, según catálogo vigente | Corrección #71 | Opciones/dinámica | R | P1 |
| MAT-12 | Materiales | MAT; informe-qa71 | Resto de familias de Materiales: al menos una rama representativa por familia | — | Catálogos/validaciones | R* | P1 |
| WRT-01 | Transversal Calculadora | Contrato de registro; C71 | Guardar una emisión y comprobar aparición en listado/detalle del proyecto QA | No cubierto por último smoke #71 | Persistencia | W | P2, bloqueado |
| WRT-02 | Transversal Calculadora | Contrato de registro; C71 | Editar y guardar un registro exclusivo de QA sin afectar registros preexistentes | No cubierto por último smoke #71 | Persistencia | W | P2, bloqueado |
| WRT-03 | Transversal Calculadora | Contrato de registro; C71 | Duplicar y eliminar únicamente registros efímeros propios, con limpieza segura | No cubierto por último smoke #71 | Persistencia | W | P2, bloqueado |

## Alcance de esta primera matriz

Son **51 grupos de comprobación** (no 49 únicos tests definitivos); algunos grupos se descompondrán en casos parametrizados, especialmente Transporte, Energía y las 14 familias de Materiales. La cifra y el reparto final dependen de la comparación detallada con los wireframes durante la implementación, y de la evaluación de selectores accesibles en DEV. Los grupos PEND deben figurar como *documentados, no validados*, sin `PASS` artificial ni `test.skip()` silencioso. Esta matriz no constituye certificación de la web actual.

## Contrato técnico fijado en la auditoría 2

- HEAD `661e26d`, rama `main`, árbol limpio. El commit de cierre #71 está incluido.
- Form login con CSRF; ruta `/login/{_locale}`. Acceso `/backend` requiere autenticación completa y preview exige `ProjectVoter::EDIT`.
- Rutas `/backend/emission/landing`, `/backend/emission/records`; siete `POST /backend/emission/{categoria}/preview`.
- Las rutas `GET /backend/emission/{id}/duplicate-...` son potencialmente mutantes; bloquearlas incluso en modo READ-ONLY.
- Selección del proyecto QA «Fede» a verificar desde la sesión autorizada antes de pruebas de escritura.
- No utilizar ID numéricos de categoría obtenidos durante #71. Navegar desde UI por nombre.
- EX-01 a EX-06 siguen pendientes de aceptación documental en el cierre de #71. Ningún caso PEND representa una conformidad definitiva.
