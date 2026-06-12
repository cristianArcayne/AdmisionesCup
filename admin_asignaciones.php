<?php
/**
 * Asignaciones y Carga Horaria de Docentes - Panel Administrativo
 * Valida grado académico y límite de 4 aulas (CU14)
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
    $id_asignacion = (int)$_GET['delete'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM Asignaciones WHERE ID_asignacion = :id_asignacion");
        $stmt->execute(['id_asignacion' => $id_asignacion]);
        $message = "La asignación docente se eliminó correctamente.";
    } catch (PDOException $e) {
        $error = "Error al eliminar la asignación: " . $e->getMessage();
    }
}

// --- 2. PROCESAR ACCIÓN DE REGISTRO (INSERT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_asignacion') {
    $docente_id = (int)$_POST['docente_id'];
    $grupo_id = (int)$_POST['grupo_id'];
    $materia_id = $_POST['materia_id'];
    $gestion = date('Y');

    if (empty($docente_id) || empty($grupo_id) || empty($materia_id)) {
        $error = "Todos los campos son obligatorios.";
    } else {
        try {
            $pdo->beginTransaction();

            // A. Consultar grado académico del docente (CU14: consultarGradoAcademicoDocente)
            $stmt = $pdo->prepare("
                SELECT d.tiene_maestria, d.tiene_diplomado, p.nombre, p.apellido 
                FROM Docentes d
                JOIN Personas p ON d.ID_persona = p.ID_persona
                WHERE d.ID_docente = :docente_id
            ");
            $stmt->execute(['docente_id' => $docente_id]);
            $docente_info = $stmt->fetch();

            if (!$docente_info) {
                throw new Exception("Docente no encontrado.");
            }

            // Validar bandera true/false de posgrado (CU14: validarRequisitosYAsignarCarga)
            $has_maestria = (bool)($docente_info['tiene_maestria'] ?? false);
            $has_diplomado = (bool)($docente_info['tiene_diplomado'] ?? false);

            if (!$has_maestria && !$has_diplomado) {
                throw new Exception("El docente " . htmlspecialchars($docente_info['nombre'] . ' ' . $docente_info['apellido']) . " no posee posgrados registrados (Maestría o Diplomado) obligatorios para impartir materias.");
            }

            // B. Verificar límite de carga académica (máximo 4 aulas/grupos) (CU14: verificarCargaMaximaPermitida)
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ID_grupo) 
                FROM Asignaciones 
                WHERE ID_docente = :docente_id AND gestion_academica = :gestion
            ");
            $stmt->execute(['docente_id' => $docente_id, 'gestion' => $gestion]);
            $carga_actual = (int)$stmt->fetchColumn();

            // Si ya tiene 4 grupos asignados, verificar si este nuevo grupo es uno de los que ya tiene
            // (un docente puede dar más de una materia en un mismo grupo si fuera el caso, pero no puede tener más de 4 grupos/aulas distintas)
            if ($carga_actual >= 4) {
                // Verificar si ya está asignado a este grupo
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM Asignaciones 
                    WHERE ID_docente = :docente_id AND ID_grupo = :grupo_id AND gestion_academica = :gestion
                ");
                $stmt->execute(['docente_id' => $docente_id, 'grupo_id' => $grupo_id, 'gestion' => $gestion]);
                $is_already_in_group = (int)$stmt->fetchColumn() > 0;

                if (!$is_already_in_group) {
                    throw new Exception("El docente " . htmlspecialchars($docente_info['nombre'] . ' ' . $docente_info['apellido']) . " ya cuenta con 4 aulas distintas asignadas en esta gestión. Límite de carga excedido.");
                }
            }

            // C. Insertar registro de carga (CU14: insertarRegistroCargaDocente)
            $stmt = $pdo->prepare("
                INSERT INTO Asignaciones (ID_docente, ID_grupo, ID_materia, gestion_academica) 
                VALUES (:docente_id, :grupo_id, :materia_id, :gestion)
            ");
            $stmt->execute([
                'docente_id' => $docente_id,
                'grupo_id' => $grupo_id,
                'materia_id' => $materia_id,
                'gestion' => $gestion
            ]);

            $pdo->commit();
            $message = "Asignación horaria registrada con éxito.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == '23505') {
                $error = "El docente ya se encuentra asignado a este grupo y materia para la gestión actual.";
            } else {
                $error = "Error de base de datos al asignar carga docente: " . $e->getMessage();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// --- 3. CARGAR CATÁLOGOS Y ASIGNACIONES ---
$docentes = [];
$grupos = [];
$materias = [];
$asignaciones = [];

try {
    // Listar Docentes
    $docentes = $pdo->query("
        SELECT d.ID_docente, p.nombre, p.apellido, d.tiene_maestria, d.tiene_diplomado 
        FROM Docentes d
        JOIN Personas p ON d.ID_persona = p.ID_persona
        WHERE d.Estado = TRUE
        ORDER BY p.apellido, p.nombre
    ")->fetchAll();

    // Listar Grupos
    $grupos = $pdo->query("
        SELECT ID_grupo, nombre_grupo, cantidad_estudiantes 
        FROM Grupos 
        WHERE estado = TRUE
        ORDER BY nombre_grupo
    ")->fetchAll();

    // Listar Materias
    $materias = $pdo->query("
        SELECT ID_materia, nombre_materia 
        FROM Materias
        ORDER BY nombre_materia
    ")->fetchAll();

    // Listar Asignaciones Existentes
    $asignaciones = $pdo->query("
        SELECT a.ID_asignacion, p.nombre, p.apellido, g.nombre_grupo, m.nombre_materia, a.gestion_academica
        FROM Asignaciones a
        JOIN Docentes d ON a.ID_docente = d.ID_docente
        JOIN Personas p ON d.ID_persona = p.ID_persona
        JOIN Grupos g ON a.ID_grupo = g.ID_grupo
        JOIN Materias m ON a.ID_materia = m.ID_materia
        ORDER BY a.ID_asignacion DESC
    ")->fetchAll();

} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carga de Docentes | FICCT</title>
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
                <li>
                    <a href="admin_estudiantes.php">Gestionar Estudiantes</a>
                </li>
                <li>
                    <a href="admin_docentes.php">Gestionar Docentes</a>
                </li>
                <li class="active">
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
                    <h2>Asignaciones y Carga Horaria</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Vincular docentes calificados con posgrado a materias y grupos de estudio</p>
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

            <!-- FORMULARIO DE ASIGNACIÓN -->
            <section class="card" style="margin-bottom: 40px;">
                <h3 style="font-size: 1.4rem; margin-bottom: 25px; color: var(--primary);">Registrar Nueva Asignación</h3>
                
                <form method="POST" action="admin_asignaciones.php">
                    <input type="hidden" name="action" value="create_asignacion">

                    <div class="form-row">
                        
                        <div class="form-group">
                            <label for="docente_id">Docente Calificado *</label>
                            <select id="docente_id" name="docente_id" class="form-control" required>
                                <option value="">Seleccionar Docente...</option>
                                <?php foreach ($docentes as $d): 
                                    $titulos = [];
                                    if ($d['tiene_maestria']) $titulos[] = "Maestría";
                                    if ($d['tiene_diplomado']) $titulos[] = "Diplomado";
                                    $titulos_str = count($titulos) > 0 ? implode(" y ", $titulos) : "Sin Posgrado";
                                ?>
                                    <option value="<?= $d['id_docente'] ?>"><?= htmlspecialchars($d['nombre'] . ' ' . $d['apellido']) ?> (<?= $titulos_str ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="grupo_id">Grupo Asignado *</label>
                            <select id="grupo_id" name="grupo_id" class="form-control" required>
                                <option value="">Seleccionar Grupo...</option>
                                <?php foreach ($grupos as $g): ?>
                                    <option value="<?= $g['id_grupo'] ?>"><?= htmlspecialchars($g['nombre_grupo']) ?> (<?= $g['cantidad_estudiantes'] ?> / 70 Alumnos)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="materia_id">Materia Impartida *</label>
                            <select id="materia_id" name="materia_id" class="form-control" required>
                                <option value="">Seleccionar Materia...</option>
                                <?php foreach ($materias as $m): ?>
                                    <option value="<?= $m['id_materia'] ?>"><?= htmlspecialchars($m['nombre_materia']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
                        <button type="submit" class="btn btn-primary">Registrar Asignación</button>
                    </div>
                </form>
            </section>

            <!-- TABLA DE ASIGNACIONES ACTIVAS -->
            <section class="card">
                <h3 style="font-size: 1.4rem; margin-bottom: 20px; color: var(--primary);">Planilla de Carga Académica</h3>

                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Docente</th>
                                <th>Grupo</th>
                                <th>Materia</th>
                                <th>Gestión Académica</th>
                                <th style="text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($asignaciones) === 0): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No se encuentran asignaciones de docentes registradas.</td></tr>
                            <?php else: ?>
                                <?php foreach ($asignaciones as $row): ?>
                                    <tr>
                                        <td><?= $row['id_asignacion'] ?></td>
                                        <td style="font-weight: 600; color: #333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                        <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($row['nombre_materia']) ?></td>
                                        <td><?= htmlspecialchars($row['gestion_academica']) ?></td>
                                        <td style="text-align: center;">
                                            <a href="admin_asignaciones.php?delete=<?= $row['id_asignacion'] ?>" onclick="return confirm('¿Estás seguro de que deseas eliminar esta asignación?')" class="btn btn-danger btn-small" style="padding: 6px 12px; font-size: 0.8rem;">Eliminar</a>
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
