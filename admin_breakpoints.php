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

// Manejar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $antibiotic_id = (int)($_POST['antibiotic_id'] ?? 0);
        $standard = $_POST['standard'] ?? 'CLSI';
        $version = trim($_POST['version'] ?? '');
        $method = $_POST['method'] ?? 'disk';
        $unit = trim($_POST['unit'] ?? '');
        $s_upper = $_POST['s_upper'] !== '' ? $_POST['s_upper'] : null;
        $i_lower = $_POST['i_lower'] !== '' ? $_POST['i_lower'] : null;
        $i_upper = $_POST['i_upper'] !== '' ? $_POST['i_upper'] : null;
        $r_lower = $_POST['r_lower'] !== '' ? $_POST['r_lower'] : null;
        $note = trim($_POST['note'] ?? '');
        $ins = $pdo->prepare('INSERT INTO breakpoints (antibiotic_id, standard, version, method, unit, s_upper, i_lower, i_upper, r_lower, note) VALUES (:ab,:std,:ver,:met,:unit,:s,:il,:iu,:r,:note)');
        $ins->execute([':ab'=>$antibiotic_id,':std'=>$standard,':ver'=>$version,':met'=>$method,':unit'=>$unit,':s'=>$s_upper,':il'=>$i_lower,':iu'=>$i_upper,':r'=>$r_lower,':note'=>$note]);
        $_SESSION['flash_success'] = 'Punto de corte creado.';
    }
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $antibiotic_id = (int)($_POST['antibiotic_id'] ?? 0);
        $standard = $_POST['standard'] ?? 'CLSI';
        $version = trim($_POST['version'] ?? '');
        $method = $_POST['method'] ?? 'disk';
        $unit = trim($_POST['unit'] ?? '');
        $s_upper = $_POST['s_upper'] !== '' ? $_POST['s_upper'] : null;
        $i_lower = $_POST['i_lower'] !== '' ? $_POST['i_lower'] : null;
        $i_upper = $_POST['i_upper'] !== '' ? $_POST['i_upper'] : null;
        $r_lower = $_POST['r_lower'] !== '' ? $_POST['r_lower'] : null;
        $note = trim($_POST['note'] ?? '');
        if ($id > 0) {
            $u = $pdo->prepare('UPDATE breakpoints SET antibiotic_id=:ab, standard=:std, version=:ver, method=:met, unit=:unit, s_upper=:s, i_lower=:il, i_upper=:iu, r_lower=:r, note=:note WHERE id = :id');
            $u->execute([':ab'=>$antibiotic_id,':std'=>$standard,':ver'=>$version,':met'=>$method,':unit'=>$unit,':s'=>$s_upper,':il'=>$i_lower,':iu'=>$i_upper,':r'=>$r_lower,':note'=>$note,':id'=>$id]);
            $_SESSION['flash_success'] = 'Punto de corte actualizado.';
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $d = $pdo->prepare('DELETE FROM breakpoints WHERE id = :id');
            $d->execute([':id'=>$id]);
            $_SESSION['flash_success'] = 'Punto de corte eliminado.';
        }
    }
    header('Location: admin_breakpoints.php');
    exit;
}

// Obtener antibioticos para selector
$ants = $pdo->query('SELECT id, name, abbreviation FROM antibiotics ORDER BY name ASC')->fetchAll();

$filter_ab = isset($_GET['antibiotic_id']) ? (int)$_GET['antibiotic_id'] : 0;
if ($filter_ab > 0) {
    $bpStmt = $pdo->prepare('SELECT b.*, a.name as antibiotic_name FROM breakpoints b JOIN antibiotics a ON a.id = b.antibiotic_id WHERE b.antibiotic_id = :ab ORDER BY b.id DESC');
    $bpStmt->execute([':ab'=>$filter_ab]);
} else {
    $bpStmt = $pdo->query('SELECT b.*, a.name as antibiotic_name FROM breakpoints b JOIN antibiotics a ON a.id = b.antibiotic_id ORDER BY a.name, b.id DESC');
}
$bps = $bpStmt->fetchAll();

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestionar Breakpoints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Gestionar Breakpoints</h4>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary">Volver</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
      <?php endif; ?>

      <form class="row g-2 mb-3">
        <div class="col-auto">
          <select class="form-select" onchange="this.form.submit()" name="antibiotic_id">
            <option value="">-- Todos los antibióticos --</option>
            <?php foreach ($ants as $a): ?>
              <option value="<?php echo (int)$a['id']; ?>" <?php echo $filter_ab === (int)$a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">Nuevo Breakpoint</button>
        </div>
      </form>

      <table class="table table-sm">
        <thead>
          <tr>
            <th>Antibiótico</th>
            <th>Norma</th>
            <th>Método</th>
            <th>S_upper</th>
            <th>I_lower - I_upper</th>
            <th>R_lower</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bps as $b): ?>
            <tr>
              <td><?php echo htmlspecialchars($b['antibiotic_name']); ?></td>
              <td><?php echo htmlspecialchars($b['standard']); ?> <?php echo htmlspecialchars($b['version']); ?></td>
              <td><?php echo htmlspecialchars($b['method']); ?> (<?php echo htmlspecialchars($b['unit']); ?>)</td>
              <td><?php echo htmlspecialchars($b['s_upper']); ?></td>
              <td><?php echo htmlspecialchars($b['i_lower']); ?> - <?php echo htmlspecialchars($b['i_upper']); ?></td>
              <td><?php echo htmlspecialchars($b['r_lower']); ?></td>
              <td>
                <button class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit" 
                  data-id="<?php echo (int)$b['id']; ?>" data-antibiotic="<?php echo (int)$b['antibiotic_id']; ?>" data-standard="<?php echo htmlspecialchars($b['standard'], ENT_QUOTES); ?>" data-method="<?php echo htmlspecialchars($b['method'], ENT_QUOTES); ?>" data-s="<?php echo htmlspecialchars($b['s_upper'], ENT_QUOTES); ?>" data-il="<?php echo htmlspecialchars($b['i_lower'], ENT_QUOTES); ?>" data-iu="<?php echo htmlspecialchars($b['i_upper'], ENT_QUOTES); ?>" data-r="<?php echo htmlspecialchars($b['r_lower'], ENT_QUOTES); ?>" data-unit="<?php echo htmlspecialchars($b['unit'], ENT_QUOTES); ?>" data-version="<?php echo htmlspecialchars($b['version'], ENT_QUOTES); ?>" data-note="<?php echo htmlspecialchars($b['note'], ENT_QUOTES); ?>">Editar</button>
                <form method="post" style="display:inline-block" onsubmit="return confirm('Eliminar breakpoint?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                  <button class="btn btn-sm btn-danger">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Modal crear -->
      <div class="modal fade" id="modalCreate" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <form method="post">
              <div class="modal-header"><h5 class="modal-title">Nuevo Breakpoint</h5></div>
              <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label">Antibiótico</label>
                    <select name="antibiotic_id" class="form-select" required>
                      <?php foreach ($ants as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label">Norma</label><select name="standard" class="form-select"><option>CLSI</option><option>EUCAST</option><option>LOCAL</option></select></div>
                  <div class="col-md-3"><label class="form-label">Versión</label><input name="version" class="form-control"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-3"><label class="form-label">Método</label><select name="method" class="form-select"><option value="disk">disk</option><option value="mic">mic</option></select></div>
                  <div class="col-md-3"><label class="form-label">Unidad</label><input name="unit" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">S_upper</label><input name="s_upper" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">R_lower</label><input name="r_lower" class="form-control"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-3"><label class="form-label">I_lower</label><input name="i_lower" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">I_upper</label><input name="i_upper" class="form-control"></div>
                  <div class="col-md-6"><label class="form-label">Nota</label><input name="note" class="form-control"></div>
                </div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal editar -->
      <div class="modal fade" id="modalEdit" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <form method="post">
              <div class="modal-header"><h5 class="modal-title">Editar Breakpoint</h5></div>
              <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label">Antibiótico</label>
                    <select name="antibiotic_id" id="edit_antibiotic" class="form-select" required>
                      <?php foreach ($ants as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label">Norma</label><select name="standard" id="edit_standard" class="form-select"><option>CLSI</option><option>EUCAST</option><option>LOCAL</option></select></div>
                  <div class="col-md-3"><label class="form-label">Versión</label><input id="edit_version" name="version" class="form-control"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-3"><label class="form-label">Método</label><select id="edit_method" name="method" class="form-select"><option value="disk">disk</option><option value="mic">mic</option></select></div>
                  <div class="col-md-3"><label class="form-label">Unidad</label><input id="edit_unit" name="unit" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">S_upper</label><input id="edit_s" name="s_upper" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">R_lower</label><input id="edit_r" name="r_lower" class="form-control"></div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-md-3"><label class="form-label">I_lower</label><input id="edit_il" name="i_lower" class="form-control"></div>
                  <div class="col-md-3"><label class="form-label">I_upper</label><input id="edit_iu" name="i_upper" class="form-control"></div>
                  <div class="col-md-6"><label class="form-label">Nota</label><input id="edit_note" name="note" class="form-control"></div>
                </div>
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
        var btn = event.relatedTarget;
        document.getElementById('edit_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_antibiotic').value = btn.getAttribute('data-antibiotic');
        document.getElementById('edit_standard').value = btn.getAttribute('data-standard');
        document.getElementById('edit_version').value = btn.getAttribute('data-version');
        document.getElementById('edit_method').value = btn.getAttribute('data-method');
        document.getElementById('edit_unit').value = btn.getAttribute('data-unit');
        document.getElementById('edit_s').value = btn.getAttribute('data-s');
        document.getElementById('edit_il').value = btn.getAttribute('data-il');
        document.getElementById('edit_iu').value = btn.getAttribute('data-iu');
        document.getElementById('edit_r').value = btn.getAttribute('data-r');
        document.getElementById('edit_note').value = btn.getAttribute('data-note');
      });
    </script>
  </body>
</html>
