<?php
// Migration: add `antibiotic_method` column to `survey_questions`
// Usage (CLI): php scripts/add_antibiotic_method_migration.php
// Or open in browser (only if on a protected/dev server).

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo "Fallo al conectar con la BD: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'survey_questions' AND COLUMN_NAME = 'antibiotic_method'");
    $check->execute();
    $exists = (int)$check->fetchColumn() > 0;

    if ($exists) {
        echo "La columna `antibiotic_method` ya existe en survey_questions. Nada que hacer." . PHP_EOL;
        exit(0);
    }

    echo "Aplicando ALTER TABLE para añadir `antibiotic_method`..." . PHP_EOL;
    $pdo->beginTransaction();
    $pdo->exec("ALTER TABLE survey_questions ADD COLUMN antibiotic_method ENUM('disk','mic') DEFAULT NULL AFTER antibiotic_id;");
    $pdo->commit();
    echo "Columna añadida correctamente." . PHP_EOL;
    echo "Recomendación: elimina este script o muévelo fuera del servidor público después de usarlo." . PHP_EOL;
    exit(0);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR aplicando la migración: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
