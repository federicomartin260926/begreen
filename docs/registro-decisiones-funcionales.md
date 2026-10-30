# BEGREEN · Calculadora CO₂
## Registro de decisiones funcionales y excepciones respecto a los wireframes

**Versión del registro:** 0.1 — borrador contrastado, 29/10/2026

**Ámbito:** 7 categorías de la Calculadora CO₂ (no el módulo BGoS, informes ni planes).

**Referencia de implementación/QA consultada:** commit `47451b0` de DEV, smoke focal informado el 10/10/2026. **No certifica versiones posteriores.**

**Estado editorial:** base inicial para mantener en Git; **no es un inventario exhaustivo de todas las decisiones históricas**. Las decisiones procedentes de WhatsApp/reuniones o posteriores al 10/10 requieren cotejo adicional.

> **Objetivo.** Ante una incidencia en un formulario, determinar rápidamente si el comportamiento observado (a) cumple el wireframe, (b) responde a una modificación **expresamente aprobada** después, (c) contradice el requisito vigente, o (d) está pendiente de definición. Evitar que una interpretación de desarrollo, un cambio en el código o un test PASS se conviertan retrospectivamente en una «aprobación» de Franc.

### 1. Regla de autoridad y alcance de este documento

1. **UX, campos, orden, listas, textos y visibilidad:** punto de partida = wireframe de la entrega informática, **salvo modificación puntual posterior aprobada y acreditada en este registro**.
2. **Reglas de cálculo, factores, unidades, años y contratos:** rige el orden de autoridad incluido en `00_LEEME_PRIMERO/LEEME_IMPLEMENTACION.md`: `04_REGLAS_COMUNES` + `05_CONTRATO_DE_DATOS` → `02_BASES_DE_FACTORES` → `03_ESPECIFICACIONES` → `01_WIREFRAMES`. Por tanto el HTML/JS es prototipo UX, **no autoridad numérica sobre la Base Maestra**.
3. Una confirmación posterior solo sustituye el fragmento al que se refiere. **Todo lo demás sigue igual**. Una aprobación parcial nunca autoriza completar los huecos por deducción.
4. **Implementado**, **aprobado** y **verificado** son tres dimensiones distintas. El código, un commit y una QA no prueban aprobación de negocio. La ausencia de factor tampoco autoriza eliminar una opción del wireframe sin decisión documentada.
5. No incorporar a la referencia cerrada de #71 el **Excel nuevo de Catering de octubre**, ni abrir nuevas metodologías por el mero hecho de mencionarlas aquí. Cualquier sustitución de fuente necesita decisión y alcance propios.

**Fuentes originales:** `Begreen_71_fuentes_referencia_2026-10-09.zip` (entrega informática original del 06/09, Base Maestra vigente 07/09, wireframe común y documentos del 14/09). Las rutas `01_WIREFRAMES/...` y `03_ESPECIFICACIONES/...` mencionadas abajo son **relativas al ZIP `BGMF_CALCULADORA_V1_ENTREGA_INFORMATICA.zip`** incluido en ese paquete.

**Evidencia de aprobación principal:** correo de Franc del **08/10/2026**, asunto *RE: ejemplos calculadora sin resultado*, respuestas insertadas en el correo de Federico. [Abrir evidencia en Gmail — acceso del titular](https://mail.google.com/mail/u/?authuser=federicopenalopez2021%40gmail.com#all/1a11c94acd7cbcab). Las fechas/fragmentos aquí reseñados permiten localizar el correo también desde la cuenta de Franc sin depender del enlace privado.

**Otras referencias:** [Consulta de Franc sobre Transporte, 05/10/2026](https://mail.google.com/mail/u/?authuser=federicopenalopez2021%40gmail.com#all/1a10cc307646e510); [respuesta de Franc sobre necesidad de fidelidad, 05/10/2026](https://mail.google.com/mail/u/?authuser=federicopenalopez2021%40gmail.com#all/1a10d8a6e9f1cdc0); informe `Begreen_71_informe_excepciones_para_Franc_2026-10-09.md`.

### 2. Cómo leer el estado

- **APROBADO:** existe instrucción inequívoca de Franc. Si contradice el wireframe, **esa regla puntual prevalece**.
- **RATIFICADO:** Franc confirmó una conducta que ya aparecía en el wireframe; **no hay excepción**.
- **PARCIAL:** Franc definió parte del flujo, pero no lo suficiente para sustituir íntegramente el wireframe o la especificación.
- **PENDIENTE:** no se encontró aprobación inequívoca para la desviación o el criterio.
- **TÉCNICO:** explicación o limitación de implementación, **no es una aprobación de negocio**.

**Estado de ejecución:** QA focal `47451b0` = comprobado en esa versión; implementado = identificado en informes/commits, sin nueva QA específica; desconocido = no se ha cotejado el código actual. Se evita usar «actual» sin commit.

### 3. Índice de decisiones identificadas

| ID | Categoría · punto de consulta | Relación con wireframe | Decisión | Evidencia | Ejecución conocida |
|---|---|---|---|---|---|
| ENE-01 | Energía · electricidad «No lo sé» | Criterio de factor no visible directamente | **APROBADO:** promedio/mix de red del país | Franc 08/10 | QA `47451b0` |
| ENE-02 | Energía · España, comercializadora/GdO | Precisa flujo visible | **APROBADO:** procedencia → Sí/No → nombre si Sí → consumo/unidad; sin GdO visible | Franc 08/10 | QA `47451b0` |
| ENE-03 | Energía · fuera de España, certificado | Precisa/explicita información adicional | **APROBADO:** proveedor, certificado renovable, kWh cubiertos y evidencia; consumo/unidad | Franc 08/10 | QA anterior focal; no nueva QA de adjuntos |
| TRA-01 | Transporte · Turismo | Confirma orden y lógica del diseño | **RATIFICADO:** tamaño → motorización/combustible → distancia o litros | Franc 08/10 | QA `47451b0` sobre secuencia |
| TRA-02 | Transporte · opciones agrupadas | El wireframe separa alternativas | **PARCIAL:** Franc acepta separar motocicleta/ciclomotor; sin mapeo completo de todas las opciones | Franc 08/10 | Cobertura parcial |
| MAT-01 | Materiales · Papel, orden y formato | Resuelve discrepancia entre `Materiales.md` y HTML | **APROBADO:** seguir wireframe; ocultar formato en peso total | Franc 08/10 | QA `47451b0` |
| MAT-02 | Materiales · Papel, gramaje | Wireframe: rangos/paquetes; MD: valor exacto/hojas | **PARCIAL:** pidió formato, gramaje, tipo de gramaje y hojas; no resolvió valor/cálculo de rangos | Franc 08/10 | Excepción EX-01 |
| MAT-03 | Materiales · Papel, paquetes/hojas | Wireframe: 500 hojas; MD: cantidad variable | **PARCIAL/RATIFICADO:** Franc señala paquetes de 500 **o** hojas sueltas; falta contrato único del selector para ese caso | Franc 08/10 | QA focal solo modalidad paquetes |
| MAT-04 | Materiales · Madera, modalidad peso | Wireframe: peso unitario × unidades | **APROBADO EN PARTE:** peso total **o** medidas, con unidades; no extrapolar a otras modalidades | Franc 08/10 | Desarrollo admite también peso unitario; QA focal |
| MAT-05 | Materiales · orden de campos | Ratifica criterio general | **RATIFICADO:** respetar wireframes salvo gran incongruencia que se acuerde | Franc 08/10 | QA focal `47451b0` |
| MAT-06 | Materiales · batería Litio-Ion 9 V | Conflicto con regla escrita sobre pesos divergentes | **APROBADO:** usar `0,041 kg` por unidad | Franc 08/10 | Cambio probado en #71 |
| MAT-07 | Materiales · madera, tableros y densidades | Diferencias o dudas de interpretación | **PENDIENTE DE ACEPTACIÓN EXPRESA:** Franc pidió dónde aparecen los datos, no eligió densidad alternativa | Franc 08/10 | Distinciones técnicas registradas |
| CAT-01 | Catering · ocho actividades adicionales | Visibles en wireframe; no cubiertas por FE de Base Maestra cerrada | **PENDIENTE:** nueva fuente de Alfredo enviada, pero fuera del alcance #71 | Franc 08/10 + alcance #71 | Excepción EX-03 |
| TRA-03 | Transporte · métodos por origen/destino | Wireframe tiene capturas; requieren servicio externo para distancia | **PENDIENTE:** limitación técnica no equivale a aprobación para omitir campos | Spec Transporte + EX-05 | Cobertura parcial |
| ENE-04 | Energía/baterías · regla «peor de cuatro» | No queda definida por el paquete cerrado | **PENDIENTE:** alternativas y aplicación sin confirmar | Informe EX-06 | Sin extrapolación |

**Agua, Alojamiento y Residuos:** no se localizaron decisiones posteriores que alteren sus wireframes **en la correspondencia del 05–10/10 y los materiales revisados**. Esto **no confirma que no existan** otras decisiones (reuniones, WhatsApp o tareas anteriores). Hasta documentarlas, sus wireframes y especificaciones originales siguen siendo la referencia.

### 4. Fichas de decisión (detalle operativo)

#### ENE-01 — Electricidad, origen «No lo sé» · APROBADO
- **Fuente base:** `01_WIREFRAMES/Energia.html`, selector `#origin`; `03_ESPECIFICACIONES/Energia.md`, apartado «Electricidad España».
- **Decisión posterior:** Franc confirma utilizar el **promedio de red del país (mix)**, en vez de dejar el cálculo pendiente por origen desconocido.
- **Vigencia:** afecta a la resolución del factor; **no implica** escoger automáticamente una comercializadora ni aplicar GdO.
- **Evidencia:** correo 08/10, pregunta «Cuando el usuario selecciona “No lo sé”…».
- **Comprobación conocida:** QA `47451b0`: 2 kWh → 0,516 kg CO₂e, tanto con Sí como con No en comercializadora.

#### ENE-02 — Electricidad española, secuencia de comercializadora · APROBADO
- **Fuente base:** `01_WIREFRAMES/Energia.html`, bloques `#spainSupplier`, `#supplierName`, `#contractBox`.
- **Decisión posterior:** «Procedencia → Comercializadora (Sí/No) → Comercializadora si la conoce → Consumo → Unidad» y **«Sin GdO visible»**.
- **Evidencia:** correo 08/10, apartado Energía / España.
- **Comprobación conocida:** QA `47451b0` sobre visibilidad, preview y mix nacional para origen desconocido. No supone QA exhaustiva de todas las comercializadoras.

#### ENE-03 — Electricidad fuera de España, documentación renovable · APROBADO
- **Fuente base:** `01_WIREFRAMES/Energia.html`, certificado `#certificate`; reglas de FE en `03_ESPECIFICACIONES/Energia.md`.
- **Decisión posterior:** «Procedencia → Proveedor eléctrico → ¿Tienes certificado de origen renovable? → kWh cubiertos → Evidencia → Consumo → Unidad».
- **Regla numérica:** certificado acreditativo **no altera automáticamente** FE si no existe factor compatible; no debe inferirse reducción solo por aportar archivo.
- **Evidencia:** correo 08/10, apartado Energía / Fuera de España.
- **Comprobación conocida:** recorrido funcional previo; **adjuntos/persistencia de evidencia no incluidos en la última smoke**.

#### TRA-01 — Turismo, secuencia de preguntas · RATIFICADO
- **Fuente base:** `01_WIREFRAMES/Transporte.html`, `.car-size-block`, `.type-block` y métodos dinámicos.
- **Confirmación:** primero pequeño/mediano/grande (o promedio si desconocido), después combustible/motorización y finalmente **distancia o litros consumidos**.
- **Evidencia:** correo 08/10, apartado Transporte / Turismo.
- **Uso ante incidencias:** si esta secuencia se respeta, **no registrar bug por orden**; identificar primero un cambio posterior concreto y aprobado.

#### TRA-02 — Opciones Transporte desagrupadas · PARCIAL
- **Fuente base:** `01_WIREFRAMES/Transporte.html`, listas de `local`, `travel` y `freight`; `03_ESPECIFICACIONES/Transporte.md`.
- **Respuesta Franc:** «Si ves mejor separar Motocicleta y ciclomotor (y todas las dobles), no hay problema».
- **Límite:** esto admite separar opciones; **no proporciona FE/contrato exacto** para Minibús, tecnologías BEV/PHEV, Cargo bike o todos los modos de mensajería.
- **Uso ante incidencias:** comparar la opción con el wireframe, verificar si existe FE y tratar la falta de mapeo como **cobertura pendiente**, no como cambio aprobado al diseño.

#### MAT-01 — Papel, método antes del formato y formato oculto por peso · APROBADO
- **Fuente base:** `01_WIREFRAMES/Materiales.html`, `paperHTML()`/`paperSub()`; discrepancia con `03_ESPECIFICACIONES/Materiales.md`, §5 «Papel».
- **Respuesta Franc:** «Hazlo como en el wireframe»; si se conoce el peso dejan de importar hojas/paquetes.
- **Vigencia:** método primero; formato solo en modalidades que lo necesitan; por **peso total** no se pide formato.
- **Comprobación conocida:** QA `47451b0` PASS.

#### MAT-02 — Papel, por gramaje · PARCIAL (NO SUSTITUYE COMPLETAMENTE EL WIREFRAME)
- **Fuente base:** `01_WIREFRAMES/Materiales.html`, `paperSub()` (rango + paquetes); `03_ESPECIFICACIONES/Materiales.md`, §5 (gramaje exacto + hojas).
- **Respuesta Franc:** «Formato, gramaje, tipo de gramaje y número de hojas» y pregunta por qué faltan **gramajes predefinidos**.
- **Sin resolver:** equivalencia y cálculo de cada rango, relación exacta entre gramaje y «tipo de gramaje», y conciliación rango/paquetes frente a valor numérico/hojas.
- **Estado:** EX-01. **No etiquetar la implementación de valor exacto como conformidad final íntegra aprobada**.

#### MAT-03 — Papel, paquetes de 500 y hojas sueltas · PARCIAL
- **Fuente base:** `01_WIREFRAMES/Materiales.html`, `paperSub()`, 1 paquete = 500 hojas; `03_ESPECIFICACIONES/Materiales.md`, §5.
- **Respuesta Franc:** «En el wireframe aparecen dos opciones (paquetes de 500 hojas o hojas sueltas)».
- **Regla acreditada:** se reconocen ambos escenarios. **Falta verificar** la correspondencia exacta del selector y de su cálculo para hojas sueltas en versión vigente.
- **QA:** la smoke del 10/10 verificó etiqueta «Número de paquetes», no un ciclo nuevo de hojas sueltas.

#### MAT-04 — Madera, peso/medidas · APROBADO EN PARTE
- **Fuente base:** `01_WIREFRAMES/Materiales.html`, `woodMethod()`/`woodDetail()`.
- **Respuesta Franc:** «O se calcula por peso total o por medidas. Y en cada opción, unidades».
- **Límite:** la confirmación autoriza **peso total y medidas**; que el código también permita peso por pieza × unidades es continuidad del wireframe, no una aprobación general para otras entradas.
- **QA conocida:** peso por pieza 2,5 kg × 4 = 10 kg y campos de tableros manuales.

#### MAT-05 — Orden general de campos en Materiales · RATIFICADO
- **Respuesta Franc:** seguir el orden de los wireframes, salvo una «gran incongruencia».
- **Límite:** esa salvedad **no autoriza** cambiar por criterio técnico cualquier orden; primero documentar caso y aprobación concreta.

#### MAT-06 — Batería Litio-Ion de 9 V · APROBADO
- **Fuente base:** `03_ESPECIFICACIONES/Materiales.md`, apartado «Pilas y baterías», regla de pesos incompatibles.
- **Contradicción:** aparecen 0,041 y 0,045 kg; la especificación pide peso real si son incompatibles.
- **Respuesta Franc:** «Pon 0,041»; prevalece para esta combinación concreta.
- **Límite:** no generalizar el criterio a otras químicas/tamaños.

#### MAT-07 — Densidades/tableros Madera · PENDIENTE DE ACEPTACIÓN
- **Fuente base:** `01_WIREFRAMES/Materiales.html`, `woodDetail()`/`calcWood()`.
- **Situación técnica conocida:** madera **completamente desconocida** = 632,5 kg/m³ en wireframe; **especie desconocida dentro de maciza** = 588,1515152 kg/m³ en tabla de la Base Maestra. Los tableros de referencia usan 2,44 × 1,22 m internamente.
- **Respuesta Franc 08/10:** preguntó «¿Dónde te aparece ese dato?»; **no aprobó sustituir una densidad por la otra**.
- **Uso:** describir ambas ramas con referencia precisa. No crear aquí una decisión de negocio ficticia.

#### CAT-01 / TRA-03 / ENE-04 — Limitaciones sin aprobación de cambio
- **Catering:** las ocho actividades figuran en `01_WIREFRAMES/Catering.html`. El Excel adicional ofrecido el 08/10 no se incorporó al paquete de #71; su evaluación necesita encargo separado. **No se acredita que Franc aprobara ocultarlas.**
- **Transporte rutas:** `01_WIREFRAMES/Transporte.html` y `03_ESPECIFICACIONES/Transporte.md` exigen capturas/métodos con origen y destino; la dependencia de proveedor de rutas **no autoriza por sí misma omitir entradas**.
- **Baterías «peor de cuatro»:** en los antecedentes no quedaron fijadas inequívocamente cuatro alternativas para ese escenario. Una petición antigua que contradiga una confirmación posterior se debe marcar **sustituida en el punto concreto**, no combinarse automáticamente.

### 5. Protocolo de triaje de incidencias (obligatorio antes de clasificar «bug»)

1. **Localizar:** categoría → subcategoría/modo → campo/acción → país → método → fecha/año. Capturar reproducción concreta y versión/commit.
2. **Consultar wireframe original:** archivo + nombre de función/selector + comportamiento. Aplicar la jerarquía de factores y contratos cuando sea un cálculo.
3. **Buscar ID en este registro.** Si `APROBADO`, se evalúa contra el cambio aprobado; si `RATIFICADO` o sin registro, contra el wireframe/base; si `PARCIAL/PENDIENTE`, no convertir interpretación en aprobación.
4. **Contrastar ejecución:** qué hace hoy el código y qué comprobó realmente la QA; no extrapolar PASS de otra versión o flujo.
5. **Clasificar:** `BUG` (incumple norma vigente), `SOLICITUD DE CAMBIO` (quiere un comportamiento diferente del aprobado/original), `ACLARACIÓN PENDIENTE` (regla contradictoria/incompleta), `LIMITACIÓN CONOCIDA` (sin FE/servicio, sin aceptación aún), `CONFORME`.
6. **Trazar:** registrar ticket, evidencia, decisión de Franc (si procede), commit y test que la valida. Solo después actualizar la ficha a `APROBADO`.

**Ejemplo para Transporte:** «La selección X debería actuar distinto» → si el wireframe ya se comporta como la web y no existe una excepción aprobada para X, **no es un bug de fidelidad del formulario**; se registra como propuesta de cambio o se pide definición. Eso no descarta un bug independiente de cálculo, persistencia o validación.

### 6. Plantilla para añadir una nueva decisión

```md
#### [CAT]-[NÚMERO] — [Categoría / formulario / campo]
- Estado de negocio: PROPUESTA | PARCIAL | APROBADO | RATIFICADO | PENDIENTE | SUSTITUIDO
- Fuente original: ZIP/version, 01_WIREFRAMES/archivo.html, función/selector, 03_ESPECIFICACIONES/... cuando afecte
- Comportamiento original: [literal y condiciones]
- Cambio solicitado: [antes → después, país/modo/método/fecha afectados]
- Decisión expresa de Franc: [qué confirmó exactamente; NO completar por interpretación]
- Evidencia: [fecha, canal, asunto/ticket, enlace o captura]
- Fecha de aprobación: [AAAA-MM-DD o NO ACREDITADA]
- Vigencia: [desde cuándo y qué sustituye; ID anterior si aplica]
- Implementación: [commit, componentes]
- Validación: [caso de prueba, entorno, commit, fecha y PASS/FAIL/NO PROBADO]
- Estado de publicación: LOCAL | DEV | PROD | NO COMPROBADO
- Observaciones / asuntos abiertos: [cualquier límite]
```

### 7. Mantenimiento y pendientes de consolidación

- **Ubicación propuesta en Git:** `docs/calculadora/registro-decisiones-funcionales.md` (ajustar a la estructura real del proyecto). Mantener **un único documento vivo** con IDs estables y `git log` como histórico. No editar los wireframes originales: son la línea base inmutable.
- **No duplicar todo el manual:** registrar únicamente excepciones, aclaraciones posteriores, decisiones que sustituyen otras y ratificaciones relevantes para incidencias.
- **Tarea de consolidación:** cotejar correspondencia WhatsApp, reuniones y tickets posteriores al 10/10/2026, y cualquier requisito confirmado antes del 05/10 que ya modificara la entrega de septiembre. Hasta entonces **no declarar inventario exhaustivo**.
- **Esencial para el próximo bug de Transporte:** anotar el formulario, opción, método, acción y el cambio que Franc asegura haber aprobado; localizar evidencia antes de modificar código. **No asumir que existe esa aprobación** solo porque se reporta como bug.
- **Sin aprobación no hay sobrescritura:** la petición verbal puede registrarse `PROPUESTA`; se sube a `APROBADO` al disponer de confirmación trazable. Las decisiones antiguas superadas conservan el ID y pasan a `SUSTITUIDO`, enlazando la nueva.
- **Control editorial de este borrador:** algunas fichas contienen decisiones explicitadas en el correo del 08/10; otras están abiertas precisamente para no confundir aclaración parcial con aceptación completa. Cualquier ampliación debe verificar la fuente de manera individual.
