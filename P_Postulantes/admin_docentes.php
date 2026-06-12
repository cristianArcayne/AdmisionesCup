<?php
/**
 * Gestión de Docentes - Panel Administrativo Simplificado (Sin Emojis)
 */

session_start();
require_once '../config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../P_Seguridad/login.php?err=' . urlencode('Acceso denegado.'));
    exit;
}

$message = "";
$error = "";
$success_credentials = null;

// --- 1. PROCESAR ACCIÓN DE ELIMINACIÓN (DELETE) ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_docente = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();

        // Obtener ID_persona y ID_user del docente
        $stmt = $pdo->prepare("SELECT ID_persona, ID_user FROM Docentes WHERE ID_docente = :id_docente");
        $stmt->execute(['id_docente' => $id_docente]);
        $doc_refs = $stmt->fetch();

        if ($doc_refs) {
            $id_persona = (int)$doc_refs['id_persona'];
            $id_user = (int)$doc_refs['id_user'];

            // A. Eliminar Asignaciones asociadas
            $stmt = $pdo->prepare("DELETE FROM Asignaciones WHERE ID_docente = :id_docente");
            $stmt->execute(['id_docente' => $id_docente]);

            // B. Eliminar de la tabla Docentes
            $stmt = $pdo->prepare("DELETE FROM Docentes WHERE ID_docente = :id_docente");
            $stmt->execute(['id_docente' => $id_docente]);

            // C. Eliminar Cuenta de Usuario
            $stmt = $pdo->prepare("DELETE FROM Usuarios WHERE ID_user = :id_user");
            $stmt->execute(['id_user' => $id_user]);

            // D. Eliminar Persona
            $stmt = $pdo->prepare("DELETE FROM Personas WHERE ID_persona = :id_persona");
            $stmt->execute(['id_persona' => $id_persona]);

            $pdo->commit();
            $message = "El docente y todos sus registros de asignación y cuenta se eliminaron correctamente.";
        } else {
            throw new Exception("Docente no encontrado.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al eliminar el docente: " . $e->getMessage();
    }
}

// --- 2. PROCESAR ACCIÓN DE CREACIÓN (INSERT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_docente') {
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $ci = trim($_POST['ci']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $genero = $_POST['genero'];
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $correo_personal = trim($_POST['correo_personal']);
    $especialidad = trim($_POST['especialidad']);

    if (empty($nombre) || empty($apellido) || empty($ci) || empty($correo_personal) || empty($especialidad)) {
        $error = "Nombre, Apellido, CI, Correo y Especialidad son campos obligatorios.";
    } else {
        try {
            $pdo->beginTransaction();

            // A. Verificar si el CI ya existe
            $stmt = $pdo->prepare("SELECT ID_persona FROM Personas WHERE CI = :ci");
            $stmt->execute(['ci' => $ci]);
            if ($stmt->fetch()) {
                throw new Exception("El número de CI ya se encuentra registrado.");
            }

            // B. Insertar en Personas
            $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, fecha_nacimiento, genero, direccion, telefono, correo_personal) 
                                   VALUES (:nombre, :apellido, :ci, :fecha_nacimiento, :genero, :direccion, :telefono, :correo_personal)");
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $ci,
                'fecha_nacimiento' => $fecha_nacimiento ?: null,
                'genero' => $genero ?: null,
                'direccion' => $direccion ?: null,
                'telefono' => $telefono ?: null,
                'correo_personal' => $correo_personal
            ]);
            $id_persona = $pdo->lastInsertId();

            // C. Crear Cuenta de Usuario de Docente
            // Usuario del docente: su número de CI
            $username = $ci;

            // Contraseña: primera letra de su apellido (en minúscula) y el CI
            $first_letter_lastname = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $apellido), 0, 1));
            $plain_password = $first_letter_lastname . $username;
            $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

            // Insertar en Usuarios (ID_rol 2 = docente)
            $stmt = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) 
                                   VALUES (:username, :password, :correo, 2)");
            $stmt->execute([
                'username' => $username,
                'password' => $hashed_password,
                'correo' => $correo_personal
            ]);
            $id_user = $pdo->lastInsertId();

            // D. Insertar en la tabla Docentes
            $stmt = $pdo->prepare("INSERT INTO Docentes (ID_persona, ID_user, Especialidad, tiene_maestria, tiene_diplomado, max_grupos) 
                                   VALUES (:id_persona, :id_user, :especialidad, TRUE, TRUE, 4)");
            $stmt->execute([
                'id_persona' => $id_persona,
                'id_user' => $id_user,
                'especialidad' => $especialidad
            ]);

            $pdo->commit();
            $message = "Docente registrado con éxito.";
            
            $success_credentials = [
                'nombre' => $nombre . ' ' . $apellido,
                'username' => $username,
                'password' => $plain_password
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al registrar docente: " . $e->getMessage();
        }
    }
}

// --- 3. OBTENER LISTADO DE DOCENTES ---
$docentes = [];
try {
    $stmt = $pdo->query("
        SELECT d.ID_docente, d.Especialidad, p.nombre, p.apellido, p.CI, p.correo_personal, p.telefono, u.Username
        FROM Docentes d
        JOIN Personas p ON d.ID_persona = p.ID_persona
        JOIN Usuarios u ON d.ID_user = u.ID_user
        ORDER BY d.ID_docente DESC
    ");
    $docentes = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar docentes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Docentes | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PANEL ADMIN</span>
            </div>
            
            <ul class="sidebar-menu">
                <li>
                    <a href="../P_Academico/admin_dashboard.php">Panel Principal</a>
                </li>
                <li>
                    <a href="admin_estudiantes.php">Gestionar Estudiantes</a>
                </li>
                <li class="active">
                    <a href="admin_docentes.php">Gestionar Docentes</a>
                </li>
                <li>
                    <a href="../P_Academico/admin_asignaciones.php">Asignar Docentes</a>
                </li>
                <li style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <a href="../P_Seguridad/logout.php" style="color: var(--error);">Cerrar Sesión</a>
                </li>
            </ul>
        </aside>

        <!-- ÁREA PRINCIPAL DE CONTENIDO -->
        <main class="main-content">
            
            <header class="dash-header">
                <div>
                    <h2>Gestión de Docentes</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registrar y eliminar perfiles de docentes del sistema</p>
                </div>
                <div class="user-info-badge">
                    <span class="avatar">A</span>
                    <span style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['user_realname']) ?></span>
                </div>
            </header>

            <!-- MENSAJES DE ESTADO -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- CREDENCIALES GENERADAS DEL DOCENTE -->
            <?php if ($success_credentials): ?>
                <section class="card" style="margin-bottom: 40px; border-color: var(--secondary);">
                    <h3 style="font-size: 1.3rem; margin-bottom: 15px; color: var(--secondary);">Credenciales de Acceso para el Docente</h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 15px;">Por favor, entrega estos datos al docente registrado para que pueda ingresar al portal:</p>
                    <div style="background: #f5f5f5; padding: 20px; border-radius: 12px; border: 1px solid #e0e0e0; display: flex; flex-direction: column; gap: 10px;">
                        <div><strong>Docente:</strong> <?= htmlspecialchars($success_credentials['nombre']) ?></div>
                        <div><strong>Usuario:</strong> <code style="background: #ffffff; padding: 4px 8px; border-radius: 6px; color: var(--primary); font-family: monospace; border: 1px solid #ddd;"><?= htmlspecialchars($success_credentials['username']) ?></code></div>
                        <div><strong>Contraseña:</strong> <code style="background: #ffffff; padding: 4px 8px; border-radius: 6px; color: var(--secondary); font-family: monospace; border: 1px solid #ddd;"><?= htmlspecialchars($success_credentials['password']) ?></code> <span style="font-size: 0.8rem; color: var(--text-muted);">(Primera letra del apellido + CI)</span></div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- FORMULARIO DE REGISTRO DE NUEVO DOCENTE -->
            <section class="card" style="margin-bottom: 40px;">
                <h3 style="font-size: 1.4rem; margin-bottom: 25px; color: var(--primary);">Registrar Nuevo Docente</h3>
                
                <form method="POST" action="admin_docentes.php">
                    <input type="hidden" name="action" value="create_docente">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="nombre">Nombres *</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" placeholder="Ej: Carlos" required>
                        </div>
                        <div class="form-group">
                            <label for="apellido">Apellidos *</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" placeholder="Ej: Mendoza" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ci">CI / Carnet de Identidad *</label>
                            <input type="text" id="ci" name="ci" class="form-control" placeholder="Ej: 1234567" required>
                        </div>
                        <div class="form-group">
                            <label for="genero">Género</label>
                            <select id="genero" name="genero" class="form-control">
                                <option value="">Seleccionar...</option>
                                <option value="M">Masculino (M)</option>
                                <option value="F">Femenino (F)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fecha_nacimiento">Fecha de Nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="correo_personal">Correo Electrónico *</label>
                            <input type="email" id="correo_personal" name="correo_personal" class="form-control" placeholder="ejemplo@univ.edu" required>
                        </div>
                        <div class="form-group">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono" class="form-control" placeholder="Ej: 77012345">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="especialidad">Especialidad / Área *</label>
                            <input type="text" id="especialidad" name="especialidad" class="form-control" placeholder="Ej: Ciencias de la Computación" required>
                        </div>
                        <div class="form-group">
                            <label for="direccion">Dirección</label>
                            <input type="text" id="direccion" name="direccion" class="form-control" placeholder="Ej: Av. Bush 2do Anillo">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
                        <button type="submit" class="btn btn-primary">Registrar Docente</button>
                    </div>
                </form>
            </section>

            <!-- LISTADO GENERAL DE DOCENTES REGISTRADOS -->
            <section class="card">
                <h3 style="font-size: 1.4rem; margin-bottom: 20px; color: var(--primary);">Listado de Docentes</h3>

                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Docente</th>
                                <th>CI / Carnet</th>
                                <th>Usuario</th>
                                <th>Especialidad</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th style="text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($docentes) === 0): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-muted);">No hay docentes registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($docentes as $row): ?>
                                    <tr>
                                        <td><?= $row['id_docente'] ?></td>
                                        <td style="font-weight: 600; color: #333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                        <td><?= htmlspecialchars($row['ci']) ?></td>
                                        <td><code style="font-family: monospace;"><?= htmlspecialchars($row['username']) ?></code></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($row['especialidad']) ?></span></td>
                                        <td style="font-size: 0.85rem;"><?= htmlspecialchars($row['correo_personal']) ?></td>
                                        <td><?= htmlspecialchars($row['telefono']) ?></td>
                                        <td style="text-align: center;">
                                            <a href="admin_docentes.php?delete=<?= $row['id_docente'] ?>" onclick="return confirm('¿Estás seguro de que deseas eliminar permanentemente a este docente? Esta acción borrará todas sus asignaciones en el sistema.')" class="btn btn-danger btn-small" style="padding: 6px 12px; font-size: 0.8rem;">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>
</body>
</html>
