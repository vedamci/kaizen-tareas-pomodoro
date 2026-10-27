# Seguimiento de tareas delegadas al asistente

Estado de este documento: **implementación en rama de revisión**. El tablero, persistencia privada, rutas de sesión y herramientas MCP están en `feature/delegated-assistant-tasks`; no se ha desplegado ni creado credenciales ni ampliado scopes de producción.

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
- La vista consulta registros reales solo mediante las rutas autenticadas de Kaizen descritas abajo. Los fixtures requieren selección explícita y muestran **DEMO · datos ficticios**; nunca se mezclan con registros reales. La primera visita no carga el servidor; tras elegir **Servidor · registros privados**, se recuerda solo esa preferencia por usuario/espacio y se vuelve a consultar el servidor cuando se restaura la vista. El contenido privado nunca se guarda en `localStorage`.
- La integración se limita a registros de ejecución que el usuario haya autorizado expresamente, y a estados respaldados por eventos verificables. No crear credenciales ni ampliar scopes, allowlists o configuración privada.

## Modelo de ejecución propuesto

Persistir las ejecuciones por separado de `tasks`, en `assistant_runs` y `assistant_run_updates`. El workspace se toma de la petición, pero el servidor comprueba que la identidad autenticada tenga rol de administrador o sea creadora del espacio; el cliente no puede otorgarse acceso mediante campos de rol.

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

### Rutas de sesión implementadas

La rama añade las herramientas MCP `assistant_runs` y `assistant_run_upsert` junto a las acciones REST de sesión:

- `assistant_runs`: lista registros del espacio, solo para administradores del espacio.
- `assistant_run_upsert`: crea o actualiza un registro y agrega una actualización de auditoría; requiere `source`, `external_id`, `idempotency_key`, `title` y `status`, más campos opcionales de avance, bloqueo, próximo paso, entregables y fechas verificadas de delegación/cierre.
- `assistant_run_mark_completed`: permite a una persona administradora marcar una ejecución abierta como completada desde la interfaz autenticada de Kaizen. El servidor registra la hora, quién lo hizo y el evento “Marcada como terminada manualmente en Kaizen”; la lectura existente `assistant_runs` devuelve ese evento para que Codex pueda detectarlo. Repetir la acción sobre una ejecución ya completada es seguro; una ejecución cancelada no puede completarse manualmente. No amplía el alcance OAuth ni modifica tareas normales.
- `supervision_people` y `supervision_tasks`: devuelven integrantes y tareas del integrante seleccionado, solo a administradores del espacio.

En MCP, la lectura usa `kaizen:read`. El upsert acepta `kaizen:assistant_runs:write` o el permiso amplio preexistente `kaizen:write`, siempre con autorización administrativa del workspace. El permiso limitado no habilita mutaciones de tareas, bloques o proyectos. Los consentimientos OAuth existentes no se amplían por la actualización del código. La integración de Codex se describe en `integrations/codex/README.md` y requiere activar la conexión local y confiar en el hook.

El servidor genera `verified_at`; ignora una fecha enviada por el cliente. Repetir una clave idempotente con el mismo contenido devuelve el registro existente; reutilizarla con contenido distinto se rechaza. Tanto las lecturas como las escrituras están separadas por workspace y no modifican la tabla de tareas. La llamada necesita la sesión autorizada existente; no hay una integración de servicio ni una programación desatendida configurada.

La respuesta de supervisión contiene solo título, estado, proyecto, fecha límite, rango planificado y `workBlocks` explícitos. Omite notas y el JSON interno de la tarea. Una tarea se devuelve una sola vez con su mismo ID. El cronograma y los bloques horarios se presentan por separado; el rango planificado nunca se transforma en horas de trabajo.

## Experiencia visual

- Integrarse en la navegación de Kaizen como una sección propia, sin reemplazar **Delegadas**, **Tareas del equipo**, Proyectos ni Cronograma.
- La sección administrativa **Por persona** requiere elegir a un integrante antes de cargar sus tareas; no selecciona al primero de forma automática. Presenta sus rangos planificados y los bloques `workBlocks` explícitos de hoy en paneles separados. Los dos paneles hacen referencia al mismo ID de tarea y omiten IDs duplicados.
- Usar la tipografía, el sistema de componentes y los colores de Kaizen. Dar a la sección una cabecera visual clara y jerarquía marcada, con resumen por estados y tarjetas escaneables.
- Cada tarjeta presenta título, etiqueta de estado, edad desde delegación, último avance con hora registrada, bloqueo cuando exista, próximo paso y entregables disponibles.
- Las ejecuciones abiertas en la fuente **Servidor · registros privados** ofrecen **Marcar como completada manualmente** en la tarjeta y en el detalle. La acción guarda el evento en el servidor y muestra confirmación accesible; no aparece en datos de demo ni en ejecuciones canceladas/completadas.
- Las vistas/filtros por estado y búsqueda no mutan la ejecución. La tarjeta completada muestra **Tiempo de resolución** calculado con marcas verificadas. La cancelada se presenta como cancelada, no como resuelta.
- El detalle incluye la cronología de eventos y distingue visualmente el tiempo transcurrido del activo. Sin sesiones registradas, se lee **Tiempo activo: Sin registro**.
- Mostrar fechas y horas con zona local del dispositivo, conservando y usando la marca UTC/origen del dato. Incluir una fecha absoluta accesible además del texto relativo (“hace …”).
- Indicar que “hoy” corresponde al día y zona local de quien consulta. Marcar cuando un bloque explícito continúa desde el día anterior o después de hoy.
- Diseñar estados vacíos, carga y error; no rellenarlos con ejemplos que parezcan registros reales.
- Responsive desde 320 px; foco visible, controles de teclado, etiquetas legibles y botones con objetivos táctiles de al menos 44 px.

## Criterios de aceptación

### Datos y métricas

1. La fuente real autenticada carga registros privados; al elegir un fixture, la vista muestra de forma persistente **DEMO · datos ficticios** y no mezcla registros sintéticos con reales.
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

11. Los filtros de estado y búsqueda actualizan lista y contadores sin crear, editar ni completar ejecuciones; la acción manual de finalización es un control separado.
12. Marcar una ejecución abierta manualmente la cambia a `completed`, asigna `completed_at` desde el reloj del servidor, conserva su avance/bloqueos/entregables anteriores y añade un evento con actor humano. La misma lectura autenticada devuelve el cambio; repetir la acción no crea otro evento.
13. El detalle de una ejecución muestra eventos en orden, marcas de tiempo absolutas y zona/hora local de forma consistente.
14. La versión conectada usa identidad y workspace autorizados en servidor; no confía en identidad/rol/workspace recibidos desde el navegador.
15. Una lectura/escritura con sesión no autorizada se rechaza y no filtra títulos, briefs, eventos ni entregables entre workspaces.
16. La suite visual/funcional verifica 1280 px y anchos móviles de 375 y 320 px, navegación por teclado, foco y estados vacíos/carga/error.
17. Las pruebas de integración usan fixtures y una base aislada desechable; no cargan credenciales, OAuth ni cuentas de producción.
18. La ruta de supervisión rechaza a miembros normales, usuarios ajenos y selecciones fuera del workspace; pruebas de dos integrantes verifican aislamiento.
19. La misma tarea aparece con un único ID y conserva por separado el rango completo de cronograma y cada `workBlock` explícito.

## Fuera de alcance de este cambio

- Modificar permisos OAuth, ampliar scopes, allowlists o configuración de producción.
- Convertir `_delegated_at` o `task_shares` en una delegación al asistente.
- Habilitar en el preview peticiones a la API publicada.
- Inventar porcentajes, fechas, actividad, bloqueos, próximos pasos o entregables.
- Desplegar. La implementación necesita revisión, pruebas y reporte antes de cualquier paso de publicación.
