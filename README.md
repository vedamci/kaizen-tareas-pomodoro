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
miembros. La interfaz envía solo tareas cambiadas; el servidor exige una revisión
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
propias del navegador, separadas de las claves de la app. No demuestra ejecución
de PHP ni persistencia en MySQL. `preview/` y `tools/` no son archivos de producción.

## Verificación

No había comandos de prueba ni herramientas de build en el checkout original.

Ejecutado: `node --test tests/planning.test.cjs`: **13 pruebas pasan**. Cubren
renderizado, nombres de opciones nativas, tareas antiguas sin fechas, rangos
inválidos, UTC/cambio de zona/DST, filtros, acceso de miembros en la interfaz,
traslado a/sin proyecto, rechazo de traslado, errores y reversión, cola de
escrituras, envío solo de cambios/revisiones y conservación del tiempo real.

Ejecutado manualmente en Chrome con API simulada: crear proyecto, elegir miembros,
crear tarea dentro del proyecto y verla desde otro miembro; no ver proyectos
ajenos; traslado entre proyectos; cambio de fechas, rechazo de fin anterior,
persistencia tras recargar y tareas sin planificación. No se alteraron cuentas
ni tareas reales.

No ejecutado en este Mac por falta de PHP/MySQL; el workflow de GitHub Actions
ejecuta estas comprobaciones en Ubuntu 24.04, además de la suite MySQL:

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
La prueba HTTP integral de sesiones y `task_share` queda pendiente antes de publicar.
No se usó la configuración privada del servidor para estas pruebas.

Archivos para una futura publicación: HTML principal, `planning.js`, `support.js`,
`api/index.php`, `api/projects.php` y `api/project-policy.php`. Publicación pendiente
de confirmación separada y de completar las comprobaciones del backend.
