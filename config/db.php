<?php
/**
 * Database configuration using PDO.
 * Edit the variables below with your Hostinger credentials.
 */

$host = 'your_host_here';        // e.g. '127.0.0.1' or 'mysqlXX.hostinger.com'
$dbname = 'eqas_ng';            // nombre de la base de datos
$username = 'your_db_user';
$password = 'your_db_password';
$charset = 'utf8mb4';

/**
 * getPDO()
 * Returns a configured PDO instance or throws a PDOException on failure.
 */
function getPDO(): PDO
{
    global $host, $dbname, $username, $password, $charset;

    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $username, $password, $options);
}

// EOF
