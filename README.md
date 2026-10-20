# Kaizen: proyectos compartidos y cronograma

## Calendario de hoy

**Calendario de hoy** es una sección propia, separada de **Cronograma**. Abre
con una agenda vertical de horas: los bloques de trabajo muestran su inicio y fin,
los solapamientos ocupan columnas paralelas y los huecos de al menos 30 minutos
aparecen debajo. Al pulsar un hueco libre de la cuadrícula se abre allí mismo un
editor: se puede elegir una tarea existente o crear una nueva, ajustar inicio y fin;
**Guardar bloque** confirma y **Cancelar** descarta
el borrador. Los intervalos que cruzan medianoche se recortan al día visible.
La zona horaria es la del dispositivo; los días de 23 o 25 horas se representan
con su duración real. Cada bloque y su ficha accesible muestran creador y
responsable confirmado. Las tareas con fecha pero sin bloque horario en el día
aparecen en una sección aparte. Las flechas cambian de día y **Elegir otra fecha** despliega el
mes. Cuenta las tareas
personales creadas por ti o delegadas directamente a ti, incluso si eres admin.
La consulta de todas las tareas del espacio sigue en **Tareas del equipo**.
Pertenecer a un proyecto compartido no incorpora tareas de colegas al calendario
personal; **Proyectos** y **Cronograma** conservan su alcance colaborativo.

Puedes crear una tarea en el día, poner fecha a una tarea existente, cambiar su
fecha límite o marcarla hecha desde la agenda. Cada tarjeta muestra el responsable
confirmado. Las tareas con un rango en Cronograma aparecen en la lista del día,
pero no ocupan la cuadrícula horaria hasta que se les añada un bloque. Si también
tienen fecha límite, el día las cuenta una sola vez. `workBlocks` guarda cero o
más intervalos UTC con identificador y zona horaria dentro de la misma tarea;
`scheduledStart` y `scheduledEnd` siguen siendo exclusivos del Cronograma. Los
datos anteriores no se migran a bloques ni reciben horas inventadas. Las tareas
creadas desde una hora libre con la versión anterior conservan su rango en
Cronograma y aparecen abajo como tareas sin bloque hasta que alguien les asigne
un intervalo de trabajo. Al editar o
quitar un bloque se conservan rango, fecha límite y tiempo Pomodoro. No se generan
tareas recurrentes automáticamente ni se importan eventos de Google Calendar. El día se calcula en la zona
local del dispositivo y la fecha
elegida se restaura al recargar Kaizen. Al entrar de nuevo desde la navegación,
la agenda vuelve a hoy.

La vista móvil mantiene la página dentro del ancho disponible. A 320 px la
cuadrícula desplegable permite un desplazamiento horizontal propio para conservar botones
de día de al menos 44 px; la agenda permanece primero. La vista previa local usa
datos ficticios y nunca debe conectarse a la API publicada.

## Tareas delegadas al asistente

**Asistente** (en Más vistas) sigue las ejecuciones delegadas a Luna según
[la especificación](docs/delegated-assistant-tasks-spec.md). Muestra resumen por
estado, búsqueda, tarjetas con estado, antigüedad, último avance, bloqueo, próximo
paso y entregables, y un detalle con la cronología de eventos de la más reciente
a la más antigua. Los filtros no modifican ninguna ejecución.

La rama implementa registros privados mediante `assistant_runs` y
`assistant_run_upsert`. La carga en vivo requiere seleccionar **Servidor ·
registros privados** una vez por usuario y espacio; esa preferencia se conserva
en el navegador y la vista vuelve a cargar los registros del servidor al
recargar. Solo se guarda la preferencia, nunca el contenido privado. Los
fixtures de `assistant-runs-demo.js` requieren una selección explícita y muestran siempre
**DEMO · datos ficticios**. Nunca se mezclan con registros reales. La lógica de
presentación está en `assistant-runs.js`:

Solo el administrador del espacio activo, su creador o un superadministrador puede
ver la sección. La comprobación protege navegación, vista directa, modelo de
renderizado y carga; al perder acceso, descarta la selección y los datos en
memoria. Las rutas de sesión y herramientas MCP de rama vuelven a comprobar el
administrador, creador o superadministrador en servidor. MCP publica la lectura
con `kaizen:read` y el upsert solo cuando ya está permitido `kaizen:write`; no
añade scopes ni crea credenciales. La integración no programa ejecuciones.

- Una ejecución solo existe con `kind: "assistant_run"`, asistente y evento
  `delegated`. `done`, propietario, `sharedWith`, `_delegated_at`, `scope: team`,
  Pomodoros, `seconds`, `pomos` y `_revision` nunca crean ni miden una ejecución.
- Antigüedad = ahora − `delegated_at`; tiempo de resolución = `completed_at` −
  `delegated_at`, solo con estado y evento `completed`. Las canceladas no cuentan
  como resueltas. Marcas ausentes, inválidas o contradictorias se leen **Sin dato**.
- El tiempo activo es **Sin registro** salvo con pares explícitos
  `work_started`/`work_paused`; se ignoran intervalos abiertos, invertidos,
  duplicados o sin pareja, y el detalle avisa de ellos.
- El selector «Probar estados de la demo» muestra carga, vacío y error.

## Uso

- En **Proyectos**, crear un proyecto y elegir miembros del equipo existente.
  El creador queda incluido. Guardar miembros comparte todas las tareas del
  proyecto, incluidas las existentes; no crea cuentas ni miembros del espacio.
- Crear una tarea desde el proyecto, elegir proyecto al capturar una tarea, o
  usar **Proyecto** en el detalle para moverla. El creador de la tarea o un
  administrador puede moverla; debe tener acceso al proyecto de destino.
- En el detalle, **Planificación** permite guardar inicio y fin juntos o quitar
  las fechas. **Cronograma** muestra todas las tareas accesibles, con filtro por
  proyecto y por tareas sin proyecto. Las tareas sin fechas conservan ese estado.
- La planificación se guarda como ISO UTC y conserva la zona de origen; cada
  dispositivo muestra las horas en su propia zona, indicada junto al formulario.
  Un fin igual/anterior al inicio, una fecha inválida y una hora inexistente por
  cambio de horario se rechazan. Las horas ambiguas del cambio de otoño usan la
  primera ocurrencia que el navegador asigna a `datetime-local`.
- El tiempo real de Pomodoro/cronómetro se mantiene separado de la planificación.
- **Actualizar tareas** guarda cambios y consulta el servidor. Si hay un conflicto
  de edición, se informa y permite reintentar o descartar cambios pendientes y
  recargar. No se fusionan automáticamente ediciones de la misma tarea.

## Cronograma con calendario

- La vista inicial es **Semana**, de lunes a domingo, con una cuadrícula de
  fechas y columna fija de tareas. **Día** muestra horas y **Mes** días.
  **Hoy** vuelve al periodo actual; las flechas avanzan un periodo completo.
- El calendario tiene scroll horizontal propio en pantallas estrechas. Los
  nombres permanecen visibles y las tareas cortas tienen un objetivo de clic
  de al menos 32 px. La línea debajo de la barra conserva la duración exacta;
  la columna fija muestra horas y días cuando abarca varias fechas. El detalle
  conserva inicio, fin y tiempo real; el nombre accesible de la barra incluye fechas completas.
- Las tareas que cruzan un límite de periodo se recortan en el calendario,
  conservando sus fechas. Las que quedan fuera aparecen en una lista con
  **Ver en calendario**. Las fechas incompletas o inválidas aparecen en
  **Sin planificación**, con **Revisar fechas**, y no inflan el contador.
- Pulsar un nombre o una barra abre el editor de fechas y enfoca Inicio. En
  móvil, desplaza el panel de detalle a la vista. No cambia el Pomodoro.
  El ajuste de fechas sigue siendo mediante el formulario; no hay arrastre
  ni redimensionado de barras.
- Vista, periodo y filtro se recuerdan localmente; navegar no modifica tareas,
  proyectos, delegaciones ni fechas. Los periodos se calculan en la zona local,
  incluidos días de 23/25 horas y cambios de horario de media hora.

Verificación adicional por CLI: ocho pruebas de calendario y componente
cubren rangos y límites, cambio de mes/año, DST, tareas cortas junto a tareas
largas, contador, filtros, navegación, localizar tareas y conservación de datos.
La suite JavaScript suma ahora 59 pruebas. La captura directa en el calendario
se verificó con datos ficticios en Chrome a 1280, 375 y 320 px, incluido el
acceso por teclado, cancelar y guardar.

Referencias de diseño: [Linear Timeline](https://linear.app/docs/timeline) y
[Notion Timeline](https://www.notion.com/help/timelines): escala explícita,
navegación por periodos y nombres junto al calendario. No se añadió ninguna
biblioteca ni dependencia de estos productos.

## Interfaz diaria simplificada

La cabecera conserva reloj, iniciar/pausar y tarea activa. **Temporizador** reúne
modo, reinicio, cambio de ciclo y pantalla completa; **Más** reúne preferencias,
avisos, cuenta y espacio de trabajo. Las opciones de prueba quedan en un segundo
nivel. Hoy, Bandeja, Delegadas, Proyectos y Cronograma siguen visibles; **Más vistas**
contiene el resto, con los mismos permisos de Supervisión.

Las filas mantienen Delegar y Enfocar a mano. **Más** muestra fecha límite,
Mover a Hoy y Borrar según la vista. Es un panel en el flujo de la fila que evita
solaparse con otras tareas. Abrirlo no selecciona ni inicia un gesto sobre la tarea.
Los desplegables usan `details`/`summary` nativos, foco visible y objetivo de 44 px.
Escape cierra el desplegable enfocado y devuelve foco a su resumen. El calendario
conserva sus controles y reduce la repetición en cada fila; ayuda y actualización
están en **Opciones del calendario**.

Claude Code (Opus 5.5) propuso la jerarquía visual a partir de un brief genérico
sin código ni datos privados. La integración conserva los handlers y la API
existentes. Cuatro pruebas nuevas comprueban navegación/permisos, interacción de
filas, Escape/foco y fechas compactas de uno o varios días y distintos años.
Estas pruebas usan lógica y dobles de DOM; no sustituyen la QA visual en Chrome.

La QA de `b68435c` encontró un recorte del detalle de planificación a 375 px:
407,25 px de panel, con borde derecho en 431,25 px. En móvil el contenido ahora
se apila en columna y el detalle ocupa el ancho disponible; sus tarjetas, campos
nativos y botones permiten encogerse. La regresión visual de esta corrección debe
confirmarse en Chrome a 375 × 667 y 320 × 568 antes de considerarla terminada.
La validación de rangos y el modal de Delegar no se modificaron.


## Responsables y dashboard personal

**Responsable** en Cronograma significa destinatario confirmado de **Delegar**:
se resuelve el nombre por ID del equipo y se muestran todas las personas cuando
hay varias. El creador (`tasks.owner_id`) y pertenecer al proyecto no implican
asignación. Sin destinatarios aparece **Sin asignar**; si hay un ID asignado cuyo
nombre ya no está disponible, se informa **nombre no disponible**. La relación
`task_shares`, devuelta como `_shared_user_ids`, prevalece sobre una copia antigua
de `sharedWith`. El nombre aparece también en Fuera de este periodo y Sin
planificación; no cambia durante un borrador ni después de un guardado rechazado.

Las listas personales y sus contadores (incluida Matriz) muestran tareas creadas
por la persona o delegadas directamente a ella. Esto también se aplica a admins.
Proyectos y Cronograma mantienen la colaboración de miembros existente mientras
se aclara el alcance de los proyectos compartidos; pertenecer a un proyecto no
mete las tareas de los compañeros en Hoy/Bandeja/Futuro. La vista **Tareas del
equipo**, en Más vistas y Supervisión, permite al administrador del espacio
consultar todas las tareas de ese espacio, con creador y responsables separados.
Es de solo lectura y su colección no entra en listas, sincronización ni caché
persistida personal. Ahora incluye un resumen clicable de pendientes, atrasadas,
hechas y sin responsable; búsqueda por tarea, persona o proyecto; filtros de
estado; y agrupación por responsable, proyecto o estado. Dentro de cada grupo,
las tareas atrasadas y las fechas más próximas aparecen primero. Las tarjetas
muestran el creador, responsables confirmados, proyecto, fecha límite, rango de
planificación y tiempo Pomodoro registrado. No se cambian usuarios ni roles.

Protección en API (identidad y rol proceden de sesión/DB, no del payload):

| Consulta | Alcance |
| --- | --- |
| `tasks` sin scope / `personal` | Creador o delegación directa, respetando revocación de proyecto |
| `tasks` con `scope: projects` | Tareas autorizadas por el modelo de colaboración previo |
| `team_tasks` | Solo admin real del espacio/creador del espacio/superadmin existentes, solo dentro del espacio autorizado |
| `task_get` | Misma autorización por ID, scope personal por defecto; projects explícito para colaboración y team solo para admin |

Solicitar `team` en la lista normal no activa la vista de equipo. Roles simulados
en JSON, asignaciones inventadas en `sharedWith`, IDs de tareas privadas o de otro
espacio no conceden acceso. Las escrituras siguen con sus permisos previos:
consultar como admin no autoriza editar una tarea privada ajena. Las respuestas
tardías de otra identidad/espacio se descartan. La ampliación de pruebas cubre
estas consultas por HTTP con dos miembros, un administrador y un usuario de otro
espacio; las pruebas JS cubren nombres, cambios de delegación y separación de
colecciones. La API y la interfaz deben publicarse juntas en una futura entrega;
no se ha desplegado este cambio.

## Persistencia y permisos

Se reutilizan `workspace_members`, `task_shares`, `tasks.payload_json` y
`workspace_data.projects_json`; no se necesita una migración de esquema.
Los proyectos nuevos añaden `owner_id` y `member_ids` a su registro JSON.
El servidor verifica miembros del espacio, permisos para cambiar miembros,
lectura/edición por proyecto y autorización para trasladar tareas. Una delegación
individual no permite saltarse la membresía de un proyecto compartido.

Los proyectos antiguos sin `member_ids` mantienen su comportamiento anterior:
las tareas solo son visibles para su autor o destinatarios individuales. No se
amplía el acceso al cargar los datos. Un administrador puede convertirlos
explícitamente eligiendo miembros. Las asociaciones antiguas a proyectos ausentes
no se eliminan ni se pueden crear nuevas asociaciones a un proyecto desconocido.

`project_save` modifica solo el proyecto solicitado dentro de una transacción
y bloquea la fila del espacio. `tasks_sync` ya no sobrescribe la lista completa
de proyectos que manda un navegador. Solo el endpoint de proyectos modifica
miembros. Las delegaciones también bloquean esa fila; un destinatario inválido
se rechaza sin eliminar delegaciones previas. La interfaz envía solo tareas
cambiadas; el servidor exige una revisión
SHA-256 del JSON anterior para editar tareas existentes y devuelve **409** si cambió.
El lote se revierte si falla una validación. Se conserva siempre el propietario.

Los clientes antiguos que intenten editar tareas sin revisión recibirán un
conflicto y deberán recargar la nueva interfaz. Sus listas de proyectos enviadas
por sincronización se ignoran; no pueden sustituir la lista ni crear proyectos.
Esto es relevante al preparar una futura publicación coordinada de interfaz/API.

## Vista previa local

```sh
node tools/preview-server.mjs
```

Abrir `http://127.0.0.1:4178/` en Chrome. El servidor solo expone una lista de
recursos estáticos permitidos. No sirve `.git`, configuración PHP ni endpoints
reales; la API está simulada con personas y tareas ficticias. El selector inferior
permite revisar distintos miembros. Los datos de demostración se guardan en claves
propias del navegador, separadas de las claves de la app. El selector «Respuesta de
delegación» simula un rechazo o fallo de conexión solo en la vista previa. No demuestra ejecución
de PHP ni persistencia en MySQL. `preview/` y `tools/` no son archivos de producción.

## Verificación

No había comandos de prueba ni herramientas de build en el checkout original.

Ejecutado: `node --test tests/planning.test.cjs`: **49 pruebas pasan**. Cubren
renderizado, nombres de opciones nativas, tareas antiguas sin fechas, rangos
inválidos, UTC/cambio de zona/DST, filtros, acceso de miembros en la interfaz,
traslado a/sin proyecto, rechazo de traslado, errores y reversión, cola de
escrituras, envío solo de cambios/revisiones y conservación del tiempo real. También
cubren borrador/cancelación de delegación, bloqueo de doble guardado, rechazo y
fallo de conexión, reintento, tarea nueva antes de delegar y revocación directa.
Incluyen también la agenda horaria, solapamientos, cruces de medianoche, días
de 23/25 horas, alcance personal y conservación exacta de los datos. La QA
aislada en Chrome pasó a 1280, 375 y 320 px con datos ficticios.

Ejecutado manualmente en Chrome con API simulada: crear proyecto, elegir miembros,
crear tarea dentro del proyecto y verla desde otro miembro; no ver proyectos
ajenos; traslado entre proyectos; cambio de fechas, rechazo de fin anterior,
persistencia tras recargar y tareas sin planificación. No se alteraron cuentas
ni tareas reales.

Se reprodujo el menú de delegación tapado por la siguiente fila en Chrome a
1200 × 710. Se sustituyó por un diálogo nativo modal sobre el contenido, con
selección en borrador, Guardar/Cancelar, estado pendiente y error visible. Los
botones de las filas y del diálogo tienen un alto mínimo de 44 px y texto de
acento oscuro; las acciones de las filas pueden saltar de línea. Se comprobó
cancelar/reabrir, error de servidor, fallo de conexión, doble clic de guardado,
persistencia al recargar y lectura desde un destinatario ficticio. No se inició
el Pomodoro al delegar. La QA posterior del diálogo en `57fdf2a` completó
320 × 568 y 375 × 667, Tab/Shift+Tab, Espacio, Enter, Escape, Guardar/Cancelar,
errores, doble clic y persistencia. Se restauró 1200 × 710 y se liberó la pestaña.
Esa evidencia precede a la simplificación actual y no confirma los nuevos menús.

No ejecutado en este Mac por falta de PHP/MySQL; el workflow de GitHub Actions
ejecuta estas comprobaciones en Ubuntu 24.04, además de las suites MySQL y HTTP:

```sh
php -l api/index.php
php -l api/projects.php
php -l api/project-policy.php
php tests/project-policy.php
```

La suite PHP incluida verifica las políticas de lectura/escritura, miembros y
no miembros, administradores, traslados, datos antiguos, fechas, zona y conflictos.
La suite `tests/projects-mysql.php` usa los mismos servicios PHP que los endpoints
y comprueba creación/membresía, lectura, edición, persistencia, traslados,
revocación, conflictos y rollback en una base vacía `kaizen_test_*` de loopback.
La suite `tests/http.test.mjs` levanta cuatro workers PHP en loopback con una
copia temporal de la API y configuración ficticia. Usa una segunda base vacía
`kaizen_test_http`, cookies y cinco usuarios `example.test`; no carga configuración
privada, no crea grants ni usa cuentas de producción. Detiene el servidor y elimina
la copia y las sesiones al terminar. La base desaparece con el runner desechable.

El workflow ejecuta las suites JavaScript, políticas PHP, persistencia MySQL
y HTTP ampliada; el resultado de cada commit se consulta en el PR. La suite HTTP cubre login
válido/inválido, rotación del ID de sesión, cookie y token persistente, logout y
revocación, miembros/no miembros, lectura/escritura por ID, delegación/revocación,
destinatarios inválidos, creación/asignación/traslado, cruce de espacios, conservación
de autoría/fechas/tiempo Pomodoro, datos personales sin fechas, rollback de lotes y
dos peticiones simultáneas que producen un éxito y un conflicto 409.

Para ejecutar HTTP en un entorno aislado con PHP/MySQL, preparar una base vacía de
loopback `kaizen_test_http` y configurar `KAIZEN_TEST_DSN`, `KAIZEN_TEST_DB_USER` y
`KAIZEN_TEST_DB_PASSWORD`; ejecutar `node --test tests/http.test.mjs`. El workflow
ya prepara esa infraestructura con credenciales desechables del runner.

Límite: la prueba HTTP usa el servidor integrado de PHP; no valida la configuración
Apache/HTTPS de producción ni la entrega de notificaciones push. La vista previa
en Chrome sigue usando datos ficticios.

Al fusionar cambios a `main`, GitHub envía el evento `push` al webhook de
publicación que ya está configurado para este repositorio. El sitio se publica
desde `Gestor de Tareas.dc.html`; `index.html` debe conservar el mismo contenido
para que la vista previa y la página publicada no se separen. El workflow
comprueba que ambos archivos coincidan antes de pasar las pruebas. No hay que
cargar archivos ni configurar secretos FTP para cada cambio. Ver
[docs/github-deploy.md](docs/github-deploy.md).

El calendario horario anterior llegó a `main` el 12 de octubre de 2026. La
separación de bloques y cronograma está en `feature/daily-work-blocks` para revisión;
no se ha fusionado ni desplegado.
