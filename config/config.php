<?php
/**
 * Credenciales de Acceso a PostgreSQL
 * Modifica estos valores para que coincidan con tu servidor local
 */

define('DB_HOST', getenv('PGHOST') ?: 'localhost');
define('DB_PORT', getenv('PGPORT') ?: '5432');
define('DB_NAME', getenv('PGDATABASE') ?: 'bd_admision_cup_ficct');
define('DB_USER', getenv('PGUSER') ?: 'postgres');
define('DB_PASS', getenv('PGPASSWORD') ?: '65101590');
?>
