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

// Manejar acciones POST (create, update, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = (int)($_POST['role_id'] ?? 0);
        $lab_id = $_POST['lab_id'] === '' ? null : (int)$_POST['lab_id'];
      // Validar rol
      if ($role_id <= 0) {
        $_SESSION['flash_danger'] = 'Seleccione un rol válido.';
        header('Location: admin_users.php'); exit;
      }

      if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
          $ins = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role_id, lab_id, is_active) VALUES (:email, :ph, :fn, :rid, :lid, 1)');
          $ins->execute([':email'=>$email, ':ph'=>$hash, ':fn'=>$name, ':rid'=>$role_id, ':lid'=>$lab_id]);
          $_SESSION['flash_success'] = 'Usuario creado.';
        } catch (PDOException $e) {
          $_SESSION['flash_danger'] = 'Error al crear usuario: ' . $e->getMessage();
        }
      } else {
        $_SESSION['flash_danger'] = 'Complete nombre, email válido y contraseña.';
      }
      header('Location: admin_users.php'); exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = (int)($_POST['role_id'] ?? 0);
        $lab_id = $_POST['lab_id'] === '' ? null : (int)$_POST['lab_id'];

        if ($role_id <= 0) {
          $_SESSION['flash_danger'] = 'Seleccione un rol válido.';
          header('Location: admin_users.php'); exit;
        }

        if ($id > 0 && $name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
          try {
            if ($password !== '') {
              $hash = password_hash($password, PASSWORD_DEFAULT);
              $upd = $pdo->prepare('UPDATE users SET full_name = :fn, email = :email, password_hash = :ph, role_id = :rid, lab_id = :lid WHERE id = :id');
              $upd->execute([':fn'=>$name, ':email'=>$email, ':ph'=>$hash, ':rid'=>$role_id, ':lid'=>$lab_id, ':id'=>$id]);
            } else {
              $upd = $pdo->prepare('UPDATE users SET full_name = :fn, email = :email, role_id = :rid, lab_id = :lid WHERE id = :id');
              $upd->execute([':fn'=>$name, ':email'=>$email, ':rid'=>$role_id, ':lid'=>$lab_id, ':id'=>$id]);
            }
            $_SESSION['flash_success'] = 'Usuario actualizado.';
          } catch (PDOException $e) {
            $_SESSION['flash_danger'] = 'Error al actualizar usuario: ' . $e->getMessage();
          }
        } else {
          $_SESSION['flash_danger'] = 'Datos inválidos para actualizar usuario.';
        }
        header('Location: admin_users.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $del->execute([':id'=>$id]);
        }
        header('Location: admin_users.php'); exit;
    }
}

// Cargar roles y labs para selects
$roles = $pdo->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$labs = $pdo->query('SELECT id, name FROM labs ORDER BY id')->fetchAll();

// Cargar usuarios
$usersStmt = $pdo->query('SELECT u.id, u.full_name, u.email, u.role_id, r.name AS role_name, u.lab_id, l.name AS lab_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN labs l ON u.lab_id = l.id ORDER BY u.id');
$users = $usersStmt->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar Usuarios</title>
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
        <h3>Gestión de Usuarios</h3>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary me-2">Volver</a>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" id="btnNew">Nuevo Usuario</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>Laboratorio</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                  <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                  <td><?php echo htmlspecialchars($u['email']); ?></td>
                  <td><?php echo htmlspecialchars($u['role_name']); ?></td>
                  <td><?php echo htmlspecialchars($u['lab_name']); ?></td>
                  <td>
                    <button class="btn btn-sm btn-secondary btn-edit" 
                      data-id="<?php echo (int)$u['id']; ?>"
                      data-name="<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>"
                      data-email="<?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?>"
                      data-role="<?php echo (int)$u['role_id']; ?>"
                      data-lab="<?php echo $u['lab_id']===null ? '' : (int)$u['lab_id']; ?>"
                      >Editar</button>
                    <form method="post" action="admin_users.php" class="d-inline-block ms-1" onsubmit="return confirm('¿Borrar este usuario?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
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
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" id="userForm">
            <div class="modal-header">
              <h5 class="modal-title" id="userModalLabel">Nuevo Usuario</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="create" id="formAction">
              <input type="hidden" name="id" id="userId">
              <div class="mb-3">
                <label class="form-label">Nombre</label>
                <input class="form-control" name="name" id="userName" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" id="userEmail" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input type="password" class="form-control" name="password" id="userPassword">
                <div class="form-text">Dejar vacío al editar para mantener la contraseña actual.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Rol</label>
                <select class="form-select" name="role_id" id="userRole" required>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Laboratorio</label>
                <select class="form-select" name="lab_id" id="userLab">
                  <option value="">-- Ninguno --</option>
                  <?php foreach ($labs as $l): ?>
                    <option value="<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Permitir vacío para Super Admin.</div>
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
      // Nuevo usuario
      document.getElementById('btnNew').addEventListener('click', function(){
        document.getElementById('userModalLabel').textContent = 'Nuevo Usuario';
        document.getElementById('formAction').value = 'create';
        document.getElementById('userId').value = '';
        document.getElementById('userName').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userPassword').value = '';
          // set default to first available role to avoid empty selection
          document.getElementById('userRole').value = '<?php echo isset($roles[0]['id']) ? (int)$roles[0]['id'] : ''; ?>';
        document.getElementById('userLab').value = '';
      });

      // Edit buttons
      document.querySelectorAll('.btn-edit').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.dataset.id;
          var name = this.dataset.name;
          var email = this.dataset.email;
          var role = this.dataset.role;
          var lab = this.dataset.lab;

          document.getElementById('userModalLabel').textContent = 'Editar Usuario';
          document.getElementById('formAction').value = 'update';
          document.getElementById('userId').value = id;
          document.getElementById('userName').value = name;
          document.getElementById('userEmail').value = email;
          document.getElementById('userPassword').value = '';
          document.getElementById('userRole').value = role;
          document.getElementById('userLab').value = lab;

          var modal = new bootstrap.Modal(document.getElementById('userModal'));
          modal.show();
        });
      });

      // Toggle lab select disabled if role is super_admin (server name 'super_admin')
      // We'll map role id to name server-side not here; keep client flexible.
    </script>
  </body>
</html>
