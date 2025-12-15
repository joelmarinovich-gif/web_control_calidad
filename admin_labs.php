<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Verificar sesión
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

// Verificar rol 'super_admin'
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || $roleRow['name'] !== 'super_admin') {
    http_response_code(403);
    echo "Acceso denegado. Se requiere rol 'super_admin'.";
    exit;
}

// Manejar acciones POST: create, update, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $code = trim($_POST['code'] ?? '');
      $address = trim($_POST['address'] ?? '');
      $contact = trim($_POST['contact'] ?? '');

      if ($name !== '') {
        $ins = $pdo->prepare('INSERT INTO labs (name, code, address, contact) VALUES (:name, :code, :address, :contact)');
        $ins->execute([':name' => $name, ':code' => $code, ':address' => $address, ':contact' => $contact]);
        $_SESSION['flash_success'] = 'Laboratorio creado.';
      } else {
        $_SESSION['flash_danger'] = 'Nombre requerido.';
      }
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $code = trim($_POST['code'] ?? '');
      $address = trim($_POST['address'] ?? '');
      $contact = trim($_POST['contact'] ?? '');

      if ($id > 0 && $name !== '') {
        $upd = $pdo->prepare('UPDATE labs SET name = :name, code = :code, address = :address, contact = :contact WHERE id = :id');
        $upd->execute([':name' => $name, ':code' => $code, ':address' => $address, ':contact' => $contact, ':id' => $id]);
        $_SESSION['flash_success'] = 'Laboratorio actualizado.';
      } else {
        $_SESSION['flash_danger'] = 'Datos inválidos para actualizar.';
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $del = $pdo->prepare('DELETE FROM labs WHERE id = :id');
        $del->execute([':id' => $id]);
        $_SESSION['flash_success'] = 'Laboratorio eliminado.';
      }
    }
  } catch (PDOException $e) {
    $_SESSION['flash_danger'] = 'Error en la operación: ' . $e->getMessage();
  }

  header('Location: admin_labs.php');
  exit;
}

// Obtener lista de laboratorios
$labsStmt = $pdo->query('SELECT id, name, code, contact FROM labs ORDER BY id');
$labs = $labsStmt->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar Laboratorios</title>
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Gestión de Laboratorios</h3>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary me-2">Volver</a>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#labModal" id="btnNew">Nuevo Laboratorio</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nombre</th>
                  <th>Código</th>
                  <th>Contacto</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($labs as $l): ?>
                <tr>
                  <td><?php echo (int)$l['id']; ?></td>
                  <td><?php echo htmlspecialchars($l['name']); ?></td>
                  <td><?php echo htmlspecialchars($l['code']); ?></td>
                  <td><?php echo htmlspecialchars($l['contact']); ?></td>
                  <td>
                    <button class="btn btn-sm btn-secondary btn-edit" 
                      data-id="<?php echo (int)$l['id']; ?>" 
                      data-name="<?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?>" 
                      data-code="<?php echo htmlspecialchars($l['code'], ENT_QUOTES); ?>" 
                      data-address="" 
                      data-contact="<?php echo htmlspecialchars($l['contact'], ENT_QUOTES); ?>"
                      >Editar</button>
                    <form method="post" action="admin_labs.php" class="d-inline-block ms-1" onsubmit="return confirm('¿Borrar este laboratorio?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Borrar</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="labModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" id="labForm">
            <div class="modal-header">
              <h5 class="modal-title" id="labModalLabel">Nuevo Laboratorio</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="create" id="formAction">
              <input type="hidden" name="id" id="labId">
              <div class="mb-3">
                <label class="form-label">Nombre</label>
                <input class="form-control" name="name" id="labName" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Código</label>
                <input class="form-control" name="code" id="labCode">
              </div>
              <div class="mb-3">
                <label class="form-label">Dirección</label>
                <input class="form-control" name="address" id="labAddress">
              </div>
              <div class="mb-3">
                <label class="form-label">Contacto</label>
                <input class="form-control" name="contact" id="labContact">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Nuevo laboratorio: establecer formulario a create
      document.getElementById('btnNew').addEventListener('click', function(){
        document.getElementById('labModalLabel').textContent = 'Nuevo Laboratorio';
        document.getElementById('formAction').value = 'create';
        document.getElementById('labId').value = '';
        document.getElementById('labName').value = '';
        document.getElementById('labCode').value = '';
        document.getElementById('labAddress').value = '';
        document.getElementById('labContact').value = '';
      });

      // Edit buttons: llenar modal con datos y cambiar action
      document.querySelectorAll('.btn-edit').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.dataset.id;
          var name = this.dataset.name;
          var code = this.dataset.code;
          var address = this.dataset.address;
          var contact = this.dataset.contact;

          document.getElementById('labModalLabel').textContent = 'Editar Laboratorio';
          document.getElementById('formAction').value = 'update';
          document.getElementById('labId').value = id;
          document.getElementById('labName').value = name;
          document.getElementById('labCode').value = code;
          document.getElementById('labAddress').value = address;
          document.getElementById('labContact').value = contact;

          var modal = new bootstrap.Modal(document.getElementById('labModal'));
          modal.show();
        });
      });
    </script>
  </body>
</html>
