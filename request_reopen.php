<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

try { $pdo = getPDO(); } catch (PDOException $e) { $_SESSION['flash_danger']='DB error'; header('Location: user_dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: user_dashboard.php'); exit; }

$responseId = isset($_POST['response_id']) ? (int)$_POST['response_id'] : 0;
$reason = trim($_POST['reason'] ?? '');

if ($responseId <= 0) { $_SESSION['flash_danger']='Solicitud inválida.'; header('Location: user_dashboard.php'); exit; }

// Verify ownership: only owner can request reopen
$chk = $pdo->prepare('SELECT id, user_id, survey_id FROM responses WHERE id = :id LIMIT 1');
$chk->execute([':id'=>$responseId]); $row = $chk->fetch();
if (!$row) { $_SESSION['flash_danger']='Envío no encontrado.'; header('Location: user_dashboard.php'); exit; }
if ((int)$row['user_id'] !== (int)$_SESSION['user_id']) { $_SESSION['flash_danger']='No tienes permiso para solicitar reabrir este envío.'; header('Location: user_dashboard.php'); exit; }

try {
  // Optional: avoid duplicate requests by same user for same response
  $exists = $pdo->prepare('SELECT id FROM audit_logs WHERE action = :act AND object_type = :otype AND object_id = :oid AND user_id = :uid LIMIT 1');
  $exists->execute([':act'=>'request_reopen', ':otype'=>'response', ':oid'=>$responseId, ':uid'=>$_SESSION['user_id']]);
  if ($exists->fetch()) {
    $_SESSION['flash_info'] = 'Ya has solicitado la reapertura de este envío. Espera respuesta del administrador.';
    header('Location: user_dashboard.php'); exit;
  }

  $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, object_type, object_id, detail) VALUES (:uid,:action,:otype,:oid,:detail)');
  $detail = 'User requested reopen for response_id=' . $responseId . '\nReason: ' . $reason;
  $log->execute([':uid'=>$_SESSION['user_id'], ':action'=>'request_reopen', ':otype'=>'response', ':oid'=>$responseId, ':detail'=>$detail]);

  $_SESSION['flash_success'] = 'Solicitud enviada. El administrador será notificado.';
  header('Location: user_dashboard.php');
  exit;
} catch (Exception $e) {
  $_SESSION['flash_danger'] = 'Error al enviar la solicitud.';
  header('Location: user_dashboard.php');
  exit;
}
