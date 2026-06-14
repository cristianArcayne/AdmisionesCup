<?php
/**
 * Registro y Edición de Notas - Módulo 5: NOTAS
 */

session_start();
require_once '../config/db.php';

// Validar que el usuario esté logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: ../P_Seguridad/login.php?err=' . urlencode('Acceso denegado.'));
    exit;
}

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];
$realname = $_SESSION['user_realname'] ?? 'Usuario';

$message = "";
$error = "";

// Cargar materias y grupos disponibles
$materias_disponibles = [];
$grupos_disponibles = [];

try {
    if ($user_role === 'admin') {
        // Administrador ve todas las materias y todos los grupos
        $materias_disponibles = $pdo->query("SELECT ID_materia, nombre_materia FROM Materias ORDER BY nombre_materia")->fetchAll();
        $grupos_disponibles = $pdo->query("SELECT ID_grupo, nombre_grupo FROM Grupos WHERE estado = TRUE ORDER BY nombre_grupo")->fetchAll();
    } elseif ($user_role === 'docente') {
        // Docente ve sólo lo que tiene asignado
        $docente_id = $_SESSION['docente_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.ID_materia, m.nombre_materia 
            FROM Asignaciones a
            JOIN Materias m ON a.ID_materia = m.ID_materia
            WHERE a.ID_docente = :id_docente
        ");
        $stmt->execute(['id_docente' => $docente_id]);
        $materias_disponibles = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT DISTINCT g.ID_grupo, g.nombre_grupo 
            FROM Asignaciones a
            JOIN Grupos g ON a.ID_grupo = g.ID_grupo
            WHERE a.ID_docente = :id_docente AND g.estado = TRUE
        ");
        $stmt->execute(['id_docente' => $docente_id]);
        $grupos_disponibles = $stmt->fetchAll();
    } else {
        // Estudiantes no entran aquí para calificar
        header('Location: estudiante_dashboard.php');
        exit;
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}

// Procesar selección
$selected_materia = $_GET['materia'] ?? ($_POST['materia'] ?? '');
$selected_grupo = $_GET['grupo'] ?? ($_POST['grupo'] ?? '');

// --- 1. PROCESAR ACCIÓN DE GUARDADO (UPSERT NOTAS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notas') {
    $notas_input = $_POST['notas'] ?? []; // Array de ID_estudiante => [nota1, nota2, nota3]
    
    try {
        $pdo->beginTransaction();
        
        $stmt_upsert = $pdo->prepare("
            INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3)
            VALUES (:id_estudiante, :id_materia, :id_grupo, :nota1, :nota2, :nota3)
            ON CONFLICT (ID_estudiante, ID_materia, ID_grupo)
            DO UPDATE SET 
                nota1 = EXCLUDED.nota1,
                nota2 = EXCLUDED.nota2,
                nota3 = EXCLUDED.nota3,
                Fecha_registro = CURRENT_DATE
        ");

        foreach ($notas_input as $id_estudiante => $scores) {
            $nota1 = $scores['nota1'] !== '' ? (float)$scores['nota1'] : null;
            $nota2 = $scores['nota2'] !== '' ? (float)$scores['nota2'] : null;
            $nota3 = $scores['nota3'] !== '' ? (float)$scores['nota3'] : null;

            // Validar notas en backend
            if (($nota1 !== null && ($nota1 < 0 || $nota1 > 100)) ||
                ($nota2 !== null && ($nota2 < 0 || $nota2 > 100)) ||
                ($nota3 !== null && ($nota3 < 0 || $nota3 > 100))) {
                throw new Exception("Las notas deben estar estrictamente en el rango de 0 a 100.");
            }

            $stmt_upsert->execute([
                'id_estudiante' => (int)$id_estudiante,
                'id_materia' => $selected_materia,
                'id_grupo' => (int)$selected_grupo,
                'nota1' => $nota1,
                'nota2' => $nota2,
                'nota3' => $nota3
            ]);
        }

        $pdo->commit();
        $message = "Las calificaciones se guardaron correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al guardar calificaciones: " . $e->getMessage();
    }
}

// --- 2. OBTENER LISTA DE ESTUDIANTES Y SUS NOTAS ---
$estudiantes_notas = [];
if (!empty($selected_materia) && !empty($selected_grupo)) {
    try {
        $stmt = $pdo->prepare("
            SELECT e.ID_estudiante, p.nombre, p.apellido, p.CI,
                   n.nota1, n.nota2, n.nota3, n.promedio, n.estado
            FROM Estudiantes e
            JOIN Personas p ON e.ID_persona = p.ID_persona
            LEFT JOIN Notas n ON e.ID_estudiante = n.ID_estudiante 
                              AND n.ID_materia = :materia 
                              AND n.ID_grupo = :grupo
            WHERE e.ID_grupo = :grupo AND e.estado = TRUE
            ORDER BY p.apellido, p.nombre
        ");
        $stmt->execute([
            'materia' => $selected_materia,
            'grupo' => (int)$selected_grupo
        ]);
        $estudiantes_notas = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Error al cargar alumnos: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Notas | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-layout">
        
        <!-- BARRA LATERAL (SIDEBAR) UNIFICADA O DOCENTE -->
        <aside class="sidebar">
            <div class="brand">
                <span style="font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">PANEL CONTROL</span>
            </div>
            
            <ul class="sidebar-menu">
                <?php if ($user_role === 'admin'): ?>
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
                    <li class="active">
                        <a href="notas.php">Gestionar Notas</a>
                    </li>
                    <li>
                        <a href="reportes.php">Reportes</a>
                    </li>
                <?php else: ?>
                    <li class="active">
                        <a href="notas.php">Calificaciones</a>
                    </li>
                <?php endif; ?>
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
                <strong>Vista:</strong> notas.blade.php<br>
                <strong>Controlador:</strong> NotaController<br>
                <strong>Funciones:</strong> registrarNota(), editarNota(), validarNota(), calcularPromedio(), determinarEstado()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Registro de Calificaciones Académicas</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Planilla de Control y Carga de Notas del CUP</p>
                </div>
                <div class="user-info-badge">
                    <span class="avatar"><?= strtoupper(substr($realname, 0, 1)) ?></span>
                    <span style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($realname) ?> (<?= htmlspecialchars(strtoupper($user_role)) ?>)</span>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- SELECTORES -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 20px;">
                <form method="GET" action="notas.php" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    
                    <div class="form-group" style="margin: 0; min-width: 200px;">
                        <label for="materia" style="margin-bottom: 5px;">Materia *</label>
                        <select id="materia" name="materia" class="form-control" required>
                            <option value="">Seleccione Materia...</option>
                            <?php foreach ($materias_disponibles as $m): ?>
                                <option value="<?= htmlspecialchars($m['id_materia']) ?>" <?= $selected_materia === $m['id_materia'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nombre_materia']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0; min-width: 200px;">
                        <label for="grupo" style="margin-bottom: 5px;">Grupo *</label>
                        <select id="grupo" name="grupo" class="form-control" required>
                            <option value="">Seleccione Grupo...</option>
                            <?php foreach ($grupos_disponibles as $g): ?>
                                <option value="<?= htmlspecialchars($g['id_grupo']) ?>" <?= (int)$selected_grupo === (int)$g['id_grupo'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['nombre_grupo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-secondary" style="align-self: flex-end;">Cargar Planilla</button>

                </form>
            </section>

            <!-- FORMULARIO DE CARGA DE NOTAS -->
            <?php if (!empty($selected_materia) && !empty($selected_grupo)): ?>
                <section class="glass-panel" style="padding: 20px;">
                    <form id="notasForm" method="POST" action="notas.php">
                        <input type="hidden" name="action" value="save_notas">
                        <input type="hidden" name="materia" value="<?= htmlspecialchars($selected_materia) ?>">
                        <input type="hidden" name="grupo" value="<?= htmlspecialchars($selected_grupo) ?>">

                        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--primary);">
                            Alumnos del Grupo Seleccionado
                        </h3>

                        <div class="table-responsive">
                            <table class="custom-table" style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>CI</th>
                                        <th style="width: 100px; text-align: center;">Nota 1</th>
                                        <th style="width: 100px; text-align: center;">Nota 2</th>
                                        <th style="width: 100px; text-align: center;">Nota 3</th>
                                        <th style="width: 110px; text-align: center;">Promedio</th>
                                        <th style="width: 130px; text-align: center;">Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($estudiantes_notas) === 0): ?>
                                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay estudiantes matriculados en este grupo.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($estudiantes_notas as $row): 
                                            $id_est = $row['id_estudiante'];
                                        ?>
                                            <tr class="student-row" data-id="<?= $id_est ?>">
                                                <td>
                                                    <strong><?= htmlspecialchars($row['apellido'] . ' ' . $row['nombre']) ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($row['ci']) ?></td>
                                                <td>
                                                    <input type="number" 
                                                           name="notas[<?= $id_est ?>][nota1]" 
                                                           class="form-control score-input nota1" 
                                                           value="<?= $row['nota1'] !== null ? htmlspecialchars($row['nota1']) : '' ?>" 
                                                           min="0" max="100" step="0.01" style="text-align: center; padding: 6px;">
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                           name="notas[<?= $id_est ?>][nota2]" 
                                                           class="form-control score-input nota2" 
                                                           value="<?= $row['nota2'] !== null ? htmlspecialchars($row['nota2']) : '' ?>" 
                                                           min="0" max="100" step="0.01" style="text-align: center; padding: 6px;">
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                           name="notas[<?= $id_est ?>][nota3]" 
                                                           class="form-control score-input nota3" 
                                                           value="<?= $row['nota3'] !== null ? htmlspecialchars($row['nota3']) : '' ?>" 
                                                           min="0" max="100" step="0.01" style="text-align: center; padding: 6px;">
                                                </td>
                                                <td style="text-align: center; font-weight: 700;">
                                                    <span class="average-display"><?= $row['promedio'] !== null ? number_format($row['promedio'], 2) : '0.00' ?></span>
                                                </td>
                                                <td style="text-align: center;">
                                                    <?php
                                                    $badge = 'badge-secondary';
                                                    $state = $row['estado'] ?? 'REPROBADO';
                                                    if ($state === 'APROBADO') $badge = 'badge-success';
                                                    elseif ($state === 'REPROBADO') $badge = 'badge-error';
                                                    ?>
                                                    <span class="badge status-display <?= $badge ?>"><?= $state ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($estudiantes_notas) > 0): ?>
                            <div style="display: flex; gap: 15px; margin-top: 25px;">
                                <button type="submit" class="btn btn-primary">Guardar notas</button>
                                <button type="button" id="calcAverageBtn" class="btn btn-secondary">Calcular promedio</button>
                            </div>
                        <?php endif; ?>

                    </form>
                </section>
            <?php endif; ?>

        </main>
    </div>

    <!-- VALIDACIONES JS Y PROMEDIO AUTOMÁTICO -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('notasForm');
            if (!form) return;

            // Función para calcular e inyectar promedio y estado para una fila
            function calculateRow(row) {
                const n1Val = parseFloat(row.querySelector('.nota1').value) || 0;
                const n2Val = parseFloat(row.querySelector('.nota2').value) || 0;
                const n3Val = parseFloat(row.querySelector('.nota3').value) || 0;

                const average = (n1Val + n2Val + n3Val) / 3;
                
                // Actualizar promedio
                row.querySelector('.average-display').innerText = average.toFixed(2);

                // Determinar estado
                const statusSpan = row.querySelector('.status-display');
                if (average >= 60) {
                    statusSpan.innerText = 'APROBADO';
                    statusSpan.className = 'badge status-display badge-success';
                } else {
                    statusSpan.innerText = 'REPROBADO';
                    statusSpan.className = 'badge status-display badge-error';
                }
            }

            // Escuchar cambios de notas para el cálculo instantáneo
            form.addEventListener('input', function(e) {
                if (e.target.classList.contains('score-input')) {
                    // Validar límite
                    let val = parseFloat(e.target.value);
                    if (val < 0) e.target.value = 0;
                    if (val > 100) e.target.value = 100;
                    
                    const row = e.target.closest('.student-row');
                    calculateRow(row);
                }
            });

            // Botón Calcular promedio manual
            document.getElementById('calcAverageBtn').addEventListener('click', function() {
                const rows = document.querySelectorAll('.student-row');
                rows.forEach(row => calculateRow(row));
                alert("Promedios calculados correctamente en pantalla.");
            });

            // Validación al guardar
            form.addEventListener('submit', function(event) {
                const inputs = form.querySelectorAll('.score-input');
                let valid = true;
                let errorMessage = "";

                // Limpiar alertas de error previas
                const existingAlerts = document.querySelectorAll('.alert-error');
                existingAlerts.forEach(a => a.remove());

                inputs.forEach(input => {
                    const valStr = input.value.trim();
                    if (valStr !== "") {
                        const val = parseFloat(valStr);
                        if (isNaN(val) || val < 0 || val > 100) {
                            valid = false;
                            errorMessage = "Todas las calificaciones ingresadas deben estar entre 0 y 100.";
                        }
                    }
                });

                if (!valid) {
                    event.preventDefault();
                    // Inyectar alerta
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-error';
                    alertDiv.style.marginBottom = '20px';
                    alertDiv.innerText = errorMessage;
                    
                    form.insertBefore(alertDiv, form.firstChild);
                    window.scrollTo({ top: form.offsetTop - 50, behavior: 'instant' });
                }
            });
        });
    </script>
</body>
</html>
