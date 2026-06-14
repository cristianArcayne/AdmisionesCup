<?php
/**
 * Panel de Administración Principal - CUP FICCT
 */

session_start();
require_once '../config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../P_Seguridad/login.php?err=' . urlencode('Acceso denegado: Se requieren privilegios de Administrador.'));
    exit;
}

$realname = $_SESSION['user_realname'] ?? 'Administrador';

// --- CONSULTAS PARA MÉTRICAS DEL DASHBOARD ---
try {
    // 1. Total Postulantes (desde la tabla Postulantes)
    $stmt = $pdo->query("SELECT COUNT(*) FROM Postulantes");
    $total_postulantes = $stmt->fetchColumn();

    // 2. Total Aprobados (Estudiantes con promedio general >= 60)
    $stmt = $pdo->query("SELECT COUNT(*) FROM (
        SELECT ID_estudiante FROM Notas 
        GROUP BY ID_estudiante 
        HAVING AVG(promedio) >= 60
    ) AS aprobados");
    $total_aprobados = $stmt->fetchColumn();

    // 3. Total Reprobados (Estudiantes con promedio general < 60 o sin notas aún)
    $stmt = $pdo->query("SELECT COUNT(*) FROM (
        SELECT e.ID_estudiante FROM Estudiantes e
        LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
        GROUP BY e.ID_estudiante 
        HAVING AVG(COALESCE(n.promedio, 0)) < 60
    ) AS reprobados");
    $total_reprobados = $stmt->fetchColumn();

    // 4. Total Grupos Activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM Grupos WHERE estado = TRUE");
    $total_grupos = $stmt->fetchColumn();

    // 5. Total Docentes Asignados
    $stmt = $pdo->query("SELECT COUNT(DISTINCT ID_docente) FROM Asignaciones");
    $total_docentes = $stmt->fetchColumn();

} catch (PDOException $e) {
    die("Error al cargar métricas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) UNIFICADA -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PANEL ADMIN</span>
            </div>
            
            <ul class="sidebar-menu">
                <li class="active">
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
                <li>
                    <a href="reportes.php">Reportes</a>
                </li>
                <li style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <a href="../P_Seguridad/logout.php" style="color: var(--error);">Cerrar Sesión</a>
                </li>
            </ul>
        </aside>

        <!-- ÁREA PRINCIPAL DE CONTENIDO -->
        <main class="main-content">
            
            <!-- CONEXIÓN VISUAL -->
            <div class="card" style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px; font-size: 0.85rem; text-align: left; line-height: 1.5;">
                <div style="font-weight: bold; margin-bottom: 4px; color: var(--primary);">Conexión Visual:</div>
                <strong>Vista:</strong> dashboard.blade.php<br>
                <strong>Controlador:</strong> DashboardController<br>
                <strong>Funciones:</strong> cargarIndicadores(), obtenerTotalPostulantes(), obtenerAprobados(), obtenerReprobados(), obtenerGruposActivos(), obtenerDocentesAsignados()
            </div>

            <!-- ENCABEZADO -->
            <header class="dash-header" style="margin-bottom: 25px;">
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
            <section class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                
                <div class="stat-card">
                    <div class="info">
                        <span class="label">Total Postulantes</span>
                        <span class="value"><?= $total_postulantes ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Aprobados</span>
                        <span class="value" style="color: var(--success);"><?= $total_aprobados ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Reprobados</span>
                        <span class="value" style="color: var(--error);"><?= $total_reprobados ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Grupos Activos</span>
                        <span class="value" style="color: var(--warning);"><?= $total_grupos ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Docentes Asignados</span>
                        <span class="value" style="color: var(--secondary);"><?= $total_docentes ?></span>
                    </div>
                </div>

            </section>

            <!-- BIENVENIDA -->
            <section class="glass-panel" style="padding: 30px; text-align: left;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Bienvenido al Administrador del CUP</h3>
                <p style="color: var(--text-muted); line-height: 1.6; margin-bottom: 20px;">
                    Desde este panel puedes controlar el registro de postulantes, realizar el seguimiento de los pagos de inscripción, procesar la admisión automatizada a las carreras ofertadas de la facultad de tecnología, organizar la cantidad de grupos requeridos, coordinar los horarios y asignación de docentes, cargar las notas por materia, y emitir reportes de gestión imprimibles.
                </p>
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <a href="../P_Postulantes/postulantes.php" class="btn btn-primary btn-small">Gestionar Postulantes</a>
                    <a href="reportes.php" class="btn btn-secondary btn-small">Ver Reportes y Estadísticas</a>
                </div>
            </section>

        </main>
    </div>
</body>
</html>
