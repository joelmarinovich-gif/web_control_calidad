<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

require_once __DIR__ . '/config/db.php';
// Obtener nombre para mostrar
$name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : $_SESSION['email'];

// Determinar rol para redirecciones y enlaces
$isSuper = false;
$isAdmin = false;
$roleName = null;
try {
  $pdo = getPDO();
  $r = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
  $r->execute([':id' => $_SESSION['role_id'] ?? 0]);
  $rr = $r->fetch();
  if ($rr) {
    $roleName = $rr['name'];
    if ($roleName === 'super_admin') $isSuper = true;
    if (in_array($roleName, ['super_admin','admin'])) $isAdmin = true;
    // Redirigir lab_user directamente al dashboard de usuario
    if ($roleName === 'lab_user') {
      header('Location: user_dashboard.php');
      exit;
    }
  }
} catch (Exception $e) {
  // ignore - si falla, no mostramos el enlace
}
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Control de Calidad</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-5">
      <div class="row">
        <div class="col-md-8 mx-auto">
          <div class="card">
            <div class="card-body">
              <h4 class="card-title">Bienvenido, <?php echo htmlspecialchars($name); ?></h4>
              <p class="card-text">Has ingresado al panel de control.</p>
              <?php if ($isAdmin): ?>
                <a href="admin_responses.php" class="btn btn-secondary me-2">Ver Envíos</a>
                <a href="admin_statistics.php" class="btn btn-primary me-2">Estadísticas</a>
                <a href="admin_reference_results.php" class="btn btn-info me-2">Resultados de Referencia</a>
                <a href="admin_surveys.php" class="btn btn-info me-2">Gestionar Encuestas</a>
              <?php endif; ?>
              <?php if ($isSuper): ?>
                <a href="admin_labs.php" class="btn btn-primary me-2">Administrar Laboratorios</a>
                <a href="admin_users.php" class="btn btn-success me-2">Administrar Usuarios</a>
                <a href="admin_antibiotics.php" class="btn btn-warning me-2">Gestionar Antibióticos</a>
                <a href="admin_breakpoints.php" class="btn btn-dark me-2">Gestionar Breakpoints</a>
              <?php endif; ?>
              <a href="logout.php" class="btn btn-outline-secondary">Cerrar Sesión</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>
