# Publicar Kaizen desde GitHub

Este repositorio ya tiene configurado un webhook de GitHub llamado `web` para
eventos `push`. El webhook apunta al hosting `vedamci.com.mx` y se encarga de la
publicación del sitio. No se requieren secretos FTP nuevos en GitHub Actions.

## Flujo normal

1. Hacer los cambios en una rama de trabajo.
2. Abrir un pull request hacia `main` y esperar que el job `test` quede en verde.
3. Fusionar el pull request. El evento `push` de `main` activa el webhook.
4. Revisar **Settings → Webhooks → web → Recent Deliveries**. Una respuesta
   `2xx` (actualmente `204`) confirma que el hosting aceptó el evento.
5. Abrir el sitio y actualizarlo para confirmar que los cambios ya se ven.

El job de GitHub Actions solo ejecuta pruebas; la publicación se realiza en el
servidor al recibir el evento. Por eso, que las pruebas pasen no confirma por sí
solo que el sitio ya se actualizó. La entrega del webhook y la página publicada
son las comprobaciones de despliegue.

## Workflow de pruebas

`.github/workflows/test.yml` ejecuta las pruebas al abrir o actualizar un pull
request, en ramas `feature/**` y al recibir cambios en `main`. Las ramas de
trabajo no publican el sitio: los cambios se publican al fusionarlos en `main`.
