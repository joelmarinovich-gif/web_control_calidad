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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo 'ID inválido'; exit; }

$respStmt = $pdo->prepare('SELECT r.*, l.name AS lab_name, s.title AS survey_title FROM responses r LEFT JOIN labs l ON l.id=r.lab_id LEFT JOIN surveys s ON s.id=r.survey_id WHERE r.id = :id LIMIT 1');
$respStmt->execute([':id'=>$id]);
$resp = $respStmt->fetch();
if (!$resp) { echo 'Respuesta no encontrada'; exit; }

// fetch questions for the survey
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
$qStmt->execute([':sid' => $resp['survey_id']]);
$questions = $qStmt->fetchAll();

// fetch answers for this response
$aStmt = $pdo->prepare('SELECT ra.*, qo.value AS option_value, qo.label AS option_label FROM response_answers ra LEFT JOIN question_options qo ON qo.id = ra.option_id WHERE ra.response_id = :rid');
$aStmt->execute([':rid'=>$id]);
$answers = [];
foreach ($aStmt->fetchAll() as $ar) {
    $answers[(int)$ar['question_id']][] = $ar;
}

?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ver respuesta #<?php echo (int)$id; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5>Respuesta #<?php echo (int)$id; ?> — <?php echo htmlspecialchars($resp['survey_title'] ?? ''); ?></h5>
    <a href="admin_responses.php" class="btn btn-sm btn-outline-secondary">Volver</a>
  </div>

  <div class="mb-2"><strong>Laboratorio:</strong> <?php echo htmlspecialchars($resp['lab_name'] ?? '—'); ?> | <strong>Fecha:</strong> <?php echo htmlspecialchars($resp['submitted_at']); ?></div>

  <div class="card"><div class="card-body">
    <?php foreach ($questions as $q):
        $qid = (int)$q['id'];
        $ansList = $answers[$qid] ?? [];
    ?>
      <div class="mb-3">
        <label class="form-label"><strong><?php echo htmlspecialchars($q['question_text']); ?></strong></label>
        <div>
          <?php if (empty($ansList)): ?>
            <div class="text-muted">(sin respuesta)</div>
          <?php else: ?>
            <?php foreach ($ansList as $av): ?>
              <?php if ($q['question_type'] === 'numeric'): ?>
                <div class="form-control-plaintext"><?php echo htmlspecialchars($av['answer_number']); ?></div>
              <?php elseif ($q['question_type'] === 'antibiotic'): ?>
                <div class="form-control-plaintext">Valor: <?php echo htmlspecialchars($av['answer_number']); ?> — Interpretación: <?php echo htmlspecialchars($av['answer_text']); ?></div>
                <?php
                  // show antibiotic_results if present
                  $arRes = $pdo->prepare('SELECT * FROM antibiotic_results WHERE response_answer_id = :raid LIMIT 1');
                  $arRes->execute([':raid' => $av['id']]);
                  $abres = $arRes->fetch();
                  if ($abres) {
                      echo '<div class="small text-muted">('.htmlspecialchars($abres['method']).' ' . htmlspecialchars($abres['raw_value']) . ' ' . htmlspecialchars($abres['unit']) . ' → ' . htmlspecialchars($abres['interpretation']) . ')</div>';
                  }
                ?>
              <?php else: ?>
                <div class="form-control-plaintext"><?php echo htmlspecialchars($av['answer_text'] ?? $av['option_value'] ?? ''); ?></div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div></div>
</div>
</body></html>
