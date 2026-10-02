<?php
// database.php already required by public/index.php

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;
$id = $_GET['id'] ?? null;

if ($id && !is_numeric($id)) {
    $id = null;
}

function responderError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['error' => $mensaje]);
    exit;
}

// Stock per warehouse, grouped by product id.
function ubicacionesPorProducto(PDO $pdo, ?int $productoId = null): array
{
    $sql = "SELECT pa.producto_id, pa.almacen_id, a.nombre, pa.cantidad
            FROM producto_almacen pa JOIN almacenes a ON a.id = pa.almacen_id";
    $params = [];
    if ($productoId !== null) {
        $sql .= " WHERE pa.producto_id = ?";
        $params[] = $productoId;
    }
    $stmt = $pdo->prepare($sql . " ORDER BY a.id");
    $stmt->execute($params);

    $grupos = [];
    foreach ($stmt->fetchAll() as $fila) {
        $grupos[$fila['producto_id']][] = [
            'almacen_id' => $fila['almacen_id'],
            'nombre' => $fila['nombre'],
            'cantidad' => $fila['cantidad'],
        ];
    }
    return $grupos;
}

// Returns [almacen_id => cantidad] with only positive amounts, or null when the request sends no distribution.
function leerUbicaciones(array $data): ?array
{
    if (!array_key_exists('ubicaciones', $data)) {
        return null;
    }
    if (!is_array($data['ubicaciones'])) {
        responderError(400, 'Las cantidades por almacén no son válidas.');
    }

    $ubicaciones = [];
    foreach ($data['ubicaciones'] as $u) {
        $almacen = filter_var($u['almacen_id'] ?? null, FILTER_VALIDATE_INT);
        $cantidad = filter_var($u['cantidad'] ?? null, FILTER_VALIDATE_INT);
        if ($almacen === false || $cantidad === false || $cantidad < 0) {
            responderError(400, 'Las cantidades por almacén deben ser números enteros de 0 o más.');
        }
        if ($cantidad > 0) {
            $ubicaciones[$almacen] = ($ubicaciones[$almacen] ?? 0) + $cantidad;
        }
    }
    return $ubicaciones;
}

function guardarUbicaciones(PDO $pdo, int $productoId, array $ubicaciones): void
{
    $pdo->prepare("DELETE FROM producto_almacen WHERE producto_id = ?")->execute([$productoId]);
    $stmt = $pdo->prepare("INSERT INTO producto_almacen (producto_id, almacen_id, cantidad) VALUES (?, ?, ?)");
    foreach ($ubicaciones as $almacen => $cantidad) {
        $stmt->execute([$productoId, $almacen, $cantidad]);
    }
}

// CATEGORÍAS
if ($resource === 'categorias') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM categorias WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch());
        } else {
            $stmt = $pdo->query("SELECT * FROM categorias ORDER BY nombre");
            echo json_encode($stmt->fetchAll());
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['nombre']) || empty($data['nombre'])) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre es requerido']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO categorias (nombre, descripcion, icono, color_hex) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['nombre'],
            $data['descripcion'] ?? null,
            $data['icono'] ?? null,
            $data['color_hex'] ?? null
        ]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'mensaje' => 'Categoría creada']);
    } elseif ($method === 'PUT' && $id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE categorias SET nombre=?, descripcion=?, icono=?, color_hex=? WHERE id=?");
        $stmt->execute([$data['nombre'], $data['descripcion'] ?? null, $data['icono'] ?? null, $data['color_hex'] ?? null, $id]);
        echo json_encode(['mensaje' => 'Categoría actualizada']);
    } elseif ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['mensaje' => 'Categoría eliminada']);
    }
}

// PRODUCTOS
elseif ($resource === 'productos') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM productos WHERE id=?");
            $stmt->execute([$id]);
            $producto = $stmt->fetch();
            if ($producto) {
                $producto['ubicaciones'] = ubicacionesPorProducto($pdo, (int) $id)[$id] ?? [];
            }
            echo json_encode($producto);
        } else {
            $stmt = $pdo->query("SELECT p.*, c.nombre as categoria, c.icono as categoria_icono, c.color_hex as categoria_color FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.id ORDER BY p.nombre");
            $productos = $stmt->fetchAll();
            $ubicaciones = ubicacionesPorProducto($pdo);
            foreach ($productos as &$producto) {
                $producto['ubicaciones'] = $ubicaciones[$producto['id']] ?? [];
            }
            unset($producto);
            echo json_encode($productos);
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['nombre']) || empty($data['nombre'])) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre es requerido']);
            exit;
        }
        if (!isset($data['sku']) || empty($data['sku'])) {
            http_response_code(400);
            echo json_encode(['error' => 'El SKU es requerido']);
            exit;
        }

        $ubicaciones = leerUbicaciones($data);
        $cantidad = $ubicaciones === null ? ($data['cantidad'] ?? 0) : array_sum($ubicaciones);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO productos (nombre, descripcion, sku, categoria_id, cantidad, precio_unitario, precio_compra, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nombre'],
            $data['descripcion'] ?? null,
            $data['sku'],
            $data['categoria_id'],
            $cantidad,
            $data['precio_unitario'] ?? null,
            $data['precio_compra'] ?? null,
            $data['estado'] ?? 'activo'
        ]);
        $nuevoId = (int) $pdo->lastInsertId();
        if ($ubicaciones !== null) {
            guardarUbicaciones($pdo, $nuevoId, $ubicaciones);
        }
        $pdo->commit();
        echo json_encode(['id' => $nuevoId, 'mensaje' => 'Producto creado']);
    } elseif ($method === 'PUT' && $id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $ubicaciones = leerUbicaciones($data);
        $cantidad = $ubicaciones === null ? ($data['cantidad'] ?? 0) : array_sum($ubicaciones);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE productos
            SET nombre=?, descripcion=?, sku=?, categoria_id=?, cantidad=?, precio_unitario=?, precio_compra=?, estado=?
            WHERE id=?
        ");
        $stmt->execute([
            $data['nombre'],
            $data['descripcion'] ?? null,
            $data['sku'],
            $data['categoria_id'],
            $cantidad,
            $data['precio_unitario'] ?? null,
            $data['precio_compra'] ?? null,
            $data['estado'] ?? 'activo',
            $id
        ]);
        if ($ubicaciones !== null) {
            guardarUbicaciones($pdo, (int) $id, $ubicaciones);
        }
        $pdo->commit();
        echo json_encode(['mensaje' => 'Producto actualizado']);
    } elseif ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare("DELETE FROM productos WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['mensaje' => 'Producto eliminado']);
    }
} elseif ($resource === 'almacenes') {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT id, nombre, capacidad FROM almacenes ORDER BY id");
        echo json_encode($stmt->fetchAll());
    } elseif ($method === 'PUT') {
        // The form sends the whole list. Rows keep their id so stock stays linked to its warehouse.
        $data = json_decode(file_get_contents('php://input'), true);
        $lista = $data['almacenes'] ?? null;

        if (!is_array($lista) || count($lista) === 0) {
            responderError(400, 'Agrega al menos un almacén.');
        }

        $filas = [];
        foreach ($lista as $i => $almacen) {
            $almacenId = filter_var($almacen['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $nombre = trim((string) ($almacen['nombre'] ?? ''));
            $capacidad = filter_var($almacen['capacidad'] ?? null, FILTER_VALIDATE_INT);
            if ($capacidad === false || $capacidad < 1) {
                responderError(400, 'Cada almacén debe tener al menos 1 caja de capacidad.');
            }
            $filas[] = [$almacenId ?: null, $nombre !== '' ? $nombre : 'Almacén ' . ($i + 1), $capacidad];
        }

        $pdo->beginTransaction();
        $actualizar = $pdo->prepare("UPDATE almacenes SET nombre = ?, capacidad = ? WHERE id = ?");
        $insertar = $pdo->prepare("INSERT INTO almacenes (nombre, capacidad) VALUES (?, ?)");
        $conservar = [];
        foreach ($filas as [$almacenId, $nombre, $capacidad]) {
            if ($almacenId) {
                $actualizar->execute([$nombre, $capacidad, $almacenId]);
                $conservar[] = $almacenId;
            } else {
                $insertar->execute([$nombre, $capacidad]);
                $conservar[] = (int) $pdo->lastInsertId();
            }
        }
        // Removing a warehouse that still holds stock fails on the foreign key (error 1451).
        $marcadores = implode(',', array_fill(0, count($conservar), '?'));
        $pdo->prepare("DELETE FROM almacenes WHERE id NOT IN ($marcadores)")->execute($conservar);
        $pdo->commit();
        echo json_encode(['mensaje' => 'Almacenes guardados']);
    }
} elseif ($resource === 'movimientos') {
    if ($method === 'POST') {
        // Moves stock between warehouses; product totals do not change. All or nothing.
        $data = json_decode(file_get_contents('php://input'), true);
        $lista = $data['movimientos'] ?? null;
        if (!is_array($lista) || count($lista) === 0) {
            responderError(400, 'No hay movimientos para aplicar.');
        }

        $pdo->beginTransaction();
        $leer = $pdo->prepare("SELECT cantidad FROM producto_almacen WHERE producto_id = ? AND almacen_id = ? FOR UPDATE");
        $restar = $pdo->prepare("UPDATE producto_almacen SET cantidad = cantidad - ? WHERE producto_id = ? AND almacen_id = ?");
        $borrar = $pdo->prepare("DELETE FROM producto_almacen WHERE producto_id = ? AND almacen_id = ?");
        $sumar = $pdo->prepare("
            INSERT INTO producto_almacen (producto_id, almacen_id, cantidad) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE cantidad = cantidad + ?
        ");

        foreach ($lista as $m) {
            $producto = filter_var($m['producto_id'] ?? null, FILTER_VALIDATE_INT);
            $desde = filter_var($m['desde_id'] ?? null, FILTER_VALIDATE_INT);
            $hacia = filter_var($m['hacia_id'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($m['cantidad'] ?? null, FILTER_VALIDATE_INT);
            if (!$producto || !$desde || !$hacia || $desde === $hacia || !$cantidad || $cantidad < 1) {
                $pdo->rollBack();
                responderError(400, 'Hay un movimiento no válido.');
            }

            $leer->execute([$producto, $desde]);
            $actual = (int) $leer->fetchColumn();
            if ($actual < $cantidad) {
                $pdo->rollBack();
                responderError(409, 'El stock cambió desde que se calculó la sugerencia. Recarga la página e inténtalo de nuevo.');
            }
            // Rows with 0 are deleted, since the table only allows positive amounts.
            if ($actual === $cantidad) {
                $borrar->execute([$producto, $desde]);
            } else {
                $restar->execute([$cantidad, $producto, $desde]);
            }
            $sumar->execute([$producto, $hacia, $cantidad, $cantidad]);
        }
        $pdo->commit();
        echo json_encode(['mensaje' => 'Stock redistribuido']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
}
