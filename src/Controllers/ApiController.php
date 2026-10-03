<?php
// Inventory API. database.php and auth.php are loaded by public/index.php.

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;
$id = $_GET['id'] ?? null;

if ($id && !is_numeric($id)) {
    $id = null;
}

$actual = requerirSesion($pdo);

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

// A non-admin sees a product when it has a row in one of their warehouses, or no rows at all
// (not placed anywhere yet). They only see their own warehouses and a total limited to them.
function vistaParaUsuario(array $producto, array $filas, array $usuario): ?array
{
    if (esAdmin($usuario)) {
        $producto['ubicaciones'] = $filas;
        return $producto;
    }
    $propias = array_values(array_filter($filas, fn ($u) => puedeUsarAlmacen($usuario, (int) $u['almacen_id'])));
    if ($filas && !$propias) {
        return null;
    }
    $producto['ubicaciones'] = $propias;
    $producto['cantidad'] = array_sum(array_column($propias, 'cantidad'));
    return $producto;
}

// Stops with 404 when the product does not exist or the user cannot see it. Returns all its stock rows.
function exigirProductoVisible(PDO $pdo, int $productoId, array $usuario): array
{
    $stmt = $pdo->prepare("SELECT id FROM productos WHERE id = ?");
    $stmt->execute([$productoId]);
    if (!$stmt->fetch()) {
        responderError(404, 'Producto no encontrado.');
    }
    $filas = ubicacionesPorProducto($pdo, $productoId)[$productoId] ?? [];
    if (!vistaParaUsuario([], $filas, $usuario)) {
        responderError(404, 'Producto no encontrado.');
    }
    return $filas;
}

// Returns [almacen_id => cantidad], or null when the request sends no distribution.
function leerUbicaciones(array $data, array $usuario): ?array
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
        if (!puedeUsarAlmacen($usuario, $almacen)) {
            responderError(403, 'No tienes acceso a uno de esos almacenes.');
        }
        $ubicaciones[$almacen] = ($ubicaciones[$almacen] ?? 0) + $cantidad;
    }
    return $ubicaciones;
}

// Writes only the warehouses received (already permission-checked); other warehouses' rows are never touched.
// A row that reaches 0 stays, so the product keeps showing, and alerting as low stock, in that warehouse.
function guardarUbicaciones(PDO $pdo, int $productoId, array $ubicaciones, bool $anclarSiVacio): void
{
    $stmt = $pdo->prepare("SELECT almacen_id FROM producto_almacen WHERE producto_id = ?");
    $stmt->execute([$productoId]);
    $existentes = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $guardar = $pdo->prepare("
        INSERT INTO producto_almacen (producto_id, almacen_id, cantidad) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE cantidad = ?
    ");
    $sinFila = true;
    foreach ($ubicaciones as $almacen => $cantidad) {
        if ($cantidad > 0 || isset($existentes[$almacen])) {
            $guardar->execute([$productoId, $almacen, $cantidad, $cantidad]);
            $sinFila = false;
        }
    }
    // A non-admin's item with no stock yet is anchored to their first warehouse; otherwise it would be visible to everyone.
    if ($sinFila && $anclarSiVacio && $ubicaciones) {
        $guardar->execute([$productoId, array_key_first($ubicaciones), 0, 0]);
    }
    $pdo->prepare("
        UPDATE productos SET cantidad = (SELECT COALESCE(SUM(cantidad), 0) FROM producto_almacen WHERE producto_id = ?)
        WHERE id = ?
    ")->execute([$productoId, $productoId]);
}

function exigirAlmacenesAsignados(array $usuario): void
{
    if (!esAdmin($usuario) && !$usuario['almacenes']) {
        responderError(403, 'No tienes almacenes asignados. Pídele al administrador que te asigne uno.');
    }
}

// CATEGORÍAS: everyone reads them (the product form needs them), only admins change them.
if ($resource === 'categorias') {
    if ($method !== 'GET') {
        requerirAdmin($actual);
    }
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
            responderError(400, 'El nombre es requerido', 'nombre');
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
            $producto = $producto ? vistaParaUsuario($producto, ubicacionesPorProducto($pdo, (int) $id)[$id] ?? [], $actual) : null;
            if (!$producto) {
                responderError(404, 'Producto no encontrado.');
            }
            echo json_encode($producto);
        } else {
            $stmt = $pdo->query("SELECT p.*, c.nombre as categoria, c.icono as categoria_icono, c.color_hex as categoria_color FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.id ORDER BY p.nombre");
            $ubicaciones = ubicacionesPorProducto($pdo);
            $visibles = [];
            foreach ($stmt->fetchAll() as $producto) {
                $vista = vistaParaUsuario($producto, $ubicaciones[$producto['id']] ?? [], $actual);
                if ($vista) {
                    $visibles[] = $vista;
                }
            }
            echo json_encode($visibles);
        }
    } elseif ($method === 'POST') {
        exigirAlmacenesAsignados($actual);
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['nombre']) || empty($data['nombre'])) {
            responderError(400, 'El nombre es requerido', 'nombre');
        }
        if (!isset($data['sku']) || empty($data['sku'])) {
            responderError(400, 'El SKU es requerido', 'sku');
        }

        $ubicaciones = leerUbicaciones($data, $actual);
        if ($ubicaciones === null && !esAdmin($actual)) {
            $ubicaciones = array_fill_keys($actual['almacenes'], 0);
        }
        // Without warehouses (admin only) the total is typed directly; otherwise it is derived from the warehouses.
        $cantidad = $ubicaciones === null ? ($data['cantidad'] ?? 0) : 0;

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
            guardarUbicaciones($pdo, $nuevoId, $ubicaciones, !esAdmin($actual));
        }
        $pdo->commit();
        echo json_encode(['id' => $nuevoId, 'mensaje' => 'Producto creado']);
    } elseif ($method === 'PUT' && $id) {
        exigirAlmacenesAsignados($actual);
        exigirProductoVisible($pdo, (int) $id, $actual);
        $data = json_decode(file_get_contents('php://input'), true);
        $ubicaciones = leerUbicaciones($data, $actual);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE productos
            SET nombre=?, descripcion=?, sku=?, categoria_id=?, precio_unitario=?, precio_compra=?, estado=?
            WHERE id=?
        ");
        $stmt->execute([
            $data['nombre'],
            $data['descripcion'] ?? null,
            $data['sku'],
            $data['categoria_id'],
            $data['precio_unitario'] ?? null,
            $data['precio_compra'] ?? null,
            $data['estado'] ?? 'activo',
            $id
        ]);
        if ($ubicaciones !== null) {
            guardarUbicaciones($pdo, (int) $id, $ubicaciones, !esAdmin($actual));
        } elseif (esAdmin($actual)) {
            $pdo->prepare("UPDATE productos SET cantidad = ? WHERE id = ?")->execute([$data['cantidad'] ?? 0, $id]);
        }
        $pdo->commit();
        echo json_encode(['mensaje' => 'Producto actualizado']);
    } elseif ($method === 'DELETE' && $id) {
        exigirAlmacenesAsignados($actual);
        $filas = exigirProductoVisible($pdo, (int) $id, $actual);
        foreach ($filas as $fila) {
            if (!puedeUsarAlmacen($actual, (int) $fila['almacen_id'])) {
                responderError(403, 'Este producto también está en almacenes que no tienes asignados. Pídele al administrador que lo borre.');
            }
        }
        $stmt = $pdo->prepare("DELETE FROM productos WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['mensaje' => 'Producto eliminado']);
    }
} elseif ($resource === 'almacenes') {
    if ($method === 'GET') {
        if (esAdmin($actual)) {
            $stmt = $pdo->query("SELECT id, nombre, capacidad FROM almacenes ORDER BY id");
            echo json_encode($stmt->fetchAll());
        } elseif ($actual['almacenes']) {
            $marcadores = implode(',', array_fill(0, count($actual['almacenes']), '?'));
            $stmt = $pdo->prepare("SELECT id, nombre, capacidad FROM almacenes WHERE id IN ($marcadores) ORDER BY id");
            $stmt->execute($actual['almacenes']);
            echo json_encode($stmt->fetchAll());
        } else {
            echo json_encode([]);
        }
    } elseif ($method === 'PUT') {
        requerirAdmin($actual);
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
            if (!puedeUsarAlmacen($actual, $desde) || !puedeUsarAlmacen($actual, $hacia)) {
                $pdo->rollBack();
                responderError(403, 'Solo puedes mover stock entre tus almacenes.');
            }

            $leer->execute([$producto, $desde]);
            $stockActual = (int) $leer->fetchColumn();
            if ($stockActual < $cantidad) {
                $pdo->rollBack();
                responderError(409, 'El stock cambió desde que se calculó la sugerencia. Recarga la página e inténtalo de nuevo.');
            }
            // The source row is kept even at 0, so the product stays listed in that warehouse.
            $restar->execute([$cantidad, $producto, $desde]);
            $sumar->execute([$producto, $hacia, $cantidad, $cantidad]);
        }
        $pdo->commit();
        echo json_encode(['mensaje' => 'Stock redistribuido']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
}
