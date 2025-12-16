<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
try { $pdo = getPDO(); } catch (PDOException $e) { $_SESSION['flash_danger']='DB error'; header('Location: admin_responses.php'); exit; }

// role check
$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || !in_array($roleRow['name'], ['super_admin','admin'])) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_reopen_requests.php'); exit; }

$auditId = isset($_POST['audit_id']) ? (int)$_POST['audit_id'] : 0;
$responseId = isset($_POST['response_id']) ? (int)$_POST['response_id'] : 0;
action:
$action = $_POST['action'] ?? '';

if ($auditId <= 0 || $responseId <= 0 || !in_array($action, ['accept','reject'])) {
    $_SESSION['flash_danger'] = 'Solicitud inválida.';
    header('Location: admin_reopen_requests.php'); exit;
}

try {
    $pdo->beginTransaction();

    // Verify audit exists
    $chk = $pdo->prepare('SELECT * FROM audit_logs WHERE id = :id LIMIT 1'); $chk->execute([':id'=>$auditId]); $aud = $chk->fetch();
    if (!$aud) { if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['flash_danger']='Registro de solicitud no encontrado.'; header('Location: admin_reopen_requests.php'); exit; }

    if ($action === 'accept') {
        // Reopen response: set status=draft, clear submitted_at, delete antibiotic_results
        $u = $pdo->prepare('UPDATE responses SET status = :st, submitted_at = NULL WHERE id = :id');
        $u->execute([':st'=>'draft', ':id'=>$responseId]);
        $del = $pdo->prepare('DELETE ar FROM antibiotic_results ar JOIN response_answers ra ON ar.response_answer_id = ra.id WHERE ra.response_id = :rid');
        $del->execute([':rid'=>$responseId]);

        // Log action
        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, object_type, object_id, detail) VALUES (:uid,:action,:otype,:oid,:detail)');
        $detail = 'Admin accepted reopen (audit_id=' . $auditId . ') by admin_user_id=' . $_SESSION['user_id'];
        $log->execute([':uid'=>$_SESSION['user_id'], ':action'=>'reopen_granted', ':otype'=>'response', ':oid'=>$responseId, ':detail'=>$detail]);

        // Optionally mark original audit entry by appending note
        $updAud = $pdo->prepare('UPDATE audit_logs SET detail = CONCAT(detail, "\n[admin_action] accepted by user_id=', (int)$_SESSION['user_id'], '") WHERE id = :id');
        $updAud->execute([':id'=>$auditId]);

        $pdo->commit();
        $_SESSION['flash_success'] = 'Solicitud aceptada y envío reabierto.';
        header('Location: admin_reopen_requests.php'); exit;
    } else {
        // Reject: create audit_logs entry and mark original
        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, object_type, object_id, detail) VALUES (:uid,:action,:otype,:oid,:detail)');
        $detail = 'Admin rejected reopen (audit_id=' . $auditId . ') by admin_user_id=' . $_SESSION['user_id'];
        $log->execute([':uid'=>$_SESSION['user_id'], ':action'=>'reopen_rejected', ':otype'=>'response', ':oid'=>$responseId, ':detail'=>$detail]);

        $updAud = $pdo->prepare('UPDATE audit_logs SET detail = CONCAT(detail, "\n[admin_action] rejected by user_id=', (int)$_SESSION['user_id'], '") WHERE id = :id');
        $updAud->execute([':id'=>$auditId]);

        $pdo->commit();
        $_SESSION['flash_info'] = 'Solicitud rechazada.';
        header('Location: admin_reopen_requests.php'); exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_danger'] = 'Error procesando la solicitud: ' . $e->getMessage();
    header('Location: admin_reopen_requests.php'); exit;
}
