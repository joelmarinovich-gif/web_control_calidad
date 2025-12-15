<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo 'DB error: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Verificar rol super_admin
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || $roleRow['name'] !== 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

// Manejar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $abbr = trim($_POST['abbreviation'] ?? '');
      $atc = trim($_POST['atc_code'] ?? '');
      if ($name !== '') {
        $ins = $pdo->prepare('INSERT INTO antibiotics (name, abbreviation, atc_code) VALUES (:name,:abbr,:atc)');
        $ins->execute([':name'=>$name,':abbr'=>$abbr,':atc'=>$atc]);
        $_SESSION['flash_success'] = 'Antibiótico creado.';
      } else {
        $_SESSION['flash_danger'] = 'Nombre requerido.';
      }
    }
    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $abbr = trim($_POST['abbreviation'] ?? '');
      $atc = trim($_POST['atc_code'] ?? '');
      if ($id > 0) {
        $u = $pdo->prepare('UPDATE antibiotics SET name = :name, abbreviation = :abbr, atc_code = :atc WHERE id = :id');
        $u->execute([':name'=>$name,':abbr'=>$abbr,':atc'=>$atc,':id'=>$id]);
        $_SESSION['flash_success'] = 'Antibiótico actualizado.';
      }
    }
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $d = $pdo->prepare('DELETE FROM antibiotics WHERE id = :id');
        $d->execute([':id'=>$id]);
        $_SESSION['flash_success'] = 'Antibiótico eliminado.';
      }
    }
  } catch (PDOException $e) {
    $_SESSION['flash_danger'] = 'Error en la operación: ' . $e->getMessage();
  }
  header('Location: admin_antibiotics.php');
  exit;
}

$ants = $pdo->query('SELECT * FROM antibiotics ORDER BY name ASC')->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar Antibióticos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Gestionar Antibióticos</h4>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary">Volver</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_danger'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['flash_danger']); unset($_SESSION['flash_danger']); ?></div>
      <?php endif; ?>

      <div class="mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">Nuevo Antibiótico</button>
      </div>

      <table class="table table-striped">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Abreviatura</th>
            <th>ATC</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ants as $a): ?>
            <tr>
              <td><?php echo htmlspecialchars($a['name']); ?></td>
              <td><?php echo htmlspecialchars($a['abbreviation']); ?></td>
              <td><?php echo htmlspecialchars($a['atc_code']); ?></td>
              <td>
                <button class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit" data-id="<?php echo (int)$a['id']; ?>" data-name="<?php echo htmlspecialchars($a['name'], ENT_QUOTES); ?>" data-abbr="<?php echo htmlspecialchars($a['abbreviation'], ENT_QUOTES); ?>" data-atc="<?php echo htmlspecialchars($a['atc_code'], ENT_QUOTES); ?>">Editar</button>
                <form method="post" style="display:inline-block" onsubmit="return confirm('Eliminar antibiótico?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <button class="btn btn-sm btn-danger">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Modal Crear -->
      <div class="modal fade" id="modalCreate" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <div class="modal-header"><h5 class="modal-title">Nuevo Antibiótico</h5></div>
              <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="mb-3"><label class="form-label">Nombre</label><input name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Abreviatura</label><input name="abbreviation" class="form-control"></div>
                <div class="mb-3"><label class="form-label">Código ATC</label><input name="atc_code" class="form-control"></div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal Editar -->
      <div class="modal fade" id="modalEdit" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <div class="modal-header"><h5 class="modal-title">Editar Antibiótico</h5></div>
              <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="mb-3"><label class="form-label">Nombre</label><input id="edit_name" name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Abreviatura</label><input id="edit_abbr" name="abbreviation" class="form-control"></div>
                <div class="mb-3"><label class="form-label">Código ATC</label><input id="edit_atc" name="atc_code" class="form-control"></div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
            </form>
          </div>
        </div>
      </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      var modalEdit = document.getElementById('modalEdit');
      modalEdit.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        document.getElementById('edit_id').value = button.getAttribute('data-id');
        document.getElementById('edit_name').value = button.getAttribute('data-name');
        document.getElementById('edit_abbr').value = button.getAttribute('data-abbr');
        document.getElementById('edit_atc').value = button.getAttribute('data-atc');
      });
    </script>
  </body>
</html>
