<?php
header('Content-Type: text/plain; charset=utf-8');

$host = 'postgresql-admisionescup.alwaysdata.net';
$port = '5432';
$user = 'admisionescup';
$pass = '65101590';

// We try to connect to 'template1' or 'postgres' or 'admisionescup' to query the system catalogs
$dbs_to_try = ['template1', 'admisionescup', 'postgres'];
$connected = false;
$pdo = null;

foreach ($dbs_to_try as $db) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        echo "Successfully connected to default database '$db'!\n";
        $connected = true;
        break;
    } catch (PDOException $e) {
        echo "Could not connect to '$db': " . $e->getMessage() . "\n";
    }
}

if (!$connected) {
    echo "Fatal: Could not connect to any default database to scan. Please check your credentials.\n";
    exit;
}

try {
    // Query pg_database to list all databases
    $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false;");
    echo "\nAvailable databases:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['datname'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error querying databases list: " . $e->getMessage() . "\n";
}
?>
