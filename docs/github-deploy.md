# Publicar Kaizen desde GitHub

Este repositorio ya tiene configurado un webhook de GitHub llamado `web` para
eventos `push`. El webhook apunta al hosting `vedamci.com.mx` y se encarga de la
publicación del sitio. No se requieren secretos FTP nuevos en GitHub Actions.

El archivo que el publicador copia como página de inicio es
`Gestor de Tareas.dc.html`. `index.html` es su espejo para la vista previa del
repositorio. Los dos deben quedar idénticos: el workflow lo verifica y falla si
un cambio actualiza solo uno.

## Flujo normal

1. Hacer los cambios en una rama de trabajo.
2. Abrir un pull request hacia `main` y esperar que el job `test` quede en verde.
3. Fusionar el pull request. El evento `push` de `main` activa el webhook.
4. Esperar a que las pruebas de GitHub Actions terminen correctamente. La
   verificación también confirma que se modificó el archivo que publica el
   hosting.
5. Revisar **Settings → Webhooks → web → Recent Deliveries**. Una respuesta
   `2xx` (actualmente `204`) confirma que el hosting aceptó el evento.
6. Abrir el sitio y actualizarlo para confirmar que los cambios ya se ven.

El job de GitHub Actions comprueba el contenido y ejecuta pruebas; la
publicación se realiza en el servidor al recibir el evento. Que las pruebas
pasen o que el webhook responda `204` no confirma por sí solo que la página ya
se actualizó: hay que abrir el sitio y verificar el cambio.

## Workflow de pruebas

`.github/workflows/test.yml` ejecuta las pruebas al abrir o actualizar un pull
request, en ramas `feature/**` y al recibir cambios en `main`. Las ramas de
trabajo no publican el sitio: los cambios se publican al fusionarlos en `main`.
