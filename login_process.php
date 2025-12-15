<?php
require_once __DIR__ . '/config/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$login_password = isset($_POST['password']) ? $_POST['password'] : '';

if ($email === '' || $login_password === '') {
    echo "Email y contraseña son requeridos.";
    exit;
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role_id, lab_id FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "Usuario no encontrado o inactivo.";
        exit;
    }

    if (!password_verify($login_password, $user['password_hash'])) {
        echo "Contraseña incorrecta.";
        exit;
    }

    // Autenticación exitosa
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['lab_id'] = $user['lab_id'];

    header('Location: dashboard.php');
    exit;

} catch (PDOException $e) {
    echo "Error en la conexión: " . htmlspecialchars($e->getMessage());
    exit;
}

// EOF
