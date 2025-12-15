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

$surveyId = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;

// load active surveys for selection
$sStmt = $pdo->prepare('SELECT id, title FROM surveys WHERE is_active = 1 ORDER BY created_at DESC'); $sStmt->execute(); $surveys=$sStmt->fetchAll();

// find reference response
$refId = null;
if ($surveyId > 0) {
    $rr = $pdo->prepare('SELECT response_id FROM reference_responses WHERE survey_id = :sid LIMIT 1');
    $rr->execute([':sid'=>$surveyId]); $rrow = $rr->fetch(); if ($rrow) $refId = (int)$rrow['response_id'];
}

?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin - Estadísticas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between mb-3">
    <h4>Estadísticas EQAS</h4>
    <a href="admin_responses.php" class="btn btn-sm btn-outline-secondary">Volver</a>
  </div>

  <form method="get" class="mb-3">
    <div class="row g-2">
      <div class="col-md-8">
        <select name="survey_id" class="form-select">
          <option value="">-- Seleccione encuesta --</option>
          <?php foreach ($surveys as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php if ($s['id']==$surveyId) echo 'selected'; ?>><?php echo htmlspecialchars($s['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><button class="btn btn-primary">Calcular estadísticas</button></div>
    </div>
  </form>

  <?php if ($surveyId <= 0): ?>
    <div class="alert alert-info">Seleccione una encuesta para ver las estadísticas.</div>
  <?php else: ?>
    <?php if (!$refId): ?>
      <div class="alert alert-warning">No se encontró un resultado de referencia para esta encuesta. Cargue uno en "Cargar resultados de referencia".</div>
    <?php else: ?>
      <?php
        // Identification concordance overall (non-antibiotic text/select/numeric)
        $id_sql = "SELECT SUM(CASE WHEN (ra.answer_text IS NOT NULL AND ref.answer_text IS NOT NULL AND ra.answer_text = ref.answer_text) OR (ra.answer_number IS NOT NULL AND ref.answer_number IS NOT NULL AND ra.answer_number = ref.answer_number) THEN 1 ELSE 0 END) AS matches,
        COUNT(*) AS total FROM response_answers ra
        JOIN responses r ON r.id = ra.response_id AND r.survey_id = :sid AND r.lab_id IS NOT NULL
        LEFT JOIN response_answers ref ON ref.response_id = :refid AND ref.question_id = ra.question_id
        JOIN survey_questions q ON q.id = ra.question_id AND q.question_type IN ('text','select','numeric')";
        $stmt = $pdo->prepare($id_sql); $stmt->execute([':sid'=>$surveyId, ':refid'=>$refId]); $idres = $stmt->fetch();
        $matches = (int)($idres['matches'] ?? 0); $total = (int)($idres['total'] ?? 0);
        $incorrect = $total - $matches;
      ?>
      <div class="row mb-4">
        <div class="col-md-6">
          <h6>Concordancia (Identificación) — Total respuestas comparadas: <?php echo $total; ?></h6>
          <canvas id="idPie"></canvas>
        </div>

        <div class="col-md-6">
          <h6>Resumen</h6>
          <ul>
            <li>Correctas: <?php echo $matches; ?></li>
            <li>Incorrectas: <?php echo $incorrect; ?></li>
            <li>Porcentaje concordancia: <?php echo $total>0 ? round(($matches/$total)*100,2) : 0; ?>%</li>
          </ul>
        </div>
      </div>

      <?php
        // Antibiotic analysis per antibiotic
        $ab_sql = "SELECT ab.id AS antibiotic_id, ab.name AS antibiotic_name,
          COUNT(*) AS total,
          SUM(CASE WHEN ar_lab.interpretation = ar_ref.interpretation THEN 1 ELSE 0 END) AS matches,
          SUM(CASE WHEN ar_ref.interpretation='R' AND ar_lab.interpretation='S' THEN 1 ELSE 0 END) AS VME,
          SUM(CASE WHEN ar_ref.interpretation='S' AND ar_lab.interpretation='R' THEN 1 ELSE 0 END) AS ME,
          SUM(CASE WHEN (ar_ref.interpretation='I' AND ar_lab.interpretation IN ('S','R')) OR (ar_lab.interpretation='I' AND ar_ref.interpretation IN ('S','R')) THEN 1 ELSE 0 END) AS mE
          FROM responses r
          JOIN response_answers ra_lab ON ra_lab.response_id = r.id
          JOIN survey_questions q ON q.id = ra_lab.question_id AND q.question_type='antibiotic'
          JOIN antibiotics ab ON ab.id = q.antibiotic_id
          LEFT JOIN antibiotic_results ar_lab ON ar_lab.response_answer_id = ra_lab.id
          LEFT JOIN response_answers ra_ref ON ra_ref.response_id = :refid AND ra_ref.question_id = q.id
          LEFT JOIN antibiotic_results ar_ref ON ar_ref.response_answer_id = ra_ref.id
          WHERE r.survey_id = :sid AND r.lab_id IS NOT NULL
          GROUP BY ab.id, ab.name";
        $ast = $pdo->prepare($ab_sql); $ast->execute([':sid'=>$surveyId, ':refid'=>$refId]); $abrows = $ast->fetchAll();
      ?>

      <h5>Análisis por Antibiótico</h5>
      <table class="table table-sm table-bordered">
        <thead><tr><th>Antibiótico</th><th>Total</th><th>Coincide</th><th>% Coinc.</th><th>VME</th><th>ME</th><th>mE</th></tr></thead>
        <tbody>
          <?php foreach ($abrows as $ab): $tot=(int)$ab['total']; $mat=(int)$ab['matches']; ?>
            <tr>
              <td><?php echo htmlspecialchars($ab['antibiotic_name']); ?></td>
              <td><?php echo $tot; ?></td>
              <td><?php echo $mat; ?></td>
              <td><?php echo $tot>0 ? round(($mat/$tot)*100,2) : '0'; ?>%</td>
              <td><?php echo (int)$ab['VME']; ?></td>
              <td><?php echo (int)$ab['ME']; ?></td>
              <td><?php echo (int)$ab['mE']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php
        // Per-lab per-antibiotic summary (simple aggregation)
        $lab_sql = "SELECT l.id AS lab_id, l.name AS lab_name, ab.id AS antibiotic_id, ab.name AS antibiotic_name,
          COUNT(*) AS total,
          SUM(CASE WHEN ar_lab.interpretation = ar_ref.interpretation THEN 1 ELSE 0 END) AS matches
          FROM responses r
          JOIN labs l ON l.id = r.lab_id
          JOIN response_answers ra_lab ON ra_lab.response_id = r.id
          JOIN survey_questions q ON q.id = ra_lab.question_id AND q.question_type='antibiotic'
          JOIN antibiotics ab ON ab.id = q.antibiotic_id
          LEFT JOIN antibiotic_results ar_lab ON ar_lab.response_answer_id = ra_lab.id
          LEFT JOIN response_answers ra_ref ON ra_ref.response_id = :refid AND ra_ref.question_id = q.id
          LEFT JOIN antibiotic_results ar_ref ON ar_ref.response_answer_id = ra_ref.id
          WHERE r.survey_id = :sid AND r.lab_id IS NOT NULL
          GROUP BY l.id, ab.id
          ORDER BY l.name, ab.name";
        $lst = $pdo->prepare($lab_sql); $lst->execute([':sid'=>$surveyId, ':refid'=>$refId]); $labrows = $lst->fetchAll();
      ?>

      <h5>Resumen por Laboratorio</h5>
      <table class="table table-sm table-bordered">
        <thead><tr><th>Laboratorio</th><th>Antibiótico</th><th>Total</th><th>Coinc.</th><th>% Coinc.</th></tr></thead>
        <tbody>
          <?php foreach ($labrows as $lr): $tot=(int)$lr['total']; $mat=(int)$lr['matches']; ?>
            <tr>
              <td><?php echo htmlspecialchars($lr['lab_name']); ?></td>
              <td><?php echo htmlspecialchars($lr['antibiotic_name']); ?></td>
              <td><?php echo $tot; ?></td>
              <td><?php echo $mat; ?></td>
              <td><?php echo $tot>0 ? round(($mat/$tot)*100,2) : '0'; ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($surveyId>0): ?>
<script>
  const matches = <?php echo json_encode($matches ?? 0); ?>;
  const incorrect = <?php echo json_encode($incorrect ?? 0); ?>;
  const ctx = document.getElementById('idPie');
  if (ctx) {
    new Chart(ctx, { type: 'pie', data: { labels: ['Correctas','Incorrectas'], datasets: [{ data: [matches, incorrect], backgroundColor: ['#28a745','#dc3545'] }] } });
  }
</script>
<?php endif; ?>
</body></html>
