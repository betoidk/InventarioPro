<?php
// Configuración base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'inventario_db');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    // Details go to the server log; the browser only gets a generic message.
    error_log('Conexión BD falló: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'No se pudo conectar a la base de datos.']));
}

// No CORS headers: the API is only used by this same site, now with a session cookie.
header('Content-Type: application/json; charset=utf-8');
