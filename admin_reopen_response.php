<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

try { $pdo = getPDO(); } catch (PDOException $e) { echo 'DB error'; exit; }

// role check
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) {
    header('Location: dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_responses.php'); exit; }

$responseId = isset($_POST['response_id']) ? (int)$_POST['response_id'] : 0;
if ($responseId <= 0) {
    $_SESSION['flash_danger'] = 'ID de envío inválido.'; header('Location: admin_responses.php'); exit;
}

try {
    $pdo->beginTransaction();

    // Verify response exists
    $chk = $pdo->prepare('SELECT id, survey_id, lab_id, status FROM responses WHERE id = :id LIMIT 1');
    $chk->execute([':id'=>$responseId]); $row = $chk->fetch();
    if (!$row) { if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['flash_danger']='Envío no encontrado.'; header('Location: admin_responses.php'); exit; }

    // Set status back to draft and clear submitted_at
    $u = $pdo->prepare('UPDATE responses SET status = :st, submitted_at = NULL WHERE id = :id');
    $u->execute([':st'=>'draft', ':id'=>$responseId]);

    // Remove antibiotic_results linked to this response (so they'll be recalculated on resubmit)
    $del = $pdo->prepare('DELETE ar FROM antibiotic_results ar JOIN response_answers ra ON ar.response_answer_id = ra.id WHERE ra.response_id = :rid');
    $del->execute([':rid'=>$responseId]);

    // Audit log
    $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, object_type, object_id, detail) VALUES (:uid,:action,:otype,:oid,:detail)');
    $detail = 'Reopened response_id=' . $responseId . ' by admin_user_id=' . $_SESSION['user_id'];
    $log->execute([':uid'=>$_SESSION['user_id'], ':action'=>'reopen_response', ':otype'=>'response', ':oid'=>$responseId, ':detail'=>$detail]);

    $pdo->commit();
    $_SESSION['flash_success'] = 'Envío reabierto correctamente. El laboratorio podrá editar y reenviar.';
    header('Location: admin_responses.php');
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_danger'] = 'Error reabriendo envío: ' . $e->getMessage();
    header('Location: admin_responses.php');
    exit;
}
