# Kaizen MCP

El servidor MCP y el flujo OAuth se integraron mediante el [PR #8](https://github.com/vedamci/kaizen-tareas-pomodoro/pull/8) y se publicaron el 14 de octubre de 2026. La conexión de una cuenta de ChatGPT aún está pendiente del consentimiento del usuario. Sin configuración privada, el endpoint devuelve 404.

## Arquitectura verificada

Kaizen usa PHP y MySQL en un cPanel BanaHosting. La inspección de solo lectura del 14 de octubre de 2026 confirmó SSL activo, PHP 8.1 y raíz pública `public_html` para `vedamci.com.mx`; la app vive en `/tareas-pomodoro/`. Las peticiones MCP y OAuth son HTTP breves atendidas por PHP, sin proceso Node residente ni servicio externo de pago. El despliegue confirmó que Apache pasa `Authorization` a PHP y sirve las rutas `.well-known`.

El servidor implementa Streamable HTTP **MCP 2025-11-25**: POST JSON-RPC, `initialize`, `ping`, `tools/list`, `tools/call`, 202 para notificaciones y 405 para GET. No implementa SSE ni sesiones MCP. La versión MCP 2026-07-28 difiere y aún no se anuncia. Referencias: [transporte 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [autorización MCP 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization), [autenticación de conectores OpenAI](https://developers.openai.com/plugins/build/auth).

## OAuth implementado

- OAuth 2.1 authorization code con PKCE S256, cliente público `none`. Se aceptan los documentos oficiales de [ChatGPT](https://chatgpt.com/oauth/client.json) y [Codex](https://chatgpt.com/oauth/codex/client.json), verificados por HTTPS. ChatGPT conserva el retorno configurado; Codex usa `/callback` en loopback con puerto variable. Otros IDs de cliente o retornos se rechazan.
- La autorización exige la sesión web existente de Kaizen, una identidad incluida en `mcp_allowed_user_ids` y consentimiento explícito. `mcp_max_scope=kaizen:read` mantiene bloqueada la escritura general de tareas. El permiso separado `kaizen:assistant_runs:write` solo permite registrar ejecuciones del asistente para administradores; se puede desactivar con `mcp_assistant_runs_write_enabled=false` en la configuración privada. La sesión web y `X-Remember-Token` nunca autentican llamadas MCP.
- Códigos de un uso y 5 minutos. Tokens de acceso aleatorios de 1 hora; tokens de renovación de 30 días con rotación y detección de reutilización. Solo hashes de tokens se almacenan en MySQL. El usuario puede revocar la conexión en `api/oauth.php?action=connections`; revocación invalida acceso y renovación.
- La audiencia está fijada al URL exacto del recurso MCP. Cada petición consulta identidad, vigencia, revocación y scopes actuales. Un usuario no puede hacerse pasar por otro con argumentos del cliente. Se reutilizan las comprobaciones de espacio, proyecto, tarea y administrador existentes.
- El servidor publica metadatos OAuth y de recurso protegido mediante las reglas de `deployment/root-well-known.htaccess` instaladas en la raíz pública.

**Coste:** no requiere proveedor OAuth externo ni cuota adicional identificada; sí añade mantenimiento de seguridad, tablas y pruebas de compatibilidad a la app. Un proveedor de identidad externo reduciría ese mantenimiento, pero podría añadir cuotas y trabajo de mapeo de cuentas. Se recomienda la opción local por las cuentas PHP ya existentes y el alcance fijo de un solo cliente, con una revisión de seguridad antes de activarla.

## Herramientas y alcance

| Herramienta | Scope | Alcance |
| --- | --- | --- |
| `get_profile` | `kaizen:read` | Identidad estable de la cuenta conectada. |
| `list_workspaces` | `kaizen:read` | Espacios autorizados del usuario. |
| `list_projects` | `kaizen:read` | Proyectos visibles en el espacio. |
| `list_tasks` | `kaizen:read` | Tareas personales, proyectos accesibles o equipo solo para admin. |
| `get_task` | `kaizen:read` | Tarea autorizada y su revisión. |
| `day_agenda` | `kaizen:read` | Bloques `workBlocks` y fechas del día UTC. |
| `create_task` | `kaizen:write` | Tarea con `client_id` estable para reintentos. |
| `update_task` | `kaizen:write` | Edición con control de revisión. |
| `set_task_done` | `kaizen:write` | Estado con revisión e idempotencia de repetición. |
| `set_work_block` | `kaizen:write` | Bloque horario validado. |
| `remove_work_block` | `kaizen:write` | Retirada idempotente de un bloque. |
| `save_project` | `kaizen:write` | Proyecto y miembros bajo política existente. |
| `assistant_runs` | `kaizen:read` | Ver registros del asistente como administrador. |
| `assistant_run_upsert` | `kaizen:assistant_runs:write` o `kaizen:write` | Registrar y actualizar ejecuciones del asistente como administrador. |

Los dos permisos de escritura requieren `kaizen:read`. El consentimiento de `kaizen:assistant_runs:write` no permite crear o editar tareas, bloques ni proyectos normales. No hay herramienta de borrado de tareas, administración de cuentas ni lectura de tareas privadas ajenas. **Rocas:** `main` no tiene entidad, campo ni endpoint específico; definirlas como categoría de tarea o nueva entidad requiere una decisión de producto posterior.

## Conexión

El URL del servidor es `https://vedamci.com.mx/tareas-pomodoro/api/mcp.php`. Para el registro automático desde Codex, seguir [integrations/codex/README.md](integrations/codex/README.md). El consentimiento OAuth para el permiso limitado sigue siendo necesario; publicar código en GitHub por sí solo no instala el hook local ni concede permisos. Verificar `tools/list` y una escritura de prueba en un espacio administrado antes de considerar activa la integración.

Si en el futuro se cambia `mcp_allowed_user_ids` o `mcp_max_scope`, revocar primero las concesiones y tokens afectados con `mcpOAuthRevokeGrant` y comprobarlo antes de volver a ampliar la política. Cambiar solo la configuración a un valor más estrecho bloquea el uso presente, pero ampliar después al valor anterior podría reactivar tokens todavía vigentes.

Las pruebas de CI usan base MySQL y cuentas sintéticas descartables; cubren emisión, scopes, rotación, reutilización y revocación de tokens, además de reglas de herramientas. No prueban cPanel ni una conexión ChatGPT real.
