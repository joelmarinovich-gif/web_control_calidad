<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Mostrar flash (si existe)
$flash = null;
if (isset($_SESSION['flash'])) {
  $flash = $_SESSION['flash'];
  unset($_SESSION['flash']);
}

// Requiere ID de encuesta
$survey_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($survey_id <= 0) {
    header('Location: admin_surveys.php');
    exit;
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    echo "Error de conexión: " . htmlspecialchars($e->getMessage());
    exit;
}

// Verificar sesión y rol super_admin
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || $roleRow['name'] !== 'super_admin') {
    http_response_code(403);
    echo "Acceso denegado. Se requiere rol 'super_admin'.";
    exit;
}

// Cargar encuesta
$sStmt = $pdo->prepare('SELECT id, title FROM surveys WHERE id = :id LIMIT 1');
$sStmt->execute([':id' => $survey_id]);
$survey = $sStmt->fetch();
if (!$survey) {
    header('Location: admin_surveys.php');
    exit;
}

// Manejo POST: create, update, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'text';
        $required = isset($_POST['required']) ? 1 : 0;
        $display_order = (int)($_POST['display_order'] ?? 0);
        $antibiotic_id = ($_POST['antibiotic_id'] !== '') ? (int)$_POST['antibiotic_id'] : null;
        $options_raw = $_POST['options_raw'] ?? '';

        if ($question_text === '') {
            header('Location: admin_questions.php?id=' . $survey_id);
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($action === 'create') {
                $ins = $pdo->prepare('INSERT INTO survey_questions (survey_id, question_text, question_key, question_type, required, display_order, max_length, antibiotic_id) VALUES (:survey_id, :qtext, :qkey, :qtype, :req, :dorder, NULL, :abid)');
                $ins->execute([
                    ':survey_id' => $survey_id,
                    ':qtext' => $question_text,
                    ':qkey' => null,
                    ':qtype' => $question_type,
                    ':req' => $required,
                    ':dorder' => $display_order,
                    ':abid' => $antibiotic_id
                ]);
                $new_qid = (int)$pdo->lastInsertId();
            } else {
                $upd = $pdo->prepare('UPDATE survey_questions SET question_text = :qtext, question_type = :qtype, required = :req, display_order = :dorder, antibiotic_id = :abid WHERE id = :id AND survey_id = :survey_id');
                $upd->execute([
                    ':qtext' => $question_text,
                    ':qtype' => $question_type,
                    ':req' => $required,
                    ':dorder' => $display_order,
                    ':abid' => $antibiotic_id,
                    ':id' => $id,
                    ':survey_id' => $survey_id
                ]);
                $new_qid = $id;
                // borrar opciones previas (se reinsertarán si aplica)
                $delOpt = $pdo->prepare('DELETE FROM question_options WHERE question_id = :qid');
                $delOpt->execute([':qid' => $new_qid]);
            }

            // Si tipo select o multiselect, insertar opciones (una por línea)
            if (in_array($question_type, ['select', 'multiselect'])) {
                $lines = preg_split('/\r?\n/', $options_raw);
                $optIns = $pdo->prepare('INSERT INTO question_options (question_id, value, label, display_order) VALUES (:qid, :val, :label, :dorder)');
                $order = 0;
                foreach ($lines as $line) {
                    $val = trim($line);
                    if ($val === '') continue;
                    $optIns->execute([':qid' => $new_qid, ':val' => $val, ':label' => $val, ':dorder' => $order]);
                    $order++;
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "Error guardando la pregunta: " . htmlspecialchars($e->getMessage());
            exit;
        }

        $_SESSION['flash'] = ['type'=>'success','message'=>'Pregunta guardada correctamente.'];
        header('Location: admin_questions.php?id=' . $survey_id);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $pdo->prepare('DELETE FROM survey_questions WHERE id = :id AND survey_id = :survey_id');
            $del->execute([':id' => $id, ':survey_id' => $survey_id]);
        }
        $_SESSION['flash'] = ['type'=>'success','message'=>'Pregunta eliminada correctamente.'];
        header('Location: admin_questions.php?id=' . $survey_id);
        exit;
    }
}

// Cargar preguntas
$qStmt = $pdo->prepare('SELECT id, question_text, question_type, required, display_order, antibiotic_id FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
$qStmt->execute([':sid' => $survey_id]);
$questions = $qStmt->fetchAll();

// Cargar antibióticos para dropdown
$antibiotics = $pdo->query('SELECT id, name FROM antibiotics ORDER BY name')->fetchAll();

// Obtener opciones para preguntas (map)
$optionsMap = [];
$optStmt = $pdo->prepare('SELECT id, question_id, value, label, display_order FROM question_options WHERE question_id = :qid ORDER BY display_order ASC');
foreach ($questions as $qq) {
    if (in_array($qq['question_type'], ['select','multiselect'])) {
        $optStmt->execute([':qid' => $qq['id']]);
        $optionsMap[$qq['id']] = $optStmt->fetchAll();
    }
}

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preguntas - <?php echo htmlspecialchars($survey['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <?php if ($flash): ?>
        <div class="container mt-2">
          <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        </div>
      <?php endif; ?>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <a href="admin_surveys.php" class="btn btn-outline-secondary">&larr; Volver a Encuestas</a>
        </div>
        <h4>Preguntas de: <?php echo htmlspecialchars($survey['title']); ?></h4>
        <div>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#questionModal" id="btnNew">Nueva Pregunta</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped mb-0">
              <thead>
                <tr>
                  <th>Texto</th>
                  <th>Tipo</th>
                  <th>Req.</th>
                  <th>Orden</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($questions as $q): ?>
                <tr>
                  <td><?php echo htmlspecialchars($q['question_text']); ?></td>
                  <td><?php echo htmlspecialchars($q['question_type']); ?><?php echo $q['question_type'] === 'antibiotic' && $q['antibiotic_id'] ? ' (ID: '.(int)$q['antibiotic_id'].')' : ''; ?></td>
                  <td><?php echo $q['required'] ? 'Sí' : 'No'; ?></td>
                  <td><?php echo (int)$q['display_order']; ?></td>
                  <td>
                    <button class="btn btn-sm btn-secondary btn-edit" 
                      data-id="<?php echo (int)$q['id']; ?>"
                      data-text="<?php echo htmlspecialchars($q['question_text'], ENT_QUOTES); ?>"
                      data-type="<?php echo htmlspecialchars($q['question_type']); ?>"
                      data-required="<?php echo (int)$q['required']; ?>"
                      data-order="<?php echo (int)$q['display_order']; ?>"
                      data-antibiotic="<?php echo $q['antibiotic_id']===null ? '' : (int)$q['antibiotic_id']; ?>"
                      >Editar</button>
                    <form method="post" action="admin_questions.php?id=<?php echo $survey_id;?>" class="d-inline-block ms-1" onsubmit="return confirm('¿Borrar esta pregunta?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$q['id']; ?>">
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
    <div class="modal fade" id="questionModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" id="questionForm">
            <div class="modal-header">
              <h5 class="modal-title" id="questionModalLabel">Nueva Pregunta</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" id="formAction" value="create">
              <input type="hidden" name="id" id="qId">
              <div class="mb-3">
                <label class="form-label">Texto de la Pregunta</label>
                <input class="form-control" name="question_text" id="qText" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="question_type" id="qType">
                  <option value="text">text</option>
                  <option value="numeric">numeric</option>
                  <option value="select">select</option>
                  <option value="multiselect">multiselect</option>
                  <option value="antibiotic">antibiotic</option>
                </select>
              </div>
              <div class="mb-3" id="antibioticWrap" style="display:none;">
                <label class="form-label">Antibiótico</label>
                <select class="form-select" name="antibiotic_id" id="antibioticId">
                  <option value="">-- Seleccionar --</option>
                  <?php foreach ($antibiotics as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3" id="optionsWrap" style="display:none;">
                <label class="form-label">Opciones (una por línea)</label>
                <textarea class="form-control" name="options_raw" id="optionsRaw" rows="5"></textarea>
              </div>
              <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" name="required" id="qRequired">
                <label class="form-check-label" for="qRequired">Requerida</label>
              </div>
              <div class="mb-3">
                <label class="form-label">Orden de visualización</label>
                <input class="form-control" type="number" name="display_order" id="qOrder" value="0">
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
      var qTypeEl = document.getElementById('qType');
      var antibioticWrap = document.getElementById('antibioticWrap');
      var optionsWrap = document.getElementById('optionsWrap');
      function toggleFields() {
        var t = qTypeEl.value;
        antibioticWrap.style.display = (t === 'antibiotic') ? '' : 'none';
        optionsWrap.style.display = (t === 'select' || t === 'multiselect') ? '' : 'none';
      }
      qTypeEl.addEventListener('change', toggleFields);

      document.getElementById('btnNew').addEventListener('click', function(){
        document.getElementById('questionModalLabel').textContent = 'Nueva Pregunta';
        document.getElementById('formAction').value = 'create';
        document.getElementById('qId').value = '';
        document.getElementById('qText').value = '';
        document.getElementById('qType').value = 'text';
        document.getElementById('antibioticId').value = '';
        document.getElementById('optionsRaw').value = '';
        document.getElementById('qRequired').checked = false;
        document.getElementById('qOrder').value = 0;
        toggleFields();
      });

      document.querySelectorAll('.btn-edit').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.dataset.id;

          fetch('api_get_question.php?id=' + encodeURIComponent(id), {credentials: 'same-origin'})
            .then(function(res){
              if (!res.ok) throw new Error('Error en la petición');
              return res.json();
            })
            .then(function(data){
              document.getElementById('questionModalLabel').textContent = 'Editar Pregunta';
              document.getElementById('formAction').value = 'update';
              document.getElementById('qId').value = data.id;
              document.getElementById('qText').value = data.question_text;
              document.getElementById('qType').value = data.question_type;
              document.getElementById('antibioticId').value = data.antibiotic_id || '';
              document.getElementById('qRequired').checked = data.required == 1 || data.required === true;
              document.getElementById('qOrder').value = data.display_order || 0;
              // llenar opciones raw si existen
              document.getElementById('optionsRaw').value = data.options_raw || '';
              toggleFields();
              var modal = new bootstrap.Modal(document.getElementById('questionModal'));
              modal.show();
            })
            .catch(function(err){
              alert('No se pudo cargar la pregunta: ' + err.message);
            });
        });
      });
    </script>
  </body>
</html>
