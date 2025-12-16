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

// Helper: load survey questions
$questions = [];
if ($surveyId>0) {
    $qs = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
    $qs->execute([':sid'=>$surveyId]); $questions = $qs->fetchAll();
}

// Build reference answers map (question_id => value or array for multiselect)
$ref_answers = [];
$ref_antibiotics = []; // question_id => ['raw'=>..., 'interp'=>...]
if ($refId) {
    $rqa = $pdo->prepare('SELECT * FROM response_answers WHERE response_id = :rid');
    $rqa->execute([':rid'=>$refId]);
    $rows = $rqa->fetchAll();
    foreach ($rows as $ra) {
        $qid = (int)$ra['question_id'];
        if (!isset($ref_answers[$qid])) $ref_answers[$qid] = [];
        if ($ra['option_id']) {
            // load option value/label
            $opt = $pdo->prepare('SELECT value, label FROM question_options WHERE id = :id LIMIT 1'); $opt->execute([':id'=>$ra['option_id']]); $o=$opt->fetch();
            $val = $o ? ($o['label'] ?: $o['value']) : 'option_'.$ra['option_id'];
            $ref_answers[$qid][] = $val;
        } elseif ($ra['answer_text'] !== null) {
            $ref_answers[$qid][] = $ra['answer_text'];
        } elseif ($ra['answer_number'] !== null) {
            $ref_answers[$qid][] = (string)$ra['answer_number'];
        }
    }
    // flatten singles
    foreach ($ref_answers as $k=>$v) $ref_answers[$k] = count($v)===1? $v[0] : $v;

    // reference antibiotic results
    $rab = $pdo->prepare('SELECT ar.*, ra.question_id FROM antibiotic_results ar JOIN response_answers ra ON ar.response_answer_id = ra.id WHERE ra.response_id = :rid');
    $rab->execute([':rid'=>$refId]);
    foreach ($rab->fetchAll() as $r) {
        $qid = (int)$r['question_id'];
        $ref_antibiotics[$qid] = ['raw'=>$r['raw_value'], 'interp'=>strtoupper(trim($r['interpretation'] ?? ''))];
    }
}

// Load lab responses' answers and antibiotic results
$lab_responses = []; // response_id => ['lab_id','lab_name','answers'=>[qid=>value(s)], 'antibiotics'=>[qid=>['raw','interp']]]
if ($surveyId>0) {
    $rstmt = $pdo->prepare('SELECT r.id AS response_id, r.lab_id, l.name AS lab_name FROM responses r LEFT JOIN labs l ON l.id=r.lab_id WHERE r.survey_id = :sid AND r.lab_id IS NOT NULL');
    $rstmt->execute([':sid'=>$surveyId]); $rrs = $rstmt->fetchAll();
    foreach ($rrs as $r) {
        $lab_responses[(int)$r['response_id']] = ['lab_id'=>$r['lab_id'],'lab_name'=>$r['lab_name'] ?? 'Desconocido','answers'=>[],'antibiotics'=>[]];
    }
    if (!empty($lab_responses)) {
        $rids = array_keys($lab_responses);
        $ph = implode(',', array_fill(0,count($rids),'?'));
        $q = $pdo->prepare("SELECT ra.* FROM response_answers ra WHERE ra.response_id IN ($ph)");
        $q->execute($rids);
        foreach ($q->fetchAll() as $ra) {
            $rid = (int)$ra['response_id']; $qid=(int)$ra['question_id'];
            if (!isset($lab_responses[$rid]['answers'][$qid])) $lab_responses[$rid]['answers'][$qid]=[];
            if ($ra['option_id']) {
                $opt = $pdo->prepare('SELECT value, label FROM question_options WHERE id = :id LIMIT 1'); $opt->execute([':id'=>$ra['option_id']]); $o=$opt->fetch();
                $val = $o ? ($o['label'] ?: $o['value']) : 'option_'.$ra['option_id'];
                $lab_responses[$rid]['answers'][$qid][] = $val;
            } elseif ($ra['answer_text'] !== null) {
                $lab_responses[$rid]['answers'][$qid][] = $ra['answer_text'];
            } elseif ($ra['answer_number'] !== null) {
                $lab_responses[$rid]['answers'][$qid][] = (string)$ra['answer_number'];
            }
        }
        foreach ($lab_responses as $rid=>$_) $lab_responses[$rid]['answers'] = array_map(function($v){ return count($v)===1? $v[0] : $v; }, $lab_responses[$rid]['answers']);

        // antibiotic results for labs
        $qar = $pdo->prepare("SELECT ar.*, ra.question_id, ra.response_id FROM antibiotic_results ar JOIN response_answers ra ON ar.response_answer_id = ra.id WHERE ra.response_id IN ($ph)");
        $qar->execute($rids);
        foreach ($qar->fetchAll() as $ar) {
            $rid = (int)$ar['response_id']; $qid = (int)$ar['question_id'];
            $lab_responses[$rid]['antibiotics'][$qid] = ['raw'=>$ar['raw_value'],'interp'=>strtoupper(trim($ar['interpretation'] ?? ''))];
        }
    }
}

// Prepare qualitative charts data
$qual_charts = []; // qid => ['labels'=>[], 'data'=>[], 'colors'=>[], 'ref'=>value]
foreach ($questions as $q) {
    $qid = (int)$q['id'];
    if (in_array($q['question_type'], ['text','select','multiselect'])) {
        $freq = [];
        foreach ($lab_responses as $rid=>$lr) {
            if (!isset($lr['answers'][$qid])) continue;
            $vals = $lr['answers'][$qid];
            if (!is_array($vals)) $vals = [$vals];
            foreach ($vals as $v) { if ($v===null || $v==='') $v='(vacío)'; $freq[$v] = ($freq[$v] ?? 0) + 1; }
        }
        arsort($freq);
        $labels = array_keys($freq); $data = array_values($freq);
        $refVal = $ref_answers[$qid] ?? null; if (is_array($refVal)) $refVal = implode(', ', $refVal);
        $colors = [];
        foreach ($labels as $lab) { $colors[] = ($refVal !== null && $lab == $refVal) ? '#28a745' : '#6c757d'; }
        $qual_charts[$qid] = ['title'=>$q['question_text'],'labels'=>$labels,'data'=>$data,'colors'=>$colors,'ref'=>$refVal];
    }
}

// Prepare antibiotic questions list
$antibiotic_questions = array_filter($questions, function($q){ return $q['question_type']==='antibiotic'; });

?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reporte de Calidad - Detallado</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head><body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between mb-3">
    <h4>Reporte de Calidad Detallado</h4>
    <div>
      <a href="admin_responses.php" class="btn btn-sm btn-outline-secondary">Volver</a>
    </div>
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
      <div class="col-md-4"><button class="btn btn-primary">Generar reporte</button></div>
    </div>
  </form>

  <?php if ($surveyId<=0): ?>
    <div class="alert alert-info">Seleccione una encuesta para generar el reporte.</div>
  <?php elseif (!$refId): ?>
    <div class="alert alert-warning">No se encontró un resultado de referencia para esta encuesta. Cargue uno en "Cargar resultados de referencia".</div>
  <?php else: ?>

    <!-- Qualitative questions charts -->
    <?php foreach ($qual_charts as $qid=>$chart): ?>
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title"><?php echo htmlspecialchars($chart['title']); ?></h5>
          <div class="row">
            <div class="col-md-8">
              <canvas id="chart_<?php echo $qid; ?>" height="120"></canvas>
            </div>
            <div class="col-md-4">
              <h6>Referencia</h6>
              <p><?php echo htmlspecialchars($chart['ref'] ?? '(sin referencia)'); ?></p>
              <h6>Frecuencia</h6>
              <ul>
                <?php foreach ($chart['labels'] as $i=>$lab): ?>
                  <li><?php echo htmlspecialchars($lab); ?>: <?php echo (int)$chart['data'][$i]; ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- Antibiotic detailed tables -->
    <h4>Preguntas de Antibióticos - Tabla de Desempeño</h4>
    <?php foreach ($antibiotic_questions as $q): $qid=(int)$q['id']; ?>
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title"><?php echo htmlspecialchars($q['question_text']); ?> — <?php echo htmlspecialchars($q['antibiotic_id'] ? ($q['antibiotic_id']) : 'Antibiótico'); ?></h5>
          <p class="small text-muted">Referencia: Valor <?php echo htmlspecialchars($ref_antibiotics[$qid]['raw'] ?? 'N/A'); ?> — Interpretación <?php echo htmlspecialchars($ref_antibiotics[$qid]['interp'] ?? 'N/A'); ?></p>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead><tr><th>Laboratorio</th><th>Valor Lab</th><th>Valor Ref</th><th>Desviación</th><th>Interp Lab</th><th>Interp Ref</th><th>Evaluación</th></tr></thead>
              <tbody>
                <?php foreach ($lab_responses as $rid=>$lr):
                    $labname = $lr['lab_name'];
                    $lab_ab = $lr['antibiotics'][$qid] ?? null;
                    if (!$lab_ab) continue; // skip labs without result for this question
                    $lab_raw = $lab_ab['raw']; $lab_interp = strtoupper($lab_ab['interp'] ?? '');
                    $ref_raw = $ref_antibiotics[$qid]['raw'] ?? null; $ref_interp = strtoupper($ref_antibiotics[$qid]['interp'] ?? '');
                    $dev = is_numeric($lab_raw) && is_numeric($ref_raw) ? round($lab_raw - $ref_raw,3) : 'N/A';
                    $evaluation = 'Concordante'; $bg = '';
                    if ($lab_interp === $ref_interp) { $evaluation = 'Concordante'; }
                    else {
                      if ($ref_interp === 'R' && $lab_interp === 'S') { $evaluation = 'VME'; $bg='#dc3545'; }
                      elseif ($ref_interp === 'S' && $lab_interp === 'R') { $evaluation = 'ME'; $bg='#fd7e14'; }
                      elseif (in_array('I', [$ref_interp, $lab_interp])) { $evaluation = 'mE'; $bg='#ffc107'; }
                      else { $evaluation = 'Discordante'; $bg='#f8d7da'; }
                    }
                ?>
                  <tr>
                    <td><?php echo htmlspecialchars($labname); ?></td>
                    <td><?php echo htmlspecialchars($lab_raw); ?></td>
                    <td><?php echo htmlspecialchars($ref_raw ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($dev); ?></td>
                    <td><?php echo htmlspecialchars($lab_interp); ?></td>
                    <td><?php echo htmlspecialchars($ref_interp); ?></td>
                    <td style="background: <?php echo $bg ?: 'transparent'; ?>"><?php echo htmlspecialchars($evaluation); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>
</div>

<?php if (!empty($qual_charts)): ?>
<script>
const charts = {};
<?php foreach ($qual_charts as $qid=>$c): ?>
  (function(){
    const ctx = document.getElementById('chart_<?php echo $qid; ?>');
    if (!ctx) return;
    const data = <?php echo json_encode(array_values($c['data'])); ?>;
    const labels = <?php echo json_encode(array_values($c['labels'])); ?>;
    const colors = <?php echo json_encode(array_values($c['colors'])); ?>;
    charts['<?php echo $qid; ?>'] = new Chart(ctx, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: 'Frecuencia', data: data, backgroundColor: colors }] },
      options: { responsive:true, plugins:{legend:{display:false}} }
    });
  })();
<?php endforeach; ?>
</script>
<?php endif; ?>

</body></html>
