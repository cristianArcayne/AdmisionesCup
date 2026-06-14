<?php
/**
 * Procesamiento de Admisión - Módulo 6: ADMISIÓN
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

// --- 1. PROCESAR ADMISIÓN (PRIMERA OPCIÓN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'procesar_admision') {
    try {
        $pdo->beginTransaction();

        // Obtener todos los postulantes con pago 'Pagado' o 'Completado' y que tengan promedio académico >= 60
        // que no hayan sido admitidos aún (no existen en la tabla Estudiantes)
        $stmt = $pdo->query("
            SELECT po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal,
                   po.ID_carrera_primera, po.ID_carrera_segunda, ROUND(AVG(n.promedio), 2) AS promedio_final
            FROM Postulantes po
            JOIN Personas p ON po.ID_persona = p.ID_persona
            JOIN Estudiantes e_check ON p.ID_persona = e_check.ID_persona -- Simular que son estudiantes matriculados provisionales o postulantes con notas
            JOIN Notas n ON e_check.ID_estudiante = n.ID_estudiante
            WHERE po.Estado_postulacion = 'Pagado'
              AND NOT EXISTS (
                  SELECT 1 FROM Estudiantes est WHERE est.ID_persona = po.ID_persona AND est.ID_grupo IS NOT NULL
              )
            GROUP BY po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal, po.ID_carrera_primera, po.ID_carrera_segunda
            HAVING AVG(n.promedio) >= 60
        ");
        
        // Si no dio resultado con el JOIN de estudiantes provisionales, buscaremos postulantes directamente:
        // Nota: En nuestro diseño, un postulante debe estar en "Estudiantes" para tener notas, pero su "ID_grupo" es nulo o provisional.
        // Vamos a hacer una consulta que busque en Estudiantes sin grupo asignado o similar, o simplemente matricularlos formalmente.
        
        $postulantes_aprobados = $stmt->fetchAll();
        $admitidos_count = 0;

        foreach ($postulantes_aprobados as $post) {
            $id_persona = (int)$post['id_persona'];
            $id_postulante = (int)$post['id_postulante'];
            $carrera_1 = (int)$post['id_carrera_primera'];
            $ci = $post['ci'];
            $correo = $post['correo_personal'];

            // A. Verificar cupo disponible en carrera 1
            // Cupo máximo
            $stmt_cupo = $pdo->prepare("SELECT cupo_maximo FROM Carreras WHERE ID_carrera = :carrera");
            $stmt_cupo->execute(['carrera' => $carrera_1]);
            $cupo_max = $stmt_cupo->fetchColumn();

            // Inscritos actuales
            $stmt_inscritos = $pdo->prepare("SELECT COUNT(*) FROM Estudiantes WHERE ID_carrera = :carrera AND ID_grupo IS NOT NULL");
            $stmt_inscritos->execute(['carrera' => $carrera_1]);
            $inscritos = $stmt_inscritos->fetchColumn();

            if ($inscritos < $cupo_max) {
                // B. Verificar si ya tiene cuenta de usuario (rol estudiante)
                $stmt_user = $pdo->prepare("SELECT ID_user FROM Usuarios WHERE Correo = :correo");
                $stmt_user->execute(['correo' => $correo]);
                $id_user = $stmt_user->fetchColumn();

                if (!$id_user) {
                    // Crear usuario (User = CI, Pass = CI hash, Rol = 3 (estudiante))
                    $pass_hash = password_hash($ci, PASSWORD_DEFAULT);
                    $stmt_ins_user = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES (:username, :pass, :correo, 3)");
                    $stmt_ins_user->execute([
                        'username' => $ci,
                        'pass' => $pass_hash,
                        'correo' => $correo
                    ]);
                    $id_user = $pdo->lastInsertId();
                }

                // C. Asignar Grupo con espacio (capacidad < 70)
                $grupo_id = null;
                $grupos = $pdo->query("SELECT ID_grupo, cantidad_estudiantes FROM Grupos WHERE estado = TRUE ORDER BY cantidad_estudiantes ASC")->fetchAll();
                foreach ($grupos as $g) {
                    if ($g['cantidad_estudiantes'] < 70) {
                        $grupo_id = (int)$g['id_grupo'];
                        break;
                    }
                }

                if (!$grupo_id) {
                    // Si todos están llenos, crear uno nuevo
                    $stmt_new_g = $pdo->query("INSERT INTO Grupos (nombre_grupo, capacidad_maxima) VALUES ('Grupo ' || CHR(65 + (SELECT COUNT(*) FROM Grupos)), 70) RETURNING ID_grupo");
                    $grupo_id = $stmt_new_g->fetchColumn();
                }

                // D. Insertar/Actualizar Estudiantes
                $stmt_check_est = $pdo->prepare("SELECT ID_estudiante FROM Estudiantes WHERE ID_persona = :id_persona");
                $stmt_check_est->execute(['id_persona' => $id_persona]);
                $id_estudiante = $stmt_check_est->fetchColumn();

                if ($id_estudiante) {
                    $stmt_upd_est = $pdo->prepare("UPDATE Estudiantes SET ID_carrera = :carrera, ID_grupo = :grupo, ID_user = :id_user, estado = TRUE WHERE ID_estudiante = :id_est");
                    $stmt_upd_est->execute([
                        'carrera' => $carrera_1,
                        'grupo' => $grupo_id,
                        'id_user' => $id_user,
                        'id_est' => $id_estudiante
                    ]);
                } else {
                    $stmt_ins_est = $pdo->prepare("INSERT INTO Estudiantes (ID_persona, ID_user, ID_carrera, ID_grupo) VALUES (:id_persona, :id_user, :carrera, :grupo)");
                    $stmt_ins_est->execute([
                        'id_persona' => $id_persona,
                        'id_user' => $id_user,
                        'carrera' => $carrera_1,
                        'grupo' => $grupo_id
                    ]);
                }

                // E. Actualizar estado del postulante a 'Aprobado'
                $stmt_upd_post = $pdo->prepare("UPDATE Postulantes SET Estado_postulacion = 'Aprobado' WHERE ID_postulante = :id_post");
                $stmt_upd_post->execute(['id_post' => $id_postulante]);

                $admitidos_count++;
            }
        }

        $pdo->commit();
        $message = "Proceso terminado. Se admitieron $admitidos_count postulantes en su primera opción de carrera.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al procesar la admisión: " . $e->getMessage();
    }
}

// --- 2. REASIGNAR POR CUPO SATURADO (SEGUNDA OPCIÓN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reasignar_segunda_opcion') {
    try {
        $pdo->beginTransaction();

        // Obtener postulantes con promedio >= 60 que siguen en estado 'Pagado'
        // (lo que significa que su primera opción estaba saturada y no pudieron entrar en el paso anterior)
        $stmt = $pdo->query("
            SELECT po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal,
                   po.ID_carrera_primera, po.ID_carrera_segunda, ROUND(AVG(n.promedio), 2) AS promedio_final
            FROM Postulantes po
            JOIN Personas p ON po.ID_persona = p.ID_persona
            JOIN Estudiantes e_check ON p.ID_persona = e_check.ID_persona
            JOIN Notas n ON e_check.ID_estudiante = n.ID_estudiante
            WHERE po.Estado_postulacion = 'Pagado'
              AND NOT EXISTS (
                  SELECT 1 FROM Estudiantes est WHERE est.ID_persona = po.ID_persona AND est.ID_grupo IS NOT NULL
              )
            GROUP BY po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal, po.ID_carrera_primera, po.ID_carrera_segunda
            HAVING AVG(n.promedio) >= 60
        ");
        
        $postulantes_saturados = $stmt->fetchAll();
        $reasignados_count = 0;

        foreach ($postulantes_saturados as $post) {
            $id_persona = (int)$post['id_persona'];
            $id_postulante = (int)$post['id_postulante'];
            $carrera_2 = (int)$post['id_carrera_segunda'];
            $ci = $post['ci'];
            $correo = $post['correo_personal'];

            // A. Verificar cupo disponible en carrera 2
            $stmt_cupo = $pdo->prepare("SELECT cupo_maximo FROM Carreras WHERE ID_carrera = :carrera");
            $stmt_cupo->execute(['carrera' => $carrera_2]);
            $cupo_max = $stmt_cupo->fetchColumn();

            $stmt_inscritos = $pdo->prepare("SELECT COUNT(*) FROM Estudiantes WHERE ID_carrera = :carrera AND ID_grupo IS NOT NULL");
            $stmt_inscritos->execute(['carrera' => $carrera_2]);
            $inscritos = $stmt_inscritos->fetchColumn();

            if ($inscritos < $cupo_max) {
                // B. Verificar usuario
                $stmt_user = $pdo->prepare("SELECT ID_user FROM Usuarios WHERE Correo = :correo");
                $stmt_user->execute(['correo' => $correo]);
                $id_user = $stmt_user->fetchColumn();

                if (!$id_user) {
                    $pass_hash = password_hash($ci, PASSWORD_DEFAULT);
                    $stmt_ins_user = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES (:username, :pass, :correo, 3)");
                    $stmt_ins_user->execute([
                        'username' => $ci,
                        'pass' => $pass_hash,
                        'correo' => $correo
                    ]);
                    $id_user = $pdo->lastInsertId();
                }

                // C. Asignar Grupo
                $grupo_id = null;
                $grupos = $pdo->query("SELECT ID_grupo, cantidad_estudiantes FROM Grupos WHERE estado = TRUE ORDER BY cantidad_estudiantes ASC")->fetchAll();
                foreach ($grupos as $g) {
                    if ($g['cantidad_estudiantes'] < 70) {
                        $grupo_id = (int)$g['id_grupo'];
                        break;
                    }
                }

                if (!$grupo_id) {
                    $stmt_new_g = $pdo->query("INSERT INTO Grupos (nombre_grupo, capacidad_maxima) VALUES ('Grupo ' || CHR(65 + (SELECT COUNT(*) FROM Grupos)), 70) RETURNING ID_grupo");
                    $grupo_id = $stmt_new_g->fetchColumn();
                }

                // D. Actualizar Estudiante con carrera de segunda opción
                $stmt_check_est = $pdo->prepare("SELECT ID_estudiante FROM Estudiantes WHERE ID_persona = :id_persona");
                $stmt_check_est->execute(['id_persona' => $id_persona]);
                $id_estudiante = $stmt_check_est->fetchColumn();

                if ($id_estudiante) {
                    $stmt_upd_est = $pdo->prepare("UPDATE Estudiantes SET ID_carrera = :carrera, ID_grupo = :grupo, ID_user = :id_user, estado = TRUE WHERE ID_estudiante = :id_est");
                    $stmt_upd_est->execute([
                        'carrera' => $carrera_2,
                        'grupo' => $grupo_id,
                        'id_user' => $id_user,
                        'id_est' => $id_estudiante
                    ]);
                } else {
                    $stmt_ins_est = $pdo->prepare("INSERT INTO Estudiantes (ID_persona, ID_user, ID_carrera, ID_grupo) VALUES (:id_persona, :id_user, :carrera, :grupo)");
                    $stmt_ins_est->execute([
                        'id_persona' => $id_persona,
                        'id_user' => $id_user,
                        'carrera' => $carrera_2,
                        'grupo' => $grupo_id
                    ]);
                }

                // E. Actualizar estado del postulante a 'Aprobado'
                $stmt_upd_post = $pdo->prepare("UPDATE Postulantes SET Estado_postulacion = 'Aprobado' WHERE ID_postulante = :id_post");
                $stmt_upd_post->execute(['id_post' => $id_postulante]);

                $reasignados_count++;
            }
        }

        $pdo->commit();
        $message = "Reasignación completada. Se reasignaron $reasignados_count postulantes a su segunda opción de carrera.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al reasignar postulantes: " . $e->getMessage();
    }
}

// --- 3. CARGAR LISTADO DE POSTULANTES CON NOTAS PARA MOSTRAR ---
$postulantes_list = [];
try {
    // Listado de postulantes que tienen notas cargadas en Estudiantes provisionales
    // Se muestra su promedio general y disponibilidad de cupo en sus opciones
    $postulantes_list = $pdo->query("
        SELECT po.ID_postulante, p.nombre, p.apellido, p.CI,
               c1.nombre_carrera AS carrera_1, c1.cupo_maximo AS cupo_max_1,
               (SELECT COUNT(*) FROM Estudiantes WHERE ID_carrera = po.ID_carrera_primera AND ID_grupo IS NOT NULL) AS inscritos_1,
               c2.nombre_carrera AS carrera_2, c2.cupo_maximo AS cupo_max_2,
               (SELECT COUNT(*) FROM Estudiantes WHERE ID_carrera = po.ID_carrera_segunda AND ID_grupo IS NOT NULL) AS inscritos_2,
               ROUND(AVG(n.promedio), 2) AS promedio_final,
               po.Estado_postulacion,
               (SELECT c_asig.nombre_carrera FROM Estudiantes e_asig 
                JOIN Carreras c_asig ON e_asig.ID_carrera = c_asig.ID_carrera 
                WHERE e_asig.ID_persona = po.ID_persona AND e_asig.ID_grupo IS NOT NULL) AS carrera_asignada
        FROM Postulantes po
        JOIN Personas p ON po.ID_persona = p.ID_persona
        JOIN Estudiantes e_check ON p.ID_persona = e_check.ID_persona
        JOIN Notas n ON e_check.ID_estudiante = n.ID_estudiante
        JOIN Carreras c1 ON po.ID_carrera_primera = c1.ID_carrera
        JOIN Carreras c2 ON po.ID_carrera_segunda = c2.ID_carrera
        GROUP BY po.ID_postulante, p.nombre, p.apellido, p.CI, po.ID_persona,
                 c1.nombre_carrera, c1.cupo_maximo, po.ID_carrera_primera,
                 c2.nombre_carrera, c2.cupo_maximo, po.ID_carrera_segunda,
                 po.Estado_postulacion
        ORDER BY promedio_final DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $error = "Error al cargar planilla de admisión: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesar Admisiones | FICCT</title>
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
                <li class="active">
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

        <!-- ÁREA PRINCIPAL -->
        <main class="main-content">
            
            <!-- CONEXIÓN VISUAL -->
            <div class="card" style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; margin-bottom: 25px; font-size: 0.85rem; text-align: left; line-height: 1.5;">
                <div style="font-weight: bold; margin-bottom: 4px; color: var(--primary);">Conexión Visual:</div>
                <strong>Vista:</strong> admision.blade.php<br>
                <strong>Controlador:</strong> AdmisionController<br>
                <strong>Funciones:</strong> calcularPromedioGeneral(), determinarEstadoAdmision(), reasignarPorCupoSaturado(), verificarCupoCarrera(), asignarSegundaOpcion()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Procesamiento de Admisión e Inscripción</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Algoritmo de control de cupos y carrera por opciones</p>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- ACCIONES DE PROCESAMIENTO -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <div style="flex: 1; min-width: 300px;">
                    <h3 style="margin: 0 0 8px 0; color: var(--primary);">Herramientas de Admisión Automatizada</h3>
                    <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">El sistema admite automáticamente a los aprobados (Promedio final $\ge$ 60) que tengan pago completado.</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <form method="POST" action="admision.php" style="margin: 0;">
                        <input type="hidden" name="action" value="procesar_admision">
                        <button type="submit" class="btn btn-primary">Procesar admisión</button>
                    </form>
                    <form method="POST" action="admision.php" style="margin: 0;">
                        <input type="hidden" name="action" value="reasignar_segunda_opcion">
                        <button type="submit" class="btn btn-secondary">Reasignar por cupo saturado</button>
                    </form>
                </div>
            </section>

            <!-- TABLA DE CONTROL DE ADMISIONES -->
            <section class="glass-panel" style="padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Aspirantes y Estado de Admisión</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Postulante (CI)</th>
                                <th>Promedio Final</th>
                                <th>Estado Admisión</th>
                                <th>Carrera 1ra Opción (Cupo)</th>
                                <th>Carrera 2da Opción (Cupo)</th>
                                <th>Carrera Asignada</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($postulantes_list) === 0): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No hay postulantes con calificaciones registradas en el sistema.</td></tr>
                            <?php else: ?>
                                <?php foreach ($postulantes_list as $row): 
                                    $promedio = (float)$row['promedio_final'];
                                    $aprobado = $promedio >= 60;
                                    $cupo_disp_1 = (int)$row['cupo_max_1'] - (int)$row['inscritos_1'];
                                    $cupo_disp_2 = (int)$row['cupo_max_2'] - (int)$row['inscritos_2'];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['nombre'] . ' ' . $row['apellido']) ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">CI: <?= htmlspecialchars($row['ci']) ?></div>
                                        </td>
                                        <td style="font-weight: 700; text-align: center; font-size: 1.05rem;">
                                            <span style="color: <?= $aprobado ? 'var(--success)' : 'var(--error)' ?>;">
                                                <?= number_format($promedio, 2) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $state = $row['estado_postulacion'];
                                            $badge = 'badge-secondary';
                                            if ($state === 'Aprobado') {
                                                $state_label = 'Admitido';
                                                $badge = 'badge-success';
                                            } else {
                                                $state_label = $aprobado ? 'Habilitado (Espera)' : 'No Admitido (Reprobado)';
                                                $badge = $aprobado ? 'badge-info' : 'badge-error';
                                            }
                                            ?>
                                            <span class="badge <?= $badge ?>"><?= $state_label ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['carrera_1']) ?>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">Disponibles: <?= $cupo_disp_1 ?> / <?= $row['cupo_max_1'] ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($row['carrera_2']) ?>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);">Disponibles: <?= $cupo_disp_2 ?> / <?= $row['cupo_max_2'] ?></div>
                                        </td>
                                        <td>
                                            <?php if ($row['carrera_asignada']): ?>
                                                <span class="badge badge-success" style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($row['carrera_asignada']) ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-style: italic;">Sin asignar</span>
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
</body>
</html>
