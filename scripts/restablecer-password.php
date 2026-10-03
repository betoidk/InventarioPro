<?php
// Server-side password reset for when nobody can sign in (e.g. the only admin forgot it).
// Normal users don't need this: an admin sets a new password from Usuarios > Editar.
// Usage: php scripts/restablecer-password.php <usuario>
if (PHP_SAPI !== 'cli') {
    // Laragon serves the project root, so refuse anything that isn't a terminal.
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/database.php';

$usuario = $argv[1] ?? '';
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
$stmt->execute([$usuario]);
$id = $stmt->fetchColumn();
if (!$id) {
    fwrite(STDERR, "Uso: php scripts/restablecer-password.php <usuario>\nUsuarios:\n");
    foreach ($pdo->query("SELECT usuario, rol FROM usuarios ORDER BY rol, usuario") as $u) {
        fwrite(STDERR, "  {$u['usuario']} ({$u['rol']})\n");
    }
    exit(1);
}

// ponytail: the password is visible while typing; hide it if this ever runs on a shared screen.
echo "Nueva contraseña (mínimo 8 caracteres): ";
$password = rtrim((string) fgets(STDIN), "\r\n");
echo "Repítela: ";
if (rtrim((string) fgets(STDIN), "\r\n") !== $password) {
    fwrite(STDERR, "Las contraseñas no coinciden. No se cambió nada.\n");
    exit(1);
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres. No se cambió nada.\n");
    exit(1);
}

// Also clears the lockout, in case the forgotten password triggered it.
$pdo->prepare("UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?")
    ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
echo "Listo: {$usuario} ya puede entrar con la nueva contraseña.\n";
