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

// Kárdex: every stock change is one row. cantidad is signed (+ enters, - leaves); a traslado stores the
// amount moved from almacen_id to almacen_destino_id. The product's name and SKU are copied so the record
// survives if the product is deleted later (CFF art. 30: records are kept five years).
function registrarMovimiento(PDO $pdo, array $usuario, string $tipo, int $productoId, ?int $almacenId, int $cantidad, array $extra = []): void
{
    // The CFDI each operation should have: ingreso for purchases and sales, egreso for returns.
    $cfdi = ['compra' => 'I', 'venta' => 'I', 'devolucion_cliente' => 'E', 'devolucion_proveedor' => 'E'][$tipo] ?? null;
    $pdo->prepare("
        INSERT INTO movimientos_inventario
            (tipo, producto_id, producto_nombre, producto_sku, almacen_id, almacen_destino_id, cantidad,
             costo_unitario, precio_unitario, cfdi_tipo, cfdi_uuid, rfc, nota, usuario_id, usuario)
        SELECT ?, id, nombre, sku, ?, ?, ?, COALESCE(?, costo_promedio), ?, ?, ?, ?, ?, ?, ?
        FROM productos WHERE id = ?
    ")->execute([
        $tipo, $almacenId, $extra['destino'] ?? null, $cantidad, $extra['costo'] ?? null, $extra['precio'] ?? null,
        $cfdi, $extra['uuid'] ?? null, $extra['rfc'] ?? null, $extra['nota'] ?? null,
        $usuario['id'], $usuario['usuario'], $productoId,
    ]);
}

// Adds delta to one warehouse (never below 0) and refreshes the product total. Caller holds the transaction.
function cambiarExistencia(PDO $pdo, int $productoId, int $almacenId, int $delta): void
{
    $stmt = $pdo->prepare("SELECT cantidad FROM producto_almacen WHERE producto_id = ? AND almacen_id = ? FOR UPDATE");
    $stmt->execute([$productoId, $almacenId]);
    $fila = $stmt->fetchColumn();
    $actual = (int) $fila;
    if ($actual + $delta < 0) {
        responderError(409, "No hay suficiente existencia en ese almacén: hay $actual.", 'cantidad');
    }
    // Not INSERT ... ON DUPLICATE KEY: MySQL checks the CHECK (cantidad >= 0) on the inserted values first,
    // so a negative delta would fail even when the row exists.
    if ($fila === false) {
        $pdo->prepare("INSERT INTO producto_almacen (producto_id, almacen_id, cantidad) VALUES (?, ?, ?)")
            ->execute([$productoId, $almacenId, $delta]);
    } else {
        $pdo->prepare("UPDATE producto_almacen SET cantidad = cantidad + ? WHERE producto_id = ? AND almacen_id = ?")
            ->execute([$delta, $productoId, $almacenId]);
    }
    $pdo->prepare("
        UPDATE productos SET cantidad = (SELECT COALESCE(SUM(cantidad), 0) FROM producto_almacen WHERE producto_id = ?)
        WHERE id = ?
    ")->execute([$productoId, $productoId]);
}

// Optional CFDI data. Returns [uuid, rfc], each null when empty.
function leerCfdi(array $data): array
{
    $uuid = strtoupper(trim((string) ($data['cfdi_uuid'] ?? '')));
    if ($uuid !== '' && !preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid)) {
        responderError(400, 'El folio fiscal (UUID) tiene 36 caracteres con este formato: XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX (números y letras de la A a la F).', 'cfdi_uuid');
    }
    $rfc = mb_strtoupper(trim((string) ($data['rfc'] ?? '')));
    if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', $rfc)) {
        responderError(400, 'El RFC tiene 12 caracteres si es empresa o 13 si es persona física.', 'rfc');
    }
    return [$uuid ?: null, $rfc ?: null];
}

// Writes only the warehouses received (already permission-checked); other warehouses' rows are never touched.
// A row that reaches 0 stays, so the product keeps showing, and alerting as low stock, in that warehouse.
// Each change becomes a kárdex row of $tipo ('inicial' when the product is created, 'ajuste' when edited).
function guardarUbicaciones(PDO $pdo, int $productoId, array $ubicaciones, bool $anclarSiVacio, array $usuario, string $tipo): void
{
    $stmt = $pdo->prepare("SELECT almacen_id, cantidad FROM producto_almacen WHERE producto_id = ?");
    $stmt->execute([$productoId]);
    $existentes = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $guardar = $pdo->prepare("
        INSERT INTO producto_almacen (producto_id, almacen_id, cantidad) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE cantidad = ?
    ");
    $sinFila = true;
    foreach ($ubicaciones as $almacen => $cantidad) {
        if ($cantidad > 0 || isset($existentes[$almacen])) {
            $guardar->execute([$productoId, $almacen, $cantidad, $cantidad]);
            $sinFila = false;
            $delta = $cantidad - ($existentes[$almacen] ?? 0);
            if ($delta !== 0) {
                registrarMovimiento($pdo, $usuario, $tipo, $productoId, $almacen, $delta,
                    ['nota' => $tipo === 'ajuste' ? 'Cantidad editada en el producto' : null]);
            }
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

// Readable snapshots for the audit log, so an edit can be logged as "field: before → after".
function fotoProducto(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("
        SELECT p.nombre, p.sku, c.nombre AS categoria, p.precio_compra, p.precio_unitario, p.estado, p.cantidad
        FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $foto = $stmt->fetch() ?: [];
    foreach (ubicacionesPorProducto($pdo, $id)[$id] ?? [] as $u) {
        $foto['stock en ' . $u['nombre']] = $u['cantidad'];
    }
    return $foto;
}

function fotoCategoria(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT nombre, descripcion, icono, color_hex FROM categorias WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function fotoAlmacenes(PDO $pdo): array
{
    $foto = [];
    foreach ($pdo->query("SELECT nombre, capacidad FROM almacenes ORDER BY id") as $a) {
        $foto[$a['nombre']] = $a['capacidad'] . ' cajas';
    }
    return $foto;
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

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO categorias (nombre, descripcion, icono, color_hex) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['nombre'],
            $data['descripcion'] ?? null,
            $data['icono'] ?? null,
            $data['color_hex'] ?? null
        ]);
        $nuevoId = (int) $pdo->lastInsertId();
        registrar($pdo, $actual, 'categorias', "Creó la categoría «{$data['nombre']}»");
        $pdo->commit();
        echo json_encode(['id' => $nuevoId, 'mensaje' => 'Categoría creada']);
    } elseif ($method === 'PUT' && $id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $pdo->beginTransaction();
        $antes = fotoCategoria($pdo, (int) $id);
        $stmt = $pdo->prepare("UPDATE categorias SET nombre=?, descripcion=?, icono=?, color_hex=? WHERE id=?");
        $stmt->execute([$data['nombre'], $data['descripcion'] ?? null, $data['icono'] ?? null, $data['color_hex'] ?? null, $id]);
        $despues = fotoCategoria($pdo, (int) $id);
        registrar($pdo, $actual, 'categorias', "Editó la categoría «{$despues['nombre']}»" . describirCambios($antes, $despues));
        $pdo->commit();
        echo json_encode(['mensaje' => 'Categoría actualizada']);
    } elseif ($method === 'DELETE' && $id) {
        $pdo->beginTransaction();
        $antes = fotoCategoria($pdo, (int) $id);
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id=?");
        $stmt->execute([$id]);
        if ($antes) {
            registrar($pdo, $actual, 'categorias', "Borró la categoría «{$antes['nombre']}»");
        }
        $pdo->commit();
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
            INSERT INTO productos (nombre, descripcion, sku, categoria_id, cantidad, precio_unitario, precio_compra, estado, costo_promedio)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            // Until the first purchase, the stock is valued at the purchase price typed in.
            (float) ($data['precio_compra'] ?? 0),
        ]);
        $nuevoId = (int) $pdo->lastInsertId();
        if ($ubicaciones !== null) {
            guardarUbicaciones($pdo, $nuevoId, $ubicaciones, !esAdmin($actual), $actual, 'inicial');
        } elseif ($cantidad > 0) {
            registrarMovimiento($pdo, $actual, 'inicial', $nuevoId, null, (int) $cantidad);
        }
        $nuevo = fotoProducto($pdo, $nuevoId);
        registrar($pdo, $actual, 'inventario', "Creó el producto «{$nuevo['nombre']}» ({$nuevo['sku']}) con {$nuevo['cantidad']} unidades");
        $pdo->commit();
        echo json_encode(['id' => $nuevoId, 'mensaje' => 'Producto creado']);
    } elseif ($method === 'PUT' && $id) {
        exigirAlmacenesAsignados($actual);
        exigirProductoVisible($pdo, (int) $id, $actual);
        $data = json_decode(file_get_contents('php://input'), true);
        $ubicaciones = leerUbicaciones($data, $actual);

        $pdo->beginTransaction();
        $antes = fotoProducto($pdo, (int) $id);
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
            guardarUbicaciones($pdo, (int) $id, $ubicaciones, !esAdmin($actual), $actual, 'ajuste');
        } elseif (esAdmin($actual)) {
            $nueva = (int) ($data['cantidad'] ?? 0);
            $pdo->prepare("UPDATE productos SET cantidad = ? WHERE id = ?")->execute([$nueva, $id]);
            if ($nueva !== (int) $antes['cantidad']) {
                registrarMovimiento($pdo, $actual, 'ajuste', (int) $id, null, $nueva - (int) $antes['cantidad'],
                    ['nota' => 'Cantidad editada en el producto']);
            }
        }
        $despues = fotoProducto($pdo, (int) $id);
        registrar($pdo, $actual, 'inventario', "Editó el producto «{$despues['nombre']}» ({$despues['sku']})" . describirCambios($antes, $despues));
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
        $pdo->beginTransaction();
        $antes = fotoProducto($pdo, (int) $id);
        $stmt = $pdo->prepare("DELETE FROM productos WHERE id=?");
        $stmt->execute([$id]);
        registrar($pdo, $actual, 'inventario', "Borró el producto «{$antes['nombre']}» ({$antes['sku']}), que tenía {$antes['cantidad']} unidades");
        $pdo->commit();
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
        $antes = fotoAlmacenes($pdo);
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
        registrar($pdo, $actual, 'almacenes', 'Cambió los almacenes' . describirCambios($antes, fotoAlmacenes($pdo)));
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

        $nombreAlmacen = $pdo->query("SELECT id, nombre FROM almacenes")->fetchAll(PDO::FETCH_KEY_PAIR);
        $leerProducto = $pdo->prepare("SELECT nombre FROM productos WHERE id = ?");
        $resumen = [];
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
            registrarMovimiento($pdo, $actual, 'traslado', $producto, $desde, $cantidad,
                ['destino' => $hacia, 'nota' => 'Redistribución entre almacenes']);
            $leerProducto->execute([$producto]);
            $resumen[] = "$cantidad de «{$leerProducto->fetchColumn()}» de {$nombreAlmacen[$desde]} a {$nombreAlmacen[$hacia]}";
        }
        registrar($pdo, $actual, 'inventario', 'Movió stock: ' . implode('; ', $resumen));
        $pdo->commit();
        echo json_encode(['mensaje' => 'Stock redistribuido']);
    }
} elseif ($resource === 'kardex') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $tiposOperacion = [
        // tipo => [sign, label]
        'compra' => [1, 'compra'],
        'venta' => [-1, 'venta'],
        'devolucion_cliente' => [1, 'devolución de cliente'],
        'devolucion_proveedor' => [-1, 'devolución a proveedor'],
    ];
    if ($method === 'GET') {
        $mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn();
        $sql = "SELECT m.id, m.fecha, m.tipo, m.producto_id, m.producto_nombre, m.producto_sku, m.almacen_id, a.nombre AS almacen,
                       m.almacen_destino_id, d.nombre AS almacen_destino, m.cantidad, m.costo_unitario, m.precio_unitario,
                       m.cfdi_tipo, m.cfdi_uuid, m.rfc, m.nota, m.usuario
                FROM movimientos_inventario m
                LEFT JOIN almacenes a ON a.id = m.almacen_id
                LEFT JOIN almacenes d ON d.id = m.almacen_destino_id
                WHERE m.fecha >= ? AND m.fecha < ? + INTERVAL 1 MONTH";
        $params = ["$mes-01", "$mes-01"];
        if (!esAdmin($actual)) {
            if (!$actual['almacenes']) {
                echo json_encode([]);
                exit;
            }
            $marcadores = implode(',', array_fill(0, count($actual['almacenes']), '?'));
            $sql .= " AND (m.almacen_id IN ($marcadores) OR m.almacen_destino_id IN ($marcadores))";
            $params = [...$params, ...$actual['almacenes'], ...$actual['almacenes']];
        }
        // ponytail: one month per page, capped at 1000 rows; add paging if a month ever exceeds that.
        $stmt = $pdo->prepare($sql . " ORDER BY m.fecha DESC, m.id DESC LIMIT 1000");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    } elseif ($method === 'POST') {
        exigirAlmacenesAsignados($actual);
        $tipo = $data['tipo'] ?? '';
        if (!isset($tiposOperacion[$tipo])) {
            responderError(400, 'Elige el tipo de movimiento.', 'tipo');
        }
        [$signo, $etiqueta] = $tiposOperacion[$tipo];
        $productoId = filter_var($data['producto_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productoId) {
            responderError(400, 'Elige un producto.', 'producto_id');
        }
        $almacenId = filter_var($data['almacen_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$almacenId || !puedeUsarAlmacen($actual, $almacenId)) {
            responderError(400, 'Elige uno de tus almacenes.', 'almacen_id');
        }
        $cantidad = filter_var($data['cantidad'] ?? null, FILTER_VALIDATE_INT);
        if (!$cantidad || $cantidad < 1) {
            responderError(400, 'La cantidad debe ser un número entero mayor que 0.', 'cantidad');
        }
        $importe = function (string $campo, bool $obligatorio) use ($data): ?float {
            $valor = $data[$campo] ?? '';
            if ($valor === '' || $valor === null) {
                if ($obligatorio) {
                    responderError(400, $campo === 'costo_unitario' ? 'Escribe el costo unitario de la compra.' : 'Escribe el precio unitario de la venta.', $campo);
                }
                return null;
            }
            $numero = filter_var($valor, FILTER_VALIDATE_FLOAT);
            if ($numero === false || $numero < 0) {
                responderError(400, 'Los importes deben ser números de 0 o más.', $campo);
            }
            return $numero;
        };
        $costo = $tipo === 'compra' ? $importe('costo_unitario', true) : null;
        $precio = $tipo === 'venta' ? $importe('precio_unitario', true) : ($tipo === 'devolucion_cliente' ? $importe('precio_unitario', false) : null);
        [$uuid, $rfc] = leerCfdi($data);
        $nota = mb_substr(trim((string) ($data['nota'] ?? '')), 0, 255) ?: null;
        exigirProductoVisible($pdo, $productoId, $actual);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT nombre, cantidad, costo_promedio FROM productos WHERE id = ? FOR UPDATE");
        $stmt->execute([$productoId]);
        $producto = $stmt->fetch();
        cambiarExistencia($pdo, $productoId, $almacenId, $signo * $cantidad);
        if ($tipo === 'compra') {
            // Weighted average cost (LISR art. 41): what was on hand plus what just came in.
            $previa = max(0, (int) $producto['cantidad']);
            $promedio = $previa > 0
                ? ($previa * (float) $producto['costo_promedio'] + $cantidad * $costo) / ($previa + $cantidad)
                : $costo;
            $pdo->prepare("UPDATE productos SET costo_promedio = ? WHERE id = ?")->execute([round($promedio, 4), $productoId]);
        }
        registrarMovimiento($pdo, $actual, $tipo, $productoId, $almacenId, $signo * $cantidad, [
            'costo' => $costo, 'precio' => $precio, 'uuid' => $uuid, 'rfc' => $rfc, 'nota' => $nota,
        ]);
        $almacenNombre = $pdo->prepare("SELECT nombre FROM almacenes WHERE id = ?");
        $almacenNombre->execute([$almacenId]);
        $detalle = "Registró una $etiqueta de $cantidad «{$producto['nombre']}» en {$almacenNombre->fetchColumn()}";
        if ($costo !== null) {
            $detalle .= ' a $' . number_format($costo, 2) . ' c/u';
        }
        if ($precio !== null) {
            $detalle .= ' a $' . number_format($precio, 2) . ' c/u';
        }
        $detalle .= $uuid ? " (CFDI $uuid)" : ' (sin CFDI)';
        registrar($pdo, $actual, 'inventario', $detalle);
        $pdo->commit();
        echo json_encode(['mensaje' => 'Movimiento registrado']);
    } elseif ($method === 'PUT' && $id) {
        // Only the invoice data can be completed later; quantities and amounts are never edited.
        $stmt = $pdo->prepare("SELECT tipo, producto_nombre, almacen_id, cfdi_uuid, rfc FROM movimientos_inventario WHERE id = ?");
        $stmt->execute([$id]);
        $movimiento = $stmt->fetch();
        if (!$movimiento || !isset($tiposOperacion[$movimiento['tipo']])
            || (!esAdmin($actual) && !puedeUsarAlmacen($actual, (int) $movimiento['almacen_id']))) {
            responderError(404, 'Movimiento no encontrado.');
        }
        [$uuid, $rfc] = leerCfdi($data);
        if (!$uuid) {
            responderError(400, 'Escribe el folio fiscal (UUID) de la factura.', 'cfdi_uuid');
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE movimientos_inventario SET cfdi_uuid = ?, rfc = ? WHERE id = ?")->execute([$uuid, $rfc, $id]);
        $antes = ['UUID' => $movimiento['cfdi_uuid'], 'RFC' => $movimiento['rfc']];
        registrar($pdo, $actual, 'inventario', "Agregó la factura a la {$tiposOperacion[$movimiento['tipo']][1]} de «{$movimiento['producto_nombre']}»"
            . describirCambios($antes, ['UUID' => $uuid, 'RFC' => $rfc]));
        $pdo->commit();
        echo json_encode(['mensaje' => 'Factura agregada']);
    }
} elseif ($resource === 'valuacion' && $method === 'GET') {
    requerirAdmin($actual);
    $mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn();
    $productos = $pdo->query("
        SELECT id, nombre, sku, cantidad, costo_promedio, ROUND(cantidad * costo_promedio, 2) AS valor
        FROM productos ORDER BY nombre
    ")->fetchAll();
    // Sales leave with a negative cantidad, so -cantidad is the units sold.
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN tipo = 'venta' THEN -cantidad * precio_unitario END), 0) AS ventas,
            COALESCE(SUM(CASE WHEN tipo = 'devolucion_cliente' THEN cantidad * COALESCE(precio_unitario, 0) END), 0) AS devoluciones,
            COALESCE(SUM(CASE WHEN tipo IN ('venta', 'devolucion_cliente') THEN -cantidad * costo_unitario END), 0) AS costo_vendido,
            COALESCE(SUM(CASE WHEN tipo = 'compra' THEN cantidad * costo_unitario END), 0) AS compras,
            COALESCE(SUM(tipo IN ('compra', 'venta', 'devolucion_cliente', 'devolucion_proveedor') AND cfdi_uuid IS NULL), 0) AS sin_cfdi
        FROM movimientos_inventario
        WHERE fecha >= ? AND fecha < ? + INTERVAL 1 MONTH
    ");
    $stmt->execute(["$mes-01", "$mes-01"]);
    $periodo = array_map('floatval', $stmt->fetch());
    echo json_encode([
        'mes' => $mes,
        'productos' => $productos,
        'valor_inventario' => round(array_sum(array_column($productos, 'valor')), 2),
        'ventas_netas' => round($periodo['ventas'] - $periodo['devoluciones'], 2),
        'costo_vendido' => round($periodo['costo_vendido'], 2),
        'utilidad_bruta' => round($periodo['ventas'] - $periodo['devoluciones'] - $periodo['costo_vendido'], 2),
        'compras' => round($periodo['compras'], 2),
        'sin_cfdi' => (int) $periodo['sin_cfdi'],
    ]);
} elseif ($resource === 'auditoria' && $method === 'GET') {
    requerirAdmin($actual);
    // ponytail: latest 500 entries, filtered in the browser; add server paging if the log outgrows that.
    $stmt = $pdo->query("SELECT id, fecha, usuario, area, detalle, ip FROM auditoria ORDER BY id DESC LIMIT 500");
    echo json_encode($stmt->fetchAll());
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
}
