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
        header('Location: ../P_Academico/notas.php');
    } elseif ($_SESSION['user_role'] === 'estudiante') {
        header('Location: ../P_Academico/estudiante_dashboard.php');
    }
    exit;
}

$role_hint = isset($_GET['role']) ? $_GET['role'] : ''; // 'admin', 'docente', 'estudiante'
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($correo) || empty($password)) {
        $error = "Por favor ingresa tu correo y contraseña.";
    } else {
        try {
            // Buscar usuario en la base de datos por Correo
            $stmt = $pdo->prepare("SELECT u.*, r.nombre AS rol_nombre 
                                   FROM Usuarios u 
                                   JOIN Roles r ON u.ID_rol = r.ID_rol 
                                   WHERE LOWER(u.Correo) = LOWER(:correo) AND u.Estado = TRUE");
            $stmt->execute(['correo' => $correo]);
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
                    
                    header('Location: ../P_Academico/notas.php');
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
                $error = "Correo o contraseña incorrectos.";
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
        
        <div class="glass-panel login-container" style="padding: 40px; width: 100%; max-width: 450px;">
            

            <div class="logo-container" style="margin-bottom: 20px;">
                <h2 class="gradient-text" style="font-size: 1.8rem; margin: 0;">Portal de Acceso</h2>
            </div>

            <!-- TÍTULO DINÁMICO SEGÚN ROL -->
            <?php if ($role_hint === 'admin'): ?>
                <p style="color: var(--primary); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px; text-align: center;">Área Administrativa</p>
            <?php elseif ($role_hint === 'docente'): ?>
                <p style="color: var(--secondary); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px; text-align: center;">Portal Docente</p>
            <?php elseif ($role_hint === 'estudiante'): ?>
                <p style="color: var(--success); font-weight: 600; margin-top: -10px; margin-bottom: 25px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px; text-align: center;">Portal de Estudiantes</p>
            <?php else: ?>
                <p style="color: var(--text-muted); margin-top: -10px; margin-bottom: 25px; text-align: center;">Ingresa tus credenciales para acceder a la plataforma.</p>
            <?php endif; ?>

            <!-- ALERTA DE ERROR -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="text-align: left; font-size: 0.9rem; margin-bottom: 20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="login.php" novalidate>
                <div class="form-group">
                    <label for="correo">Correo Electrónico</label>
                    <input type="email" id="correo" name="correo" class="form-control" placeholder="Ej: admin@univ.edu" required autofocus>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label for="password" style="margin: 0;">Contraseña</label>
                        <span style="font-size: 0.8rem; color: var(--text-muted); opacity: 0.8;">CI para estudiantes</span>
                    </div>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••••••" required style="padding-right: 75px; width: 100%;">
                        <button type="button" id="togglePassword" style="position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 0.85rem; font-weight: 500; padding: 5px;">Mostrar</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-bottom: 20px;">
                    Ingresar
                </button>

                <div style="display: flex; justify-content: center; align-items: center; font-size: 0.85rem;">
                    <a href="../index.php" style="color: var(--text-muted); text-decoration: none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">Volver al Inicio</a>
                </div>
            </form>

        </div>

    </div>

    <!-- VALIDACIONES JS -->
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(event) {
            const correoInput = document.getElementById('correo');
            const passwordInput = document.getElementById('password');
            let valid = true;
            let errorMessage = "";

            // Limpiar alertas previas
            const existingAlert = document.querySelector('.alert-error');
            if (existingAlert) {
                existingAlert.remove();
            }

            // Validar Correo Obligatorio
            if (!correoInput.value.trim()) {
                valid = false;
                errorMessage = "El correo electrónico es obligatorio.";
            } 
            // Validar Formato de Correo
            else {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(correoInput.value.trim())) {
                    valid = false;
                    errorMessage = "Por favor ingresa un formato de correo electrónico válido.";
                }
            }

            // Validar Contraseña Obligatoria
            if (valid && !passwordInput.value.trim()) {
                valid = false;
                errorMessage = "La contraseña es obligatoria.";
            }

            if (!valid) {
                event.preventDefault();
                // Insertar alerta
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.style.textAlign = 'left';
                alertDiv.style.fontSize = '0.9rem';
                alertDiv.style.marginBottom = '20px';
                alertDiv.innerText = errorMessage;
                
                const form = document.getElementById('loginForm');
                form.parentNode.insertBefore(alertDiv, form);
            }
        });

        // Toggle Mostrar/Ocultar contraseña
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.innerText = 'Ocultar';
            } else {
                passwordInput.type = 'password';
                this.innerText = 'Mostrar';
            }
        });
    </script>
</body>
</html>
