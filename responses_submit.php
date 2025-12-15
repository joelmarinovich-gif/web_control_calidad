<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user_dashboard.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userLabId = $_SESSION['lab_id'] ?? null;

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    $_SESSION['flash_danger'] = 'Error de conexión a la base de datos.';
    header('Location: user_dashboard.php');
    exit;
}

$surveyId = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
if ($surveyId <= 0) {
    $_SESSION['flash_danger'] = 'Encuesta inválida.';
    header('Location: user_dashboard.php');
    exit;
}

// Cargar preguntas de la encuesta
$qStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = :sid');
$qStmt->execute([':sid' => $surveyId]);
$questions = $qStmt->fetchAll();

// Preparar breakpoints map para cálculos (por antibiotic_id)
$antibiotic_ids = [];
foreach ($questions as $qq) {
    if ($qq['question_type'] === 'antibiotic' && !empty($qq['antibiotic_id'])) $antibiotic_ids[] = (int)$qq['antibiotic_id'];
}
$antibiotic_ids = array_values(array_unique($antibiotic_ids));
$breakpoints_map = [];
if (!empty($antibiotic_ids)) {
    $placeholders = implode(',', array_fill(0, count($antibiotic_ids), '?'));
    $bpStmt = $pdo->prepare("SELECT * FROM breakpoints WHERE antibiotic_id IN ($placeholders)");
    $bpStmt->execute($antibiotic_ids);
    $bps = $bpStmt->fetchAll();
    foreach ($bps as $b) {
        $aid = (int)$b['antibiotic_id'];
        if (!isset($breakpoints_map[$aid])) $breakpoints_map[$aid] = [];
        // normalize numeric
        $b['s_upper'] = $b['s_upper'] !== null ? (float)$b['s_upper'] : null;
        $b['i_lower'] = $b['i_lower'] !== null ? (float)$b['i_lower'] : null;
        $b['i_upper'] = $b['i_upper'] !== null ? (float)$b['i_upper'] : null;
        $b['r_lower'] = $b['r_lower'] !== null ? (float)$b['r_lower'] : null;
        $breakpoints_map[$aid][] = $b;
    }
}

// helper: pick preferred breakpoint (CLSI > EUCAST > LOCAL > first)
function pick_breakpoint(array $list) {
    if (empty($list)) return null;
    $pref = ['CLSI','EUCAST','LOCAL'];
    foreach ($pref as $p) {
        foreach ($list as $b) if ($b['standard'] === $p) return $b;
    }
    return $list[0];
}

function interpret_using_bp($raw, $bp) {
    if ($bp === null) return 'U';
    if ($raw === null || $raw === '') return 'U';
    $raw = floatval(str_replace(',', '.', $raw));
    if (!is_numeric($raw)) return 'U';
    $method = $bp['method'];
    $s = $bp['s_upper'];
    $il = $bp['i_lower'];
    $iu = $bp['i_upper'];
    $r = $bp['r_lower'];
    if ($method === 'disk') {
        if ($s !== null && $raw >= $s) return 'S';
        if ($il !== null && $iu !== null && $raw >= $il && $raw <= $iu) return 'I';
        if ($r !== null && $raw <= $r) return 'R';
    } else { // mic
        if ($s !== null && $raw <= $s) return 'S';
        if ($il !== null && $iu !== null && $raw >= $il && $raw <= $iu) return 'I';
        if ($r !== null && $raw >= $r) return 'R';
    }
    return 'U';
}

// Comprobación de doble envío en backend: evitar envíos forzados
$checkStmt = $pdo->prepare('SELECT id FROM responses WHERE survey_id = :sid AND user_id = :uid LIMIT 1');
$checkStmt->execute([':sid' => $surveyId, ':uid' => $userId]);
if ($checkStmt->fetch()) {
    $_SESSION['flash_danger'] = 'Ya has enviado esta encuesta. Si cometiste un error, contacta al administrador para que la rehabilite.';
    header('Location: survey_form.php?id=' . $surveyId);
    exit;
}

try {
    $pdo->beginTransaction();

    // Insert response
    $ins = $pdo->prepare('INSERT INTO responses (survey_id, user_id, lab_id, status) VALUES (:sid, :uid, :lid, :st)');
    $ins->execute([':sid'=>$surveyId, ':uid'=>$userId, ':lid'=>$userLabId, ':st'=>'submitted']);
    $response_id = $pdo->lastInsertId();

    // Prepare statements
    $insAnswer = $pdo->prepare('INSERT INTO response_answers (response_id, question_id, option_id, answer_text, answer_number) VALUES (:rid,:qid,:optid,:txt,:num)');
    $findOption = $pdo->prepare('SELECT id FROM question_options WHERE question_id = :qid AND value = :val LIMIT 1');
    $insAbRes = $pdo->prepare('INSERT INTO antibiotic_results (response_answer_id, antibiotic_id, breakpoint_id, method, raw_value, unit, interpretation) VALUES (:ra_id,:ab_id,:bp_id,:method,:raw,:unit,:interp)');

    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $qname = 'q_'.$qid;
        $option_id = null;
        $answer_text = null;
        $answer_number = null;

        if ($q['question_type'] === 'multiselect') {
            $vals = $_POST[$qname] ?? [];
            if (!is_array($vals)) $vals = [$vals];
            foreach ($vals as $val) {
                $answer_text = (string)$val;
                // try to find option id by value
                $findOption->execute([':qid'=>$qid, ':val'=>$val]);
                $row = $findOption->fetch();
                $optid = $row ? $row['id'] : null;
                $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$optid,':txt'=>$answer_text,':num'=>null]);
            }
            continue;
        }

        if ($q['question_type'] === 'select') {
            $val = $_POST[$qname] ?? '';
            $answer_text = $val;
            $findOption->execute([':qid'=>$qid, ':val'=>$val]);
            $row = $findOption->fetch();
            $option_id = $row ? $row['id'] : null;
            $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>$option_id,':txt'=>$answer_text,':num'=>null]);
            continue;
        }

        if ($q['question_type'] === 'text') {
            $val = $_POST[$qname] ?? null;
            $answer_text = $val !== null ? (string)$val : null;
            $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$answer_text,':num'=>null]);
            continue;
        }

        if ($q['question_type'] === 'numeric') {
            $val = $_POST[$qname] ?? null;
            $answer_number = $val !== null && $val !== '' ? (float)str_replace(',', '.', $val) : null;
            $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>null,':num'=>$answer_number]);
            continue;
        }

        if ($q['question_type'] === 'antibiotic') {
            $rawKey = $qname . '_raw';
            $interpKey = $qname . '_interpretation';
            $rawVal = $_POST[$rawKey] ?? null;
            $interpVal = $_POST[$interpKey] ?? null;
            $answer_number = $rawVal !== null && $rawVal !== '' ? (float)str_replace(',', '.', $rawVal) : null;
            $insAnswer->execute([':rid'=>$response_id,':qid'=>$qid,':optid'=>null,':txt'=>$interpVal,':num'=>$answer_number]);
            $ra_id = $pdo->lastInsertId();

            // Insert antibiotic_results if antibiotic_id present
            if (!empty($q['antibiotic_id'])) {
                $abid = (int)$q['antibiotic_id'];
                $bps = $breakpoints_map[$abid] ?? [];
                $bp = pick_breakpoint($bps);
                $bp_id = $bp ? (int)$bp['id'] : null;
                $method = $bp ? $bp['method'] : 'disk';
                $unit = $bp ? $bp['unit'] : '';
                $interp = interpret_using_bp($answer_number, $bp);
                $insAbRes->execute([':ra_id'=>$ra_id,':ab_id'=>$abid,':bp_id'=>$bp_id,':method'=>$method,':raw'=>$answer_number !== null ? $answer_number : 0,':unit'=>$unit,':interp'=>$interp]);
            }
            continue;
        }
    }

    $pdo->commit();
    $_SESSION['flash_success'] = 'Resultados enviados correctamente.';
    header('Location: user_dashboard.php');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_danger'] = 'Error al guardar resultados: ' . $e->getMessage();
    header('Location: user_dashboard.php');
    exit;
}
