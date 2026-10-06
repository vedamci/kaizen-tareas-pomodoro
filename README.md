# Kaizen: proyectos compartidos y cronograma

Implementación local en `feature/shared-projects`, basada en el checkout de
KINGSTON. No se modificó ese respaldo ni se publicó la app. La rama de función
se prepara para revisión mediante un PR en borrador en el repositorio existente.

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
La suite JavaScript suma ahora 38 pruebas. La revisión visual e interacción real
del nuevo cronograma y los nuevos menús sigue pendiente: esta sesión no expone
una herramienta de control de Chrome, aunque el navegador está disponible.

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
persistida personal. No se cambian usuarios ni roles.

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

Ejecutado: `node --test tests/planning.test.cjs`: **38 pruebas pasan**. Cubren
renderizado, nombres de opciones nativas, tareas antiguas sin fechas, rangos
inválidos, UTC/cambio de zona/DST, filtros, acceso de miembros en la interfaz,
traslado a/sin proyecto, rechazo de traslado, errores y reversión, cola de
escrituras, envío solo de cambios/revisiones y conservación del tiempo real. También
cubren borrador/cancelación de delegación, bloqueo de doble guardado, rechazo y
fallo de conexión, reintento, tarea nueva antes de delegar y revocación directa.

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
Apache/cPanel/HTTPS de producción ni la entrega de notificaciones push. La vista
previa en Chrome sigue usando datos ficticios. No se probó ni publicó en producción.

Archivos para una futura publicación: HTML principal, `planning.js`, `support.js`,
`api/index.php`, `api/projects.php` y `api/project-policy.php`. Publicación pendiente
de confirmación separada. El PR permanece en borrador; no se mezcló con `main`.
