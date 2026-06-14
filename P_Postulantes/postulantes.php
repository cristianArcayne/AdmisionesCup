<?php
/**
 * CRUD de Postulantes - Módulo 3: POSTULANTES
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

// --- 1. PROCESAR ACCIÓN DE ELIMINACIÓN (DELETE) ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_postulante = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();

        // Obtener ID_persona del postulante
        $stmt = $pdo->prepare("SELECT ID_persona FROM Postulantes WHERE ID_postulante = :id_postulante");
        $stmt->execute(['id_postulante' => $id_postulante]);
        $id_persona = $stmt->fetchColumn();

        if ($id_persona) {
            // Eliminar de Notas asociadas a Estudiante (si existe matriculado)
            $stmt = $pdo->prepare("SELECT ID_estudiante FROM Estudiantes WHERE ID_persona = :id_persona");
            $stmt->execute(['id_persona' => $id_persona]);
            $id_estudiante = $stmt->fetchColumn();

            if ($id_estudiante) {
                $stmt = $pdo->prepare("DELETE FROM Notas WHERE ID_estudiante = :id_estudiante");
                $stmt->execute(['id_estudiante' => $id_estudiante]);

                $stmt = $pdo->prepare("DELETE FROM Estudiantes WHERE ID_estudiante = :id_estudiante");
                $stmt->execute(['id_estudiante' => $id_estudiante]);
            }

            // Eliminar de Pagos
            $stmt = $pdo->prepare("DELETE FROM Pagos WHERE ID_postulante = :id_postulante");
            $stmt->execute(['id_postulante' => $id_postulante]);

            // Eliminar de Postulantes
            $stmt = $pdo->prepare("DELETE FROM Postulantes WHERE ID_postulante = :id_postulante");
            $stmt->execute(['id_postulante' => $id_postulante]);

            // Eliminar Persona
            $stmt = $pdo->prepare("DELETE FROM Personas WHERE ID_persona = :id_persona");
            $stmt->execute(['id_persona' => $id_persona]);

            $pdo->commit();
            $message = "El postulante y todos sus registros asociados fueron eliminados correctamente.";
        } else {
            throw new Exception("Postulante no encontrado.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al eliminar postulante: " . $e->getMessage();
    }
}

// --- 2. PROCESAR ACCIÓN DE CREACIÓN O EDICIÓN (INSERT/UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $action = $_POST['action'];
    $ci = trim($_POST['ci'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo_personal = trim($_POST['correo_personal'] ?? '');
    $colegio_procedencia = trim($_POST['colegio_procedencia'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $carrera_primera = (int)($_POST['carrera_primera'] ?? 0);
    $carrera_segunda = (int)($_POST['carrera_segunda'] ?? 0);

    if (empty($ci) || empty($nombre) || empty($apellido) || empty($correo_personal) || empty($carrera_primera) || empty($carrera_segunda)) {
        $error = "CI, Nombres, Apellidos, Correo y ambas opciones de Carrera son obligatorios.";
    } elseif ($carrera_primera === $carrera_segunda) {
        $error = "La primera y segunda opción de carrera deben ser diferentes.";
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'create') {
                // Verificar duplicado de CI
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM Personas WHERE CI = :ci");
                $stmt->execute(['ci' => $ci]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("El número de CI ya está registrado.");
                }

                // Insertar Persona
                $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, fecha_nacimiento, genero, direccion, telefono, correo_personal) 
                                       VALUES (:nombre, :apellido, :ci, :fecha_nacimiento, :genero, :direccion, :telefono, :correo_personal)");
                $stmt->execute([
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'ci' => $ci,
                    'fecha_nacimiento' => $fecha_nacimiento ?: null,
                    'genero' => $genero ?: null,
                    'direccion' => $direccion ?: null,
                    'telefono' => $telefono ?: null,
                    'correo_personal' => $correo_personal
                ]);
                $id_persona = $pdo->lastInsertId();

                // Insertar Postulante (inicialmente Estado = 'Registrado')
                $stmt = $pdo->prepare("INSERT INTO Postulantes (ID_persona, Colegio_procedencia, Ciudad, Titulo_bachiller, ID_carrera_primera, ID_carrera_segunda, Estado_postulacion) 
                                       VALUES (:id_persona, :colegio, :ciudad, TRUE, :carrera_1, :carrera_2, 'Registrado')");
                $stmt->execute([
                    'id_persona' => $id_persona,
                    'colegio' => $colegio_procedencia,
                    'ciudad' => $ciudad,
                    'carrera_1' => $carrera_primera,
                    'carrera_2' => $carrera_segunda
                ]);

                $pdo->commit();
                $message = "El postulante fue registrado correctamente.";
            } 
            elseif ($action === 'update') {
                $id_postulante = (int)$_POST['id_postulante'];
                $id_persona = (int)$_POST['id_persona'];

                // Verificar que el nuevo CI no pertenezca a otra persona
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM Personas WHERE CI = :ci AND ID_persona != :id_persona");
                $stmt->execute(['ci' => $ci, 'id_persona' => $id_persona]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("El número de CI ya pertenece a otra persona registrada.");
                }

                // Actualizar Persona
                $stmt = $pdo->prepare("UPDATE Personas SET nombre = :nombre, apellido = :apellido, CI = :ci, 
                                       fecha_nacimiento = :fecha_nacimiento, genero = :genero, direccion = :direccion, 
                                       telefono = :telefono, correo_personal = :correo_personal WHERE ID_persona = :id_persona");
                $stmt->execute([
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'ci' => $ci,
                    'fecha_nacimiento' => $fecha_nacimiento ?: null,
                    'genero' => $genero ?: null,
                    'direccion' => $direccion ?: null,
                    'telefono' => $telefono ?: null,
                    'correo_personal' => $correo_personal,
                    'id_persona' => $id_persona
                ]);

                // Actualizar Postulante
                $stmt = $pdo->prepare("UPDATE Postulantes SET Colegio_procedencia = :colegio, Ciudad = :ciudad, 
                                       ID_carrera_primera = :carrera_1, ID_carrera_segunda = :carrera_2 WHERE ID_postulante = :id_postulante");
                $stmt->execute([
                    'colegio' => $colegio_procedencia,
                    'ciudad' => $ciudad,
                    'carrera_1' => $carrera_primera,
                    'carrera_2' => $carrera_segunda,
                    'id_postulante' => $id_postulante
                ]);

                $pdo->commit();
                $message = "Los datos del postulante fueron modificados correctamente.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al guardar los datos: " . $e->getMessage();
        }
    }
}

// --- 3. BÚSQUEDA Y LISTADO DE POSTULANTES ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$postulantes = [];

try {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal, 
                   po.Colegio_procedencia, po.Ciudad, p.fecha_nacimiento, p.genero, p.direccion, p.telefono,
                   c1.nombre_carrera AS carrera_1, c2.nombre_carrera AS carrera_2,
                   po.ID_carrera_primera, po.ID_carrera_segunda, po.Estado_postulacion
            FROM Postulantes po
            JOIN Personas p ON po.ID_persona = p.ID_persona
            JOIN Carreras c1 ON po.ID_carrera_primera = c1.ID_carrera
            JOIN Carreras c2 ON po.ID_carrera_segunda = c2.ID_carrera
            WHERE p.nombre ILIKE :search OR p.apellido ILIKE :search OR p.CI ILIKE :search
            ORDER BY po.ID_postulante DESC
        ");
        $stmt->execute(['search' => "%$search%"]);
        $postulantes = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("
            SELECT po.ID_postulante, po.ID_persona, p.nombre, p.apellido, p.CI, p.correo_personal, 
                   po.Colegio_procedencia, po.Ciudad, p.fecha_nacimiento, p.genero, p.direccion, p.telefono,
                   c1.nombre_carrera AS carrera_1, c2.nombre_carrera AS carrera_2,
                   po.ID_carrera_primera, po.ID_carrera_segunda, po.Estado_postulacion
            FROM Postulantes po
            JOIN Personas p ON po.ID_persona = p.ID_persona
            JOIN Carreras c1 ON po.ID_carrera_primera = c1.ID_carrera
            JOIN Carreras c2 ON po.ID_carrera_segunda = c2.ID_carrera
            ORDER BY po.ID_postulante DESC
        ");
        $postulantes = $stmt->fetchAll();
    }

    // Cargar Catálogos para selects
    $carreras = $pdo->query("SELECT ID_carrera, nombre_carrera FROM Carreras WHERE estado = TRUE ORDER BY ID_carrera")->fetchAll();

} catch (PDOException $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Cargar postulante para edición
$editing_postulante = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id_postulante = (int)$_GET['edit'];
    foreach ($postulantes as $po) {
        if ((int)$po['id_postulante'] === $id_postulante) {
            $editing_postulante = $po;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Postulantes | FICCT</title>
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
                    <a href="../P_Academico/admin_dashboard.php">Panel Principal</a>
                </li>
                <li class="active">
                    <a href="postulantes.php">Gestionar Postulantes</a>
                </li>
                <li>
                    <a href="pagos.php">Gestionar Pagos</a>
                </li>
                <li>
                    <a href="../P_Academico/admision.php">Procesar Admisión</a>
                </li>
                <li>
                    <a href="../P_Academico/grupos.php">Gestionar Grupos</a>
                </li>
                <li>
                    <a href="../P_Academico/docentes.php">Gestionar Docentes</a>
                </li>
                <li>
                    <a href="../P_Academico/notas.php">Gestionar Notas</a>
                </li>
                <li>
                    <a href="../P_Academico/reportes.php">Reportes</a>
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
                <strong>Vista:</strong> postulantes.blade.php<br>
                <strong>Controlador:</strong> PostulanteController<br>
                <strong>Funciones:</strong> registrarPostulante(), modificarPostulante(), eliminarPostulante(), buscarPostulante(), listarPostulantes(), validarCI(), validarCorreo()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Gestión de Postulantes</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Registrar y Administrar Aspirantes al CUP</p>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- FORMULARIO DE REGISTRO / EDICIÓN -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px;">
                <h3 class="gradient-text" style="font-size: 1.25rem; margin-bottom: 20px;">
                    <?= $editing_postulante ? 'Modificar Postulante' : 'Registrar Nuevo Postulante' ?>
                </h3>
                
                <form id="postulanteForm" method="POST" action="postulantes.php" novalidate>
                    <input type="hidden" name="action" value="<?= $editing_postulante ? 'update' : 'create' ?>">
                    <?php if ($editing_postulante): ?>
                        <input type="hidden" name="id_postulante" value="<?= $editing_postulante['id_postulante'] ?>">
                        <input type="hidden" name="id_persona" value="<?= $editing_postulante['id_persona'] ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        
                        <div class="form-group">
                            <label for="ci">CI / Carnet *</label>
                            <input type="text" id="ci" name="ci" class="form-control" value="<?= htmlspecialchars($editing_postulante['ci'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="nombre">Nombres *</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" value="<?= htmlspecialchars($editing_postulante['nombre'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="apellido">Apellidos *</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" value="<?= htmlspecialchars($editing_postulante['apellido'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="fecha_nacimiento">Fecha de Nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-control" value="<?= htmlspecialchars($editing_postulante['fecha_nacimiento'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="genero">Sexo (Género)</label>
                            <select id="genero" name="genero" class="form-control">
                                <option value="">Selecciona...</option>
                                <option value="M" <?= ($editing_postulante['genero'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                                <option value="F" <?= ($editing_postulante['genero'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="direccion">Dirección</label>
                            <input type="text" id="direccion" name="direccion" class="form-control" value="<?= htmlspecialchars($editing_postulante['direccion'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono" class="form-control" value="<?= htmlspecialchars($editing_postulante['telefono'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="correo_personal">Correo *</label>
                            <input type="email" id="correo_personal" name="correo_personal" class="form-control" value="<?= htmlspecialchars($editing_postulante['correo_personal'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="colegio_procedencia">Colegio</label>
                            <input type="text" id="colegio_procedencia" name="colegio_procedencia" class="form-control" value="<?= htmlspecialchars($editing_postulante['colegio_procedencia'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="ciudad">Ciudad</label>
                            <input type="text" id="ciudad" name="ciudad" class="form-control" value="<?= htmlspecialchars($editing_postulante['ciudad'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="carrera_primera">Carrera 1ra Opción *</label>
                            <select id="carrera_primera" name="carrera_primera" class="form-control" required>
                                <option value="">Seleccione Carrera...</option>
                                <?php foreach ($carreras as $c): ?>
                                    <option value="<?= $c['id_carrera'] ?>" <?= (int)($editing_postulante['id_carrera_primera'] ?? 0) === (int)$c['id_carrera'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nombre_carrera']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="carrera_segunda">Carrera 2da Opción *</label>
                            <select id="carrera_segunda" name="carrera_segunda" class="form-control" required>
                                <option value="">Seleccione Carrera...</option>
                                <?php foreach ($carreras as $c): ?>
                                    <option value="<?= $c['id_carrera'] ?>" <?= (int)($editing_postulante['id_carrera_segunda'] ?? 0) === (int)$c['id_carrera'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nombre_carrera']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <?= $editing_postulante ? 'Editar' : 'Registrar' ?>
                        </button>
                        <a href="postulantes.php" class="btn btn-secondary">Limpiar</a>
                    </div>
                </form>
            </section>

            <!-- BUSCADOR -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 20px;">
                <form method="GET" action="postulantes.php" style="display: flex; gap: 10px; align-items: center;">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <input type="text" name="search" class="form-control" placeholder="Buscar por Nombre, Apellido o CI..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary">Buscar</button>
                    <?php if (!empty($search)): ?>
                        <a href="postulantes.php" class="btn btn-secondary">Limpiar Búsqueda</a>
                    <?php endif; ?>
                </form>
            </section>

            <!-- TABLA DE RESULTADOS -->
            <section class="glass-panel" style="padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Listado de Postulantes</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>CI / Carnet</th>
                                <th>Nombres y Apellidos</th>
                                <th>Correo</th>
                                <th>Colegio</th>
                                <th>Carrera 1</th>
                                <th>Carrera 2</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($postulantes) === 0): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-muted);">No hay postulantes registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($postulantes as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['ci']) ?></td>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($p['nombre'] . ' ' . $p['apellido']) ?></td>
                                        <td><?= htmlspecialchars($p['correo_personal']) ?></td>
                                        <td><?= htmlspecialchars($p['colegio_procedencia'] ?: '-') ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($p['carrera_1']) ?></span></td>
                                        <td><span class="badge badge-secondary"><?= htmlspecialchars($p['carrera_2']) ?></span></td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-secondary';
                                            if ($p['estado_postulacion'] === 'Pagado') $badge_class = 'badge-success';
                                            elseif ($p['estado_postulacion'] === 'Aprobado') $badge_class = 'badge-success';
                                            elseif ($p['estado_postulacion'] === 'Rechazado') $badge_class = 'badge-error';
                                            ?>
                                            <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($p['estado_postulacion']) ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <a href="postulantes.php?edit=<?= $p['id_postulante'] ?>" class="btn btn-secondary btn-small" style="padding: 6px 12px; font-size: 0.8rem;">Editar</a>
                                                <a href="postulantes.php?delete=<?= $p['id_postulante'] ?>" class="btn btn-secondary btn-small" style="padding: 6px 12px; font-size: 0.8rem; color: var(--error);" onclick="return confirm('¿Estás seguro de eliminar este postulante? Se borrarán sus pagos y estado.')">Eliminar</a>
                                            </div>
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
        document.getElementById('postulanteForm').addEventListener('submit', function(event) {
            const ciInput = document.getElementById('ci');
            const nombreInput = document.getElementById('nombre');
            const apellidoInput = document.getElementById('apellido');
            const correoInput = document.getElementById('correo_personal');
            const carrera1 = document.getElementById('carrera_primera');
            const carrera2 = document.getElementById('carrera_segunda');
            
            let valid = true;
            let errorMessage = "";

            // Limpiar alertas de error previas
            const existingAlerts = document.querySelectorAll('.alert-error');
            existingAlerts.forEach(a => a.remove());

            // Validación de campos obligatorios
            if (!ciInput.value.trim()) {
                valid = false;
                errorMessage = "El número de CI no puede estar vacío.";
            } else if (!nombreInput.value.trim()) {
                valid = false;
                errorMessage = "Los nombres son obligatorios.";
            } else if (!apellidoInput.value.trim()) {
                valid = false;
                errorMessage = "Los apellidos son obligatorios.";
            } else if (!correoInput.value.trim()) {
                valid = false;
                errorMessage = "El correo electrónico es obligatorio.";
            } else {
                // Validación formato correo
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(correoInput.value.trim())) {
                    valid = false;
                    errorMessage = "Por favor ingresa un correo electrónico con formato válido.";
                }
            }

            // Validar Carreras
            if (valid) {
                if (!carrera1.value) {
                    valid = false;
                    errorMessage = "Debes seleccionar la primera opción de carrera.";
                } else if (!carrera2.value) {
                    valid = false;
                    errorMessage = "Debes seleccionar la segunda opción de carrera.";
                } else if (carrera1.value === carrera2.value) {
                    valid = false;
                    errorMessage = "La primera y la segunda opción de carrera deben ser distintas.";
                }
            }

            if (!valid) {
                event.preventDefault();
                // Inyectar mensaje de error
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.style.marginBottom = '20px';
                alertDiv.innerText = errorMessage;
                
                const form = document.getElementById('postulanteForm');
                form.parentNode.insertBefore(alertDiv, form);
                
                // Hacer scroll hacia arriba del formulario
                window.scrollTo({ top: form.offsetTop - 50, behavior: 'instant' });
            }
        });
    </script>
</body>
</html>
