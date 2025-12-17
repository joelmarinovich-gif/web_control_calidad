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

// Recolectar preguntas tipo 'antibiotic' y los antibioticos involucrados
$question_to_antibiotic = [];
$antibiotic_ids = [];
foreach ($questions as $qq) {
  if ($qq['question_type'] === 'antibiotic' && !empty($qq['antibiotic_id'])) {
    $question_to_antibiotic[(int)$qq['id']] = (int)$qq['antibiotic_id'];
    // map question => preferred method if set
    $question_to_method[(int)$qq['id']] = $qq['antibiotic_method'] ?? null;
    $antibiotic_ids[] = (int)$qq['antibiotic_id'];
  }
}
$antibiotic_ids = array_values(array_unique($antibiotic_ids));

// Consultar breakpoints para los antibioticos que aparecen en la encuesta
$breakpoints = [];
if (!empty($antibiotic_ids)) {
  $placeholders = implode(',', array_fill(0, count($antibiotic_ids), '?'));
  $bpStmt = $pdo->prepare("SELECT * FROM breakpoints WHERE antibiotic_id IN ($placeholders)");
  $bpStmt->execute($antibiotic_ids);
  $bpRows = $bpStmt->fetchAll();
  foreach ($bpRows as $r) {
    $aid = (int)$r['antibiotic_id'];
    // normalizar tipos numéricos a float|null para JSON
    $r['s_upper'] = $r['s_upper'] !== null ? (float)$r['s_upper'] : null;
    $r['i_lower'] = $r['i_lower'] !== null ? (float)$r['i_lower'] : null;
    $r['i_upper'] = $r['i_upper'] !== null ? (float)$r['i_upper'] : null;
    $r['r_lower'] = $r['r_lower'] !== null ? (float)$r['r_lower'] : null;
    if (!isset($breakpoints[$aid])) $breakpoints[$aid] = [];
    $breakpoints[$aid][] = $r;
  }
}

// Cargar datos de antibióticos (map id -> nombre) para mostrar en el formulario
$antibiotics_map = [];
if (!empty($antibiotic_ids)) {
  $ph = implode(',', array_fill(0, count($antibiotic_ids), '?'));
  $aStmt = $pdo->prepare("SELECT id, name, abbreviation FROM antibiotics WHERE id IN ($ph)");
  $aStmt->execute($antibiotic_ids);
  $aRows = $aStmt->fetchAll();
  foreach ($aRows as $ar) {
    $antibiotics_map[(int)$ar['id']] = $ar;
  }
}

function fetchOptions($pdo, $questionId) {
  $s = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY display_order ASC, id ASC');
  $s->execute([':qid' => $questionId]);
  return $s->fetchAll();
}

// Verificación de envío previo: si existe un response para este usuario/encuesta
$already_submitted = false;
$existing_response_id = null;
$existing_response_status = null;
if (isset($_SESSION['user_id']) && $surveyId > 0) {
  $checkStmt = $pdo->prepare('SELECT id, status FROM responses WHERE survey_id = :sid AND user_id = :uid LIMIT 1');
  $checkStmt->execute([':sid' => $surveyId, ':uid' => $_SESSION['user_id']]);
  $row = $checkStmt->fetch();
  if ($row) {
    $already_submitted = true;
    $existing_response_id = (int)$row['id'];
    $existing_response_status = $row['status'];
  }
}

// If already submitted, load the answers for read-only display
$user_answers = [];
$user_antibiotics = [];
if ($already_submitted && $existing_response_id) {
  $raStmt = $pdo->prepare('SELECT * FROM response_answers WHERE response_id = :rid ORDER BY id ASC');
  $raStmt->execute([':rid' => $existing_response_id]);
  $ras = $raStmt->fetchAll();
  foreach ($ras as $ra) {
    $qid = (int)$ra['question_id'];
    if (!isset($user_answers[$qid])) $user_answers[$qid] = [];
    if (!empty($ra['option_id'])) {
      $opt = $pdo->prepare('SELECT label, value FROM question_options WHERE id = :id LIMIT 1'); $opt->execute([':id'=>$ra['option_id']]); $o = $opt->fetch();
      $val = $o ? ($o['label'] ?: $o['value']) : 'option_'.$ra['option_id'];
      $user_answers[$qid][] = $val;
    } elseif ($ra['answer_text'] !== null) {
      $user_answers[$qid][] = $ra['answer_text'];
    } elseif ($ra['answer_number'] !== null) {
      $user_answers[$qid][] = (string)$ra['answer_number'];
    }
  }
  foreach ($user_answers as $k=>$v) $user_answers[$k] = count($v)===1? $v[0] : $v;

  // antibiotic results
  $abStmt = $pdo->prepare('SELECT ar.*, ra.question_id FROM antibiotic_results ar JOIN response_answers ra ON ar.response_answer_id = ra.id WHERE ra.response_id = :rid');
  $abStmt->execute([':rid'=>$existing_response_id]);
  foreach ($abStmt->fetchAll() as $ar) {
    $qid = (int)$ar['question_id'];
    $user_antibiotics[$qid] = ['raw'=>$ar['raw_value'],'interp'=>strtoupper(trim($ar['interpretation'] ?? ''))];
  }
}
// DEBUG: show quick diagnostics (temporal)
$debug_info = [
  'session_user_id' => $_SESSION['user_id'] ?? null,
  'existing_response_id' => $existing_response_id ?? null,
  'existing_response_status' => $existing_response_status ?? null,
  'user_answers_count' => count($user_answers),
  'user_antibiotics_count' => count($user_antibiotics),
];
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

      <?php if ($already_submitted && $existing_response_status === 'submitted'): ?>
        <div class="alert alert-warning">Ya has enviado esta encuesta. Debajo están tus respuestas en modo solo lectura. Si detectas un error, solicita que se reabra tu envío.</div>

        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">Tus respuestas (solo lectura)</h5>
            <?php if (!empty($questions)): foreach ($questions as $q): $qid=(int)$q['id']; ?>
              <div class="mb-2">
                <label class="form-label fw-bold"><?php echo htmlspecialchars($q['question_text']); ?></label>
                <div class="form-control" style="background:#f8f9fa;">
                  <?php
                    if ($q['question_type'] === 'antibiotic') {
                      $ab = $user_antibiotics[$qid] ?? null;
                      // Obtenemos el nombre del mapa de antibióticos cargado previamente
                      $abName = $antibiotics_map[$q['antibiotic_id']]['name'] ?? 'Antibiótico';
                      
                      echo '<div class="fw-bold text-primary mb-1">' . htmlspecialchars($abName) . '</div>';
                      echo 'Valor: ' . htmlspecialchars($ab['raw'] ?? '(sin dato)') . ' — Interp: ' . htmlspecialchars($ab['interp'] ?? '(sin dato)');
                    } else {
                      $val = $user_answers[$qid] ?? '(sin respuesta)';
                      if (is_array($val)) echo htmlspecialchars(implode(', ', $val)); else echo htmlspecialchars($val);
                    }
                  ?>
                </div>
              </div>
            <?php endforeach; else: ?>
              <div class="text-muted">No hay preguntas definidas.</div>
            <?php endif; ?>

            <form method="post" action="request_reopen.php" class="mt-3">
              <input type="hidden" name="response_id" value="<?php echo (int)$existing_response_id; ?>">
              <div class="mb-2">
                <label class="form-label">Motivo de la solicitud (opcional)</label>
                <textarea name="reason" class="form-control" rows="2" placeholder="Explique por qué solicita reabrir este envío..."></textarea>
              </div>
              <div class="d-flex justify-content-end">
                <button class="btn btn-warning" type="submit" onclick="return confirm('Enviar solicitud para reabrir envío al administrador?');">Solicitar reabrir envío</button>
              </div>
            </form>
          </div>
        </div>

      <?php elseif ($already_submitted && $existing_response_status === 'draft'): ?>
        <!-- Editable form: there's an existing draft response for this user, preload values and allow editing -->
        <div class="alert alert-info">Tienes un envío en borrador; edita tus respuestas y pulsa Enviar para actualizarlo.</div>
        <form method="post" action="responses_submit.php" onsubmit="return confirmSubmit();">
          <input type="hidden" name="survey_id" value="<?php echo (int)$surveyId; ?>">
          <input type="hidden" name="existing_response_id" value="<?php echo (int)$existing_response_id; ?>">

          <?php if (empty($questions)): ?>
            <div class="alert alert-info">Esta encuesta no tiene preguntas definidas.</div>
          <?php else: ?>
            <?php foreach ($questions as $q): $qid=(int)$q['id']; ?>
              <div class="mb-3">
                <label class="form-label"><?php echo htmlspecialchars($q['question_text']); ?>
                  <?php if ($q['required']): ?> <span class="text-danger">*</span><?php endif; ?>
                </label>

                <?php if ($q['question_type'] === 'text'): ?>
                  <input type="text" class="form-control" name="q_<?php echo $qid; ?>" value="<?php echo htmlspecialchars($user_answers[$qid] ?? ''); ?>" <?php echo $q['max_length'] ? 'maxlength="'.(int)$q['max_length'].'"' : ''; ?> <?php echo $q['required'] ? 'required' : ''; ?> >

                <?php elseif ($q['question_type'] === 'numeric'): ?>
                  <input type="number" step="any" class="form-control" name="q_<?php echo $qid; ?>" value="<?php echo htmlspecialchars($user_answers[$qid] ?? ''); ?>" <?php echo $q['required'] ? 'required' : ''; ?> >

                <?php elseif ($q['question_type'] === 'select' || $q['question_type'] === 'multiselect'): ?>
                  <?php $opts = fetchOptions($pdo, $q['id']); $uval = $user_answers[$qid] ?? null; if (!is_array($uval) && $uval !== null) $uval = [$uval]; ?>
                  <select class="form-select" name="<?php echo $q['question_type'] === 'multiselect' ? 'q_'. $qid .'[]' : 'q_'. $qid; ?>" <?php echo $q['question_type'] === 'multiselect' ? 'multiple' : ''; ?> <?php echo $q['required'] ? 'required' : ''; ?>>
                    <?php if ($q['question_type'] === 'select'): ?><option value="">-- Seleccione --</option><?php endif; ?>
                    <?php foreach ($opts as $o): $ov = $o['value']; $selected = in_array($ov, (array)$uval) ? 'selected' : ''; ?>
                      <option value="<?php echo htmlspecialchars($ov); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($o['label'] ?: $ov); ?></option>
                    <?php endforeach; ?>
                  </select>

                <?php elseif ($q['question_type'] === 'antibiotic'): ?>
                  <div class="mb-1"><strong><?php echo htmlspecialchars($antibiotics_map[$q['antibiotic_id']]['name'] ?? 'Antibiótico'); ?></strong></div>
                  <div class="row g-2">
                    <div class="col-md-4">
                      <input type="number" step="any" class="form-control" name="q_<?php echo $qid; ?>_raw" placeholder="Valor (halo / CIM)" value="<?php echo htmlspecialchars($user_antibiotics[$qid]['raw'] ?? ''); ?>" <?php echo $q['required'] ? 'required' : ''; ?> >
                    </div>
                    <div class="col-md-4">
                      <input type="text" class="form-control" name="q_<?php echo $qid; ?>_interpretation" placeholder="Interpretación (S/I/R)" value="<?php echo htmlspecialchars($user_antibiotics[$qid]['interp'] ?? ''); ?>" maxlength="1">
                    </div>
                  </div>

                <?php else: ?>
                  <input type="text" class="form-control" name="q_<?php echo $qid; ?>" value="<?php echo htmlspecialchars($user_answers[$qid] ?? ''); ?>">
                <?php endif; ?>

                <?php if (!empty($q['help_text'])): ?><div class="form-text"><?php echo htmlspecialchars($q['help_text']); ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <div class="d-flex justify-content-end">
            <button class="btn btn-primary" type="submit">Enviar Resultados</button>
          </div>
        </form>

      <?php else: ?>
      <form method="post" action="responses_submit.php" onsubmit="return confirmSubmit();">
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
                <div class="mb-1"><strong><?php echo htmlspecialchars($antibiotics_map[$q['antibiotic_id']]['name'] ?? 'Antibiótico'); ?></strong></div>
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
      <?php endif; ?>
    </div>
  </body>
  <script>
    const breakpoints = <?php echo json_encode($breakpoints, JSON_THROW_ON_ERROR); ?>;
    const questionToAntibiotic = <?php echo json_encode($question_to_antibiotic, JSON_THROW_ON_ERROR); ?>;
    const questionToMethod = <?php echo json_encode($question_to_method ?? [], JSON_THROW_ON_ERROR); ?>;

    // Confirmación de envío del formulario
    function confirmSubmit() {
      return confirm('¿Estás seguro que quieres enviar la encuesta? Una vez enviada no podrás modificarla');
    }

    document.addEventListener('DOMContentLoaded', function () {
      // helper: pick preferred breakpoint (CLSI > EUCAST > LOCAL > first)
      function pickBreakpoint(list) {
        if (!Array.isArray(list) || list.length === 0) return null;
        const pref = ['CLSI','EUCAST','LOCAL'];
        for (const p of pref) {
          const found = list.find(b => b.standard === p);
          if (found) return found;
        }
        return list[0];
      }

      function interpretValue(rawStr, bp) {
        if (rawStr === null || rawStr === '') return '';
        const raw = parseFloat(String(rawStr).replace(',', '.'));
        if (Number.isNaN(raw) || !bp) return 'U';

        const method = bp.method; // 'disk' or 'mic'
        const s = bp.s_upper;
        const il = bp.i_lower;
        const iu = bp.i_upper;
        const r = bp.r_lower;

        if (method === 'disk') {
          if (s !== null && raw >= s) return 'S';
          if (il !== null && iu !== null && raw >= il && raw <= iu) return 'I';
          if (r !== null && raw <= r) return 'R';
        } else { // mic
          if (s !== null && raw <= s) return 'S';
          if (il !== null && iu !== null && raw >= il && raw <= iu) return 'I';
          if (r !== null && raw >= r) return 'R';
        }
        return 'U';
      }

      function applyVisual($interpInput, code) {
        // limpiar clases previas
        $interpInput.classList.remove('border-success','text-success','border-danger','text-danger','border-warning','text-warning');
        if (!code) return;
        if (code === 'S') {
          $interpInput.classList.add('border-success','text-success');
        } else if (code === 'R') {
          $interpInput.classList.add('border-danger','text-danger');
        } else if (code === 'I') {
          $interpInput.classList.add('border-warning','text-warning');
        }
      }

      // Attach listeners to all antibiotic raw inputs
      document.querySelectorAll('input[name$="_raw"]').forEach(function (el) {
          el.addEventListener('input', function (ev) {
          const name = ev.target.name; // q_<id>_raw
          const m = name.match(/^q_(\d+)_raw$/);
          if (!m) return;
          const qid = parseInt(m[1], 10);
          const abId = questionToAntibiotic[qid];
          const bps = breakpoints[abId] || [];
          // prefer method declared on the question if present
          const qMethod = questionToMethod[qid] || '';
          let bp = null;
          if (qMethod) {
            const filtered = bps.filter(b => (b.method || '') === qMethod);
            if (filtered.length) bp = pickBreakpoint(filtered);
          }
          if (!bp) bp = pickBreakpoint(bps);
          const val = ev.target.value;
          const code = interpretValue(val, bp);
          const interpInput = document.querySelector('input[name="q_' + qid + '_interpretation"]');
          if (interpInput) {
            interpInput.value = code === 'U' ? '' : code;
            applyVisual(interpInput, code);
          }
        });
      });
    });
  </script>
</html>
