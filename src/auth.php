<?php
// Session and permission helpers shared by every API controller.

function responderError(int $codigo, string $mensaje, ?string $campo = null): void
{
    http_response_code($codigo);
    echo json_encode(['error' => $mensaje, 'campo' => $campo]);
    exit;
}

function almacenesDeUsuario(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare("SELECT almacen_id FROM usuario_almacen WHERE usuario_id = ? ORDER BY almacen_id");
    $stmt->execute([$usuarioId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Reloaded from the database on every request, so a deactivation or a change of
// warehouses takes effect immediately instead of when the session expires.
// 'almacenes' is null for admins, meaning "every warehouse".
function usuarioActual(PDO $pdo): ?array
{
    $id = $_SESSION['usuario_id'] ?? null;
    if (!$id) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, usuario, nombre, rol, activo FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario || !$usuario['activo']) {
        cerrarSesion();
        return null;
    }
    unset($usuario['activo']);
    $usuario['almacenes'] = $usuario['rol'] === 'admin' ? null : almacenesDeUsuario($pdo, (int) $usuario['id']);
    return $usuario;
}

function requerirSesion(PDO $pdo): array
{
    $usuario = usuarioActual($pdo);
    if (!$usuario) {
        responderError(401, 'Tu sesión terminó. Inicia sesión de nuevo.');
    }
    return $usuario;
}

function requerirAdmin(array $usuario): void
{
    if ($usuario['rol'] !== 'admin') {
        responderError(403, 'Solo un administrador puede hacer esto.');
    }
}

function esAdmin(array $usuario): bool
{
    return $usuario['almacenes'] === null;
}

function puedeUsarAlmacen(array $usuario, int $almacenId): bool
{
    return esAdmin($usuario) || in_array($almacenId, $usuario['almacenes'], true);
}

function iniciarSesionComo(int $usuarioId): void
{
    // A new id on login prevents session fixation.
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $usuarioId;
}

function cerrarSesion(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $p['path'],
        'secure' => $p['secure'],
        'httponly' => true,
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

// Validates the user fields present in $data. Returns only the ones sent.
function validarDatosUsuario(array $data, bool $passwordObligatoria): array
{
    $limpios = [];
    if (array_key_exists('usuario', $data) || $passwordObligatoria) {
        $usuario = trim((string) ($data['usuario'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usuario)) {
            responderError(400, 'El usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.', 'usuario');
        }
        $limpios['usuario'] = $usuario;
    }
    if (array_key_exists('nombre', $data) || $passwordObligatoria) {
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            responderError(400, 'Escribe el nombre (máximo 100 caracteres).', 'nombre');
        }
        $limpios['nombre'] = $nombre;
    }
    $password = (string) ($data['password'] ?? '');
    if ($password !== '' || $passwordObligatoria) {
        if (mb_strlen($password) < 8) {
            responderError(400, 'La contraseña debe tener al menos 8 caracteres.', 'password');
        }
        $limpios['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    return $limpios;
}
