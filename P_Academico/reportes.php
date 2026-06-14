<?php
/**
 * Generador de Reportes y Exportación - Módulo 9: REPORTES
 */

session_start();
require_once '../config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../P_Seguridad/login.php?err=' . urlencode('Acceso denegado.'));
    exit;
}

$error = "";

// Cargar catálogos para filtros
$carreras = [];
$materias = [];
$grupos = [];
try {
    $carreras = $pdo->query("SELECT ID_carrera, nombre_carrera FROM Carreras WHERE estado = TRUE ORDER BY nombre_carrera")->fetchAll();
    $materias = $pdo->query("SELECT ID_materia, nombre_materia FROM Materias ORDER BY nombre_materia")->fetchAll();
    $grupos = $pdo->query("SELECT ID_grupo, nombre_grupo FROM Grupos WHERE estado = TRUE ORDER BY nombre_grupo")->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar catálogos de filtros: " . $e->getMessage();
}

// Filtros seleccionados
$filter_carrera = isset($_GET['carrera']) && $_GET['carrera'] !== '' ? (int)$_GET['carrera'] : null;
$filter_materia = isset($_GET['materia']) && $_GET['materia'] !== '' ? $_GET['materia'] : null;
$filter_grupo = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? (int)$_GET['grupo'] : null;
$report_type = $_GET['report_type'] ?? 'general';

$report_title = "Reporte General";
$report_headers = [];
$report_data = [];

// Construcción de consultas SQL con filtros
try {
    if ($report_type === 'general') {
        $report_title = "Lista General de Postulantes Admitidos";
        $report_headers = ["ID Est.", "Nombres y Apellidos", "CI", "Correo", "Carrera", "Grupo"];
        
        $sql = "SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, p.correo_personal, c.nombre_carrera, g.nombre_grupo
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                WHERE e.estado = TRUE";
        
        $params = [];
        if ($filter_carrera) {
            $sql .= " AND e.ID_carrera = :carrera";
            $params['carrera'] = $filter_carrera;
        }
        if ($filter_grupo) {
            $sql .= " AND e.ID_grupo = :grupo";
            $params['grupo'] = $filter_grupo;
        }
        $sql .= " ORDER BY e.ID_estudiante DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $report_data[] = [
                $row['id_estudiante'],
                $row['nombre'] . ' ' . $row['apellido'],
                $row['ci'],
                $row['correo_personal'],
                $row['nombre_carrera'],
                $row['nombre_grupo']
            ];
        }
    } 
    elseif ($report_type === 'aprobados') {
        $report_title = "Postulantes Aprobados (Promedio >= 60)";
        $report_headers = ["ID Est.", "Nombres y Apellidos", "CI", "Carrera", "Grupo", "Promedio Gral"];
        
        $sql = "SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo, ROUND(AVG(n.promedio), 2) AS promedio_gral
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                WHERE e.estado = TRUE";
        
        $params = [];
        if ($filter_carrera) {
            $sql .= " AND e.ID_carrera = :carrera";
            $params['carrera'] = $filter_carrera;
        }
        if ($filter_grupo) {
            $sql .= " AND e.ID_grupo = :grupo";
            $params['grupo'] = $filter_grupo;
        }
        $sql .= " GROUP BY e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo
                  HAVING AVG(n.promedio) >= 60
                  ORDER BY promedio_gral DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $report_data[] = [
                $row['id_estudiante'],
                $row['nombre'] . ' ' . $row['apellido'],
                $row['ci'],
                $row['nombre_carrera'],
                $row['nombre_grupo'],
                $row['promedio_gral']
            ];
        }
    } 
    elseif ($report_type === 'reprobados') {
        $report_title = "Postulantes Reprobados (Promedio < 60)";
        $report_headers = ["ID Est.", "Nombres y Apellidos", "CI", "Carrera", "Grupo", "Promedio Gral"];
        
        $sql = "SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo, ROUND(AVG(COALESCE(n.promedio, 0)), 2) AS promedio_gral
                FROM Estudiantes e
                JOIN Personas p ON e.ID_persona = p.ID_persona
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera
                JOIN Grupos g ON e.ID_grupo = g.ID_grupo
                LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                WHERE e.estado = TRUE";
        
        $params = [];
        if ($filter_carrera) {
            $sql .= " AND e.ID_carrera = :carrera";
            $params['carrera'] = $filter_carrera;
        }
        if ($filter_grupo) {
            $sql .= " AND e.ID_grupo = :grupo";
            $params['grupo'] = $filter_grupo;
        }
        $sql .= " GROUP BY e.ID_estudiante, p.nombre, p.apellido, p.CI, c.nombre_carrera, g.nombre_grupo
                  HAVING AVG(COALESCE(n.promedio, 0)) < 60
                  ORDER BY promedio_gral ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $report_data[] = [
                $row['id_estudiante'],
                $row['nombre'] . ' ' . $row['apellido'],
                $row['ci'],
                $row['nombre_carrera'],
                $row['nombre_grupo'],
                $row['promedio_gral']
            ];
        }
    } 
    elseif ($report_type === 'materias') {
        $report_title = "Acreditación y Rendimiento por Materia";
        $report_headers = ["Materia", "Promedio Global", "Total Aprobados", "Total Reprobados", "Total Evaluados"];
        
        $sql = "SELECT m.nombre_materia, 
                       ROUND(AVG(n.promedio), 2) AS promedio_materia,
                       COUNT(CASE WHEN n.promedio >= 60 THEN 1 END) AS aprobados,
                       COUNT(CASE WHEN n.promedio < 60 THEN 1 END) AS reprobados,
                       COUNT(n.ID_nota) AS total_evaluados
                FROM Materias m
                LEFT JOIN Notas n ON m.ID_materia = n.ID_materia
                WHERE 1=1";
        
        $params = [];
        if ($filter_materia) {
            $sql .= " AND m.ID_materia = :materia";
            $params['materia'] = $filter_materia;
        }
        $sql .= " GROUP BY m.ID_materia, m.nombre_materia ORDER BY m.nombre_materia";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $report_data[] = [
                $row['nombre_materia'],
                $row['promedio_materia'] ?? '0.00',
                $row['aprobados'],
                $row['reprobados'],
                $row['total_evaluados']
            ];
        }
    } 
    elseif ($report_type === 'docentes') {
        $report_title = "Carga y Contrataciones de Planta Docente";
        $report_headers = ["Docente", "Especialidad", "Postgrado", "Materia", "Grupo"];
        
        $sql = "SELECT p.nombre, p.apellido, d.Especialidad, 
                       g.nombre_grupo, m.nombre_materia,
                       (CASE WHEN d.tiene_maestria THEN 'Maestría' ELSE '' END) || 
                       (CASE WHEN d.tiene_maestria AND d.tiene_diplomado THEN ' y ' ELSE '' END) || 
                       (CASE WHEN d.tiene_diplomado THEN 'Diplomado' ELSE '' END) AS titulos
                FROM Docentes d
                JOIN Personas p ON d.ID_persona = p.ID_persona
                LEFT JOIN Asignaciones a ON d.ID_docente = a.ID_docente
                LEFT JOIN Grupos g ON a.ID_grupo = g.ID_grupo
                LEFT JOIN Materias m ON a.ID_materia = m.ID_materia
                WHERE 1=1";
        
        $params = [];
        if ($filter_materia) {
            $sql .= " AND a.ID_materia = :materia";
            $params['materia'] = $filter_materia;
        }
        if ($filter_grupo) {
            $sql .= " AND a.ID_grupo = :grupo";
            $params['grupo'] = $filter_grupo;
        }
        $sql .= " ORDER BY p.apellido, g.nombre_grupo";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $report_data[] = [
                $row['nombre'] . ' ' . $row['apellido'],
                $row['especialidad'],
                $row['titulos'] ?: 'Ninguno',
                $row['nombre_materia'] ?: 'Sin asignar',
                $row['nombre_grupo'] ?: 'Sin asignar'
            ];
        }
    } 
    elseif ($report_type === 'grupos_ranking') {
        $report_title = "Ranking de Grupos con Mayor Aprobación";
        $report_headers = ["Grupo", "Inscritos", "Aprobados", "Reprobados", "Éxito (%)"];
        
        $sql = "SELECT g.nombre_grupo, g.cantidad_estudiantes,
                       COUNT(DISTINCT CASE WHEN n.promedio >= 60 THEN e.ID_estudiante END) AS cant_aprobados,
                       COUNT(DISTINCT CASE WHEN n.promedio < 60 THEN e.ID_estudiante END) AS cant_reprobados
                FROM Grupos g
                LEFT JOIN Estudiantes e ON g.ID_grupo = e.ID_grupo
                LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
                WHERE g.estado = TRUE";
        
        $params = [];
        if ($filter_grupo) {
            $sql .= " AND g.ID_grupo = :grupo";
            $params['grupo'] = $filter_grupo;
        }
        $sql .= " GROUP BY g.ID_grupo, g.nombre_grupo, g.cantidad_estudiantes
                  ORDER BY cant_aprobados DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetchAll();
        foreach ($res as $row) {
            $total = (int)$row['cantidad_estudiantes'];
            $aprob = (int)$row['cant_aprobados'];
            $pct = $total > 0 ? round(($aprob / $total) * 100, 1) : 0;

            $report_data[] = [
                $row['nombre_grupo'],
                $total,
                $aprob,
                $row['cant_reprobados'],
                $pct . '%'
            ];
        }
    }
} catch (PDOException $e) {
    $error = "Error al compilar consulta de reporte: " . $e->getMessage();
}

// --- 4. EXPORTAR EXCEL (CSV) ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_' . $report_type . '_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Escribir cabecera
    fputcsv($output, $report_headers);
    
    // Escribir filas
    foreach ($report_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Reportes | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Estilos de impresión premium */
        @media print {
            body {
                background: white;
                color: black;
            }
            .sidebar, .dash-header, .glass-panel:first-of-type, .btn, .card {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .glass-panel:last-of-type {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                background: none !important;
            }
            .custom-table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            .custom-table th, .custom-table td {
                border: 1px solid #ddd !important;
                padding: 8px !important;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) UNIFICADA -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PANEL ADMIN</span>
            </div>
            
            <ul class="sidebar-menu">
                <li>
                    <a href="admin_dashboard.php">Panel Principal</a>
                </li>
                <li>
                    <a href="../P_Postulantes/postulantes.php">Gestionar Postulantes</a>
                </li>
                <li>
                    <a href="../P_Postulantes/pagos.php">Gestionar Pagos</a>
                </li>
                <li>
                    <a href="admision.php">Procesar Admisión</a>
                </li>
                <li>
                    <a href="grupos.php">Gestionar Grupos</a>
                </li>
                <li>
                    <a href="docentes.php">Gestionar Docentes</a>
                </li>
                <li>
                    <a href="notas.php">Gestionar Notas</a>
                </li>
                <li class="active">
                    <a href="reportes.php">Reportes</a>
                </li>
                <li style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <a href="../P_Seguridad/logout.php" style="color: var(--error);">Cerrar Sesión</a>
                </li>
            </ul>
        </aside>

        <!-- ÁREA PRINCIPAL -->
        <main class="main-content">
            
            <!-- CONEXIÓN VISUAL -->
            <div class="card" style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px; font-size: 0.85rem; text-align: left; line-height: 1.5;">
                <div style="font-weight: bold; margin-bottom: 4px; color: var(--primary);">Conexión Visual:</div>
                <strong>Vista:</strong> reportes.blade.php<br>
                <strong>Controlador:</strong> ReporteController<br>
                <strong>Funciones:</strong> generarReporte(), generarReporteAprobados(), generarReporteReprobados(), generarReporteNotasMateria(), generarReporteGrupos(), generarReporteCargaDocente(), exportarPDF(), exportarExcel()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Módulo de Reportes Académicos</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Filtros de auditoría, impresión y exportación en formato Excel/CSV</p>
                </div>
            </header>

            <!-- FILTROS -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px;">
                <form method="GET" action="reportes.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: flex-end;">
                    
                    <div class="form-group" style="margin: 0;">
                        <label for="report_type">Tipo de Reporte *</label>
                        <select id="report_type" name="report_type" class="form-control" required>
                            <option value="general" <?= $report_type === 'general' ? 'selected' : '' ?>>Lista General de Admitidos</option>
                            <option value="aprobados" <?= $report_type === 'aprobados' ? 'selected' : '' ?>>Postulantes Aprobados</option>
                            <option value="reprobados" <?= $report_type === 'reprobados' ? 'selected' : '' ?>>Postulantes Reprobados</option>
                            <option value="materias" <?= $report_type === 'materias' ? 'selected' : '' ?>>Rendimiento por Materia</option>
                            <option value="docentes" <?= $report_type === 'docentes' ? 'selected' : '' ?>>Carga de Docentes</option>
                            <option value="grupos_ranking" <?= $report_type === 'grupos_ranking' ? 'selected' : '' ?>>Ranking de Grupos</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label for="carrera">Carrera</label>
                        <select id="carrera" name="carrera" class="form-control">
                            <option value="">Todas las Carreras</option>
                            <?php foreach ($carreras as $c): ?>
                                <option value="<?= $c['id_carrera'] ?>" <?= $filter_carrera === (int)$c['id_carrera'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nombre_carrera']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label for="materia">Materia</label>
                        <select id="materia" name="materia" class="form-control">
                            <option value="">Todas las Materias</option>
                            <?php foreach ($materias as $m): ?>
                                <option value="<?= htmlspecialchars($m['id_materia']) ?>" <?= $filter_materia === $m['id_materia'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nombre_materia']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label for="grupo">Grupo</label>
                        <select id="grupo" name="grupo" class="form-control">
                            <option value="">Todos los Grupos</option>
                            <?php foreach ($grupos as $g): ?>
                                <option value="<?= $g['id_grupo'] ?>" <?= $filter_grupo === (int)$g['id_grupo'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['nombre_grupo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Generar reporte</button>

                </form>
            </section>

            <!-- CONTENIDO DEL REPORTE -->
            <section class="glass-panel" style="padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; flex-wrap: wrap; gap: 15px;">
                    <h3 class="gradient-text" style="font-size: 1.35rem; margin: 0;"><?= htmlspecialchars($report_title) ?></h3>
                    
                    <div style="display: flex; gap: 10px;">
                        <button onclick="window.print()" class="btn btn-secondary btn-small">Exportar PDF</button>
                        
                        <!-- Construir URL de exportación con los mismos filtros actuales -->
                        <?php 
                        $export_url = "reportes.php?export=excel&report_type=" . urlencode($report_type)
                                    . ($filter_carrera ? "&carrera=" . $filter_carrera : "")
                                    . ($filter_materia ? "&materia=" . urlencode($filter_materia) : "")
                                    . ($filter_grupo ? "&grupo=" . $filter_grupo : "");
                        ?>
                        <a href="<?= $export_url ?>" class="btn btn-secondary btn-small">Exportar Excel</a>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="custom-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <?php foreach ($report_headers as $header): ?>
                                    <th><?= htmlspecialchars($header) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($report_data) === 0): ?>
                                <tr>
                                    <td colspan="<?= count($report_headers) ?>" style="text-align: center; color: var(--text-muted);">
                                        No se encontraron registros bajo los filtros especificados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $cell): ?>
                                            <td><?= htmlspecialchars($cell) ?></td>
                                        <?php endforeach; ?>
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
