<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/auth.php';

// Session cookie: not readable from JavaScript, and never sent by other sites (blocks CSRF).
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;

if ($resource) {
    try {
        $controlador = in_array($resource, ['sesion', 'configuracion', 'usuarios'], true)
            ? 'AuthController.php'
            : 'ApiController.php';
        require __DIR__ . '/../src/Controllers/' . $controlador;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        $duplicados = [
            'categorias' => ['Ya existe una categoría con ese nombre.', 'nombre'],
            'usuarios' => ['Ya existe un usuario con ese nombre de usuario.', 'usuario'],
            'configuracion' => ['Ya existe un usuario con ese nombre de usuario.', 'usuario'],
        ];
        [$mensajeDuplicado, $campoDuplicado] = $duplicados[$resource] ?? ['Ya existe un producto con ese SKU.', 'sku'];
        $mensajes = [
            1451 => $resource === 'almacenes'
                ? 'No se puede quitar un almacén que tiene productos. Mueve primero su stock a otro almacén.'
                : 'No se puede borrar: la categoría tiene productos asociados.',
            1452 => 'La categoría o el almacén seleccionado no existe.',
            1062 => $mensajeDuplicado,
            3819 => 'La cantidad y los precios no pueden ser negativos.',
        ];
        $codigo = $e->errorInfo[1] ?? null;
        // Lets the form highlight the input that caused a duplicate.
        $campo = $codigo === 1062 ? $campoDuplicado : null;
        http_response_code(isset($mensajes[$codigo]) ? 409 : 500);
        echo json_encode(['error' => $mensajes[$codigo] ?? 'Error de base de datos.', 'campo' => $campo]);
    }
} else {
    include __DIR__ . '/index.html';
}
