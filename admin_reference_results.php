<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

try { $pdo = getPDO(); } catch (PDOException $e) { echo 'DB error'; exit; }

// Mostrar errores (temporal, útil para diagnosticar HTTP 500 en hosting)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Preparar logger para capturar errores fatales y excepciones y facilitar diagnóstico en hosting
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
  @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/admin_reference_results.log';

set_error_handler(function ($severity, $message, $file, $line) {
  // Convertir errores en excepciones para atraparlos en el handler de excepciones
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) use ($logFile) {
  $msg = "[" . date('Y-m-d H:i:s') . "] Uncaught exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n";
  @file_put_contents($logFile, $msg, FILE_APPEND);
  http_response_code(500);
  echo '<div class="alert alert-danger">Error interno. Detalles guardados en logs/admin_reference_results.log</div>';
  exit;
});

// role check
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) { header('Location: dashboard.php'); exit; }

// ensure reference_responses table exists (with engine/charset)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reference_responses (
      survey_id INT PRIMARY KEY,
      response_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // Si falla la creación, registrar y mostrar mensaje para diagnóstico
    error_log('reference_responses create error: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Error creando la tabla reference_responses: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$surveyId = isset($_REQUEST['survey_id']) ? (int)$_REQUEST['survey_id'] : 0;

// load active surveys
$sStmt = $pdo->prepare('SELECT id, title FROM surveys WHERE is_active = 1 ORDER BY created_at DESC');
$sStmt->execute();
$surveys = $sStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_reference') {
    $surveyId = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
    if ($surveyId <= 0) { $_SESSION['flash_danger'] = 'Encuesta inválida.'; header('Location: admin_reference_results.php'); exit; }

    // load questions
    $qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
    $qStmt->execute([':sid'=>$surveyId]);
    $questions = $qStmt->fetchAll();

    try {
        $pdo->beginTransaction();
        // insert response (reference)
        $ins = $pdo->prepare('INSERT INTO responses (survey_id, user_id, lab_id, status) VALUES (:sid, :uid, :lid, :st)');
        $ins->execute([':sid'=>$surveyId, ':uid'=>$_SESSION['user_id'], ':lid'=>null, ':st'=>'submitted']);
        $response_id = $pdo->lastInsertId();

        $insAnswer = $pdo->prepare('INSERT INTO response_answers (response_id, question_id, option_id, answer_text, answer_number) VALUES (:rid,:qid,:optid,:txt,:num)');
        $findOption = $pdo->prepare('SELECT id FROM question_options WHERE question_id = :qid AND value = :val LIMIT 1');

        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $name = 'ref_q_'.$qid;
            if ($q['question_type'] === 'multiselect') {
                $vals = $_POST[$name] ?? [];
                if (!is_array($vals)) $vals = [$vals];
                foreach ($vals as $v) {
                    $findOption->execute([':qid'=>$qid,':val'=>$v]); $row = $findOption->fetch(); $optid = $row ? $row['id'] : null;
                    $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$optid,':txt'=>$v,':num'=>null]);
                }
                continue;
            }
            if ($q['question_type'] === 'select') {
                $v = $_POST[$name] ?? null; $findOption->execute([':qid'=>$qid,':val'=>$v]); $row=$findOption->fetch(); $optid=$row?$row['id']:null;
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$optid,':txt'=>$v,':num'=>null]); continue;
            }
            if ($q['question_type'] === 'text') { $v = $_POST[$name] ?? null; $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$v,':num'=>null]); continue; }
            if ($q['question_type'] === 'numeric') { $v = $_POST[$name] ?? null; $num = $v!==null && $v!=='' ? (float)str_replace(',', '.', $v) : null; $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>null,':num'=>$num]); continue; }
            if ($q['question_type'] === 'antibiotic') {
                $raw = $_POST[$name.'_raw'] ?? null; $interp = $_POST[$name.'_interpretation'] ?? null;
                $num = $raw!==null && $raw!=='' ? (float)str_replace(',', '.', $raw) : null;
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$interp,':num'=>$num]);
                $ra_id = $pdo->lastInsertId();
                // create antibiotic_results entry for reference (interpretation as provided)
                if (!empty($q['antibiotic_id'])) {
                    // we store minimal antibiotic_results; method/unit left blank
                    $iar = $pdo->prepare('INSERT INTO antibiotic_results (response_answer_id, antibiotic_id, breakpoint_id, method, raw_value, unit, interpretation) VALUES (:ra,:ab,:bp,:m,:raw,:u,:interp)');
                    $iar->execute([':ra'=>$ra_id,':ab'=>$q['antibiotic_id'],':bp'=>null,':m'=>'disk',':raw'=>$num!==null?$num:0,':u'=>'',':interp'=>$interp]);
                }
                continue;
            }
        }

        // upsert into reference_responses
        $up = $pdo->prepare('REPLACE INTO reference_responses (survey_id, response_id) VALUES (:sid, :rid)');
        $up->execute([':sid'=>$surveyId,':rid'=>$response_id]);

        $pdo->commit();
        $_SESSION['flash_success'] = 'Patrón de oro guardado.';
        header('Location: admin_reference_results.php?survey_id=' . $surveyId);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_danger'] = 'Error: '.$e->getMessage(); header('Location: admin_reference_results.php'); exit;
    }
}

// If survey selected, load questions for rendering form
$questions = [];
if ($surveyId > 0) {
    $qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
    $qStmt->execute([':sid'=>$surveyId]); $questions = $qStmt->fetchAll();
    // load antibiotics map for antibiotic questions
    $antibiotic_ids = [];
    foreach ($questions as $qq) if ($qq['question_type'] === 'antibiotic' && !empty($qq['antibiotic_id'])) $antibiotic_ids[] = (int)$qq['antibiotic_id'];
    $antibiotics_map = [];
    if (!empty($antibiotic_ids)) {
        $ph = implode(',', array_fill(0,count($antibiotic_ids),'?'));
        $a = $pdo->prepare("SELECT id,name FROM antibiotics WHERE id IN ($ph)"); $a->execute($antibiotic_ids);
        foreach ($a->fetchAll() as $ar) $antibiotics_map[(int)$ar['id']] = $ar;
    }
}

?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin - Cargar Resultado de Referencia</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between mb-3">
    <h4>Cargar Resultado de Referencia</h4>
    <a href="admin_responses.php" class="btn btn-sm btn-outline-secondary">Volver</a>
  </div>

  <?php if (!empty($_SESSION['flash_success'])) { echo '<div class="alert alert-success">'.htmlspecialchars($_SESSION['flash_success']).'</div>'; unset($_SESSION['flash_success']); }
        if (!empty($_SESSION['flash_danger'])) { echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['flash_danger']).'</div>'; unset($_SESSION['flash_danger']); }
  ?>

  <form method="get" class="mb-3">
    <div class="row g-2">
      <div class="col-md-8">
        <select name="survey_id" class="form-select">
          <option value="">-- Seleccione encuesta activa --</option>
          <?php foreach ($surveys as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php if ($s['id']==$surveyId) echo 'selected'; ?>><?php echo htmlspecialchars($s['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><button class="btn btn-primary">Cargar formulario</button></div>
    </div>
  </form>

  <?php if ($surveyId > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="save_reference">
      <input type="hidden" name="survey_id" value="<?php echo (int)$surveyId; ?>">

      <?php if (empty($questions)): ?>
        <div class="alert alert-info">Esta encuesta no tiene preguntas.</div>
      <?php else: foreach ($questions as $q): ?>
        <div class="mb-3">
          <label class="form-label"><?php echo htmlspecialchars($q['question_text']); ?> <?php if ($q['required']) echo '<span class="text-danger">*</span>'; ?></label>
          <?php if ($q['question_type'] === 'text'): ?>
            <input type="text" name="ref_q_<?php echo (int)$q['id']; ?>" class="form-control">
          <?php elseif ($q['question_type'] === 'numeric'): ?>
            <input type="number" step="any" name="ref_q_<?php echo (int)$q['id']; ?>" class="form-control">
          <?php elseif ($q['question_type'] === 'select' || $q['question_type'] === 'multiselect'): ?>
            <?php $opts = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY display_order ASC'); $opts->execute([':qid'=>$q['id']]); $optsf = $opts->fetchAll(); ?>
            <select name="<?php echo $q['question_type']==='multiselect' ? 'ref_q_'.(int)$q['id'].'[]' : 'ref_q_'.(int)$q['id']; ?>" <?php if ($q['question_type']==='multiselect') echo 'multiple'; ?> class="form-select">
              <?php if ($q['question_type'] === 'select') echo '<option value="">-- selecciona --</option>'; ?>
              <?php foreach ($optsf as $o): ?>
                <option value="<?php echo htmlspecialchars($o['value']); ?>"><?php echo htmlspecialchars($o['label'] ?: $o['value']); ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($q['question_type'] === 'antibiotic'): ?>
            <div class="mb-1"><strong><?php echo htmlspecialchars($antibiotics_map[$q['antibiotic_id']]['name'] ?? 'Antibiótico'); ?></strong></div>
            <div class="row g-2"><div class="col-md-4"><input type="number" step="any" name="ref_q_<?php echo (int)$q['id']; ?>_raw" class="form-control" placeholder="Valor"></div>
              <div class="col-md-4"><input type="text" name="ref_q_<?php echo (int)$q['id']; ?>_interpretation" class="form-control" placeholder="Interpretación (S/I/R)"></div></div>
          <?php else: ?>
            <input type="text" name="ref_q_<?php echo (int)$q['id']; ?>" class="form-control">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="d-flex justify-content-end"><button class="btn btn-success" type="submit">Guardar como referencia</button></div>
    </form>
  <?php endif; ?>
</div>
</body></html>
