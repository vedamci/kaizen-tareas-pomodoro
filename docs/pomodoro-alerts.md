# Pomodoro: ciclos, enfoque diario y avisos

Kaizen es un sitio HTTPS con manifest display: standalone y service worker. Puede abrirse en una pestaña o instalarse como PWA. El panel de avisos detecta el modo de la ventana actual; el repositorio no demuestra qué aplicación está instalada en una Mac concreta.

- Los ciclos continúan con los valores por defecto de 25 minutos de enfoque y 5 de descanso. Pausar conserva la fase; Detener vuelve al enfoque inicial sin borrar el trabajo.
- Se registra el tiempo transcurrido mediante un checkpoint persistido, se excluyen descansos y pausas, y se separan días y horas locales. Cada tarea conserva focusByDay[fecha][usuario:espacio]; el encabezado usa únicamente estos registros, sin atribuir totales históricos por conjetura.
- La precisión local es de segundos, con remanentes de milisegundos por tarea, usuario y día. El checkpoint y los remanentes se conservan en el almacenamiento existente. Las revisiones y la sincronización de tareas mantienen sus comprobaciones previas.
- Iniciar o Enfocar prepara un AudioContext durante la acción de la persona. Las campanas de fin de enfoque y descanso se programan por adelantado en el reloj de audio, sin esperar al siguiente setInterval. Al terminar suenan cuatro campanas suaves durante 5,4 segundos, con parciales inarmónicos y una caída gradual de volumen, y queda un aviso dentro de Kaizen hasta pulsar Entendido. Pausar, detener, saltar de fase o cambiar de tarea cancela los avisos futuros; una transición no duplica las campanas ya programadas.
- Solo Activar avisos puede llamar a Notification.requestPermission. Probar aviso y sonido funciona sin solicitar ese permiso. Con permiso concedido se usa showNotification del service worker; la notificación se pide silenciosa solo si Kaizen está visible y tiene foco y ya se reprodujo el sonido web. En segundo plano se deja habilitado también el sonido del sistema, sujeto a las preferencias del equipo.
- Más incluye Probar campanas en 10 segundos: prepara el audio mediante una acción explícita y permite cambiar a otra ventana de Chrome antes del aviso, sin iniciar un Pomodoro ni solicitar permisos de notificación.
- Los trabajos push existentes se siguen programando para las siguientes transiciones y se cancelan al pausar/detener. No se recrean trabajos que hayan quedado obsoletos mientras se esperaba una respuesta.

## Límites verificables

El sonido personalizado y el avance en tiempo real requieren que Kaizen pueda ejecutarse. Al volver de una suspensión se reconcilian los ciclos vencidos sin duplicar tiempo y se muestra únicamente el último cambio de fase. Mantener la app cerrada impide reprogramar indefinidamente nuevos trabajos desde JavaScript; el push ya programado depende de la conexión, los permisos y el proceso de entrega del hosting.

Un service worker no puede reproducir un sonido Web Audio personalizado de 5,4 segundos. Con la app cerrada, macOS controla el sonido, la entrega, Concentración, volumen y opciones de Notificaciones. No se cambian estos ajustes automáticamente ni se asegura un aviso durante el reposo del equipo.

Las pruebas usan Node, Chromium y WebKit en Linux con datos ficticios. Miden la señal Web Audio renderizada durante más de cinco segundos, la presencia del fundamental y un parcial de campana, y la caída natural del sonido y comprueban una notificación persistente mediante el service worker con permiso preconcedido en Chromium. La prueba adicional tests/pomodoro-background.cjs abre Chrome con interfaz real en Linux, sin las opciones de Playwright que desactivan restricciones de segundo plano ni su emulación de foco, y captura la salida real del proceso mediante PulseAudio. Durante más de siete minutos mantiene una pestaña inactiva o una ventana minimizada y otra ventana de Chrome al frente. La comparación del 9 de noviembre de 2026 registró pausas JavaScript de 60 segundos: el código anterior avisó 30,019 segundos tarde; el corregido, con ventana minimizada, produjo audio 0,594 segundos después del vencimiento y durante 5,38 segundos. El JSON y las capturas WAV están en el artefacto de la ejecución 42667589812.

No prueban el altavoz físico, la instalación PWA, los ajustes o una notificación del sistema en la Mac del usuario, ni validan el cron privado de producción.

Referencias:
- https://developer.chrome.com/blog/timer-throttling-in-chrome-88/
- https://developer.mozilla.org/en-US/docs/Web/API/AudioScheduledSourceNode/start
- https://developer.chrome.com/blog/autoplay#web-audio
- https://developer.mozilla.org/en-US/docs/Web/API/AudioContext/resume
- https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerRegistration/showNotification
- https://webkit.org/blog/14445/webkit-features-in-safari-17-0/#web-apps-on-mac
