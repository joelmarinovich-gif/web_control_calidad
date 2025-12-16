<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo "Error DB: " . htmlspecialchars($e->getMessage()); exit;
}

// Sólo admin/super_admin
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) {
    header('Location: dashboard.php'); exit;
}

$stmt = $pdo->prepare("SELECT r.id, r.survey_id, r.lab_id, r.user_id, r.submitted_at, l.name AS lab_name, s.title AS survey_title
  FROM responses r
  LEFT JOIN labs l ON l.id = r.lab_id
  LEFT JOIN surveys s ON s.id = r.survey_id
  ORDER BY r.submitted_at DESC");
$stmt->execute();
$rows = $stmt->fetchAll();

?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin - Respuestas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Envíos recibidos</h4>
    <div>
      <a href="dashboard.php" class="btn btn-sm btn-outline-secondary me-2">Volver</a>
      <a href="admin_reference_results.php" class="btn btn-sm btn-secondary">Cargar resultados de referencia</a>
      <a href="admin_statistics.php" class="btn btn-sm btn-primary">Estadísticas</a>
    </div>
  </div>

  <table class="table table-striped table-bordered">
    <thead><tr><th>ID</th><th>Laboratorio</th><th>Encuesta</th><th>Fecha</th><th>Acciones</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars($r['lab_name'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($r['survey_title'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($r['submitted_at']); ?></td>
          <td>
            <a class="btn btn-sm btn-outline-primary" href="admin_response_view.php?id=<?php echo (int)$r['id']; ?>">Ver Detalle</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body></html>
