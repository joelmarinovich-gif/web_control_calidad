<?php
require_once __DIR__ . '/config/db.php';
session_start();

// Protegido: solo super_admin
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection error']);
    exit;
}

$roleStmt = $pdo->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
$roleStmt->execute([':id' => $_SESSION['role_id'] ?? 0]);
$roleRow = $roleStmt->fetch();
if (!$roleRow || $roleRow['name'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

// Obtener pregunta
$stmt = $pdo->prepare('SELECT id, survey_id, question_text, question_type, required, display_order, antibiotic_id FROM survey_questions WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$q = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$q) {
    http_response_code(404);
    echo json_encode(['error' => 'Pregunta no encontrada']);
    exit;
}

// Si tiene opciones, combinarlas en un string con saltos de línea
$options_raw = '';
if (in_array($q['question_type'], ['select','multiselect'])) {
    $optStmt = $pdo->prepare('SELECT value FROM question_options WHERE question_id = :qid ORDER BY display_order ASC');
    $optStmt->execute([':qid' => $id]);
    $vals = $optStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($vals) {
        $options_raw = implode("\n", $vals);
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'id' => (int)$q['id'],
    'survey_id' => (int)$q['survey_id'],
    'question_text' => $q['question_text'],
    'question_type' => $q['question_type'],
    'required' => (int)$q['required'],
    'display_order' => (int)$q['display_order'],
    'antibiotic_id' => $q['antibiotic_id'] === null ? null : (int)$q['antibiotic_id'],
    'options_raw' => $options_raw
]);

// EOF
