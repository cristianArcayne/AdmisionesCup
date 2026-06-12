<?php
/**
 * Destrucción Segura de Sesiones (Cierre de Sesión)
 * Sistema de Admisión Universitaria (CUP) - FICCT
 */

session_start();

// Borrar todas las variables de sesión
$_SESSION = array();

// Si se desea destruir la sesión por completo, borrar también la cookie de sesión.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión.
session_destroy();

// Redirigir al inicio con mensaje
header("Location: index.php?msg=" . urlencode("Has cerrado tu sesión de manera segura."));
exit;
?>
