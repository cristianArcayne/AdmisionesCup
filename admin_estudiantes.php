<?php
/**
 * CRUD Completo de Estudiantes Simplificado (Sin Emojis)
 */

session_start();
require_once 'config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php?err=' . urlencode('Acceso denegado.'));
    exit;
}

$message = "";
$error = "";

// --- 1. PROCESAR ACCIÓN DE ELIMINACIÓN (DELETE) ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_estudiante = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();

        // Obtener ID_persona y ID_user del estudiante
        $stmt = $pdo->prepare("SELECT ID_persona, ID_user FROM Estudiantes WHERE ID_estudiante = :id_estudiante");
        $stmt->execute(['id_estudiante' => $id_estudiante]);
        $student_refs = $stmt->fetch();

        if ($student_refs) {
            $id_persona = (int)$student_refs['id_persona'];
            $id_user = (int)$student_refs['id_user'];

            // Obtener ID_postulante asociado a la persona
            $stmt = $pdo->prepare("SELECT ID_postulante FROM Postulantes WHERE ID_persona = :id_persona");
            $stmt->execute(['id_persona' => $id_persona]);
            $id_postulante = $stmt->fetchColumn();

            // A. Eliminar Notas asociadas
            $stmt = $pdo->prepare("DELETE FROM Notas WHERE ID_estudiante = :id_estudiante");
            $stmt->execute(['id_estudiante' => $id_estudiante]);

            // B. Eliminar Pagos del postulante
            if ($id_postulante) {
                $stmt = $pdo->prepare("DELETE FROM Pagos WHERE ID_postulante = :id_postulante");
                $stmt->execute(['id_postulante' => $id_postulante]);

                // C. Eliminar Postulante
                $stmt = $pdo->prepare("DELETE FROM Postulantes WHERE ID_persona = :id_persona");
                $stmt->execute(['id_persona' => $id_persona]);
            }

            // D. Eliminar Estudiante
            $stmt = $pdo->prepare("DELETE FROM Estudiantes WHERE ID_estudiante = :id_estudiante");
            $stmt->execute(['id_estudiante' => $id_estudiante]);

            // E. Eliminar Cuenta de Usuario
            $stmt = $pdo->prepare("DELETE FROM Usuarios WHERE ID_user = :id_user");
            $stmt->execute(['id_user' => $id_user]);

            // F. Eliminar Persona
            $stmt = $pdo->prepare("DELETE FROM Personas WHERE ID_persona = :id_persona");
            $stmt->execute(['id_persona' => $id_persona]);

            $pdo->commit();
            $message = "El estudiante y todos sus registros asociados se eliminaron correctamente.";
        } else {
            throw new Exception("Estudiante no encontrado.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al eliminar el estudiante: " . $e->getMessage();
    }
}

// --- 2. PROCESAR ACCIÓN DE EDICIÓN (UPDATE) ---
$editing_student = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id_estudiante = (int)$_GET['edit'];
    
    $stmt = $pdo->prepare("
        SELECT e.ID_estudiante, e.ID_carrera, e.ID_grupo, p.*, po.Colegio_procedencia, po.Ciudad, po.Titulo_bachiller
        FROM Estudiantes e
        JOIN Personas p ON e.ID_persona = p.ID_persona
        LEFT JOIN Postulantes po ON p.ID_persona = po.ID_persona
        WHERE e.ID_estudiante = :id_estudiante
    ");
    $stmt->execute(['id_estudiante' => $id_estudiante]);
    $editing_student = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_student') {
    $id_estudiante = (int)$_POST['id_estudiante'];
    $id_persona = (int)$_POST['id_persona'];
    
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $ci = trim($_POST['ci']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $genero = $_POST['genero'];
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $correo_personal = trim($_POST['correo_personal']);
    $colegio_procedencia = trim($_POST['colegio_procedencia']);
    $ciudad = trim($_POST['ciudad']);
    $titulo_bachiller = 1; // Siempre habilitado
    
    $carrera = (int)$_POST['carrera'];
    $grupo = (int)$_POST['grupo'];

    if (empty($nombre) || empty($apellido) || empty($ci) || empty($correo_personal)) {
        $error = "Nombre, Apellido, CI y Correo son campos obligatorios.";
    } else {
        try {
            $pdo->beginTransaction();

            // A. Actualizar Personas
            $stmt = $pdo->prepare("
                UPDATE Personas 
                SET nombre = :nombre, apellido = :apellido, CI = :ci, fecha_nacimiento = :fecha_nacimiento, 
                    genero = :genero, direccion = :direccion, telefono = :telefono, correo_personal = :correo
                WHERE ID_persona = :id_persona
            ");
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $ci,
                'fecha_nacimiento' => $fecha_nacimiento,
                'genero' => $genero,
                'direccion' => $direccion,
                'telefono' => $telefono,
                'correo' => $correo_personal,
                'id_persona' => $id_persona
            ]);

            // B. Actualizar Postulantes si existe
            $stmt = $pdo->prepare("
                UPDATE Postulantes 
                SET Colegio_procedencia = :colegio, Ciudad = :ciudad, Titulo_bachiller = 'true'
                WHERE ID_persona = :id_persona
            ");
            $stmt->execute([
                'colegio' => $colegio_procedencia,
                'ciudad' => $ciudad,
                'id_persona' => $id_persona
            ]);

            // C. Obtener el grupo actual antes de actualizar
            $stmt = $pdo->prepare("SELECT ID_grupo FROM Estudiantes WHERE ID_estudiante = :id_estudiante");
            $stmt->execute(['id_estudiante' => $id_estudiante]);
            $grupo_actual = $stmt->fetchColumn();

            // D. Actualizar Estudiantes (Carrera y Grupo)
            $stmt = $pdo->prepare("
                UPDATE Estudiantes 
                SET ID_carrera = :carrera, ID_grupo = :grupo
                WHERE ID_estudiante = :id_estudiante
            ");
            $stmt->execute([
                'carrera' => $carrera,
                'grupo' => $grupo,
                'id_estudiante' => $id_estudiante
            ]);

            // E. Si el grupo cambió, re-vincular las materias en Notas (para consistencia del trigger/docentes)
            if ((int)$grupo_actual !== $grupo) {
                $stmt = $pdo->prepare("UPDATE Notas SET ID_grupo = :nuevo_grupo WHERE ID_estudiante = :id_estudiante");
                $stmt->execute(['nuevo_grupo' => $grupo, 'id_estudiante' => $id_estudiante]);
            }

            $pdo->commit();
            $message = "Los datos del estudiante se actualizaron correctamente.";
            $editing_student = null; // Salir de modo edición

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al actualizar estudiante: " . $e->getMessage();
        }
    }
}

// --- 3. BÚSQUEDA Y LISTADO DE ESTUDIANTES ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$students = [];

try {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, p.correo_personal, c.nombre_carrera, g.nombre_grupo
            FROM Estudiantes e
            JOIN Personas p ON e.ID_persona = p.ID_persona
            JOIN Carreras c ON e.ID_carrera = c.ID_carrera
            JOIN Grupos g ON e.ID_grupo = g.ID_grupo
            WHERE p.nombre ILIKE :search OR p.apellido ILIKE :search OR p.CI ILIKE :search
            ORDER BY e.ID_estudiante DESC
        ");
        $stmt->execute(['search' => "%$search%"]);
        $students = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("
            SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, p.correo_personal, c.nombre_carrera, g.nombre_grupo
            FROM Estudiantes e
            JOIN Personas p ON e.ID_persona = p.ID_persona
            JOIN Carreras c ON e.ID_carrera = c.ID_carrera
            JOIN Grupos g ON e.ID_grupo = g.ID_grupo
            ORDER BY e.ID_estudiante DESC
        ");
        $students = $stmt->fetchAll();
    }

    // Cargar Catálogos para los selects del formulario de edición
    $carreras = $pdo->query("SELECT ID_carrera, nombre_carrera FROM Carreras WHERE estado = TRUE ORDER BY ID_carrera")->fetchAll();
    $grupos = $pdo->query("SELECT ID_grupo, nombre_grupo, cantidad_estudiantes FROM Grupos WHERE estado = TRUE ORDER BY ID_grupo")->fetchAll();

} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Estudiantes | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
                    <a href="admin_dashboard.php">Panel Principal</a>
                </li>
                <li class="active">
                    <a href="admin_estudiantes.php">Gestionar Estudiantes</a>
                </li>
                <li>
                    <a href="admin_docentes.php">Gestionar Docentes</a>
                </li>
                <li>
                    <a href="admin_asignaciones.php">Asignar Docentes</a>
                </li>
                <li style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <a href="logout.php" style="color: var(--error);">Cerrar Sesión</a>
                </li>
            </ul>
        </aside>

        <!-- ÁREA PRINCIPAL DE CONTENIDO -->
        <main class="main-content">
            
            <header class="dash-header">
                <div>
                    <h2>Gestión de Estudiantes</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registrar, modificar y eliminar estudiantes con integridad referencial</p>
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

            <!-- FORMULARIO DE EDICIÓN (Muestra solo si está editando) -->
            <?php if ($editing_student): ?>
                <section class="card" style="margin-bottom: 40px; border-color: var(--primary);">
                    <h3 style="font-size: 1.4rem; margin-bottom: 20px; color: var(--primary);">Modificar Estudiante: <?= htmlspecialchars($editing_student['nombre'] . ' ' . $editing_student['apellido']) ?></h3>
                    
                    <form method="POST" action="admin_estudiantes.php">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="id_estudiante" value="<?= $editing_student['id_estudiante'] ?>">
                        <input type="hidden" name="id_persona" value="<?= $editing_student['id_persona'] ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="nombre">Nombres</label>
                                <input type="text" id="nombre" name="nombre" class="form-control" value="<?= htmlspecialchars($editing_student['nombre']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="apellido">Apellidos</label>
                                <input type="text" id="apellido" name="apellido" class="form-control" value="<?= htmlspecialchars($editing_student['apellido']) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="ci">CI / Carnet de Identidad</label>
                                <input type="text" id="ci" name="ci" class="form-control" value="<?= htmlspecialchars($editing_student['ci']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="fecha_nacimiento">Fecha Nacimiento</label>
                                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-control" value="<?= $editing_student['fecha_nacimiento'] ?>">
                            </div>
                            <div class="form-group">
                                <label for="genero">Género</label>
                                <select id="genero" name="genero" class="form-control">
                                    <option value="M" <?= $editing_student['genero'] === 'M' ? 'selected' : '' ?>>M (Masculino)</option>
                                    <option value="F" <?= $editing_student['genero'] === 'F' ? 'selected' : '' ?>>F (Femenino)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="telefono">Teléfono</label>
                                <input type="text" id="telefono" name="telefono" class="form-control" value="<?= htmlspecialchars($editing_student['telefono']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="correo_personal">Correo Electrónico</label>
                                <input type="email" id="correo_personal" name="correo_personal" class="form-control" value="<?= htmlspecialchars($editing_student['correo_personal']) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="colegio_procedencia">Colegio</label>
                                <input type="text" id="colegio_procedencia" name="colegio_procedencia" class="form-control" value="<?= htmlspecialchars($editing_student['colegio_procedencia']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="ciudad">Ciudad</label>
                                <input type="text" id="ciudad" name="ciudad" class="form-control" value="<?= htmlspecialchars($editing_student['ciudad']) ?>">
                            </div>
                        </div>

                        <div class="form-row" style="border-top: 1px solid var(--border-color); padding-top: 20px; margin-bottom: 20px;">
                            <div class="form-group">
                                <label for="carrera">Carrera Inscrita</label>
                                <select id="carrera" name="carrera" class="form-control">
                                    <?php foreach ($carreras as $c): ?>
                                        <option value="<?= $c['id_carrera'] ?>" <?= $editing_student['id_carrera'] == $c['id_carrera'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre_carrera']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="grupo">Grupo Asignado (Capacidad Max 70)</label>
                                <select id="grupo" name="grupo" class="form-control">
                                    <?php foreach ($grupos as $g): ?>
                                        <option value="<?= $g['id_grupo'] ?>" <?= $editing_student['id_grupo'] == $g['id_grupo'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nombre_grupo']) ?> (Actualmente: <?= $g['cantidad_estudiantes'] ?> / 70)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; justify-content: flex-end;">
                            <a href="admin_estudiantes.php" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <!-- LISTADO GENERAL CON BUSCADOR -->
            <section class="card">
                
                <div class="table-controls">
                    <form method="GET" action="admin_estudiantes.php" class="search-box">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por Nombre, Apellido o CI..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                    
                    <a href="register.php" class="btn btn-primary btn-small">Registrar Nuevo Postulante</a>
                </div>

                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombres y Apellidos</th>
                                <th>CI / Carnet</th>
                                <th>Carrera</th>
                                <th>Grupo</th>
                                <th>Correo</th>
                                <th style="text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($students) === 0): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No se encontraron estudiantes para la búsqueda.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $row): ?>
                                    <tr>
                                        <td><?= $row['id_estudiante'] ?></td>
                                        <td style="font-weight: 600; color: #333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                        <td><?= htmlspecialchars($row['ci']) ?></td>
                                        <td><span class="badge badge-success"><?= htmlspecialchars($row['nombre_carrera']) ?></span></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($row['correo_personal']) ?></td>
                                        <td style="text-align: center;">
                                            <div class="actions-cell" style="justify-content: center;">
                                                <a href="admin_estudiantes.php?edit=<?= $row['id_estudiante'] ?>" class="btn btn-secondary btn-small" style="padding: 6px 12px; font-size: 0.8rem; border-color: var(--primary); color: var(--primary);">Editar</a>
                                                <a href="admin_estudiantes.php?delete=<?= $row['id_estudiante'] ?>" onclick="return confirm('¿Estás seguro de que deseas eliminar permanentemente a este estudiante? Esta acción borrará todas sus calificaciones y registros relacionados en cascada.')" class="btn btn-danger btn-small" style="padding: 6px 12px; font-size: 0.8rem;">Eliminar</a>
                                            </div>
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
