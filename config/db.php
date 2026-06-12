<?php
/**
 * Configuración de la Base de Datos - PostgreSQL
 * Sistema de Admisión Universitaria (CUP) - FICCT
 */

require_once 'config.php';

try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Si hay un error de conexión, se muestra elegantemente
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>
