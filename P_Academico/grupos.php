<?php
/**
 * Gestión de Grupos - Módulo 7: GRUPOS
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

// --- 1. PROCESAR ACCIÓN DE CÁLCULO Y GENERACIÓN AUTOMÁTICA DE GRUPOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generar_grupos_auto') {
    try {
        $pdo->beginTransaction();

        // A. Contar postulantes admitidos en Estudiantes
        $stmt = $pdo->query("SELECT COUNT(*) FROM Estudiantes WHERE estado = TRUE");
        $total_inscritos = (int)$stmt->fetchColumn();

        if ($total_inscritos === 0) {
            throw new Exception("No hay estudiantes admitidos aún en el sistema para agrupar.");
        }

        // B. Calcular cantidad de grupos necesarios
        $grupos_necesarios = (int)ceil($total_inscritos / 70.0);

        // C. Obtener cantidad de grupos existentes
        $stmt = $pdo->query("SELECT COUNT(*) FROM Grupos");
        $grupos_existentes = (int)$stmt->fetchColumn();

        // D. Si se necesitan más grupos, crearlos
        if ($grupos_necesarios > $grupos_existentes) {
            $grupos_a_crear = $grupos_necesarios - $grupos_existentes;
            for ($i = 0; $i < $grupos_a_crear; $i++) {
                $nombre_grupo = 'Grupo ' . chr(65 + ($grupos_existentes + $i)); // Grupo A, Grupo B, etc.
                $stmt_ins = $pdo->prepare("INSERT INTO Grupos (nombre_grupo, capacidad_maxima) VALUES (:nombre, 70)");
                $stmt_ins->execute(['nombre' => $nombre_grupo]);
            }
        }

        // E. Cargar todos los grupos activos
        $grupos_activos = $pdo->query("SELECT ID_grupo FROM Grupos WHERE estado = TRUE ORDER BY ID_grupo")->fetchAll(PDO::FETCH_COLUMN);

        // F. Cargar todos los estudiantes matriculados
        $estudiantes = $pdo->query("SELECT ID_estudiante FROM Estudiantes WHERE estado = TRUE ORDER BY ID_estudiante")->fetchAll(PDO::FETCH_COLUMN);

        // G. Distribuir de forma equitativa (máximo 70 por grupo)
        // Reiniciar cantidad_estudiantes en Grupos
        $pdo->exec("UPDATE Grupos SET cantidad_estudiantes = 0");

        $index_grupo = 0;
        $contadores_grupos = array_fill_keys($grupos_activos, 0);

        foreach ($estudiantes as $id_estudiante) {
            $id_grupo_actual = $grupos_activos[$index_grupo];
            
            // Asignar estudiante al grupo actual
            $stmt_asig = $pdo->prepare("UPDATE Estudiantes SET ID_grupo = :id_grupo WHERE ID_estudiante = :id_est");
            $stmt_asig->execute([
                'id_grupo' => $id_grupo_actual,
                'id_est' => $id_estudiante
            ]);

            $contadores_grupos[$id_grupo_actual]++;

            // Si el grupo actual llegó a 70, pasar al siguiente grupo
            if ($contadores_grupos[$id_grupo_actual] >= 70) {
                if ($index_grupo < count($grupos_activos) - 1) {
                    $index_grupo++;
                }
            } else {
                // Rotar distribución para que queden balanceados si no están llenos
                $index_grupo = ($index_grupo + 1) % count($grupos_activos);
            }
        }

        // H. Actualizar la cuenta en la tabla Grupos
        foreach ($contadores_grupos as $id_g => $cant) {
            $stmt_upd_g = $pdo->prepare("UPDATE Grupos SET cantidad_estudiantes = :cant WHERE ID_grupo = :id_g");
            $stmt_upd_g->execute(['cant' => $cant, 'id_g' => $id_g]);
        }

        $pdo->commit();
        $message = "Grupos calculados y ordenados. Se agruparon $total_inscritos estudiantes en $grupos_necesarios grupos de máximo 70 alumnos.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al agrupar de forma automática: " . $e->getMessage();
    }
}

// --- 2. LISTADO DE GRUPOS ---
$total_inscritos = 0;
$grupos_necesarios = 0;
$grupos_list = [];

try {
    // Contar inscritos
    $total_inscritos = (int)$pdo->query("SELECT COUNT(*) FROM Estudiantes WHERE estado = TRUE")->fetchColumn();
    $grupos_necesarios = (int)ceil($total_inscritos / 70.0);

    // Cargar planilla de grupos con carreras de los estudiantes asignados a ellos
    // Para simplificar, listaremos los grupos y el recuento real de inscritos.
    $grupos_list = $pdo->query("
        SELECT g.ID_grupo, g.nombre_grupo, g.cantidad_estudiantes, g.capacidad_maxima, g.estado,
               (SELECT c.nombre_carrera FROM Estudiantes e 
                JOIN Carreras c ON e.ID_carrera = c.ID_carrera 
                WHERE e.ID_grupo = g.ID_grupo LIMIT 1) AS carrera_ejemplo
        FROM Grupos g
        ORDER BY g.nombre_grupo
    ")->fetchAll();

} catch (PDOException $e) {
    $error = "Error al cargar planilla de grupos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Grupos | FICCT</title>
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
                <li class="active">
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

        <!-- ÁREA PRINCIPAL -->
        <main class="main-content">
            
            <!-- CONEXIÓN VISUAL -->
            <div class="card" style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px; font-size: 0.85rem; text-align: left; line-height: 1.5;">
                <div style="font-weight: bold; margin-bottom: 4px; color: var(--primary);">Conexión Visual:</div>
                <strong>Vista:</strong> grupos.blade.php<br>
                <strong>Controlador:</strong> GrupoController<br>
                <strong>Funciones:</strong> calcularCantidadGrupos(), contarPostulantes(), generarGrupos(), listarGrupos()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Gestión y Distribución de Grupos</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Organización automatizada de aulas bajo tope de capacidad</p>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- INDICADORES DE GRUPOS -->
            <section class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                
                <div class="stat-card">
                    <div class="info">
                        <span class="label">Total Estudiantes Matriculados</span>
                        <span class="value"><?= $total_inscritos ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Cupo Máximo por Grupo</span>
                        <span class="value" style="color: var(--primary);">70</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="info">
                        <span class="label">Grupos Necesarios (Calculados)</span>
                        <span class="value" style="color: var(--success);"><?= $grupos_necesarios ?></span>
                    </div>
                </div>

            </section>

            <!-- ACCIÓN AUTOMÁTICA -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0 0 5px 0; color: var(--primary);">Balancear Estudiantes y Aulas</h3>
                    <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">Genera los grupos de estudio necesarios y distribuye a los alumnos de manera equitativa.</p>
                </div>
                <form method="POST" action="grupos.php">
                    <input type="hidden" name="action" value="generar_grupos_auto">
                    <button type="submit" class="btn btn-primary">Calcular grupos automáticamente</button>
                </form>
            </section>

            <!-- TABLA DE GRUPOS -->
            <section class="glass-panel" style="padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Planilla de Grupos Activos</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Nombre del Grupo</th>
                                <th>Área / Carrera Referencial</th>
                                <th>Cantidad de Estudiantes</th>
                                <th>Capacidad Máxima</th>
                                <th>Estado del Grupo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($grupos_list) === 0): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No existen grupos configurados en la base de datos.</td></tr>
                            <?php else: ?>
                                <?php foreach ($grupos_list as $g): 
                                    $alumnos = (int)$g['cantidad_estudiantes'];
                                    $saturado = $alumnos >= 70;
                                ?>
                                    <tr>
                                        <td><strong style="font-size: 1.1rem; color: var(--primary);"><?= htmlspecialchars($g['nombre_grupo']) ?></strong></td>
                                        <td><?= htmlspecialchars($g['carrera_ejemplo'] ?: 'General / Varios') ?></td>
                                        <td style="font-weight: 600; color: <?= $saturado ? 'var(--error)' : 'var(--success)' ?>;">
                                            <?= $alumnos ?> inscritos
                                        </td>
                                        <td><strong><?= $g['capacidad_maxima'] ?> alumnos</strong></td>
                                        <td>
                                            <?php if ($saturado): ?>
                                                <span class="badge badge-error">Lleno (70/70)</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">Activo (Cupo Libre)</span>
                                            <?php endif; ?>
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

    <!-- VALIDACIONES JS -->
    <script>
        // Validación en frontend para alertar si algún grupo excede el límite de 70 estudiantes
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.custom-table tbody tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 2) {
                    const cantInscritos = parseInt(cells[2].innerText) || 0;
                    if (cantInscritos > 70) {
                        alert("Advertencia: El " + cells[0].innerText + " excede el límite máximo permitido de 70 estudiantes.");
                    }
                }
            });
        });
    </script>
</body>
</html>
