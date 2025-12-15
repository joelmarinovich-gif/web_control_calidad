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

// Sólo usuarios de laboratorio (no super_admin ni admin)
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || in_array($roleRow['name'], ['super_admin','admin'])) {
    header('Location: dashboard.php');
    exit;
}

$surveyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($surveyId <= 0) {
    echo "Encuesta no especificada.";
    exit;
}

// Verificar acceso a la encuesta
$surveyStmt = $pdo->prepare('SELECT * FROM surveys WHERE id = :id AND is_active = 1 LIMIT 1');
$surveyStmt->execute([':id' => $surveyId]);
$survey = $surveyStmt->fetch();
if (!$survey) {
    echo "Encuesta no encontrada o inactiva.";
    exit;
}

$userLabId = $_SESSION['lab_id'] ?? null;
if ($survey['scope'] === 'lab' && $survey['lab_id'] != $userLabId) {
    echo "No tiene permiso para acceder a esta encuesta.";
    exit;
}

// Obtener preguntas
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
$qStmt->execute([':sid' => $surveyId]);
$questions = $qStmt->fetchAll();

function fetchOptions($pdo, $questionId) {
    $s = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY display_order ASC, id ASC');
    $s->execute([':qid' => $questionId]);
    return $s->fetchAll();
}

?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Encuesta - <?php echo htmlspecialchars($survey['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><?php echo htmlspecialchars($survey['title']); ?></h4>
        <a href="user_dashboard.php" class="btn btn-outline-secondary">Volver</a>
      </div>

      <form method="post" action="responses_submit.php">
        <input type="hidden" name="survey_id" value="<?php echo (int)$surveyId; ?>">

        <?php if (empty($questions)): ?>
          <div class="alert alert-info">Esta encuesta no tiene preguntas definidas.</div>
        <?php else: ?>
          <?php foreach ($questions as $q): ?>
            <div class="mb-3">
              <label class="form-label"><?php echo htmlspecialchars($q['question_text']); ?>
                <?php if ($q['required']): ?> <span class="text-danger">*</span><?php endif; ?>
              </label>

              <?php if ($q['question_type'] === 'text'): ?>
                <input type="text" class="form-control" name="q_<?php echo (int)$q['id']; ?>" <?php echo $q['max_length'] ? 'maxlength="'.(int)$q['max_length'].'"' : ''; ?> <?php echo $q['required'] ? 'required' : ''; ?> >

              <?php elseif ($q['question_type'] === 'numeric'): ?>
                <input type="number" step="any" class="form-control" name="q_<?php echo (int)$q['id']; ?>" <?php echo $q['required'] ? 'required' : ''; ?> >

              <?php elseif ($q['question_type'] === 'select' || $q['question_type'] === 'multiselect'): ?>
                <?php $opts = fetchOptions($pdo, $q['id']); ?>
                <select class="form-select" name="<?php echo $q['question_type'] === 'multiselect' ? 'q_'.(int)$q['id'].'[]' : 'q_'.(int)$q['id']; ?>" <?php echo $q['question_type'] === 'multiselect' ? 'multiple' : ''; ?> <?php echo $q['required'] ? 'required' : ''; ?>>
                  <?php if ($q['question_type'] === 'select'): ?><option value="">-- Seleccione --</option><?php endif; ?>
                  <?php foreach ($opts as $o): ?>
                    <option value="<?php echo htmlspecialchars($o['value']); ?>"><?php echo htmlspecialchars($o['label'] ?: $o['value']); ?></option>
                  <?php endforeach; ?>
                </select>

              <?php elseif ($q['question_type'] === 'antibiotic'): ?>
                <div class="row g-2">
                  <div class="col-md-4">
                    <input type="number" step="any" class="form-control" name="q_<?php echo (int)$q['id']; ?>_raw" placeholder="Valor (halo / CIM)" <?php echo $q['required'] ? 'required' : ''; ?> >
                  </div>
                  <div class="col-md-4">
                    <input type="text" readonly class="form-control" name="q_<?php echo (int)$q['id']; ?>_interpretation" placeholder="Interpretación (S/I/R)" >
                  </div>
                </div>

              <?php else: ?>
                <input type="text" class="form-control" name="q_<?php echo (int)$q['id']; ?>">
              <?php endif; ?>

              <?php if (!empty($q['help_text'])): ?>
                <div class="form-text"><?php echo htmlspecialchars($q['help_text']); ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="d-flex justify-content-end">
          <button class="btn btn-primary" type="submit">Enviar Resultados</button>
        </div>
      </form>
    </div>
  </body>
</html>
