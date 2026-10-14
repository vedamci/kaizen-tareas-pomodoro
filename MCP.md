# Kaizen MCP (candidato desactivado)

Esta rama propone un endpoint `api/mcp.php` para herramientas MCP sobre las entidades que Kaizen guarda hoy. **No está desplegado, activado ni conectado.** La configuración actual no contiene `mcp_enabled`, `mcp_auth_file` ni `mcp_resource`, de modo que el endpoint responde 404. Ninguna sesión web, cookie ni `X-Remember-Token` sirve como credencial MCP.

## Arquitectura y compatibilidad

- La app publicada usa PHP, MySQL y Apache/cPanel. El endpoint atiende cada petición con PHP y devuelve JSON; no necesita proceso residente, WebSocket ni SSE. No se ha comprobado en el cPanel de producción que Apache entregue `Authorization` a PHP o que permita la ruta definitiva.
- Implementa el transporte Streamable HTTP de **MCP 2025-11-25**, con POST JSON-RPC, `initialize`, `ping`, `tools/list`, `tools/call`, 202 para notificaciones y 405 para GET. No ofrece flujos SSE ni sesiones de protocolo. Un cliente debe enviar `Accept: application/json, text/event-stream` y `MCP-Protocol-Version: 2025-11-25` después de inicializar.
- El protocolo 2026-07-28 cambió el transporte (eliminó GET y sesiones y agregó metadatos en cabeceras). Este endpoint anuncia solo 2025-11-25, no 2026-07-28. Fuentes primarias: [transporte 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [transporte 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [autorización 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- El servidor está preparado para recibir exclusivamente `Authorization: Bearer` y comprobar identidad, vigencia, audiencia y scopes por petición. El **verificador OAuth y el servidor de autorización aún deben elegirse e implementarse**. Esta rama no publica metadatos OAuth ni emite tokens; por tanto todavía no es conectable mediante el flujo OAuth de un host MCP remoto. La especificación actual pide metadatos de recurso protegido y un servidor de autorización descubrible para ese flujo.

## Herramientas

| Herramienta | Scope | Alcance |
| --- | --- | --- |
| `list_workspaces` | `kaizen:read` | Espacios autorizados del usuario actual. |
| `list_projects` | `kaizen:read` | Proyectos visibles en el espacio. |
| `list_tasks` | `kaizen:read` | Personal, proyectos accesibles o equipo solo para admin. |
| `get_task` | `kaizen:read` | Tarea autorizada con revisión. |
| `day_agenda` | `kaizen:read` | Bloques `workBlocks` y tareas con fecha/rango del día UTC. |
| `create_task` | `kaizen:write` | Tarea nueva con `client_id` estable para reintentos. |
| `update_task` | `kaizen:write` | Edita título, notas, fecha límite o proyecto con revisión. |
| `set_task_done` | `kaizen:write` | Actualiza estado con revisión; repetir el mismo estado devuelve éxito. |
| `set_work_block` | `kaizen:write` | Inserta o reemplaza un bloque validado; conserva cronograma y Pomodoro. |
| `remove_work_block` | `kaizen:write` | Quita un bloque; repetir la retirada devuelve éxito. |
| `save_project` | `kaizen:write` | Crea o edita un proyecto y sus miembros bajo la política existente. |

`kaizen:write` implica acceso a las herramientas de lectura; `kaizen:read` solo ofrece lectura. La identidad procede del verificador y se consulta de nuevo en MySQL; un ID o rol enviado por el cliente nunca crea permisos. Las herramientas reutilizan `projectTaskAccess`, `projectTaskWrite`, `validateTaskRevision`, `validateTaskWorkBlocks`, `loadWorkspaceTasks` y la comprobación de membresía de espacio. No hay herramienta para borrar tareas, delegar, administrar cuentas ni ver datos privados de otros miembros. La vista `team` es de solo lectura y solo para admin. Los errores internos ocultan detalles y las respuestas no devuelven secretos.

**Rocas:** Kaizen no tiene tabla, campo ni endpoint específico para rocas en `main`; no se expone una herramienta ficticia. Si se desea que “roca” sea una categoría de tarea o una entidad nueva, debe definirse su semántica y permisos antes de agregarla.

## Decisión pendiente para conexión remota

**Recomendación:** un proveedor OAuth 2.1 externo con autorización explícita del usuario, acceso por usuario, audiencia fijada al URL MCP y scopes `kaizen:read` / `kaizen:write`. El backend PHP actuaría como *resource server* y validaría tokens mediante un verificador privado fuera del árbol web. Hace falta implementar descubrimiento OAuth (incluido Protected Resource Metadata), consentimiento, revocación y mapeo de identidad antes de conectar un host.

Otra opción es alojar autorización OAuth dentro de Kaizen, pero añade almacenamiento y rotación de clientes, códigos, PKCE, tokens y revocación al cPanel. No se recomienda para la primera integración. Un token estático copiado a un host no proporciona por sí solo el flujo OAuth que espera un host remoto y no se propone como activación.

Contrato previsto para el verificador **después de aprobación**: `mcp_auth_file` apunta a un archivo PHP privado que devuelve un callable. Este valida firma o introspección, emisor, audiencia, expiración y revocación; devuelve `['user_id'=>int,'scopes'=>string[],'audience'=>string,'expires_at'=>int]`, o `null`. El endpoint exige que `audience` coincida exactamente con `mcp_resource`. El archivo de pruebas `tests/mcp-fixture.php` crea únicamente un verificador ficticio en un directorio temporal; no es un mecanismo de producción.

## Secuencia de activación, pendiente de aprobación específica

1. Revisar y fusionar por separado este PR, sin incluir el PR7 de contraseñas.
2. Elegir proveedor OAuth, URL canónica del recurso, política de consentimiento, acceso de lectura/escritura y usuarios autorizados. No enviar claves por chat.
3. Implementar y probar descubrimiento OAuth, verificación de tokens y revocación en una entrega separada. Configurar `mcp_auth_file`, `mcp_resource`, `mcp_allowed_origins` y solo entonces `mcp_enabled=true` en configuración privada. No usar `mcp_allow_insecure_test` fuera de pruebas de loopback.
4. En staging cPanel, comprobar HTTPS, cabecera `Authorization`, POST/GET, 401/403, aislamiento entre espacios, scopes, revisiones, reintentos y compatibilidad real del host MCP. Confirmar que ningún token aparece en URL o logs.
5. Tras una aprobación específica para activar, desplegar API y registrar el URL HTTPS MCP en el host mediante su flujo OAuth. Verificar `tools/list` y una lectura sintética antes de habilitar escrituras reales.

Las pruebas de CI usan MySQL y cuentas ficticias en una base descartable. No prueban la configuración real de Apache/cPanel ni conectan un host MCP externo.
