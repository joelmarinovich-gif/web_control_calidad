<?php
// admin_statistics.php - REPORTE EQAS ISO 15189 (Histogramas + Errores + Z-SCORE)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/config/db.php';

// 1. Seguridad
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
try { $pdo = getPDO(); } catch (PDOException $e) { die("Error DB: " . $e->getMessage()); }

// Verificar Admin
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) { die("Acceso Denegado"); }

// 2. Cargar Datos
$surveys = $pdo->query('SELECT id, title FROM surveys WHERE is_active = 1 ORDER BY created_at DESC')->fetchAll();
$surveyId = isset($_REQUEST['survey_id']) ? (int)$_REQUEST['survey_id'] : 0;

$questions = [];
$reference_answers = []; 
$lab_responses = [];     

if ($surveyId > 0) {
    // Cargar Preguntas
    $qSql = "SELECT q.*, a.name as antibiotic_name 
             FROM survey_questions q 
             LEFT JOIN antibiotics a ON q.antibiotic_id = a.id
             WHERE q.survey_id = :sid 
             ORDER BY q.display_order ASC";
    $qStmt = $pdo->prepare($qSql);
    $qStmt->execute([':sid'=>$surveyId]);
    $questions = $qStmt->fetchAll();

    // Cargar REFERENCIA
    $refSql = "SELECT response_id FROM reference_responses WHERE survey_id = :sid LIMIT 1";
    $refStmt = $pdo->prepare($refSql);
    $refStmt->execute([':sid'=>$surveyId]);
    $refRow = $refStmt->fetch();

    if ($refRow) {
        $ansSql = "SELECT ra.*, ar.raw_value, ar.interpretation as ab_interp 
                   FROM response_answers ra
                   LEFT JOIN antibiotic_results ar ON ra.id = ar.response_answer_id
                   WHERE ra.response_id = :rid";
        $ansStmt = $pdo->prepare($ansSql);
        $ansStmt->execute([':rid'=>$refRow['response_id']]);
        foreach($ansStmt->fetchAll() as $r) {
            $reference_answers[$r['question_id']] = [
                'text' => $r['answer_text'],
                'number' => $r['raw_value'] ?? $r['answer_number'],
                'interpretation' => $r['ab_interp']
            ];
        }
    }

    // Cargar LABS
    $labSql = "SELECT r.lab_id, l.name as lab_name, ra.question_id, ra.answer_text, ra.answer_number, 
                      ar.raw_value, ar.interpretation as ab_interp
               FROM responses r
               JOIN labs l ON r.lab_id = l.id
               JOIN response_answers ra ON r.id = ra.response_id
               LEFT JOIN antibiotic_results ar ON ra.id = ar.response_answer_id
               WHERE r.survey_id = :sid AND r.status = 'submitted'";
    
    $labStmt = $pdo->prepare($labSql);
    $labStmt->execute([':sid'=>$surveyId]);
    foreach($labStmt->fetchAll() as $lr) {
        $lab_responses[$lr['question_id']][] = [
            'lab_name' => $lr['lab_name'],
            'val_text' => $lr['answer_text'],
            'val_num'  => $lr['raw_value'] ?? $lr['answer_number'],
            'interp'   => $lr['ab_interp']
        ];
    }
}

// Función auxiliar para Desviación Estándar
function calculate_sd($array) {
    $num_of_elements = count($array);
    if($num_of_elements <= 1) return 0; // Evitar división por cero
    $variance = 0.0;
    $average = array_sum($array)/$num_of_elements;
    foreach($array as $i) { $variance += pow(($i - $average), 2); }
    return (float)sqrt($variance/($num_of_elements-1));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte EQAS - ISO 15189</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.1.0"></script> 

    <style>
        .card-header { background-color: #f0f2f5; font-weight: bold; }
        /* Colores CLSI */
        .bg-vme { background-color: #dc3545; color: white; } 
        .bg-me { background-color: #fd7e14; color: white; }  
        .bg-me-min { background-color: #ffc107; color: black; } 
        .bg-ok { background-color: #198754; color: white; }
    </style>
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary fw-bold">📈 Análisis Estadístico & Z-Score</h2>
        <a href="dashboard.php" class="btn btn-outline-secondary">Volver</a>
    </div>

    <form method="get" class="card p-3 mb-4 shadow-sm">
        <label class="form-label fw-bold">Seleccionar Ronda / Encuesta:</label>
        <select name="survey_id" class="form-select" onchange="this.form.submit()">
            <option value="">-- Seleccione --</option>
            <?php foreach($surveys as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php if($s['id'] == $surveyId) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($s['title']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if($surveyId > 0 && !empty($questions)): ?>
        
        <?php if(empty($reference_answers)): ?>
            <div class="alert alert-danger">⚠️ <strong>Falta Referencia:</strong> Carga el 'Patrón de Oro' en el menú de Admin para habilitar el cálculo de Z-Score.</div>
        <?php endif; ?>

        <?php foreach($questions as $q): 
            $qid = $q['id'];
            $type = $q['question_type'];
            $ref = $reference_answers[$qid] ?? null;
            $labs = $lab_responses[$qid] ?? [];
            $totalLabs = count($labs);
            
            $titulo = $q['question_text'];
            if ($type === 'antibiotic' && !empty($q['antibiotic_name'])) {
                $titulo = "Antibiótico: " . strtoupper($q['antibiotic_name']);
            }
        ?>

        <div class="card mb-5 shadow border-0">
            <div class="card-header py-3 d-flex justify-content-between">
                <h5 class="m-0"><?php echo htmlspecialchars($titulo); ?></h5>
                <span class="badge bg-dark"><?php echo $totalLabs; ?> Participantes</span>
            </div>
            <div class="card-body">

                <?php if ($type === 'antibiotic'): ?>
                    <?php 
                        // A. CÁLCULOS ESTADÍSTICOS BÁSICOS
                        $stats = ['VME'=>0, 'ME'=>0, 'mE'=>0, 'OK'=>0];
                        $frequencies = [];
                        $all_values_for_sd = [];
                        
                        $refVal = $ref ? (float)$ref['number'] : null;
                        $refInterp = $ref ? strtoupper(trim($ref['interpretation'])) : '';

                        // Extraer valores para calcular SD del grupo
                        foreach($labs as $l) {
                            if($l['val_num'] !== "" && $l['val_num'] !== null) {
                                $all_values_for_sd[] = (float)$l['val_num'];
                            }
                        }
                        
                        // Cálculo de Desviación Estándar (SD) del Grupo
                        $groupSD = calculate_sd($all_values_for_sd);
                        if ($groupSD == 0) $groupSD = 0.000001; // Evitar div/0 si todos coinciden perfecto

                        // B. PROCESAR CADA LAB (Z-Score y Errores)
                        $zScoreData = []; // Para el gráfico
                        $zScoreLabels = [];

                        foreach($labs as $k => $l) {
                            // 1. Histograma
                            $valStr = (string)$l['val_num'];
                            if($valStr !== "") {
                                if(!isset($frequencies[$valStr])) $frequencies[$valStr] = 0;
                                $frequencies[$valStr]++;
                            }

                            // 2. Cálculo Z-SCORE: (ValorLab - ValorRef) / SD
                            $z = 0;
                            if ($refVal !== null && $l['val_num'] !== null) {
                                $z = ((float)$l['val_num'] - $refVal) / $groupSD;
                            }
                            $labs[$k]['z_score'] = round($z, 2);

                            // Datos para Gráfico Z
                            $zScoreLabels[] = $l['lab_name'];
                            $zScoreData[] = round($z, 2);

                            // 3. Clasificación Error CLSI
                            $labInterp = strtoupper(trim($l['interp']));
                            $errorType = 'OK';
                            if ($ref) {
                                if ($refInterp == $labInterp) $errorType = 'OK';
                                elseif ($refInterp == 'R' && $labInterp == 'S') $errorType = 'VME';
                                elseif ($refInterp == 'S' && $labInterp == 'R') $errorType = 'ME';
                                else $errorType = 'mE';
                            }
                            $labs[$k]['error_type'] = $errorType;
                            $stats[$errorType]++;
                        }
                        ksort($frequencies, SORT_NUMERIC);
                    ?>

                    <div class="row mb-4">
                        <div class="col-md-8">
                            <h6 class="text-center text-muted small text-uppercase">Distribución de Valores (Histograma)</h6>
                            <div style="height: 200px;"><canvas id="hist_<?php echo $qid; ?>"></canvas></div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-center text-muted small text-uppercase">Evaluación CLSI</h6>
                            <div style="height: 200px;"><canvas id="bar_<?php echo $qid; ?>"></canvas></div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card border-primary">
                                <div class="card-header bg-white text-primary text-center">
                                    <strong>Z-SCORE PLOT (Desempeño Cuantitativo)</strong><br>
                                    <small class="text-muted">Fórmula: (Resultado Lab - Asignado) / SD Grupo</small>
                                </div>
                                <div class="card-body">
                                    <div style="height: 300px;"><canvas id="zchart_<?php echo $qid; ?>"></canvas></div>
                                    <div class="mt-2 text-center small">
                                        <span class="badge bg-success">|Z| ≤ 2 (Aceptable)</span>
                                        <span class="badge bg-warning text-dark">2 < |Z| < 3 (Alerta)</span>
                                        <span class="badge bg-danger">|Z| ≥ 3 (No Satisfactorio)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        // A. HISTOGRAMA
                        <?php 
                            $hLabels = array_keys($frequencies);
                            $hData = array_values($frequencies);
                            $hColors = [];
                            foreach($hLabels as $lbl) {
                                $hColors[] = ($refVal !== null && (float)$lbl == $refVal) ? '#198754' : '#adb5bd';
                            }
                        ?>
                        new Chart(document.getElementById('hist_<?php echo $qid; ?>'), {
                            type: 'bar',
                            data: { labels: <?php echo json_encode($hLabels); ?>, datasets: [{ data: <?php echo json_encode($hData); ?>, backgroundColor: <?php echo json_encode($hColors); ?> }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: {display: false} } }
                        });

                        // B. BARRAS CLSI
                        new Chart(document.getElementById('bar_<?php echo $qid; ?>'), {
                            type: 'bar',
                            data: { labels: ['Eval'], datasets: [
                                { label: 'OK', data: [<?php echo $stats['OK']; ?>], backgroundColor: '#198754' },
                                { label: 'mE', data: [<?php echo $stats['mE']; ?>], backgroundColor: '#ffc107' },
                                { label: 'ME', data: [<?php echo $stats['ME']; ?>], backgroundColor: '#fd7e14' },
                                { label: 'VME', data: [<?php echo $stats['VME']; ?>], backgroundColor: '#dc3545' }
                            ]},
                            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, display: false } } }
                        });

                        // C. Z-SCORE CHART (EL NUEVO REY)
                        <?php
                            $zColors = [];
                            foreach($zScoreData as $z) {
                                $absZ = abs($z);
                                if ($absZ <= 2) $zColors[] = '#198754'; // Verde
                                elseif ($absZ < 3) $zColors[] = '#ffc107'; // Amarillo
                                else $zColors[] = '#dc3545'; // Rojo
                            }
                        ?>
                        new Chart(document.getElementById('zchart_<?php echo $qid; ?>'), {
                            type: 'bar',
                            data: {
                                labels: <?php echo json_encode($zScoreLabels); ?>,
                                datasets: [{
                                    label: 'Z-Score',
                                    data: <?php echo json_encode($zScoreData); ?>,
                                    backgroundColor: <?php echo json_encode($zColors); ?>,
                                    borderColor: '#000',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: {
                                        title: { display: true, text: 'Valor Z' },
                                        suggestedMin: -4,
                                        suggestedMax: 4,
                                        grid: { color: (ctx) => (Math.abs(ctx.tick.value) === 2 || Math.abs(ctx.tick.value) === 3) ? '#ff0000' : '#e5e5e5', lineWidth: (ctx) => (Math.abs(ctx.tick.value) === 2 || Math.abs(ctx.tick.value) === 3) ? 2 : 1 }
                                    }
                                },
                                plugins: {
                                    annotation: {
                                        annotations: {
                                            line1: { type: 'line', yMin: 2, yMax: 2, borderColor: 'orange', borderWidth: 2, borderDash: [5, 5], label: { enabled: true, content: '+2 SD' } },
                                            line2: { type: 'line', yMin: -2, yMax: -2, borderColor: 'orange', borderWidth: 2, borderDash: [5, 5] },
                                            line3: { type: 'line', yMin: 3, yMax: 3, borderColor: 'red', borderWidth: 2, label: { enabled: true, content: '+3 SD' } },
                                            line4: { type: 'line', yMin: -3, yMax: -3, borderColor: 'red', borderWidth: 2 }
                                        }
                                    }
                                }
                            }
                        });
                    </script>

                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-bordered text-center align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Participante</th>
                                    <th>Valor (mm/CIM)</th>
                                    <th>Interp</th>
                                    <th>Z-Score</th>
                                    <th>Evaluación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-ref fw-bold">
                                    <td>REFERENCIA (SD: <?php echo round($groupSD, 2); ?>)</td>
                                    <td><?php echo $ref['number']; ?></td>
                                    <td><?php echo $ref['interpretation']; ?></td>
                                    <td>0.00</td>
                                    <td>PATRÓN</td>
                                </tr>
                                <?php foreach($labs as $l): 
                                    $zClass = '';
                                    if(abs($l['z_score']) > 3) $zClass = 'text-danger fw-bold';
                                    elseif(abs($l['z_score']) > 2) $zClass = 'text-warning fw-bold';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($l['lab_name']); ?></td>
                                    <td><?php echo $l['val_num']; ?></td>
                                    <td><?php echo $l['interp']; ?></td>
                                    <td class="<?php echo $zClass; ?>"><?php echo $l['z_score']; ?></td>
                                    <td>
                                        <?php if($l['error_type']=='OK'): ?> <span class="badge bg-success">Concordante</span>
                                        <?php elseif($l['error_type']=='mE'): ?> <span class="badge bg-warning text-dark">mE</span>
                                        <?php elseif($l['error_type']=='ME'): ?> <span class="badge bg-me">ME</span>
                                        <?php elseif($l['error_type']=='VME'): ?> <span class="badge bg-danger">VME</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>
                    <?php 
                        $counts = array_count_values(array_column($labs, 'val_text'));
                        $labels = array_keys($counts);
                        $data = array_values($counts);
                        $bgColors = [];
                        $refTxt = $ref ? $ref['text'] : '';
                        foreach($labels as $lbl) $bgColors[] = ($lbl === $refTxt) ? '#198754' : '#6c757d';
                    ?>
                    <div style="height: 250px;"><canvas id="chart_<?php echo $qid; ?>"></canvas></div>
                    <script>
                        new Chart(document.getElementById('chart_<?php echo $qid; ?>'), {
                            type: 'bar',
                            data: { labels: <?php echo json_encode($labels); ?>, datasets: [{ label: 'Respuestas', data: <?php echo json_encode($data); ?>, backgroundColor: <?php echo json_encode($bgColors); ?> }] },
                            options: { maintainAspectRatio: false, plugins: { legend: {display: false} } }
                        });
                    </script>
                <?php endif; ?>

            </div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
</body>
</html>