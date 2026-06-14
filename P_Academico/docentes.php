<?php
/**
 * Gestión de Docentes y Carga Horaria - Módulo 8: DOCENTES
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

// --- 1. PROCESAR REGISTRO DE NUEVO DOCENTE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_docente') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $ci = trim($_POST['ci'] ?? '');
    $especialidad = trim($_POST['especialidad'] ?? '');
    $tiene_maestria = isset($_POST['tiene_maestria']) ? 1 : 0;
    $tiene_diplomado = isset($_POST['tiene_diplomado']) ? 1 : 0;

    if (empty($nombre) || empty($apellido) || empty($ci) || empty($especialidad)) {
        $error = "Nombre, Apellido, CI y Especialidad son campos obligatorios.";
    } else {
        try {
            $pdo->beginTransaction();

            // Verificar si el CI ya existe
            $stmt = $pdo->prepare("SELECT ID_persona FROM Personas WHERE CI = :ci");
            $stmt->execute(['ci' => $ci]);
            if ($stmt->fetch()) {
                throw new Exception("El número de CI ya está registrado.");
            }

            // Insertar Persona
            $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, correo_personal) VALUES (:nombre, :apellido, :ci, :correo)");
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $ci,
                'correo' => strtolower($nombre . '.' . $apellido . '@univ.edu')
            ]);
            $id_persona = $pdo->lastInsertId();

            // Crear usuario para el docente
            $pass_hash = password_hash($ci, PASSWORD_DEFAULT);
            $stmt_u = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES (:user, :pass, :correo, 2)");
            $stmt_u->execute([
                'user' => strtolower($nombre . '_' . substr($apellido, 0, 1)),
                'pass' => $pass_hash,
                'correo' => strtolower($nombre . '.' . $apellido . '@univ.edu')
            ]);
            $id_user = $pdo->lastInsertId();

            // Insertar Docente
            $stmt_d = $pdo->prepare("INSERT INTO Docentes (ID_persona, ID_user, Especialidad, tiene_maestria, tiene_diplomado, max_grupos) 
                                     VALUES (:id_persona, :id_user, :esp, :maestria, :diplomado, 4)");
            $stmt_d->execute([
                'id_persona' => $id_persona,
                'id_user' => $id_user,
                'esp' => $especialidad,
                'maestria' => $tiene_maestria ? 'true' : 'false',
                'diplomado' => $tiene_diplomado ? 'true' : 'false'
            ]);

            $pdo->commit();
            $message = "El docente fue registrado con éxito.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al registrar docente: " . $e->getMessage();
        }
    }
}

// --- 2. PROCESAR ASIGNACIÓN DE CARGA HORARIA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'asignar_docente') {
    $id_docente = (int)($_POST['id_docente'] ?? 0);
    $id_materia = $_POST['id_materia'] ?? '';
    $id_grupo = (int)($_POST['id_grupo'] ?? 0);

    try {
        $pdo->beginTransaction();

        // A. Validar Requisitos de Postgrado del Docente
        $stmt = $pdo->prepare("SELECT tiene_maestria, tiene_diplomado, p.nombre, p.apellido 
                               FROM Docentes d
                               JOIN Personas p ON d.ID_persona = p.ID_persona
                               WHERE d.ID_docente = :id_doc");
        $stmt->execute(['id_doc' => $id_docente]);
        $doc = $stmt->fetch();

        if (!$doc) {
            throw new Exception("El docente seleccionado no existe.");
        }

        if (!$doc['tiene_maestria'] || !$doc['tiene_diplomado']) {
            throw new Exception("El docente {$doc['nombre']} {$doc['apellido']} no cuenta con los requisitos mínimos de postgrado (Maestría y Diplomado).");
        }

        // B. Validar Carga Horaria (Máximo 4 grupos)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Asignaciones WHERE ID_docente = :id_doc");
        $stmt->execute(['id_doc' => $id_docente]);
        $carga_actual = (int)$stmt->fetchColumn();

        if ($carga_actual >= 4) {
            throw new Exception("Carga saturada: El docente {$doc['nombre']} {$doc['apellido']} ya tiene el límite máximo de 4 grupos asignados.");
        }

        // C. Validar si ya hay una asignación idéntica
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Asignaciones WHERE ID_grupo = :id_grupo AND ID_materia = :id_materia");
        $stmt->execute(['id_grupo' => $id_grupo, 'id_materia' => $id_materia]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("La materia y grupo seleccionados ya tienen un docente asignado.");
        }

        // D. Insertar Asignación
        $stmt = $pdo->prepare("INSERT INTO Asignaciones (ID_docente, ID_grupo, ID_materia) VALUES (:id_doc, :id_grupo, :id_mat)");
        $stmt->execute([
            'id_doc' => $id_docente,
            'id_grupo' => $id_grupo,
            'id_mat' => $id_materia
        ]);

        $pdo->commit();
        $message = "Asignación horaria registrada exitosamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al realizar la asignación: " . $e->getMessage();
    }
}

// --- 3. PROCESAR ACCIÓN DE ELIMINAR ASIGNACIÓN ---
if (isset($_GET['delete_asig']) && is_numeric($_GET['delete_asig'])) {
    $id_asignacion = (int)$_GET['delete_asig'];
    try {
        $stmt = $pdo->prepare("DELETE FROM Asignaciones WHERE ID_asignacion = :id");
        $stmt->execute(['id' => $id_asignacion]);
        $message = "La asignación docente fue removida.";
    } catch (PDOException $e) {
        $error = "Error al eliminar asignación: " . $e->getMessage();
    }
}

// --- 4. CARGAR LISTAS Y PLANILLAS ---
$docentes_list = [];
$asignaciones_list = [];
$materias = [];
$grupos = [];

try {
    // Listado de Docentes
    $docentes_list = $pdo->query("
        SELECT d.ID_docente, p.nombre, p.apellido, p.CI, d.Especialidad, d.tiene_maestria, d.tiene_diplomado,
               (SELECT COUNT(*) FROM Asignaciones a WHERE a.ID_docente = d.ID_docente) AS total_grupos
        FROM Docentes d
        JOIN Personas p ON d.ID_persona = p.ID_persona
        ORDER BY p.apellido, p.nombre
    ")->fetchAll();

    // Listado de Asignaciones
    $asignaciones_list = $pdo->query("
        SELECT a.ID_asignacion, p.nombre, p.apellido, m.nombre_materia, g.nombre_grupo
        FROM Asignaciones a
        JOIN Docentes d ON a.ID_docente = d.ID_docente
        JOIN Personas p ON d.ID_persona = p.ID_persona
        JOIN Materias m ON a.ID_materia = m.ID_materia
        JOIN Grupos g ON a.ID_grupo = g.ID_grupo
        ORDER BY g.nombre_grupo, m.nombre_materia
    ")->fetchAll();

    // Catálogos
    $materias = $pdo->query("SELECT ID_materia, nombre_materia FROM Materias ORDER BY nombre_materia")->fetchAll();
    $grupos = $pdo->query("SELECT ID_grupo, nombre_grupo FROM Grupos WHERE estado = TRUE ORDER BY nombre_grupo")->fetchAll();

} catch (PDOException $e) {
    $error = "Error al cargar planilla de docentes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Docentes | FICCT</title>
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
                <li>
                    <a href="grupos.php">Gestionar Grupos</a>
                </li>
                <li class="active">
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
                <strong>Vista:</strong> docentes.blade.php<br>
                <strong>Controlador:</strong> DocenteController<br>
                <strong>Funciones:</strong> listarDocentes(), asignarDocente(), validarRequisitosDocente(), validarCargaHoraria(), asignarCargaHoraria()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Gestión y Carga de Docentes</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registro académico y control de asignación de carga horaria</p>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- FORMULARIO 1: REGISTRAR DOCENTE -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px;">
                <h3 class="gradient-text" style="font-size: 1.25rem; margin-bottom: 20px;">Registrar Nuevo Docente</h3>
                
                <form id="docenteForm" method="POST" action="docentes.php">
                    <input type="hidden" name="action" value="register_docente">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="nombre">Nombre *</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" placeholder="Ej: Carlos" required>
                        </div>
                        <div class="form-group">
                            <label for="apellido">Apellido *</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" placeholder="Ej: Mendoza" required>
                        </div>
                        <div class="form-group">
                            <label for="ci">CI / Carnet *</label>
                            <input type="text" id="ci" name="ci" class="form-control" placeholder="Ej: 1234567" required>
                        </div>
                        <div class="form-group">
                            <label for="especialidad">Especialidad *</label>
                            <input type="text" id="especialidad" name="especialidad" class="form-control" placeholder="Ej: Ciencias Físicas" required>
                        </div>
                    </div>

                    <div style="display: flex; gap: 30px; margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer;">
                            <input type="checkbox" id="tiene_maestria" name="tiene_maestria" value="1"> ¿Cuenta con Maestría?
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer;">
                            <input type="checkbox" id="tiene_diplomado" name="tiene_diplomado" value="1"> ¿Cuenta con Diplomado?
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">Registrar Docente</button>
                </form>
            </section>

            <!-- FORMULARIO 2: ASIGNAR CARGA HORARIA -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px;">
                <h3 class="gradient-text" style="font-size: 1.25rem; margin-bottom: 20px;">Asignar Carga Horaria (Docente a Grupo/Materia)</h3>
                
                <form id="asignacionForm" method="POST" action="docentes.php" novalidate>
                    <input type="hidden" name="action" value="asignar_docente">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        
                        <div class="form-group">
                            <label for="id_docente">Docente Calificado *</label>
                            <select id="id_docente" name="id_docente" class="form-control" required>
                                <option value="">Seleccione Docente...</option>
                                <?php foreach ($docentes_list as $d): 
                                    $apto = $d['tiene_maestria'] && $d['tiene_diplomado'];
                                ?>
                                    <option value="<?= $d['id_docente'] ?>" data-maestria="<?= $d['tiene_maestria'] ? '1' : '0' ?>" data-diplomado="<?= $d['tiene_diplomado'] ? '1' : '0' ?>" data-carga="<?= $d['total_grupos'] ?>">
                                        <?= htmlspecialchars($d['apellido'] . ' ' . $d['nombre']) ?> (<?= $apto ? 'Apto' : 'No Apto' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="id_materia">Materia *</label>
                            <select id="id_materia" name="id_materia" class="form-control" required>
                                <option value="">Seleccione Materia...</option>
                                <?php foreach ($materias as $m): ?>
                                    <option value="<?= htmlspecialchars($m['id_materia']) ?>">
                                        <?= htmlspecialchars($m['nombre_materia']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="id_grupo">Grupo de Estudio *</label>
                            <select id="id_grupo" name="id_grupo" class="form-control" required>
                                <option value="">Seleccione Grupo...</option>
                                <?php foreach ($grupos as $g): ?>
                                    <option value="<?= $g['id_grupo'] ?>">
                                        <?= htmlspecialchars($g['nombre_grupo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">Asignar Docente</button>
                </form>
            </section>

            <!-- TABLA DE ASIGNACIONES (CARGA HORARIA) -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Planilla de Asignaciones de Carga Horaria</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Materia Asignada</th>
                                <th>Grupo</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($asignaciones_list) === 0): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No hay asignaciones cargadas.</td></tr>
                            <?php else: ?>
                                <?php foreach ($asignaciones_list as $a): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($a['apellido'] . ' ' . $a['nombre']) ?></strong></td>
                                        <td><span class="badge badge-success"><?= htmlspecialchars($a['nombre_materia']) ?></span></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($a['nombre_grupo']) ?></span></td>
                                        <td>
                                            <a href="docentes.php?delete_asig=<?= $a['id_asignacion'] ?>" class="btn btn-secondary btn-small" style="color: var(--error);" onclick="return confirm('¿Remover esta carga horaria del docente?')">Eliminar asignación</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- TABLA DE DOCENTES REGISTRADOS -->
            <section class="glass-panel" style="padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Lista de Planta Docente</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>CI / Carnet</th>
                                <th>Nombres y Apellidos</th>
                                <th>Especialidad</th>
                                <th>Maestría</th>
                                <th>Diplomado</th>
                                <th>Grupos Asignados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($docentes_list as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['ci']) ?></td>
                                    <td><strong><?= htmlspecialchars($d['apellido'] . ' ' . $d['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($d['especialidad']) ?></td>
                                    <td><?= $d['tiene_maestria'] ? 'SI' : 'NO' ?></td>
                                    <td><?= $d['tiene_diplomado'] ? 'SI' : 'NO' ?></td>
                                    <td><span style="font-weight: 700; color: <?= (int)$d['total_grupos'] >= 4 ? 'var(--error)' : 'inherit' ?>;"><?= $d['total_grupos'] ?> / 4 máx</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>

    <!-- VALIDACIONES JS -->
    <script>
        // Validación de Carga Horaria y Postgrado al enviar la asignación
        document.getElementById('asignacionForm').addEventListener('submit', function(event) {
            const docSelect = document.getElementById('id_docente');
            const selectedOpt = docSelect.options[docSelect.selectedIndex];
            
            let valid = true;
            let errorMessage = "";

            // Limpiar alertas de error previas
            const existingAlerts = document.querySelectorAll('.alert-error');
            existingAlerts.forEach(a => a.remove());

            if (!docSelect.value) {
                valid = false;
                errorMessage = "Por favor selecciona un docente.";
            } else {
                const tieneMaestria = selectedOpt.getAttribute('data-maestria') === '1';
                const tieneDiplomado = selectedOpt.getAttribute('data-diplomado') === '1';
                const carga = parseInt(selectedOpt.getAttribute('data-carga')) || 0;

                // Regla 1: Postgrado
                if (!tieneMaestria || !tieneDiplomado) {
                    valid = false;
                    errorMessage = "Requisito de Postgrado Incumplido: El docente debe contar obligatoriamente con Maestría y Diplomado para dictar clases.";
                }
                // Regla 2: Carga máxima 4 grupos
                else if (carga >= 4) {
                    valid = false;
                    errorMessage = "Carga Saturada: El docente ya tiene asignado el límite máximo permitido de 4 grupos.";
                }
            }

            if (!valid) {
                event.preventDefault();
                // Inyectar alerta
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.style.marginBottom = '20px';
                alertDiv.innerText = errorMessage;
                
                const form = document.getElementById('asignacionForm');
                form.parentNode.insertBefore(alertDiv, form);
                window.scrollTo({ top: form.offsetTop - 50, behavior: 'instant' });
            }
        });
    </script>
</body>
</html>
