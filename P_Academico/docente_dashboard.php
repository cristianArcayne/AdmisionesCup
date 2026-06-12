<?php
/**
 * Panel del Docente - Registro y Control de Calificaciones (CU10, CU11)
 */

session_start();
require_once '../config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'docente'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'docente') {
    header('Location: ../P_Seguridad/login.php?err=' . urlencode('Acceso denegado: Se requieren privilegios de Docente.'));
    exit;
}

$realname = $_SESSION['user_realname'];
$docente_id = $_SESSION['docente_id'];

$message = "";
$error = "";

// --- 1. CARGAR CARGA HORARIA / ASIGNACIONES DEL DOCENTE ---
$asignaciones = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.ID_asignacion, a.ID_grupo, g.nombre_grupo, a.ID_materia, m.nombre_materia
        FROM Asignaciones a
        JOIN Grupos g ON a.ID_grupo = g.ID_grupo
        JOIN Materias m ON a.ID_materia = m.ID_materia
        WHERE a.ID_docente = :docente_id AND g.estado = TRUE
    ");
    $stmt->execute(['docente_id' => $docente_id]);
    $asignaciones = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error al cargar asignaciones académicas: " . $e->getMessage());
}

// --- 2. DETECTAR GRUPO Y MATERIA SELECCIONADOS ---
$id_grupo_sel = isset($_GET['grupo']) ? (int)$_GET['grupo'] : (count($asignaciones) > 0 ? (int)$asignaciones[0]['id_grupo'] : null);
$id_materia_sel = isset($_GET['materia']) ? $_GET['materia'] : (count($asignaciones) > 0 ? $asignaciones[0]['id_materia'] : null);

// --- 3. PROCESAR ACCIÓN DE GUARDADO DE CALIFICACIONES (CU10: guardarCambiosPlanillaEvaluacion / procesarGuardadoNotas) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_grades') {
    $id_estudiante = (int)$_POST['id_estudiante'];
    $nota1 = trim($_POST['nota1']);
    $nota2 = trim($_POST['nota2']);
    $nota3 = trim($_POST['nota3']);

    if ($nota1 === '' || $nota2 === '' || $nota3 === '') {
        $error = "Todas las calificaciones parciales son obligatorias.";
    } else {
        $nota1 = (float)$nota1;
        $nota2 = (float)$nota2;
        $nota3 = (float)$nota3;

        // Validar rango matemático 0 a 100 (CU10: validarRangoCalificacion)
        if ($nota1 < 0 || $nota1 > 100 || $nota2 < 0 || $nota2 > 100 || $nota3 < 0 || $nota3 > 100) {
            $error = "Las calificaciones deben estar en el rango de 0 a 100.";
        } else {
            try {
                // Operación UPSERT SQL (CU10: insertarOActualizarNotasEstudiante)
                $stmt = $pdo->prepare("
                    INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3)
                    VALUES (:id_estudiante, :materia_id, :grupo_id, :nota1, :nota2, :nota3)
                    ON CONFLICT (ID_estudiante, ID_materia, ID_grupo) DO UPDATE 
                    SET nota1 = EXCLUDED.nota1,
                        nota2 = EXCLUDED.nota2,
                        nota3 = EXCLUDED.nota3
                ");
                $stmt->execute([
                    'id_estudiante' => $id_estudiante,
                    'materia_id' => $id_materia_sel,
                    'grupo_id' => $id_grupo_sel,
                    'nota1' => $nota1,
                    'nota2' => $nota2,
                    'nota3' => $nota3
                ]);
                $message = "Calificaciones registradas correctamente.";
            } catch (PDOException $e) {
                $error = "Error al guardar calificaciones: " . $e->getMessage();
            }
        }
    }
}

// --- 4. DETECTAR SI SE ESTÁ CALIFICANDO A UN ESTUDIANTE ESPECÍFICO ---
$calificar_student = null;
if (isset($_GET['calificar_id']) && is_numeric($_GET['calificar_id']) && $id_grupo_sel && $id_materia_sel) {
    $calificar_id = (int)$_GET['calificar_id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT e.ID_estudiante, p.nombre, p.apellido, n.nota1, n.nota2, n.nota3
            FROM Estudiantes e
            JOIN Personas p ON e.ID_persona = p.ID_persona
            LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante AND n.ID_materia = :materia AND n.ID_grupo = :grupo
            WHERE e.ID_estudiante = :calificar_id AND e.ID_grupo = :grupo AND e.Estado = TRUE
        ");
        $stmt->execute([
            'calificar_id' => $calificar_id,
            'materia' => $id_materia_sel,
            'grupo' => $id_grupo_sel
        ]);
        $calificar_student = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error al cargar información de edición: " . $e->getMessage();
    }
}

// --- 5. OBTENER LISTA DE ALUMNOS Y CALIFICACIONES DEL GRUPO SELECCIONADO ---
$alumnos = [];
$materia_nombre = "";
$grupo_nombre = "";

if ($id_grupo_sel && $id_materia_sel) {
    try {
        // Obtener nombres de materia y grupo activos
        $stmt = $pdo->prepare("SELECT nombre_materia FROM Materias WHERE ID_materia = :materia");
        $stmt->execute(['materia' => $id_materia_sel]);
        $materia_nombre = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT nombre_grupo FROM Grupos WHERE ID_grupo = :grupo");
        $stmt->execute(['grupo' => $id_grupo_sel]);
        $grupo_nombre = $stmt->fetchColumn();

        // Obtener alumnos de ese grupo y sus notas
        $stmt = $pdo->prepare("
            SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI,
                   n.nota1, n.nota2, n.nota3, n.promedio, n.estado
            FROM Estudiantes e
            JOIN Personas p ON e.ID_persona = p.ID_persona
            LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante AND n.ID_materia = :materia AND n.ID_grupo = :grupo
            WHERE e.ID_grupo = :grupo AND e.Estado = TRUE
            ORDER BY p.apellido, p.nombre
        ");
        $stmt->execute([
            'grupo' => $id_grupo_sel,
            'materia' => $id_materia_sel
        ]);
        $alumnos = $stmt->fetchAll();

    } catch (PDOException $e) {
        $error = "Error al cargar la planilla de alumnos: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planilla Docente | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PORTAL DOCENTE</span>
            </div>
            
            <h4 style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); padding: 0 16px; margin-bottom: 5px; font-weight: 600; letter-spacing: 0.5px;">Carga Horaria Asignada</h4>
            
            <ul class="sidebar-menu">
                <?php if (count($asignaciones) === 0): ?>
                    <li style="padding: 12px 16px; color: var(--text-muted); font-size: 0.85rem;">No tienes grupos asignados en esta gestión.</li>
                <?php else: ?>
                    <?php foreach ($asignaciones as $asig): 
                        $isActive = ($asig['id_grupo'] == $id_grupo_sel && $asig['id_materia'] == $id_materia_sel);
                    ?>
                        <li class="<?= $isActive ? 'active' : '' ?>">
                            <a href="docente_dashboard.php?grupo=<?= $asig['id_grupo'] ?>&materia=<?= $asig['id_materia'] ?>">
                                <div>
                                    <div style="font-weight: 600; font-size: 0.9rem; color: #333;"><?= htmlspecialchars($asig['nombre_grupo']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($asig['nombre_materia']) ?></div>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <li style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <a href="../P_Seguridad/logout.php" style="color: var(--error);">Cerrar Sesión</a>
                </li>
            </ul>
        </aside>

        <!-- ÁREA PRINCIPAL DE CONTENIDO -->
        <main class="main-content">
            
            <!-- ENCABEZADO -->
            <header class="dash-header">
                <div>
                    <h2>Planilla de Notas</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registro y edición de calificaciones oficiales para el curso preuniversitario (CUP)</p>
                </div>
                <div class="user-info-badge">
                    <span class="avatar">D</span>
                    <span style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($realname) ?></span>
                </div>
            </header>

            <!-- MENSAJES DE ESTADO -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- FORMULARIO DE CALIFICACIÓN -->
            <?php if ($calificar_student): ?>
                <section class="card" style="margin-bottom: 40px; border-color: var(--primary);">
                    <h3 style="font-size: 1.4rem; margin-bottom: 20px; color: var(--primary);">Calificar Estudiante: <?= htmlspecialchars($calificar_student['nombre'] . ' ' . $calificar_student['apellido']) ?></h3>
                    
                    <form method="POST" action="docente_dashboard.php?grupo=<?= $id_grupo_sel ?>&materia=<?= htmlspecialchars($id_materia_sel) ?>">
                        <input type="hidden" name="action" value="save_grades">
                        <input type="hidden" name="id_estudiante" value="<?= $calificar_student['id_estudiante'] ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="nota1">Primer Parcial (0-100) *</label>
                                <input type="number" id="nota1" name="nota1" class="form-control" 
                                       min="0" max="100" step="0.01" 
                                       value="<?= htmlspecialchars(number_format($calificar_student['nota1'] ?? 0.00, 2)) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="nota2">Segundo Parcial (0-100) *</label>
                                <input type="number" id="nota2" name="nota2" class="form-control" 
                                       min="0" max="100" step="0.01" 
                                       value="<?= htmlspecialchars(number_format($calificar_student['nota2'] ?? 0.00, 2)) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="nota3">Examen Final (0-100) *</label>
                                <input type="number" id="nota3" name="nota3" class="form-control" 
                                       min="0" max="100" step="0.01" 
                                       value="<?= htmlspecialchars(number_format($calificar_student['nota3'] ?? 0.00, 2)) ?>" required>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 15px;">
                            <a href="docente_dashboard.php?grupo=<?= $id_grupo_sel ?>&materia=<?= htmlspecialchars($id_materia_sel) ?>" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Guardar Calificaciones</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <!-- LISTA DE ESTUDIANTES A EVALUAR -->
            <?php if ($id_grupo_sel && $id_materia_sel): ?>
                <section class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <h3 style="font-size: 1.4rem; font-weight: 700; color: var(--primary);"><?= htmlspecialchars($grupo_nombre) ?> - Estudiantes Inscritos</h3>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 3px;">Materia activa: <strong><?= htmlspecialchars($materia_nombre) ?></strong></p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Estudiante</th>
                                    <th>CI / Carnet</th>
                                    <th>Parcial 1 (30%)</th>
                                    <th>Parcial 2 (30%)</th>
                                    <th>Examen Final (40%)</th>
                                    <th>Promedio</th>
                                    <th>Estado Admisión</th>
                                    <th style="text-align: center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($alumnos) === 0): ?>
                                    <tr><td colspan="8" style="text-align: center; color: var(--text-muted);">No hay estudiantes matriculados en este grupo actualmente.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($alumnos as $row): 
                                        $promedio = isset($row['promedio']) ? number_format($row['promedio'], 2) : '0.00';
                                        $estado = $row['estado'] ?? 'REPROBADO';
                                        $badge_class = ($estado === 'APROBADO') ? 'badge-success' : 'badge-danger';
                                    ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                            <td><?= htmlspecialchars($row['ci']) ?></td>
                                            <td><?= isset($row['nota1']) ? number_format($row['nota1'], 2) : '0.00' ?></td>
                                            <td><?= isset($row['nota2']) ? number_format($row['nota2'], 2) : '0.00' ?></td>
                                            <td><?= isset($row['nota3']) ? number_format($row['nota3'], 2) : '0.00' ?></td>
                                            <td style="font-weight: 700; color: var(--primary);"><?= $promedio ?></td>
                                            <td><span class="badge <?= $badge_class ?>"><?= $estado ?></span></td>
                                            <td style="text-align: center;">
                                                <a href="docente_dashboard.php?grupo=<?= $id_grupo_sel ?>&materia=<?= htmlspecialchars($id_materia_sel) ?>&calificar_id=<?= $row['id_estudiante'] ?>" class="btn btn-primary btn-small" style="padding: 6px 12px; font-size: 0.8rem;">Calificar</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 50px;">
                    <h3>Bienvenido a tu Portal Académico</h3>
                    <p style="color: var(--text-muted); max-width: 500px; margin: 15px auto;">Actualmente no posees materias o grupos asignados vigentes para visualizar. Por favor contacta con el administrador académico de la facultad.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>
