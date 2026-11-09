# Pomodoro: ciclos, enfoque diario y avisos

Kaizen es un sitio HTTPS con manifest display: standalone y service worker. Puede abrirse en una pestaña o instalarse como PWA. El panel de avisos detecta el modo de la ventana actual; el repositorio no demuestra qué aplicación está instalada en una Mac concreta.

- Los ciclos continúan con los valores por defecto de 25 minutos de enfoque y 5 de descanso. Pausar conserva la fase; Detener vuelve al enfoque inicial sin borrar el trabajo.
- Se registra el tiempo transcurrido mediante un checkpoint persistido, se excluyen descansos y pausas, y se separan días y horas locales. Cada tarea conserva focusByDay[fecha][usuario:espacio]; el encabezado usa únicamente estos registros, sin atribuir totales históricos por conjetura.
- La precisión local es de segundos, con remanentes de milisegundos por tarea, usuario y día. El checkpoint y los remanentes se conservan en el almacenamiento existente. Las revisiones y la sincronización de tareas mantienen sus comprobaciones previas.
- Iniciar o Enfocar prepara un AudioContext durante la acción de la persona. Al terminar se emiten seis pulsos durante 5,4 segundos y queda un aviso dentro de Kaizen hasta pulsar Entendido.
- Solo Activar avisos puede llamar a Notification.requestPermission. Probar aviso y sonido funciona sin solicitar ese permiso. Con permiso concedido se usa showNotification del service worker; si el sonido web ya se reprodujo, la notificación se pide silenciosa para no superponer dos sonidos.
- Los trabajos push existentes se siguen programando para las siguientes transiciones y se cancelan al pausar/detener. No se recrean trabajos que hayan quedado obsoletos mientras se esperaba una respuesta.

## Límites verificables

El sonido personalizado y el avance en tiempo real requieren que Kaizen pueda ejecutarse. Al volver de una suspensión se reconcilian los ciclos vencidos sin duplicar tiempo y se muestra únicamente el último cambio de fase. Mantener la app cerrada impide reprogramar indefinidamente nuevos trabajos desde JavaScript; el push ya programado depende de la conexión, los permisos y el proceso de entrega del hosting.

Un service worker no puede reproducir un sonido Web Audio personalizado de 5,4 segundos. Con la app cerrada, macOS controla el sonido, la entrega, Concentración, volumen y opciones de Notificaciones. No se cambian estos ajustes automáticamente ni se asegura un aviso durante el reposo del equipo.

Las pruebas usan Node, Chromium y WebKit en Linux con datos ficticios. Miden la señal Web Audio renderizada durante más de cinco segundos y comprueban una notificación persistente mediante el service worker con permiso preconcedido en Chromium. No prueban el altavoz físico, la instalación PWA, los ajustes o una notificación del sistema en la Mac del usuario, ni validan el cron privado de producción.

Referencias:
- https://developer.chrome.com/blog/autoplay#web-audio
- https://developer.mozilla.org/en-US/docs/Web/API/AudioContext/resume
- https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerRegistration/showNotification
- https://webkit.org/blog/14445/webkit-features-in-safari-17-0/#web-apps-on-mac
