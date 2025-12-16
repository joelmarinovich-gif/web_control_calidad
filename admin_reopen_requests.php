<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
try { $pdo = getPDO(); } catch (PDOException $e) { echo 'DB error'; exit; }

// role check
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) { header('Location: dashboard.php'); exit; }

// Load reopen requests from audit_logs (action = 'request_reopen')
$stmt = $pdo->prepare("SELECT al.*, u.full_name AS user_name, r.survey_id FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id LEFT JOIN responses r ON r.id = al.object_id WHERE al.action = 'request_reopen' ORDER BY al.created_at DESC");
$stmt->execute();
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Solicitudes de Reapertura</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Solicitudes de Reapertura de Envíos</h4>
    <div><a href="admin_responses.php" class="btn btn-sm btn-outline-secondary">Volver</a></div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="alert alert-info">No hay solicitudes pendientes.</div>
  <?php else: ?>
    <table class="table table-sm table-bordered">
      <thead><tr><th>Fecha</th><th>Usuario</th><th>Response ID</th><th>Survey ID</th><th>Detalle</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td><?php echo htmlspecialchars($r['user_name'] ?? 'Usuario ID '.$r['user_id']); ?></td>
            <td><?php echo (int)$r['object_id']; ?></td>
            <td><?php echo htmlspecialchars($r['survey_id'] ?? '—'); ?></td>
            <td style="max-width:420px;white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($r['detail'])); ?></td>
            <td>
              <form method="post" action="admin_handle_reopen.php" style="display:inline-block;margin-right:6px;">
                <input type="hidden" name="audit_id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="response_id" value="<?php echo (int)$r['object_id']; ?>">
                <input type="hidden" name="action" value="accept">
                <button class="btn btn-sm btn-success" onclick="return confirm('Aceptar y reabrir el envío?')">Aceptar</button>
              </form>
              <form method="post" action="admin_handle_reopen.php" style="display:inline-block;">
                <input type="hidden" name="audit_id" value="<?php echo (int)$r['id']; ?>">
                <input type="hidden" name="response_id" value="<?php echo (int)$r['object_id']; ?>">
                <input type="hidden" name="action" value="reject">
                <button class="btn btn-sm btn-danger" onclick="return confirm('Rechazar la solicitud?')">Rechazar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body></html>
