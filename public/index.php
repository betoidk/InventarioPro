<?php
require __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;

if ($resource) {
    try {
        require __DIR__ . '/../src/Controllers/ApiController.php';
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        $mensajes = [
            1451 => $resource === 'almacenes'
                ? 'No se puede quitar un almacén que tiene productos. Mueve primero su stock a otro almacén.'
                : 'No se puede borrar: la categoría tiene productos asociados.',
            1452 => 'La categoría o el almacén seleccionado no existe.',
            1062 => $resource === 'categorias'
                ? 'Ya existe una categoría con ese nombre.'
                : 'Ya existe un producto con ese SKU.',
            3819 => 'La cantidad y los precios no pueden ser negativos.',
        ];
        $codigo = $e->errorInfo[1] ?? null;
        http_response_code(isset($mensajes[$codigo]) ? 409 : 500);
        echo json_encode(['error' => $mensajes[$codigo] ?? 'Error de base de datos.']);
    }
} else {
    include __DIR__ . '/index.html';
}
