<?php
// Copiar como config.php y completar con los datos de MySQL de cPanel.
return [
    'db_host' => 'localhost',
    'db_name' => 'cpanel_database_name',
    'db_user' => 'cpanel_database_user',
    'db_pass' => 'replace-with-a-strong-password',
    'session_name' => 'tareas_session',
    // Configurar solo después de aprobar remitente y entrega de correo.
    'password_reset_enabled' => false,
    'password_reset_url' => 'https://vedamci.com.mx/tareas-pomodoro/',
    'password_reset_from_email' => '',
    'password_reset_rate_secret' => '', // Valor aleatorio privado de al menos 32 caracteres.
];
