<?php
/**
 * Gestión de Pagos - Módulo 4: PAGOS
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

// --- 1. PROCESAR ACCIONES DE PAGOS (REGISTRAR / VALIDAR / ANULAR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $ci_postulante = trim($_POST['ci_postulante'] ?? '');
    $monto = (float)($_POST['monto'] ?? 350.00);
    $nro_transaccion = trim($_POST['nro_transaccion'] ?? '');
    $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d');
    $estado_pago = $_POST['estado_pago'] ?? 'Pendiente';

    try {
        $pdo->beginTransaction();

        // Buscar el postulante asociado al CI
        $stmt = $pdo->prepare("SELECT po.ID_postulante, po.Estado_postulacion 
                               FROM Postulantes po
                               JOIN Personas p ON po.ID_persona = p.ID_persona
                               WHERE p.CI = :ci");
        $stmt->execute(['ci' => $ci_postulante]);
        $postulante = $stmt->fetch();

        if (!$postulante) {
            throw new Exception("No existe ningún postulante registrado con el CI '$ci_postulante'.");
        }

        $id_postulante = (int)$postulante['id_postulante'];

        if ($action === 'register_pago') {
            if (empty($nro_transaccion)) {
                throw new Exception("El número de transacción es obligatorio.");
            }
            
            // Verificar si el número de transacción ya existe
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Pagos WHERE comprobante = :comprobante");
            $stmt->execute(['comprobante' => $nro_transaccion]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("El número de transacción '$nro_transaccion' ya fue utilizado.");
            }

            // Insertar pago
            $stmt = $pdo->prepare("INSERT INTO Pagos (ID_postulante, monto, comprobante, fecha_pago, estado_pago) 
                                   VALUES (:id_postulante, :monto, :comprobante, :fecha, :estado)");
            $stmt->execute([
                'id_postulante' => $id_postulante,
                'monto' => $monto,
                'comprobante' => $nro_transaccion,
                'fecha' => $fecha_pago,
                'estado' => $estado_pago
            ]);

            // Si se registra directamente como Completado, actualizar estado del postulante a 'Pagado'
            if ($estado_pago === 'Completado') {
                $stmt = $pdo->prepare("UPDATE Postulantes SET Estado_postulacion = 'Pagado' WHERE ID_postulante = :id_postulante");
                $stmt->execute(['id_postulante' => $id_postulante]);
            }

            $pdo->commit();
            $message = "El pago de transacción '$nro_transaccion' fue registrado correctamente.";
        }
        
        elseif ($action === 'validar_pago') {
            $id_pago = (int)$_POST['id_pago'];
            
            // Actualizar pago a 'Completado'
            $stmt = $pdo->prepare("UPDATE Pagos SET estado_pago = 'Completado' WHERE ID_pago = :id_pago");
            $stmt->execute(['id_pago' => $id_pago]);

            // Actualizar Postulante a 'Pagado'
            $stmt = $pdo->prepare("UPDATE Postulantes SET Estado_postulacion = 'Pagado' WHERE ID_postulante = :id_postulante");
            $stmt->execute(['id_postulante' => $id_postulante]);

            $pdo->commit();
            $message = "El pago fue validado y completado exitosamente. Postulante habilitado.";
        }
        
        elseif ($action === 'anular_pago') {
            $id_pago = (int)$_POST['id_pago'];
            
            // Actualizar pago a 'Fallido' (Anulado)
            $stmt = $pdo->prepare("UPDATE Pagos SET estado_pago = 'Fallido' WHERE ID_pago = :id_pago");
            $stmt->execute(['id_pago' => $id_pago]);

            // Devolver Postulante a 'Registrado'
            $stmt = $pdo->prepare("UPDATE Postulantes SET Estado_postulacion = 'Registrado' WHERE ID_postulante = :id_postulante");
            $stmt->execute(['id_postulante' => $id_postulante]);

            $pdo->commit();
            $message = "El pago fue anulado correctamente.";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al procesar pago: " . $e->getMessage();
    }
}

// --- 2. BÚSQUEDA Y LISTADO DE PAGOS ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$pagos = [];

try {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT pa.ID_pago, pa.ID_postulante, p.nombre, p.apellido, p.CI, pa.monto, pa.comprobante, pa.fecha_pago, pa.estado_pago
            FROM Pagos pa
            JOIN Postulantes po ON pa.ID_postulante = po.ID_postulante
            JOIN Personas p ON po.ID_persona = p.ID_persona
            WHERE p.CI = :search OR p.nombre ILIKE :search_like OR p.apellido ILIKE :search_like
            ORDER BY pa.ID_pago DESC
        ");
        $stmt->execute([
            'search' => $search,
            'search_like' => "%$search%"
        ]);
        $pagos = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("
            SELECT pa.ID_pago, pa.ID_postulante, p.nombre, p.apellido, p.CI, pa.monto, pa.comprobante, pa.fecha_pago, pa.estado_pago
            FROM Pagos pa
            JOIN Postulantes po ON pa.ID_postulante = po.ID_postulante
            JOIN Personas p ON po.ID_persona = p.ID_persona
            ORDER BY pa.ID_pago DESC
        ");
        $pagos = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = "Error al cargar pagos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Pagos | FICCT</title>
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
                <li>
                    <a href="postulantes.php">Gestionar Postulantes</a>
                </li>
                <li class="active">
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
                <strong>Vista:</strong> pagos.blade.php<br>
                <strong>Controlador:</strong> PagoController<br>
                <strong>Funciones:</strong> registrarPago(), validarPago(), buscarPago(), listarPagos(), actualizarEstadoPago()
            </div>

            <header class="dash-header" style="margin-bottom: 25px;">
                <div>
                    <h2>Gestión de Pagos de Inscripción</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Verificación de transacciones financieras del CUP</p>
                </div>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- FORMULARIO: REGISTRAR PAGO -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 25px;">
                <h3 class="gradient-text" style="font-size: 1.25rem; margin-bottom: 20px;">Registrar Transacción de Pago</h3>
                
                <form id="pagoForm" method="POST" action="pagos.php" novalidate>
                    <input type="hidden" name="action" value="register_pago">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        
                        <div class="form-group">
                            <label for="ci_postulante">CI del Postulante *</label>
                            <input type="text" id="ci_postulante" name="ci_postulante" class="form-control" placeholder="Ej: 8765432-LP" required>
                        </div>

                        <div class="form-group">
                            <label for="monto">Monto (BOB) *</label>
                            <input type="number" id="monto" name="monto" class="form-control" value="350.00" step="0.01" required>
                        </div>

                        <div class="form-group">
                            <label for="nro_transaccion">Número de Transacción (Comprobante) *</label>
                            <input type="text" id="nro_transaccion" name="nro_transaccion" class="form-control" placeholder="Ej: COMP-584930" required>
                        </div>

                        <div class="form-group">
                            <label for="fecha_pago">Fecha de Pago</label>
                            <input type="date" id="fecha_pago" name="fecha_pago" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label for="estado_pago">Estado Inicial</label>
                            <select id="estado_pago" name="estado_pago" class="form-control">
                                <option value="Pendiente">Pendiente</option>
                                <option value="Completado" selected>Completado</option>
                                <option value="Fallido">Fallido</option>
                            </select>
                        </div>

                    </div>

                    <button type="submit" class="btn btn-primary">Registrar Pago</button>
                </form>
            </section>

            <!-- BUSCADOR -->
            <section class="glass-panel" style="margin-bottom: 30px; padding: 20px;">
                <form method="GET" action="pagos.php" style="display: flex; gap: 10px; align-items: center;">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <input type="text" name="search" class="form-control" placeholder="Buscar pagos por CI del postulante..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary">Buscar</button>
                    <?php if (!empty($search)): ?>
                        <a href="pagos.php" class="btn btn-secondary">Limpiar</a>
                    <?php endif; ?>
                </form>
            </section>

            <!-- TABLA DE RESULTADOS -->
            <section class="glass-panel" style="padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px; color: var(--primary);">Listado de Pagos de Matrícula</h3>
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Postulante (CI)</th>
                                <th>Nombres y Apellidos</th>
                                <th>Monto</th>
                                <th>Número de Transacción</th>
                                <th>Fecha de Pago</th>
                                <th>Estado de Pago</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($pagos) === 0): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay pagos registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pagos as $p): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($p['ci']) ?></strong></td>
                                        <td><?= htmlspecialchars($p['nombre'] . ' ' . $p['apellido']) ?></td>
                                        <td><?= number_format($p['monto'], 2) ?> BOB</td>
                                        <td><code><?= htmlspecialchars($p['comprobante']) ?></code></td>
                                        <td><?= htmlspecialchars($p['fecha_pago']) ?></td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-secondary';
                                            if ($p['estado_pago'] === 'Completado') $badge_class = 'badge-success';
                                            elseif ($p['estado_pago'] === 'Fallido') $badge_class = 'badge-error';
                                            ?>
                                            <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($p['estado_pago']) ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <?php if ($p['estado_pago'] !== 'Completado'): ?>
                                                    <form method="POST" action="pagos.php" style="display: inline;">
                                                        <input type="hidden" name="action" value="validar_pago">
                                                        <input type="hidden" name="id_pago" value="<?= $p['id_pago'] ?>">
                                                        <input type="hidden" name="ci_postulante" value="<?= htmlspecialchars($p['ci']) ?>">
                                                        <button type="submit" class="btn btn-secondary btn-small" style="color: var(--success);">Validar Pago</button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($p['estado_pago'] !== 'Fallido'): ?>
                                                    <form method="POST" action="pagos.php" style="display: inline;" onsubmit="return confirm('¿Estás seguro de anular esta transacción?')">
                                                        <input type="hidden" name="action" value="anular_pago">
                                                        <input type="hidden" name="id_pago" value="<?= $p['id_pago'] ?>">
                                                        <input type="hidden" name="ci_postulante" value="<?= htmlspecialchars($p['ci']) ?>">
                                                        <button type="submit" class="btn btn-secondary btn-small" style="color: var(--error);">Anular Pago</button>
                                                    </form>
                                                <?php endif; ?>
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
        document.getElementById('pagoForm').addEventListener('submit', function(event) {
            const ciInput = document.getElementById('ci_postulante');
            const montoInput = document.getElementById('monto');
            const trxInput = document.getElementById('nro_transaccion');
            
            let valid = true;
            let errorMessage = "";

            // Limpiar alertas de error previas
            const existingAlerts = document.querySelectorAll('.alert-error');
            existingAlerts.forEach(a => a.remove());

            if (!ciInput.value.trim()) {
                valid = false;
                errorMessage = "El CI del postulante es obligatorio.";
            } else if (!montoInput.value || parseFloat(montoInput.value) <= 0) {
                valid = false;
                errorMessage = "El monto debe ser un valor positivo.";
            } else if (!trxInput.value.trim()) {
                valid = false;
                errorMessage = "El número de transacción es obligatorio.";
            }

            if (!valid) {
                event.preventDefault();
                // Inyectar alerta
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.style.marginBottom = '20px';
                alertDiv.innerText = errorMessage;
                
                const form = document.getElementById('pagoForm');
                form.parentNode.insertBefore(alertDiv, form);
                window.scrollTo({ top: form.offsetTop - 50, behavior: 'instant' });
            }
        });
    </script>
</body>
</html>
