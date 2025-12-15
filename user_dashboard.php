<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo "Error de conexión: " . htmlspecialchars($e->getMessage());
    exit;
}

// Permitir sólo usuarios de laboratorio (no super_admin ni admin)
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || in_array($roleRow['name'], ['super_admin','admin'])) {
  header('Location: dashboard.php');
  exit;
}

$userLabId = $_SESSION['lab_id'] ?? null;

// Nombre del usuario para saludo
$displayName = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : (!empty($_SESSION['email']) ? $_SESSION['email'] : 'Usuario');

// Obtener nombre del laboratorio si existe
$labName = null;
if ($userLabId) {
  try {
    $l = $pdo->prepare('SELECT name FROM labs WHERE id = :id LIMIT 1');
    $l->execute([':id' => $userLabId]);
    $lr = $l->fetch();
    if ($lr) $labName = $lr['name'];
  } catch (Exception $e) {
    // ignore
  }
}

// Obtener encuestas activas: globales o específicas del laboratorio del usuario
$stmt = $pdo->prepare("SELECT id, title, description, scope, lab_id, created_at FROM surveys WHERE is_active = 1 AND (scope = 'global' OR (scope = 'lab' AND lab_id = :lab_id)) ORDER BY created_at DESC");
$stmt->execute([':lab_id' => $userLabId]);
$surveys = $stmt->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel - Laboratorio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_danger'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['flash_danger']); unset($_SESSION['flash_danger']); ?></div>
      <?php endif; ?>
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h4>Hola, <?php echo htmlspecialchars($displayName); ?></h4>
          <?php if ($labName): ?><div class="text-muted">Laboratorio: <?php echo htmlspecialchars($labName); ?></div><?php endif; ?>
        </div>
        <div>
          <a href="logout.php" class="btn btn-outline-secondary">Cerrar sesión</a>
        </div>
      </div>

      <?php if (empty($surveys)): ?>
        <div class="alert alert-info">No hay encuestas disponibles.</div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach ($surveys as $s): ?>
            <a href="survey_form.php?id=<?php echo (int)$s['id']; ?>" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h5 class="mb-1"><?php echo htmlspecialchars($s['title']); ?></h5>
                <small><?php echo htmlspecialchars($s['created_at']); ?></small>
              </div>
              <p class="mb-1"><?php echo htmlspecialchars($s['description']); ?></p>
              <small><?php echo $s['scope'] === 'global' ? 'Global' : 'Específica'; ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </body>
</html>
