<?php
require_once __DIR__ . '/config/db.php';

/**
 * setup_admin.php
 * Inserta un usuario Super Admin inicial si la tabla `users` está vacía.
 */
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo "Error de conexión: " . htmlspecialchars($e->getMessage());
    exit;
}

// ¿Hay algún usuario?
$stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM users');
$count = (int) $stmt->fetchColumn();

if ($count > 0) {
    echo "Ya existe al menos un usuario en la tabla `users`. No se creó ningún usuario nuevo.";
    exit;
}

// Obtener role_id para 'super_admin'
$stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
$stmt->execute([':name' => 'super_admin']);
$role = $stmt->fetch();

if (!$role) {
    // crear rol si no existe
    $pdo->prepare('INSERT INTO roles (name, description) VALUES (:name, :desc)')
        ->execute([':name' => 'super_admin', ':desc' => 'Control total del sistema']);
    $role_id = (int)$pdo->lastInsertId();
} else {
    $role_id = (int)$role['id'];
}

// Insertar usuario Super Admin
$email = 'admin@control.com';
$plainPassword = 'admin123';
$passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
$fullName = 'Super Admin';

$insert = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role_id, is_active) VALUES (:email, :ph, :fn, :rid, 1)');
$insert->execute([
    ':email' => $email,
    ':ph' => $passwordHash,
    ':fn' => $fullName,
    ':rid' => $role_id
]);

echo "Usuario Super Admin creado con email: " . htmlspecialchars($email) . " (contraseña: admin123).\n";
echo "Por seguridad, cambia la contraseña después del primer acceso.";

// EOF
