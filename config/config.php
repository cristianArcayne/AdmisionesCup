<?php
/**
 * Credenciales de Acceso a PostgreSQL (Detectadas dinámicamente)
 */

if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'alwaysdata.net') !== false) {
    // Credenciales de Producción en Alwaysdata
    define('DB_HOST', 'postgresql-admisionescup.alwaysdata.net');
    define('DB_PORT', '5432');
    define('DB_NAME', 'admisionescup_bd');
    define('DB_USER', 'admisionescup');
    define('DB_PASS', '65101590');
} else {
    // Credenciales de Desarrollo Local (XAMPP)
    define('DB_HOST', 'localhost');
    define('DB_PORT', '5432');
    define('DB_NAME', 'bd_admision_cup_ficct');
    define('DB_USER', 'postgres');
    define('DB_PASS', '65101590');
}
?>
