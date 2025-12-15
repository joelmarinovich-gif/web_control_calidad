<?php
require_once __DIR__ . '/config/db.php';

/**
 * Test database connection and list antibiotics.
 */
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo "<h2>Conexión fallida</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

try {
    $stmt = $pdo->query('SELECT id, name FROM antibiotics ORDER BY name');
    $rows = $stmt->fetchAll();

    echo "<h2>Antibióticos registrados</h2>\n";
    if (empty($rows)) {
        echo "<p>No hay antibióticos en la tabla.</p>\n";
    } else {
        echo "<ul>\n";
        foreach ($rows as $r) {
            echo "<li>" . htmlspecialchars($r['name']) . "</li>\n";
        }
        echo "</ul>\n";
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "<h2>Error en la consulta</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

// EOF
