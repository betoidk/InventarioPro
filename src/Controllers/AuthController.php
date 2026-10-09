<?php
// Login, first-time setup and user management. database.php and auth.php are loaded by public/index.php.

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$data = json_decode(file_get_contents('php://input'), true) ?: [];

const MAX_INTENTOS = 5;
const MINUTOS_BLOQUEO = 5;

function hayUsuarios(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT EXISTS (SELECT 1 FROM usuarios)")->fetchColumn();
}

// Replaces a user's warehouses. Admins have none: they see every warehouse.
function guardarAlmacenesUsuario(PDO $pdo, int $usuarioId, string $rol, array $almacenes): void
{
    $pdo->prepare("DELETE FROM usuario_almacen WHERE usuario_id = ?")->execute([$usuarioId]);
    if ($rol === 'admin') {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO usuario_almacen (usuario_id, almacen_id) VALUES (?, ?)");
    foreach (array_unique($almacenes) as $almacen) {
        $almacen = filter_var($almacen, FILTER_VALIDATE_INT);
        if ($almacen === false) {
            responderError(400, 'Hay un almacén no válido.');
        }
        $stmt->execute([$usuarioId, $almacen]);
    }
}

// Readable snapshot of a user for the audit log.
function fotoUsuario(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT usuario, nombre, rol, IF(activo, 'activo', 'dado de baja') AS estado FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $foto = $stmt->fetch();
    $stmt = $pdo->prepare("
        SELECT GROUP_CONCAT(a.nombre ORDER BY a.id SEPARATOR ', ')
        FROM usuario_almacen ua JOIN almacenes a ON a.id = ua.almacen_id WHERE ua.usuario_id = ?
    ");
    $stmt->execute([$id]);
    $foto['almacenes'] = $foto['rol'] === 'admin' ? 'todos' : ($stmt->fetchColumn() ?: 'ninguno');
    return $foto;
}

function leerRol(array $data): string
{
    $rol = $data['rol'] ?? 'usuario';
    if (!in_array($rol, ['admin', 'usuario'], true)) {
        responderError(400, 'El rol no es válido.', 'rol');
    }
    return $rol;
}

if ($resource === 'sesion') {
    if ($method === 'GET') {
        echo json_encode([
            'usuario' => usuarioActual($pdo),
            'requiereConfiguracion' => !hayUsuarios($pdo),
        ]);
    } elseif ($method === 'POST') {
        $usuario = trim((string) ($data['usuario'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $stmt = $pdo->prepare("
            SELECT id, password_hash, activo, intentos_fallidos, bloqueado_hasta > NOW() AS bloqueado
            FROM usuarios WHERE usuario = ?
        ");
        $stmt->execute([$usuario]);
        $fila = $stmt->fetch();

        if ($fila && $fila['bloqueado']) {
            responderError(429, 'Demasiados intentos fallidos. Espera ' . MINUTOS_BLOQUEO . ' minutos e inténtalo de nuevo.');
        }
        // Unknown users still pay for a hash, so response time does not reveal which names exist.
        $hash = $fila['password_hash'] ?? password_hash('usuario-inexistente', PASSWORD_DEFAULT);
        // Failed attempts are logged under the name typed, linked to the account when it exists.
        $intento = $fila ? ['id' => $fila['id'], 'usuario' => $usuario] : null;
        if (!password_verify($password, $hash) || !$fila) {
            $detalle = 'Intento fallido de inicio de sesión';
            if ($fila) {
                $intentos = $fila['intentos_fallidos'] + 1;
                $bloquear = $intentos >= MAX_INTENTOS;
                $pdo->prepare("
                    UPDATE usuarios SET intentos_fallidos = ?,
                        bloqueado_hasta = IF(?, NOW() + INTERVAL " . MINUTOS_BLOQUEO . " MINUTE, NULL)
                    WHERE id = ?
                ")->execute([$bloquear ? 0 : $intentos, $bloquear ? 1 : 0, $fila['id']]);
                if ($bloquear) {
                    $detalle .= '; el usuario quedó bloqueado ' . MINUTOS_BLOQUEO . ' minutos';
                }
            } else {
                $detalle .= ' (ese usuario no existe)';
            }
            registrar($pdo, $intento, 'sesion', $detalle, $usuario);
            responderError(401, 'Usuario o contraseña incorrectos.');
        }
        // Only reached with the right password, so this message reveals nothing new.
        if (!$fila['activo']) {
            registrar($pdo, $intento, 'sesion', 'Intentó entrar con un usuario dado de baja');
            responderError(403, 'Tu usuario está dado de baja. Habla con el administrador.');
        }

        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?")
            ->execute([$fila['id']]);
        if (password_needs_rehash($fila['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $fila['id']]);
        }
        iniciarSesionComo((int) $fila['id']);
        $yo = usuarioActual($pdo);
        registrar($pdo, $yo, 'sesion', 'Inició sesión');
        echo json_encode(['usuario' => $yo]);
    } elseif ($method === 'DELETE') {
        $yo = usuarioActual($pdo);
        if ($yo) {
            registrar($pdo, $yo, 'sesion', 'Cerró sesión');
        }
        cerrarSesion();
        echo json_encode(['mensaje' => 'Sesión cerrada']);
    }
} elseif ($resource === 'configuracion') {
    if ($method === 'POST') {
        // Creates the first admin. Only works while the users table is empty.
        $campos = validarDatosUsuario($data, true);
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (usuario, nombre, password_hash, rol)
            SELECT ?, ?, ?, 'admin' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM usuarios)
        ");
        $stmt->execute([$campos['usuario'], $campos['nombre'], $campos['password_hash']]);
        if ($stmt->rowCount() === 0) {
            responderError(409, 'Ya existe un administrador. Inicia sesión.');
        }
        iniciarSesionComo((int) $pdo->lastInsertId());
        $yo = usuarioActual($pdo);
        registrar($pdo, $yo, 'usuarios', 'Creó la cuenta de administrador inicial');
        echo json_encode(['usuario' => $yo]);
    }
} elseif ($resource === 'usuarios') {
    $actual = requerirSesion($pdo);
    requerirAdmin($actual);

    if ($method === 'GET') {
        $usuarios = $pdo->query("SELECT id, usuario, nombre, rol, activo FROM usuarios ORDER BY usuario")->fetchAll();
        $asignados = [];
        foreach ($pdo->query("SELECT usuario_id, almacen_id FROM usuario_almacen")->fetchAll() as $fila) {
            $asignados[$fila['usuario_id']][] = (int) $fila['almacen_id'];
        }
        foreach ($usuarios as &$u) {
            $u['activo'] = (bool) $u['activo'];
            $u['almacenes'] = $asignados[$u['id']] ?? [];
        }
        unset($u);
        echo json_encode($usuarios);
    } elseif ($method === 'POST') {
        $campos = validarDatosUsuario($data, true);
        $rol = leerRol($data);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO usuarios (usuario, nombre, password_hash, rol) VALUES (?, ?, ?, ?)")
            ->execute([$campos['usuario'], $campos['nombre'], $campos['password_hash'], $rol]);
        $nuevoId = (int) $pdo->lastInsertId();
        guardarAlmacenesUsuario($pdo, $nuevoId, $rol, $data['almacenes'] ?? []);
        $nuevo = fotoUsuario($pdo, $nuevoId);
        registrar($pdo, $actual, 'usuarios', "Creó el usuario «{$nuevo['usuario']}» (rol: {$nuevo['rol']}; almacenes: {$nuevo['almacenes']})");
        $pdo->commit();
        echo json_encode(['id' => $nuevoId, 'mensaje' => 'Usuario creado']);
    } elseif ($method === 'PUT' && $id) {
        // Partial update: only the fields sent are changed. "Baja" is activo = false.
        $stmt = $pdo->prepare("SELECT id, rol, activo FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $objetivo = $stmt->fetch();
        if (!$objetivo) {
            responderError(404, 'Usuario no encontrado.');
        }

        $campos = validarDatosUsuario($data, false);
        if (array_key_exists('rol', $data)) {
            $campos['rol'] = leerRol($data);
        }
        if (array_key_exists('activo', $data)) {
            $campos['activo'] = $data['activo'] ? 1 : 0;
        }
        // The acting admin can never lock themselves out, which also guarantees one active admin remains.
        $quitaAdmin = ($campos['rol'] ?? 'admin') !== 'admin' || ($campos['activo'] ?? 1) === 0;
        if ($id === (int) $actual['id'] && $quitaAdmin) {
            responderError(400, 'No puedes quitarte el rol de administrador ni darte de baja a ti mismo.');
        }
        if (isset($campos['password_hash'])) {
            $campos['intentos_fallidos'] = 0;
            $campos['bloqueado_hasta'] = null;
        }

        $pdo->beginTransaction();
        $antes = fotoUsuario($pdo, $id);
        if ($campos) {
            $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($campos)));
            $pdo->prepare("UPDATE usuarios SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
        }
        if (array_key_exists('almacenes', $data) || isset($campos['rol'])) {
            $almacenes = $data['almacenes'] ?? almacenesDeUsuario($pdo, $id);
            guardarAlmacenesUsuario($pdo, $id, $campos['rol'] ?? $objetivo['rol'], is_array($almacenes) ? $almacenes : []);
        }
        $despues = fotoUsuario($pdo, $id);
        // Only the fact that the password changed is logged, never the password.
        if (isset($campos['password_hash'])) {
            $antes['contraseña'] = 'anterior';
            $despues['contraseña'] = 'nueva';
        }
        registrar($pdo, $actual, 'usuarios', "Editó el usuario «{$despues['usuario']}»" . describirCambios($antes, $despues));
        $pdo->commit();
        echo json_encode(['mensaje' => 'Usuario actualizado']);
    }
}
