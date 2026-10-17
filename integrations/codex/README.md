# Registro automático de encargos de Codex en Kaizen

Esta integración usa un hook global `UserPromptSubmit` para dar a Codex IDs
estables y la instrucción de registrar encargos reales en la sección **Asistente**.
El hook no envía datos por red. Codex llama a `assistant_run_upsert` mediante
OAuth cuando existe un encargo y actualiza el mismo registro al avanzar o
terminar. Las preguntas y mensajes de estado no crean otro registro.

## Conexión local, una sola vez

1. Publicar esta versión de la API Kaizen. El permiso nuevo
   `kaizen:assistant_runs:write` permite solo `assistant_run_upsert`, exige
   `kaizen:read` y administración del espacio. Los permisos de escritura de
   tareas normales siguen separados. Los consentimientos existentes no cambian.
2. Añadir el MCP de Kaizen en la configuración local de Codex:

   ```toml
   [mcp_servers.kaizen]
   url = "https://vedamci.com.mx/tareas-pomodoro/api/mcp.php"
   auth = "oauth"
   scopes = ["kaizen:read", "kaizen:assistant_runs:write"]
   enabled_tools = ["list_workspaces", "assistant_runs", "assistant_run_upsert"]
   ```

   Luego iniciar `codex mcp login kaizen` y autorizar **Codex** en Kaizen.
   Kaizen acepta el documento de identidad oficial
   `https://chatgpt.com/oauth/codex/client.json` y su callback local `/callback`
   con puerto variable. La conexión ChatGPT previa sigue funcionando.
3. Instalar un hook de usuario en `~/.codex/hooks.json` para cubrir todos los
   chats locales de Codex. `RUTA_REPO` es la ruta absoluta de este checkout:

   ```json
   {
     "hooks": {
       "UserPromptSubmit": [{
         "hooks": [{
           "type": "command",
           "command": "/usr/bin/python3 RUTA_REPO/integrations/codex/kaizen_task_hook.py",
           "timeout": 5
         }]
       }]
     }
   }
   ```

   Si ya existe `~/.codex/hooks.json`, fusionar el evento sin sustituir los
   demás hooks. Revisar y confiar en este hook desde `/hooks` en Codex; Codex
   no ejecuta hooks de usuario nuevos hasta completar esa revisión.

## Alcance y verificación

El hook solo se ejecuta en sesiones locales donde está activa la configuración
de usuario. No registra mensajes de otros servicios o sesiones en la nube que
no usen este runtime. Una tarea nueva usa el ID del turno inicial como
`external_id`; instrucciones posteriores conservan ese ID y cada actualización
tiene una clave de idempotencia distinta. El estado final refleja lo que
realmente ocurrió y no se marca completado por el mero fin de un turno.

Tras el consentimiento OAuth y la confianza en el hook, enviar un encargo de
prueba sin datos sensibles, comprobar que aparece en **Asistente · Servidor**,
enviar una corrección en el mismo chat y verificar que actualiza el mismo
registro. Revocar ambas conexiones desde la página de conexiones de Kaizen
si se desea detener el acceso.
