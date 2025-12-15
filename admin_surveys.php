<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Acceso: solo usuarios autenticados
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

// Manejo de acciones POST: create, update, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $scope = ($_POST['scope'] === 'lab') ? 'lab' : 'global';
        $lab_id = ($_POST['scope'] === 'lab' && $_POST['lab_id'] !== '') ? (int)$_POST['lab_id'] : null;
        $status = ($_POST['status'] === 'active') ? 1 : 0;

        if ($title !== '') {
            $ins = $pdo->prepare('INSERT INTO surveys (title, description, created_by, scope, lab_id, is_active) VALUES (:title, :desc, :cb, :scope, :lab_id, :is_active)');
            $ins->execute([
                ':title' => $title,
                ':desc' => $description,
                ':cb' => $_SESSION['user_id'],
                ':scope' => $scope,
                ':lab_id' => $lab_id,
                ':is_active' => $status
            ]);
        }
        header('Location: admin_surveys.php'); exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $scope = ($_POST['scope'] === 'lab') ? 'lab' : 'global';
        $lab_id = ($_POST['scope'] === 'lab' && $_POST['lab_id'] !== '') ? (int)$_POST['lab_id'] : null;
        $status = ($_POST['status'] === 'active') ? 1 : 0;

        if ($id > 0 && $title !== '') {
            $upd = $pdo->prepare('UPDATE surveys SET title = :title, description = :desc, scope = :scope, lab_id = :lab_id, is_active = :is_active WHERE id = :id');
            $upd->execute([
                ':title' => $title,
                ':desc' => $description,
                ':scope' => $scope,
                ':lab_id' => $lab_id,
                ':is_active' => $status,
                ':id' => $id
            ]);
        }
        header('Location: admin_surveys.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $pdo->prepare('DELETE FROM surveys WHERE id = :id');
            $del->execute([':id' => $id]);
        }
        header('Location: admin_surveys.php'); exit;
    }
}

// Cargar laboratorios para select
$labs = $pdo->query('SELECT id, name FROM labs ORDER BY name')->fetchAll();

// Obtener encuestas
$surveysStmt = $pdo->query('SELECT id, title, description, is_active, created_at, scope, lab_id FROM surveys ORDER BY created_at DESC');
$surveys = $surveysStmt->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar Encuestas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Gestión de Encuestas</h3>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary me-2">Volver</a>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#surveyModal" id="btnNew">Nueva Encuesta</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>Título</th>
                  <th>Descripción</th>
                  <th>Estado</th>
                  <th>Creación</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($surveys as $s): ?>
                <tr>
                  <td><?php echo htmlspecialchars($s['title']); ?></td>
                  <td><?php echo htmlspecialchars($s['description']); ?></td>
                  <td><?php echo $s['is_active'] ? 'Activo' : 'Borrador'; ?></td>
                  <td><?php echo htmlspecialchars($s['created_at']); ?></td>
                  <td>
                    <a href="admin_questions.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-warning">Gestionar Preguntas</a>
                    <button class="btn btn-sm btn-secondary btn-edit" 
                      data-id="<?php echo (int)$s['id']; ?>"
                      data-title="<?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?>"
                      data-description="<?php echo htmlspecialchars($s['description'], ENT_QUOTES); ?>"
                      data-scope="<?php echo htmlspecialchars($s['scope']); ?>"
                      data-lab="<?php echo $s['lab_id']===null ? '' : (int)$s['lab_id']; ?>"
                      data-status="<?php echo $s['is_active'] ? 'active' : 'draft'; ?>"
                      >Editar</button>
                    <form method="post" action="admin_surveys.php" class="d-inline-block ms-1" onsubmit="return confirm('¿Borrar esta encuesta?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
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
    <div class="modal fade" id="surveyModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" id="surveyForm">
            <div class="modal-header">
              <h5 class="modal-title" id="surveyModalLabel">Nueva Encuesta</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="create" id="formAction">
              <input type="hidden" name="id" id="surveyId">
              <div class="mb-3">
                <label class="form-label">Título</label>
                <input class="form-control" name="title" id="surveyTitle" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Descripción</label>
                <textarea class="form-control" name="description" id="surveyDescription" rows="3"></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">Alcance</label>
                <select class="form-select" name="scope" id="surveyScope">
                  <option value="global">Global</option>
                  <option value="lab">Por Laboratorio</option>
                </select>
              </div>
              <div class="mb-3" id="labSelectWrap" style="display:none;">
                <label class="form-label">Laboratorio</label>
                <select class="form-select" name="lab_id" id="surveyLab">
                  <option value="">-- Seleccionar --</option>
                  <?php foreach ($labs as $l): ?>
                    <option value="<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Estado</label>
                <select class="form-select" name="status" id="surveyStatus">
                  <option value="active">Activa</option>
                  <option value="draft">Borrador</option>
                </select>
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
      // Mostrar/ocultar select laboratorio según scope
      var scopeEl = document.getElementById('surveyScope');
      var labWrap = document.getElementById('labSelectWrap');
      function toggleLab() {
        if (scopeEl.value === 'lab') labWrap.style.display = '';
        else labWrap.style.display = 'none';
      }
      scopeEl.addEventListener('change', toggleLab);

      // Nuevo
      document.getElementById('btnNew').addEventListener('click', function(){
        document.getElementById('surveyModalLabel').textContent = 'Nueva Encuesta';
        document.getElementById('formAction').value = 'create';
        document.getElementById('surveyId').value = '';
        document.getElementById('surveyTitle').value = '';
        document.getElementById('surveyDescription').value = '';
        document.getElementById('surveyScope').value = 'global';
        document.getElementById('surveyLab').value = '';
        document.getElementById('surveyStatus').value = 'active';
        toggleLab();
      });

      // Edit buttons
      document.querySelectorAll('.btn-edit').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.dataset.id;
          var title = this.dataset.title;
          var description = this.dataset.description;
          var scope = this.dataset.scope;
          var lab = this.dataset.lab;
          var status = this.dataset.status;

          document.getElementById('surveyModalLabel').textContent = 'Editar Encuesta';
          document.getElementById('formAction').value = 'update';
          document.getElementById('surveyId').value = id;
          document.getElementById('surveyTitle').value = title;
          document.getElementById('surveyDescription').value = description;
          document.getElementById('surveyScope').value = scope;
          document.getElementById('surveyLab').value = lab;
          document.getElementById('surveyStatus').value = status;
          toggleLab();

          var modal = new bootstrap.Modal(document.getElementById('surveyModal'));
          modal.show();
        });
      });
    </script>
  </body>
</html>
