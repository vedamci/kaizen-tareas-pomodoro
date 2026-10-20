# Publicar Kaizen desde GitHub

El workflow `.github/workflows/test.yml` ejecuta las pruebas para los pull
requests y ramas de trabajo. Al hacer push a `main` —por ejemplo, al fusionar un
pull request— espera a que el job `test` termine y después publica los archivos
de la aplicación al hosting por FTPS. También permite volver a publicar `main`
desde **Actions → Test and deploy Kaizen → Run workflow**. No publica pull
requests ni ramas de trabajo.

## Configuración única en GitHub

Antes de fusionar cambios a `main`, guarda estos secretos en **Settings → Secrets
and variables → Actions → New repository secret** del repositorio:

| Secreto | Valor |
| --- | --- |
| `KAIZEN_FTP_HOST` | Nombre del servidor FTPS, sin `ftp://`. |
| `KAIZEN_FTP_USERNAME` | Usuario de una cuenta FTP autorizada para la carpeta pública de Kaizen. |
| `KAIZEN_FTP_PASSWORD` | Contraseña de esa cuenta FTP. |
| `KAIZEN_FTP_REMOTE_DIR` | Carpeta remota que contiene `index.html`, relativa al acceso FTP o absoluta según el servidor. |

El puerto usa `21` por defecto. Si el servidor utiliza otro, crea la variable
`KAIZEN_FTP_PORT` en **Settings → Secrets and variables → Actions → Variables**.
Usa una cuenta con acceso limitado a la carpeta del sitio, FTPS explícito y
certificado TLS válido. Los valores no se escriben en el repositorio ni aparecen
en los registros del workflow.

Después de esta configuración única, el flujo normal es editar, revisar y
fusionar desde GitHub. El workflow publica solo cuando las pruebas de esa misma
ejecución pasan; una conexión o carga fallida deja el job en rojo con el error
de publicación.

## Archivos y preservación del servidor

`tools/deploy_ftp.py` toma los archivos versionados con Git y publica la app,
incluidos `index.html`, JavaScript, el manifiesto, el service worker y la API.
No publica pruebas, documentación, archivos SQL de instalación, configuración
de ejemplo ni herramientas locales. Conserva `Gestor de Tareas.dc.html`, que es
una copia divergente del servidor y no la página servida; también conserva
`api/config.php`, `api/mcp-local.php` y otros archivos locales ignorados.

La primera publicación no borra archivos que ya existan en el hosting. Después,
un manifiesto oculto del despliegue permite retirar solo archivos que esta
automatización publicó anteriormente y que luego se quitaron de Git. Los demás
archivos y carpetas del servidor se conservan.

El job no ejecuta migraciones SQL. Los cambios de esquema que no se creen con
las migraciones idempotentes actuales de la API necesitan un paso de migración
separado y revisado.
