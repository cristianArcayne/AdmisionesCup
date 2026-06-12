<?php
/**
 * Panel de Administración - CUP FICCT
 */

session_start();
require_once 'config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php?err=' . urlencode('Acceso denegado: Se requieren privilegios de Administrador.'));
    exit;
}

$realname = $_SESSION['user_realname'] ?? 'Administrador';

// --- CONSULTAS PARA MÉTRICAS DEL DASHBOARD ---
try {
    // 1. Total Inscritos
    $stmt = $pdo->query("SELECT COUNT(*) FROM Estudiantes");
    $total_inscritos = $stmt->fetchColumn();

    // 2. Total Aprobados (Estudiantes con promedio general >= 60)
    $stmt = $pdo->query("SELECT COUNT(*) FROM (
        SELECT ID_estudiante FROM Notas 
        GROUP BY ID_estudiante 
        HAVING AVG(promedio) >= 60
    ) AS aprobados");
    $total_aprobados = $stmt->fetchColumn();

    // 3. Total Reprobados (Estudiantes con promedio general < 60)
    // Se cuentan también estudiantes sin notas cargadas aún (promedio = 0)
    $stmt = $pdo->query("SELECT COUNT(*) FROM (
        SELECT e.ID_estudiante FROM Estudiantes e
        LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
        GROUP BY e.ID_estudiante 
        HAVING AVG(COALESCE(n.promedio, 0)) < 60
    ) AS reprobados");
    $total_reprobados = $stmt->fetchColumn();

    // 4. Total Grupos Habilitados
    $stmt = $pdo->query("SELECT COUNT(*) FROM Grupos WHERE estado = TRUE");
    $total_grupos = $stmt->fetchColumn();

} catch (PDOException $e) {
    die("Error al cargar métricas: " . $e->getMessage());
}

// --- CARGA DE REPORTES (SEGÚN LA SECCIÓN SELECCIONADA) ---
$report = isset($_GET['report']) ? $_GET['report'] : 'general';
$report_title = "";
$report_data = [];

try {
    switch ($report) {
        case 'aprobados':
            $report_title = "Postulantes Aprobados (Promedio General >= 60)";
            // Obtener estudiantes con promedio de sus materias >= 60
            $stmt = $pdo->query("
                SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo, ROUND(AVG(n.promedio), 2) AS promedio_gral
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                GROUP BY e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo
                HAVING AVG(n.promedio) >= 60
                ORDER BY promedio_gral DESC
            ");
            $report_data = $stmt->fetchAll();
            break;

        case 'reprobados':
            $report_title = "Postulantes Reprobados (Promedio General < 60)";
            $stmt = $pdo->query("
                SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo, ROUND(AVG(COALESCE(n.promedio, 0)), 2) AS promedio_gral
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                GROUP BY e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo
                HAVING AVG(COALESCE(n.promedio, 0)) < 60
                ORDER BY promedio_gral ASC
            ");
            $report_data = $stmt->fetchAll();
            break;

        case 'materias':
            $report_title = "Estadísticas de Acreditación por Materia";
            $stmt = $pdo->query("
                SELECT m.nombre_materia, 
                       ROUND(AVG(n.promedio), 2) AS promedio_materia,
                       COUNT(CASE WHEN n.promedio >= 60 THEN 1 END) AS aprobados,
                       COUNT(CASE WHEN n.promedio < 60 THEN 1 END) AS reprobados,
                       COUNT(n.ID_nota) AS total_evaluados
                FROM Materias m
                LEFT JOIN Notas n ON m.ID_materia = n.ID_materia
                GROUP BY m.ID_materia, m.nombre_materia
                ORDER BY m.nombre_materia
            ");
            $report_data = $stmt->fetchAll();
            break;

        case 'docentes':
            $report_title = "Contrataciones y Carga de Docentes por Grupos";
            $stmt = $pdo->query("
                SELECT p.nombre, p.apellido, p.telefono, d.Especialidad, 
                       g.nombre_grupo, m.nombre_materia,
                       (CASE WHEN d.tiene_maestria THEN 'Maestría' ELSE '' END) || 
                       (CASE WHEN d.tiene_maestria AND d.tiene_diplomado THEN ' y ' ELSE '' END) || 
                       (CASE WHEN d.tiene_diplomado THEN 'Diplomado' ELSE '' END) AS titulos
                FROM Docentes d
                JOIN Personas p ON d.ID_persona = p.ID_persona
                JOIN Asignaciones a ON d.ID_docente = a.ID_docente
                JOIN Grupos g ON a.ID_grupo = g.ID_grupo
                JOIN Materias m ON a.ID_materia = m.ID_materia
                ORDER BY p.apellido, g.nombre_grupo
            ");
            $report_data = $stmt->fetchAll();
            break;

        case 'grupos_ranking':
            $report_title = "Ranking de Grupos con Mayor Cantidad de Aprobados";
            $stmt = $pdo->query("
                SELECT g.nombre_grupo, g.cantidad_estudiantes,
                       COUNT(DISTINCT CASE WHEN n.promedio >= 60 THEN e.ID_estudiante END) AS cant_aprobados,
                       COUNT(DISTINCT CASE WHEN n.promedio < 60 THEN e.ID_estudiante END) AS cant_reprobados
                FROM Grupos g
                LEFT JOIN Estudiantes e ON g.ID_grupo = e.ID_grupo
                LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                WHERE g.estado = TRUE
                GROUP BY g.ID_grupo, g.nombre_grupo, g.cantidad_estudiantes
                ORDER BY cant_aprobados DESC
            ");
            $report_data = $stmt->fetchAll();
            break;

        case 'general':
        default:
            $report = 'general';
            $report_title = "Lista General de Postulantes Admitidos (Estudiantes)";
            $stmt = $pdo->query("
                SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, p.correo_personal, p.telefono, c.nombre_carrera, g.nombre_grupo
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                ORDER BY e.ID_estudiante DESC
            ");
            $report_data = $stmt->fetchAll();
            break;
    }
} catch (PDOException $e) {
    $error = "Error al generar reporte: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo | FICCT</title>
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
                <li class="active">
                    <a href="admin_dashboard.php">Panel Principal</a>
                </li>
                <li>
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
            
            <!-- ENCABEZADO -->
            <header class="dash-header">
                <div>
                    <h2>Panel de Control</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Gestión del Proceso de Admisión Universitaria (CUP)</p>
                </div>
                <div class="user-info-badge">
                    <span class="avatar">A</span>
                    <span style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($realname) ?></span>
                </div>
            </header>

            <!-- GRILLA DE CONTADORES / ESTADÍSTICAS -->
            <section class="stats-grid">
                
                <div class="stat-card">
                    <div class="info">
                        <span class="label">Total Estudiantes</span>
                        <span class="value"><?= $total_inscritos ?></span>
                    </div>
                    <div class="icon-box"></div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Total Aprobados</span>
                        <span class="value" style="color: var(--success);"><?= $total_aprobados ?></span>
                    </div>
                    <div class="icon-box"></div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Total Reprobados</span>
                        <span class="value" style="color: var(--error);"><?= $total_reprobados ?></span>
                    </div>
                    <div class="icon-box"></div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Grupos Habilitados</span>
                        <span class="value" style="color: var(--warning);"><?= $total_grupos ?></span>
                    </div>
                    <div class="icon-box"></div>
                </div>

            </section>

            <!-- VISTA DE REPORTES -->
            <section class="glass-panel">
                
                <!-- Report Selector Tabs -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                    <a href="admin_dashboard.php?report=general" class="btn <?= $report === 'general' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Lista General</a>
                    <a href="admin_dashboard.php?report=aprobados" class="btn <?= $report === 'aprobados' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Aprobados</a>
                    <a href="admin_dashboard.php?report=reprobados" class="btn <?= $report === 'reprobados' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Reprobados</a>
                    <a href="admin_dashboard.php?report=materias" class="btn <?= $report === 'materias' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Notas por Materia</a>
                    <a href="admin_dashboard.php?report=docentes" class="btn <?= $report === 'docentes' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Carga Docentes</a>
                    <a href="admin_dashboard.php?report=grupos_ranking" class="btn <?= $report === 'grupos_ranking' ? 'btn-primary' : 'btn-secondary' ?> btn-small">Ranking Grupos</a>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 10px; flex-wrap: wrap; gap: 15px;">
                    <h3 class="gradient-text" style="font-size: 1.4rem; font-weight: 700;"><?= $report_title ?></h3>
                    
                    <div style="display: flex; gap: 10px;">
                        <button onclick="window.print()" class="btn btn-secondary btn-small">Imprimir Reporte</button>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- CONTENIDO DEL REPORTE -->
                <div class="table-responsive">
                    
                    <?php if ($report === 'general'): ?>
                        <!-- 1. REPORTE GENERAL -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombres y Apellidos</th>
                                    <th>CI / Carnet</th>
                                    <th>Carrera</th>
                                    <th>Grupo Asignado</th>
                                    <th>Teléfono</th>
                                    <th>Correo Electrónico</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($report_data) === 0): ?>
                                    <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay estudiantes registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td><?= $row['id_estudiante'] ?></td>
                                            <td style="font-weight: 600; color: #333333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                            <td><?= htmlspecialchars($row['ci']) ?></td>
                                            <td><span class="badge badge-success"><?= htmlspecialchars($row['nombre_carrera']) ?></span></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                            <td><?= htmlspecialchars($row['telefono']) ?></td>
                                            <td style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($row['correo_personal']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ($report === 'aprobados'): ?>
                        <!-- 2. REPORTE APROBADOS -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Nombres y Apellidos</th>
                                    <th>CI / Carnet</th>
                                    <th>Carrera</th>
                                    <th>Grupo</th>
                                    <th>Promedio Gral. Exámenes</th>
                                    <th>Estado Admisión</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($report_data) === 0): ?>
                                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay postulantes aprobados en esta gestión académica.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                            <td><?= htmlspecialchars($row['ci']) ?></td>
                                            <td><?= htmlspecialchars($row['nombre_carrera']) ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                            <td style="font-weight: 700; color: var(--success); font-size: 1.1rem;"><?= $row['promedio_gral'] ?></td>
                                            <td><span class="badge badge-success">APROBADO</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ($report === 'reprobados'): ?>
                        <!-- 3. REPORTE REPROBADOS -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Nombres y Apellidos</th>
                                    <th>CI / Carnet</th>
                                    <th>Carrera</th>
                                    <th>Grupo</th>
                                    <th>Promedio Gral. Exámenes</th>
                                    <th>Estado Admisión</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($report_data) === 0): ?>
                                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay postulantes reprobados en esta gestión académica.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                            <td><?= htmlspecialchars($row['ci']) ?></td>
                                            <td><?= htmlspecialchars($row['nombre_carrera']) ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                            <td style="font-weight: 700; color: var(--error); font-size: 1.1rem;"><?= $row['promedio_gral'] ?></td>
                                            <td><span class="badge badge-danger">REPROBADO</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ($report === 'materias'): ?>
                        <!-- 4. REPORTE PROMEDIOS GENERALES POR MATERIA -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Área Evaluada / Materia</th>
                                    <th>Promedio General de Notas</th>
                                    <th>Aprobados (>=60)</th>
                                    <th>Reprobados (<60)</th>
                                    <th>Rendimiento Visual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): 
                                    $prom = $row['promedio_materia'] ?? 0;
                                    $percent = min(100, max(0, $prom));
                                    $color = $prom >= 60 ? 'var(--success)' : 'var(--error)';
                                ?>
                                    <tr>
                                        <td style="font-weight: 600; color: #333333; font-size: 1.1rem;"><?= htmlspecialchars($row['nombre_materia']) ?></td>
                                        <td style="font-weight: 700; color: <?= $color ?>; font-size: 1.2rem;"><?= $prom ?> / 100</td>
                                        <td style="color: var(--success); font-weight: 600;"><?= $row['aprobados'] ?> alumnos</td>
                                        <td style="color: var(--error);"><?= $row['reprobados'] ?> alumnos</td>
                                        <td style="width: 250px;">
                                            <div style="background: rgba(0,0,0,0.05); width: 100%; height: 8px; border-radius: 4px; overflow: hidden; border: 1px solid var(--border-color);">
                                                <div style="background: <?= $color ?>; width: <?= $percent ?>%; height: 100%; border-radius: 4px;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($report === 'docentes'): ?>
                        <!-- 5. REPORTE DOCENTES POR GRUPOS -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Docente</th>
                                    <th>Especialidad Profesional</th>
                                    <th>Grado Académico</th>
                                    <th>Grupo Asignado</th>
                                    <th>Materia Dictada</th>
                                    <th>Contacto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($report_data) === 0): ?>
                                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay asignaciones de docentes vigentes.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333333;"><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></td>
                                            <td><?= htmlspecialchars($row['especialidad']) ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($row['titulos']) ?></span></td>
                                            <td><span class="badge badge-warning"><?= htmlspecialchars($row['nombre_grupo']) ?></span></td>
                                            <td style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($row['nombre_materia']) ?></td>
                                            <td><?= htmlspecialchars($row['telefono']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ($report === 'grupos_ranking'): ?>
                        <!-- 6. REPORTE GRUPOS RANKING APROBADOS -->
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Nombre del Grupo</th>
                                    <th>Estudiantes Matriculados</th>
                                    <th>Total Aprobados</th>
                                    <th>Total Reprobados</th>
                                    <th>Porcentaje de Éxito</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($report_data) === 0): ?>
                                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No hay información de grupos disponible.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): 
                                        $total = (int)$row['cantidad_estudiantes'];
                                        $aprob = (int)$row['cant_aprobados'];
                                        $pct = $total > 0 ? round(($aprob / $total) * 100, 1) : 0;
                                        $color = $pct >= 50 ? 'var(--success)' : 'var(--warning)';
                                    ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333333; font-size: 1.1rem;"><?= htmlspecialchars($row['nombre_grupo']) ?></td>
                                            <td style="font-weight: 600; color: var(--primary);"><?= $total ?> / 70 max</td>
                                            <td style="color: var(--success); font-weight: 600;">Aprobados: <?= $aprob ?></td>
                                            <td style="color: var(--error);">Reprobados: <?= $row['cant_reprobados'] ?></td>
                                            <td style="font-weight: 700; color: <?= $color ?>; font-size: 1.1rem;"><?= $pct ?> %</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                </div>

            </section>

        </main>
    </div>
</body>
</html>
