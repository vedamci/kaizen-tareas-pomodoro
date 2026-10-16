# Seguimiento de tareas delegadas al asistente

Estado de este documento: **especificación para implementación y revisión**. La entidad y su API son una propuesta coordinable; no existen todavía en el contrato MCP descrito aquí.

## Objetivo

Añadir a Kaizen una sección visual para que la persona usuaria siga las ejecuciones que delegó a Luna: estado, último avance verificable, bloqueo, próximo paso, entregables, antigüedad desde la delegación y tiempo hasta la resolución de las tareas completadas.

La vista distingue:

- **Tiempo transcurrido**: diferencia entre las marcas de tiempo de delegación y finalización (o entre delegación y ahora para una tarea abierta).
- **Tiempo activo**: suma de intervalos de trabajo registrados explícitamente. Si esos intervalos no existen, el dato se muestra como **Sin registro**; nunca se deduce del tiempo transcurrido, de Pomodoros, de `seconds`, de `pomos` ni de `_revision`.

No muestra porcentajes de avance ni fechas calculadas a partir de datos incompletos.

## Contrato existente y límite de alcance

El contrato MCP recibido expone `list_tasks(workspace_id, scope)` con `personal`, `projects` y `team`, y `get_task(workspace_id, task_id, scope)`. El payload de tareas es flexible y actualmente incluye campos como `done`, `doneAt`, `createdAt`, `seconds`, `pomos` y `_revision`.

- `_delegated_at` representa el compartir/asignar una tarea entre usuarios de Kaizen; **no** es una delegación a Luna.
- Las tareas de Kaizen y las ejecuciones del asistente son conceptos distintos. No se debe inferir una ejecución a partir de `done`, del propietario, de compartir una tarea, de Pomodoros o de su presencia en `team`.
- Hasta que la fuente y el contrato nuevos estén habilitados y comprobados, la vista previa usa fixtures locales ficticios con una etiqueta visible **DEMO · datos ficticios**. No debe hacer solicitudes a la API de producción ni importar notas internas.
- La integración futura se limita a tareas y apuntes que el usuario haya autorizado expresamente, y a estados respaldados por eventos verificables. No cambiar OAuth, scopes, allowlists ni configuración privada como parte de este trabajo.

## Modelo de ejecución propuesto

Persistir las ejecuciones por separado de `tasks` —por ejemplo, una entidad `assistant_runs` y una colección/tabla `assistant_run_events`—, con acceso siempre limitado al `workspace_id` autorizado por la sesión. No aceptar el workspace ni los roles como fuente de autorización desde el payload del cliente.

### Registro de ejecución

Campos conceptuales:

- ID estable y `workspace_id`.
- Identidad del usuario que delegó, resuelta por sesión.
- Asistente destinatario (por ejemplo, Luna).
- Título breve y brief autorizado.
- Estado: `queued`, `in_progress`, `blocked`, `completed` o `cancelled`.
- `delegated_at`: marca de tiempo registrada al aceptar la delegación; si se importa una fecha anterior, conservar su origen verificable.
- `completed_at` o `closed_at`, según estado; solo se completa al registrar el evento correspondiente.
- Bloqueo actual, próximo paso y entregables actuales, derivados de eventos explícitos.
- `last_activity_at`, derivado del evento más reciente y no editado a mano.

No inventar un responsable humano, fecha límite, actualización, adjunto ni hora de delegación. Los campos desconocidos se omiten o se muestran como “Sin dato”.

### Eventos

Cada actualización relevante añade un evento con ID, `run_id`, marca de tiempo de servidor, tipo, actor y resumen autorizado. Tipos mínimos:

- `delegated`
- `status_changed`
- `progress_note`
- `blocker_set` / `blocker_cleared`
- `next_step_set`
- `deliverable_added`
- `work_started` / `work_paused` (solo si se requiere medir horas activas)
- `completed` / `cancelled`

Los eventos se muestran en orden cronológico inverso en el detalle. Una marca temporal de evento es evidencia de cuándo se registró ese evento, no de cuánto tiempo se trabajó. Para tiempo activo, emparejar intervalos explícitos `work_started`–`work_paused`; descartar intervalos abiertos/inválidos del total hasta cerrarlos y mostrar el estado de medición.

### API por acordar antes de conectar

La API MCP actual de tareas no cubre estas ejecuciones. Acordar con el trabajo de MCP un contrato separado y limitado al workspace autorizado. Operaciones conceptuales:

- listar ejecuciones del asistente, con filtro de estado y paginación;
- obtener ejecución y eventos;
- crear una ejecución desde una delegación autorizada;
- añadir eventos de avance;
- cerrar como completada o cancelada.

La lista debe devolver `delegated_at`, estado y `last_activity_at`; el detalle debe incluir eventos, bloqueos, próximo paso y entregables. Debe rechazar IDs o workspaces no autorizados sin alterar tareas existentes. Los nombres y la forma final de operaciones quedan pendientes de coordinación; este documento no afirma que ya estén implementadas.

## Experiencia visual

- Integrarse en la navegación de Kaizen como una sección propia, sin reemplazar **Delegadas**, **Tareas del equipo**, Proyectos ni Cronograma.
- Usar la tipografía, el sistema de componentes y los colores de Kaizen. Dar a la sección una cabecera visual clara y jerarquía marcada, con resumen por estados y tarjetas escaneables.
- Cada tarjeta presenta título, etiqueta de estado, edad desde delegación, último avance con hora registrada, bloqueo cuando exista, próximo paso y entregables disponibles.
- Las vistas/filtros por estado y búsqueda no mutan la ejecución. La tarjeta completada muestra **Tiempo de resolución** calculado con marcas verificadas. La cancelada se presenta como cancelada, no como resuelta.
- El detalle incluye la cronología de eventos y distingue visualmente el tiempo transcurrido del activo. Sin sesiones registradas, se lee **Tiempo activo: Sin registro**.
- Mostrar fechas y horas con zona local del dispositivo, conservando y usando la marca UTC/origen del dato. Incluir una fecha absoluta accesible además del texto relativo (“hace …”).
- Diseñar estados vacíos, carga y error; no rellenarlos con ejemplos que parezcan registros reales.
- Responsive desde 320 px; foco visible, controles de teclado, etiquetas legibles y botones con objetivos táctiles de al menos 44 px.

## Criterios de aceptación

### Datos y métricas

1. Con un fixture marcado como demo, la vista muestra de forma persistente **DEMO · datos ficticios** y no hace llamadas de lectura o escritura a la API real.
2. No aparecen tareas reales, notas privadas, miembros de otros espacios ni datos derivados de un `scope: team` sin autorización.
3. Una ejecución solo aparece cuando existe un registro explícito de delegación al asistente; compartir una tarea con otro usuario Kaizen no la crea ni la cambia.
4. Una ejecución abierta muestra antigüedad como `ahora - delegated_at`. Una completada muestra tiempo de resolución como `completed_at - delegated_at`. Ambas diferencias se calculan desde timestamps válidos y se identifican como **transcurridas**.
5. Una ejecución cancelada se distingue de una completada y no se cuenta como resuelta.
6. Si falta `delegated_at`, `completed_at` o el timestamp del último evento, no se fabrica una fecha ni una duración: se informa que el dato no está disponible.
7. El resumen del último avance coincide con el evento verificable más reciente. Bloqueo, próximo paso y entregable solo se muestran cuando hay evento/valor registrado.
8. `seconds`, `pomos`, `doneAt` y `_revision` no se presentan como horas activas ni como duración de resolución.
9. Sin intervalos explícitos de trabajo, las horas activas son “Sin registro”. Con intervalos válidos, el total es la suma de sus duraciones y se prueba con un reloj controlado.
10. Un intervalo de trabajo abierto, invertido, duplicado o sin pareja no incrementa silenciosamente el tiempo activo.

### Interacción, acceso y calidad

11. Los filtros de estado y búsqueda actualizan lista y contadores sin crear, editar ni completar ejecuciones.
12. El detalle de una ejecución muestra eventos en orden, marcas de tiempo absolutas y zona/hora local de forma consistente.
13. La versión conectada usa identidad y workspace autorizados en servidor; no confía en identidad/rol/workspace recibidos desde el navegador.
14. Una lectura/escritura con sesión no autorizada se rechaza y no filtra títulos, briefs, eventos ni entregables entre workspaces.
15. La suite visual/funcional verifica 1280 px y anchos móviles de 375 y 320 px, navegación por teclado, foco y estados vacíos/carga/error.
16. Las pruebas de integración usan fixtures y una base aislada desechable; no cargan credenciales, OAuth ni cuentas de producción.

## Fuera de alcance de este cambio

- Modificar permisos OAuth, ampliar scopes, allowlists o configuración de producción.
- Convertir `_delegated_at` o `task_shares` en una delegación al asistente.
- Habilitar en el preview peticiones a la API publicada.
- Inventar porcentajes, fechas, actividad, bloqueos, próximos pasos o entregables.
- Desplegar. La implementación necesita revisión, pruebas y reporte antes de cualquier paso de publicación.
