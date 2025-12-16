<?php
// 1. ACTIVAR MODO DETECTIVE (Mostrar todos los errores en pantalla)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 2. CONEXIÓN A LA BASE DE DATOS
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) { 
    die("Error: No has iniciado sesión. <a href='index.php'>Ir al login</a>"); 
}

try { 
    $pdo = getPDO(); 
} catch (PDOException $e) { 
    die("Error Fatal de Conexión: " . $e->getMessage()); 
}

// 3. VERIFICACIÓN DE ROL (Super Admin)
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();

// Si no es admin, lo echamos
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) { 
    die("ACCESO DENEGADO. Tu rol es: " . ($roleRow['name'] ?? 'Desconocido') . ". Se requiere: super_admin."); 
}

// 4. CREAR TABLA AUTOMÁTICAMENTE (Si no existe)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reference_responses (
      survey_id INT PRIMARY KEY,
      response_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    die("<h1>ERROR CREANDO TABLA</h1><p>" . $e->getMessage() . "</p>");
}

// --- LÓGICA DEL FORMULARIO ---

$surveyId = isset($_REQUEST['survey_id']) ? (int)$_REQUEST['survey_id'] : 0;

// Cargar encuestas activas
$sStmt = $pdo->prepare('SELECT id, title FROM surveys WHERE is_active = 1 ORDER BY created_at DESC');
$sStmt->execute();
$surveys = $sStmt->fetchAll();

// PROCESAR GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_reference') {
    $surveyId = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
    
    if ($surveyId <= 0) { 
        die("Error: ID de encuesta inválido al guardar."); 
    }

    // Cargar preguntas para saber qué guardar
    $qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
    $qStmt->execute([':sid'=>$surveyId]);
    $questions = $qStmt->fetchAll();

    try {
        $pdo->beginTransaction();
        
        // 1. Crear la "Respuesta" (Response)
        // Usamos lab_id NULL porque es una referencia del sistema
        $ins = $pdo->prepare('INSERT INTO responses (survey_id, user_id, lab_id, status) VALUES (:sid, :uid, :lid, :st)');
        $ins->execute([
            ':sid' => $surveyId, 
            ':uid' => $_SESSION['user_id'], 
            ':lid' => null, 
            ':st' => 'submitted'
        ]);
        $response_id = $pdo->lastInsertId();

        // Preparar consultas para respuestas individuales
        $insAnswer = $pdo->prepare('INSERT INTO response_answers (response_id, question_id, option_id, answer_text, answer_number) VALUES (:rid,:qid,:optid,:txt,:num)');
        $findOption = $pdo->prepare('SELECT id FROM question_options WHERE question_id = :qid AND value = :val LIMIT 1');

        // 2. Guardar cada pregunta
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $name = 'ref_q_'.$qid; // El nombre del campo en el HTML

            // Lógica según tipo de pregunta
            if ($q['question_type'] === 'multiselect') {
                $vals = $_POST[$name] ?? [];
                if (!is_array($vals)) $vals = [$vals];
                foreach ($vals as $v) {
                    $findOption->execute([':qid'=>$qid,':val'=>$v]); 
                    $row = $findOption->fetch(); 
                    $optid = $row ? $row['id'] : null;
                    $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$optid,':txt'=>$v,':num'=>null]);
                }
                continue;
            }
            
            if ($q['question_type'] === 'select') {
                $v = $_POST[$name] ?? null; 
                $findOption->execute([':qid'=>$qid,':val'=>$v]); 
                $row=$findOption->fetch(); 
                $optid=$row?$row['id']:null;
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$optid,':txt'=>$v,':num'=>null]); 
                continue;
            }
            
            if ($q['question_type'] === 'text') { 
                $v = $_POST[$name] ?? null; 
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$v,':num'=>null]); 
                continue; 
            }
            
            if ($q['question_type'] === 'numeric') { 
                $v = $_POST[$name] ?? null; 
                $num = $v!==null && $v!=='' ? (float)str_replace(',', '.', $v) : null; 
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>null,':num'=>$num]); 
                continue; 
            }
            
            if ($q['question_type'] === 'antibiotic') {
                $raw = $_POST[$name.'_raw'] ?? null; 
                $interp = $_POST[$name.'_interpretation'] ?? null;
                $num = $raw!==null && $raw!=='' ? (float)str_replace(',', '.', $raw) : null;
                
                // Guardar respuesta general
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$interp,':num'=>$num]);
                $ra_id = $pdo->lastInsertId();
                
                // Guardar detalle en antibiotic_results (VITAL para estadísticas)
                if (!empty($q['antibiotic_id'])) {
                    $iar = $pdo->prepare('INSERT INTO antibiotic_results (response_answer_id, antibiotic_id, breakpoint_id, method, raw_value, unit, interpretation) VALUES (:ra,:ab,:bp,:m,:raw,:u,:interp)');
                    $iar->execute([
                        ':ra'=>$ra_id,
                        ':ab'=>$q['antibiotic_id'],
                        ':bp'=>null,
                        ':m'=>'disk', // Asumimos disco por defecto para la referencia
                        ':raw'=>$num!==null?$num:0,
                        ':u'=>'',
                        ':interp'=>$interp
                    ]);
                }
                continue;
            }
        }

        // 3. Vincular esta respuesta como "La Referencia Oficial"
        $up = $pdo->prepare('REPLACE INTO reference_responses (survey_id, response_id) VALUES (:sid, :rid)');
        $up->execute([':sid'=>$surveyId,':rid'=>$response_id]);

        $pdo->commit();
        
        // Redirigir con éxito
        echo "<script>alert('¡Referencia Guardada Correctamente!'); window.location.href='admin_reference_results.php?survey_id=$surveyId';</script>";
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("<h1>ERROR GUARDANDO:</h1><p>" . $e->getMessage() . "</p>");
    }
}

// Cargar preguntas para mostrar el formulario (HTML)
$questions = [];
if ($surveyId > 0) {
    $qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid ORDER BY display_order ASC, id ASC');
    $qStmt->execute([':sid'=>$surveyId]); 
    $questions = $qStmt->fetchAll();
    
    // Mapa de antibióticos para mostrar nombres reales
    $antibiotic_ids = [];
    foreach ($questions as $qq) if ($qq['question_type'] === 'antibiotic' && !empty($qq['antibiotic_id'])) $antibiotic_ids[] = (int)$qq['antibiotic_id'];
    $antibiotics_map = [];
    if (!empty($antibiotic_ids)) {
        $ph = implode(',', array_fill(0,count($antibiotic_ids),'?'));
        $a = $pdo->prepare("SELECT id,name FROM antibiotics WHERE id IN ($ph)"); 
        $a->execute($antibiotic_ids);
        foreach ($a->fetchAll() as $ar) $antibiotics_map[(int)$ar['id']] = $ar;
    }
}
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cargar Referencia (Modo Debug)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between mb-3">
    <h4>Cargar Resultado de Referencia (Patrón de Oro)</h4>
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Volver al Dashboard</a>
  </div>

  <form method="get" class="mb-3 card p-3">
    <label class="form-label fw-bold">1. Selecciona la Encuesta a Evaluar:</label>
    <div class="row g-2">
      <div class="col-md-8">
        <select name="survey_id" class="form-select" required>
          <option value="">-- Seleccione --</option>
          <?php foreach ($surveys as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php if ($s['id']==$surveyId) echo 'selected'; ?>>
                <?php echo htmlspecialchars($s['title']); ?> (ID: <?php echo $s['id']; ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary w-100">Cargar Formulario</button>
      </div>
    </div>
  </form>

  <?php if ($surveyId > 0): ?>
    <div class="card p-4 shadow-sm">
        <h5 class="card-title mb-4">2. Ingresa los Valores Correctos</h5>
        
        <form method="post">
          <input type="hidden" name="action" value="save_reference">
          <input type="hidden" name="survey_id" value="<?php echo (int)$surveyId; ?>">

          <?php if (empty($questions)): ?>
            <div class="alert alert-warning">Esta encuesta no tiene preguntas cargadas aún.</div>
          <?php else: foreach ($questions as $q): ?>
            <div class="mb-3 border-bottom pb-3">
              <label class="form-label fw-bold">
                  <?php echo htmlspecialchars($q['question_text']); ?>
              </label>
              
              <?php if ($q['question_type'] === 'text'): ?>
                <input type="text" name="ref_q_<?php echo (int)$q['id']; ?>" class="form-control" placeholder="Respuesta correcta esperada">
              
              <?php elseif ($q['question_type'] === 'numeric'): ?>
                <input type="number" step="any" name="ref_q_<?php echo (int)$q['id']; ?>" class="form-control">
              
              <?php elseif ($q['question_type'] === 'select' || $q['question_type'] === 'multiselect'): ?>
                <?php 
                    $opts = $pdo->prepare('SELECT * FROM question_options WHERE question_id = :qid ORDER BY display_order ASC'); 
                    $opts->execute([':qid'=>$q['id']]); 
                    $optsf = $opts->fetchAll(); 
                ?>
                <select name="<?php echo $q['question_type']==='multiselect' ? 'ref_q_'.(int)$q['id'].'[]' : 'ref_q_'.(int)$q['id']; ?>" <?php if ($q['question_type']==='multiselect') echo 'multiple'; ?> class="form-select">
                  <?php if ($q['question_type'] === 'select') echo '<option value="">-- selecciona --</option>'; ?>
                  <?php foreach ($optsf as $o): ?>
                    <option value="<?php echo htmlspecialchars($o['value']); ?>"><?php echo htmlspecialchars($o['label'] ?: $o['value']); ?></option>
                  <?php endforeach; ?>
                </select>
              
              <?php elseif ($q['question_type'] === 'antibiotic'): ?>
                <div class="alert alert-info py-1 mb-2">
                    <small>Antibiótico: <strong><?php echo htmlspecialchars($antibiotics_map[$q['antibiotic_id']]['name'] ?? 'Desconocido'); ?></strong></small>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="small text-muted">Valor Numérico (mm/CIM)</label>
                        <input type="number" step="any" name="ref_q_<?php echo (int)$q['id']; ?>_raw" class="form-control" placeholder="Ej: 25">
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted">Interpretación (S, I, R)</label>
                        <input type="text" name="ref_q_<?php echo (int)$q['id']; ?>_interpretation" class="form-control text-uppercase" placeholder="S / I / R" maxlength="1">
                    </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="d-grid gap-2">
             <button class="btn btn-success btn-lg" type="submit">GUARDAR REFERENCIA</button>
          </div>
        </form>
    <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
