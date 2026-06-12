<?php
/**
 * Portal del Estudiante - Consulta de Notas y Estado de Admisión (CU11)
 */

session_start();
require_once 'config/db.php';

// Validar que el usuario esté logueado y tenga el rol de 'estudiante'
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'estudiante') {
    header('Location: login.php?err=' . urlencode('Acceso denegado: Se requieren privilegios de Estudiante.'));
    exit;
}

$estudiante_id = $_SESSION['estudiante_id'];
$message = "";
$error = "";

$estudiante = null;
$notas = [];
$promedio_gral = 0.00;
$admitido_status = "REPROBADO";
$admitido_badge = "badge-danger";

try {
    // 1. Obtener Datos del Perfil del Estudiante (Persona, Carrera, Grupo)
    $stmt = $pdo->prepare("
        SELECT e.ID_estudiante, e.ID_carrera, c.nombre_carrera, e.ID_grupo, g.nombre_grupo, p.* 
        FROM Estudiantes e 
        JOIN Personas p ON e.ID_persona = p.ID_persona 
        JOIN Carreras c ON e.ID_carrera = c.ID_carrera 
        JOIN Grupos g ON e.ID_grupo = g.ID_grupo 
        WHERE e.ID_estudiante = :estudiante_id AND e.Estado = TRUE
    ");
    $stmt->execute(['estudiante_id' => $estudiante_id]);
    $estudiante = $stmt->fetch();

    if (!$estudiante) {
        $error = "No se encontraron los datos del perfil del estudiante activo.";
    } else {
        // 2. Obtener Calificaciones por Materias (CU11: evaluarCondicionAprobacion)
        $stmt = $pdo->prepare("
            SELECT m.nombre_materia, n.nota1, n.nota2, n.nota3, n.promedio, n.estado
            FROM Notas n
            JOIN Materias m ON n.ID_materia = m.ID_materia
            WHERE n.ID_estudiante = :estudiante_id
            ORDER BY m.nombre_materia
        ");
        $stmt->execute(['estudiante_id' => $estudiante_id]);
        $notas = $stmt->fetchAll();

        // 3. Calcular Promedio Aritmético Final (CU11: calcularPromedioAritmetico)
        if (count($notas) > 0) {
            $sum_promedios = 0;
            foreach ($notas as $nota) {
                $sum_promedios += (float)($nota['promedio'] ?? 0);
            }
            $promedio_gral = $sum_promedios / count($notas);
            
            // Evaluar condición de aprobación (CU11: evaluarCondicionAprobacion)
            if ($promedio_gral >= 60) {
                $admitido_status = "APROBADO";
                $admitido_badge = "badge-success";
            }
        }
    }

} catch (PDOException $e) {
    $error = "Error al cargar la información académica: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Estudiante | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">ESTUDIANTE</span>
            </div>
            
            <ul class="sidebar-menu">
                <li class="active"><a href="estudiante_dashboard.php">Mi Información</a></li>
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
                    <h2>Portal del Estudiante</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Curso Preuniversitario (CUP) de Nivelación Académica</p>
                </div>
                <div class="user-info-badge">
                    <span class="avatar">E</span>
                    <span style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['user_realname']) ?></span>
                </div>
            </header>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($estudiante): ?>
                
                <!-- Tarjeta de Datos de Perfil Simplificada -->
                <div class="card" style="max-width: 700px; margin: 0 auto; padding: 40px;">
                    <h3 style="font-size: 1.6rem; margin-bottom: 25px; font-weight: 700; color: var(--primary); text-align: center;">Datos de Matriculación</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px; font-size: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 25px; margin-bottom: 25px;">
                        <div style="display: flex; justify-content: space-between;"><strong>Nombres:</strong> <span style="color: #333; font-weight: 500;"><?= htmlspecialchars($estudiante['nombre']) ?></span></div>
                        <div style="display: flex; justify-content: space-between;"><strong>Apellidos:</strong> <span style="color: #333; font-weight: 500;"><?= htmlspecialchars($estudiante['apellido']) ?></span></div>
                        <div style="display: flex; justify-content: space-between;"><strong>C.I. Identidad:</strong> <span><?= htmlspecialchars($estudiante['ci']) ?></span></div>
                        <div style="display: flex; justify-content: space-between;"><strong>Género / Sexo:</strong> <span><?= $estudiante['genero'] === 'M' ? 'Masculino' : 'Femenino' ?></span></div>
                        <div style="display: flex; justify-content: space-between;"><strong>Correo Personal:</strong> <span style="color: var(--text-muted);"><?= htmlspecialchars($estudiante['correo_personal']) ?></span></div>
                        <div style="display: flex; justify-content: space-between;"><strong>Celular/Teléfono:</strong> <span><?= htmlspecialchars($estudiante['telefono']) ?></span></div>
                    </div>

                    <div style="display: flex; justify-content: space-around; text-align: center; gap: 15px;">
                        <div>
                            <span style="font-size: 0.85rem; color: var(--text-muted); display: block;">Carrera de Admisión</span>
                            <span class="badge badge-success" style="font-size: 0.95rem; padding: 8px 16px; margin-top: 6px;"><?= htmlspecialchars($estudiante['nombre_carrera']) ?></span>
                        </div>
                        <div>
                            <span style="font-size: 0.85rem; color: var(--text-muted); display: block;">Grupo de Estudio</span>
                            <span class="badge badge-info" style="font-size: 0.95rem; padding: 8px 16px; margin-top: 6px;"><?= htmlspecialchars($estudiante['nombre_grupo']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Libreta Académica Simplificada -->
                <div class="card" style="max-width: 700px; margin: 30px auto 0 auto; padding: 40px;">
                    <h3 style="font-size: 1.6rem; margin-bottom: 25px; font-weight: 700; color: var(--primary); text-align: center;">Libreta Académica</h3>
                    
                    <div class="table-responsive">
                        <table class="custom-table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Materia</th>
                                    <th>Parcial 1</th>
                                    <th>Parcial 2</th>
                                    <th>Examen Final</th>
                                    <th>Promedio</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($notas) === 0): ?>
                                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay calificaciones registradas aún.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($notas as $row): 
                                        $promedio = isset($row['promedio']) ? number_format($row['promedio'], 2) : '0.00';
                                        $estado = $row['estado'] ?? 'REPROBADO';
                                        $badge_class = ($estado === 'APROBADO') ? 'badge-success' : 'badge-danger';
                                    ?>
                                        <tr>
                                            <td style="font-weight: 600; color: #333;"><?= htmlspecialchars($row['nombre_materia']) ?></td>
                                            <td><?= isset($row['nota1']) ? number_format($row['nota1'], 2) : '0.00' ?></td>
                                            <td><?= isset($row['nota2']) ? number_format($row['nota2'], 2) : '0.00' ?></td>
                                            <td><?= isset($row['nota3']) ? number_format($row['nota3'], 2) : '0.00' ?></td>
                                            <td style="font-weight: 700; color: var(--primary);"><?= $promedio ?></td>
                                            <td><span class="badge <?= $badge_class ?>"><?= $estado ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($notas) > 0): ?>
                        <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <span style="font-size: 0.9rem; color: var(--text-muted); display: block;">Promedio General Académico</span>
                                <span style="font-size: 1.8rem; font-weight: 800; color: var(--primary);"><?= number_format($promedio_gral, 2) ?> / 100</span>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.9rem; color: var(--text-muted); display: block;">Estatus de Admisión</span>
                                <span class="badge <?= $admitido_badge ?>" style="font-size: 1.1rem; padding: 8px 16px; margin-top: 5px;"><?= $admitido_status ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </main>
    </div>
</body>
</html>
