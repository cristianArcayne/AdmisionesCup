<?php
/**
 * Inicio de Sesión Seguro y Autenticación por Roles
 * Sistema de Admisión Universitaria (CUP) - FICCT
 */

session_start();
require_once '../config/db.php';

// Si ya está logueado, redirigir al panel correspondiente
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: ../P_Academico/admin_dashboard.php');
    } elseif ($_SESSION['user_role'] === 'docente') {
        header('Location: ../P_Academico/docente_dashboard.php');
    } elseif ($_SESSION['user_role'] === 'estudiante') {
        header('Location: ../P_Academico/estudiante_dashboard.php');
    }
    exit;
}

$role_hint = isset($_GET['role']) ? $_GET['role'] : ''; // 'admin', 'docente', 'estudiante'
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Por favor ingresa tu usuario y contraseña.";
    } else {
        try {
            // Buscar usuario en la base de datos (con coincidencia insensible a mayúsculas/minúsculas)
            $stmt = $pdo->prepare("SELECT u.*, r.nombre AS rol_nombre 
                                   FROM Usuarios u 
                                   JOIN Roles r ON u.ID_rol = r.ID_rol 
                                   WHERE LOWER(u.Username) = LOWER(:username) AND u.Estado = TRUE");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Autenticación Exitosa! Registrar variables de sesión
                $_SESSION['user_id'] = $user['id_user'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['rol_nombre']; // 'admin', 'docente', 'estudiante'

                // Guardar datos adicionales del perfil en la sesión según el rol
                if ($user['rol_nombre'] === 'admin') {
                    $stmt_adm = $pdo->prepare("SELECT Nombre, Apellido FROM Administrativos WHERE ID_user = :user_id");
                    $stmt_adm->execute(['user_id' => $user['id_user']]);
                    $adm = $stmt_adm->fetch();
                    $_SESSION['user_realname'] = $adm ? $adm['nombre'] . ' ' . $adm['apellido'] : 'Administrador';
                    
                    header('Location: ../P_Academico/admin_dashboard.php');
                } 
                elseif ($user['rol_nombre'] === 'docente') {
                    $stmt_doc = $pdo->prepare("SELECT d.ID_docente, p.nombre, p.apellido 
                                               FROM Docentes d 
                                               JOIN Personas p ON d.ID_persona = p.ID_persona 
                                               WHERE d.ID_user = :user_id");
                    $stmt_doc->execute(['user_id' => $user['id_user']]);
                    $doc = $stmt_doc->fetch();
                    $_SESSION['docente_id'] = $doc ? $doc['id_docente'] : 0;
                    $_SESSION['user_realname'] = $doc ? $doc['nombre'] . ' ' . $doc['apellido'] : 'Docente';
                    
                    header('Location: ../P_Academico/docente_dashboard.php');
                } 
                elseif ($user['rol_nombre'] === 'estudiante') {
                    $stmt_est = $pdo->prepare("SELECT e.ID_estudiante, p.nombre, p.apellido 
                                               FROM Estudiantes e 
                                               JOIN Personas p ON e.ID_persona = p.ID_persona 
                                               WHERE e.ID_user = :user_id");
                    $stmt_est->execute(['user_id' => $user['id_user']]);
                    $est = $stmt_est->fetch();
                    $_SESSION['estudiante_id'] = $est ? $est['id_estudiante'] : 0;
                    $_SESSION['user_realname'] = $est ? $est['nombre'] . ' ' . $est['apellido'] : 'Estudiante';
                    
                    header('Location: ../P_Academico/estudiante_dashboard.php');
                } 
                else {
                    $error = "Tu cuenta no tiene un rol válido asignado.";
                }
                exit;
            } else {
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error al autenticar: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container" style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;">
        
        <div class="glass-panel login-container" style="padding: 40px;">
            
            <div class="logo-container" style="margin-bottom: 25px;">
                <h2 class="gradient-text" style="font-size: 1.8rem;">Portal de Acceso</h2>
            </div>

            <!-- TÍTULO DINÁMICO SEGÚN ROL -->
            <?php if ($role_hint === 'admin'): ?>
                <p style="color: var(--primary); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;">Área Administrativa</p>
            <?php elseif ($role_hint === 'docente'): ?>
                <p style="color: var(--accent); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;">Portal Docente</p>
            <?php elseif ($role_hint === 'estudiante'): ?>
                <p style="color: var(--success); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;">Portal de Estudiantes</p>
            <?php else: ?>
                <p style="color: var(--text-muted); margin-top: -10px; margin-bottom: 25px;">Ingresa tus credenciales para acceder a la plataforma.</p>
            <?php endif; ?>

            <!-- ALERTA DE ERROR -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="text-align: left; font-size: 0.9rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Nombre de Usuario</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Ej: admin o carlos_m" required autofocus>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label for="password" style="margin: 0;">Contraseña</label>
                        <span style="font-size: 0.8rem; color: var(--text-muted); opacity: 0.8;">CI para estudiantes</span>
                    </div>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-bottom: 20px;">
                    Iniciar Sesión
                </button>

                <div style="display: flex; justify-content: center; align-items: center; font-size: 0.85rem;">
                    <a href="../index.php" style="color: var(--text-muted); text-decoration: none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">Volver al Inicio</a>
                </div>
            </form>

        </div>

    </div>
</body>
</html>
