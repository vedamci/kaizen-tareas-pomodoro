# Kaizen MCP: propuesta desactivada

Este PR añade un servidor MCP y un flujo OAuth para conectar una cuenta Kaizen con ChatGPT. **El código no está desplegado, activado ni conectado.** El endpoint devuelve 404 sin `mcp_enabled` y `mcp_oauth_enabled` en la configuración privada. No se crean clientes, permisos, tokens ni accesos reales al fusionar el código.

## Arquitectura verificada

Kaizen usa PHP y MySQL en un cPanel BanaHosting. La inspección de solo lectura del 14 de octubre de 2026 confirmó SSL activo, PHP 8.1 y raíz pública `public_html` para `vedamci.com.mx`; la app vive en `/tareas-pomodoro/`. Las peticiones MCP y OAuth son HTTP breves atendidas por PHP, sin proceso Node residente ni servicio externo de pago. Aún falta comprobar en un despliegue autorizado que Apache pase `Authorization` a PHP y sirva las rutas `.well-known`.

El servidor implementa Streamable HTTP **MCP 2025-11-25**: POST JSON-RPC, `initialize`, `ping`, `tools/list`, `tools/call`, 202 para notificaciones y 405 para GET. No implementa SSE ni sesiones MCP. La versión MCP 2026-07-28 difiere y aún no se anuncia. Referencias: [transporte 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [autorización MCP 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization), [autenticación de conectores OpenAI](https://developers.openai.com/plugins/build/auth).

## OAuth propuesto

- OAuth 2.1 authorization code con PKCE S256, cliente público `none`, cliente ChatGPT identificado por su [documento público](https://chatgpt.com/oauth/client.json). La app verifica ese documento por HTTPS y acepta exclusivamente el ID de cliente y URI de retorno configurados. El cliente fijo evita registro dinámico abierto.
- La autorización exige la sesión web existente de Kaizen y presenta un consentimiento explícito para `kaizen:read` o `kaizen:read kaizen:write`. La sesión web y `X-Remember-Token` nunca autentican llamadas MCP.
- Códigos de un uso y 5 minutos. Tokens de acceso aleatorios de 1 hora; tokens de renovación de 30 días con rotación y detección de reutilización. Solo hashes de tokens se almacenan en MySQL. El usuario puede revocar la conexión en `api/oauth.php?action=connections`; revocación invalida acceso y renovación.
- La audiencia está fijada al URL exacto del recurso MCP. Cada petición consulta identidad, vigencia, revocación y scopes actuales. Un usuario no puede hacerse pasar por otro con argumentos del cliente. Se reutilizan las comprobaciones de espacio, proyecto, tarea y administrador existentes.
- El servidor publica metadatos OAuth y de recurso protegido cuando esté activado. Por la subruta del sitio, la ruta estándar `/.well-known/oauth-authorization-server` y la ruta de recurso requieren las reglas preparadas en `deployment/root-well-known.htaccess`, instaladas en `public_html/.well-known/.htaccess` solo en un despliegue aprobado.

**Coste:** no requiere proveedor OAuth externo ni cuota adicional identificada; sí añade mantenimiento de seguridad, tablas y pruebas de compatibilidad a la app. Un proveedor de identidad externo reduciría ese mantenimiento, pero podría añadir cuotas y trabajo de mapeo de cuentas. Se recomienda la opción local por las cuentas PHP ya existentes y el alcance fijo de un solo cliente, con una revisión de seguridad antes de activarla.

## Herramientas y alcance

| Herramienta | Scope | Alcance |
| --- | --- | --- |
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

`kaizen:write` se concede junto con `kaizen:read`. No hay herramienta de borrado de tareas, administración de cuentas ni lectura de tareas privadas ajenas. **Rocas:** `main` no tiene entidad, campo ni endpoint específico; definirlas como categoría de tarea o nueva entidad requiere una decisión de producto posterior.

## Activación pendiente de aprobación específica

1. Revisar este PR independientemente del PR7 de contraseñas. No mezclar ramas. Aprobar explícitamente usuarios autorizados, scopes de lectura/escritura, identidad ChatGPT, URL canónica, consentimiento y despliegue. No enviar claves por chat.
2. En un despliegue controlado, instalar `api/mcp-schema.sql` sobre la base Kaizen y las reglas `deployment/root-well-known.htaccess` en la raíz `public_html/.well-known/`, preservando cualquier configuración existente. Asegurar HTTPS y cabecera `Authorization` en PHP.
3. Fuera del repositorio, configurar `mcp_enabled=true`, `mcp_oauth_enabled=true`, `mcp_resource=https://vedamci.com.mx/tareas-pomodoro/api/mcp.php`, `mcp_issuer=https://vedamci.com.mx`, `mcp_oauth_client_id=https://chatgpt.com/oauth/client.json`, `mcp_oauth_redirect_uri=https://chatgpt.com/connector_platform_oauth_redirect`, `mcp_oauth_authorization_url=https://vedamci.com.mx/tareas-pomodoro/api/oauth.php?action=authorize`, `mcp_oauth_token_url=https://vedamci.com.mx/tareas-pomodoro/api/oauth.php?action=token`, `mcp_resource_metadata_url=https://vedamci.com.mx/tareas-pomodoro/api/oauth.php?action=resource-metadata` y `mcp_allowed_origins` según el host probado. Nunca configurar `mcp_allow_insecure_test` en producción.
4. Comprobar descubrimiento `.well-known`, consentimiento, PKCE, tokens, revocación, 401/403, aislamiento entre usuarios y herramientas en staging. Solo después registrar el URL MCP en ChatGPT, completar OAuth para una cuenta autorizada y verificar `tools/list` y lecturas. Decidir por separado si habilitar escritura real.

Las pruebas de CI usan base MySQL y cuentas sintéticas descartables; cubren emisión, scopes, rotación, reutilización y revocación de tokens, además de reglas de herramientas. No prueban cPanel ni una conexión ChatGPT real.
