<?php
/**
 * Registro de Postulantes Simplificado (Sin Emojis)
 */

require_once '../config/db.php';

// --- CONTROL DE AJAX: Verificación de CI Duplicado ---
if (isset($_GET['check_ci'])) {
    header('Content-Type: application/json');
    $ci = trim($_GET['check_ci']);
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Personas WHERE CI = :ci");
        $stmt->execute(['ci' => $ci]);
        $exists = $stmt->fetchColumn() > 0;
        echo json_encode(['exists' => $exists]);
    } catch (PDOException $e) {
        echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    }
    exit;
}

$message = "";
$error = "";
$success_data = null;

// --- PROCESAMIENTO DEL FORMULARIO DE REGISTRO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    
    // Captura de datos
    $ci = trim($_POST['ci']);
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $genero = $_POST['genero'];
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $correo_personal = trim($_POST['correo_personal']);
    $colegio_procedencia = trim($_POST['colegio_procedencia']);
    $ciudad = trim($_POST['ciudad']);
    $titulo_bachiller = 1;
    
    $carrera_primera = (int)$_POST['carrera_primera'];
    $carrera_segunda = (int)$_POST['carrera_segunda'];
    
    $metodo_pago = $_POST['metodo_pago']; // 'qr' o 'card'

    // Validación Backend Básica
    if (empty($ci) || empty($nombre) || empty($apellido) || empty($fecha_nacimiento) || empty($genero) || empty($correo_personal) || empty($carrera_primera) || empty($carrera_segunda)) {
        $error = "Por favor completa todos los campos requeridos (*).";
    } elseif ($carrera_primera === $carrera_segunda) {
        $error = "La primera y segunda opción de carrera deben ser diferentes.";
    } else {
        try {
            // Iniciar Transacción en la Base de Datos
            $pdo->beginTransaction();

            // 1. Verificar nuevamente si la Persona existe
            $stmt = $pdo->prepare("SELECT ID_persona FROM Personas WHERE CI = :ci");
            $stmt->execute(['ci' => $ci]);
            if ($stmt->fetch()) {
                throw new Exception("El número de CI ya se encuentra registrado.");
            }

            // 2. Insertar en Personas
            $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, fecha_nacimiento, genero, direccion, telefono, correo_personal) 
                                   VALUES (:nombre, :apellido, :ci, :fecha_nacimiento, :genero, :direccion, :telefono, :correo_personal)");
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $ci,
                'fecha_nacimiento' => $fecha_nacimiento,
                'genero' => $genero,
                'direccion' => $direccion,
                'telefono' => $telefono,
                'correo_personal' => $correo_personal
            ]);
            $id_persona = $pdo->lastInsertId();

            // 3. Insertar en Postulantes (Estado_postulacion = 'Pagado')
            $stmt = $pdo->prepare("INSERT INTO Postulantes (ID_persona, Colegio_procedencia, Ciudad, Titulo_bachiller, ID_carrera_primera, ID_carrera_segunda, Estado_postulacion) 
                                   VALUES (:id_persona, :colegio, :ciudad, :bachiller, :carrera_1, :carrera_2, 'Pagado')");
            $stmt->execute([
                'id_persona' => $id_persona,
                'colegio' => $colegio_procedencia,
                'ciudad' => $ciudad,
                'bachiller' => 'true',
                'carrera_1' => $carrera_primera,
                'carrera_2' => $carrera_segunda
            ]);
            $id_postulante = $pdo->lastInsertId();

            // 4. Insertar en Pagos (350.00 completado)
            $comprobante = 'COMP-' . rand(100000, 999999);
            $stmt = $pdo->prepare("INSERT INTO Pagos (ID_postulante, monto, comprobante, estado_pago) 
                                   VALUES (:id_postulante, 350.00, :comprobante, 'Completado')");
            $stmt->execute([
                'id_postulante' => $id_postulante,
                'comprobante' => $comprobante
            ]);

            // 5. Crear cuenta de Usuario de estudiante para iniciar sesión
            // Usuario del estudiante: su número de CI
            $username = trim($ci);

            // Contraseña: primera letra de su apellido (en minúscula) y el CI
            $first_letter_lastname = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $apellido), 0, 1));
            $plain_password = $first_letter_lastname . $username;
            $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

            // Insertar en Usuarios (ID_rol 3 = estudiante)
            $stmt = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) 
                                   VALUES (:username, :password, :correo, 3)");
            $stmt->execute([
                'username' => $username,
                'password' => $hashed_password,
                'correo' => $correo_personal
            ]);
            $id_user = $pdo->lastInsertId();

            // 6. Lógica de Cupos y Carreras
            $stmt = $pdo->prepare("SELECT cupo_maximo, nombre_carrera FROM Carreras WHERE ID_carrera = :carrera");
            $stmt->execute(['carrera' => $carrera_primera]);
            $carrera1_info = $stmt->fetch();
            $cupo_max_1 = (int)$carrera1_info['cupo_maximo'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Estudiantes WHERE ID_carrera = :carrera");
            $stmt->execute(['carrera' => $carrera_primera]);
            $enrolled_1 = (int)$stmt->fetchColumn();

            $carrera_admitida = $carrera_primera;
            $carrera_nombre = $carrera1_info['nombre_carrera'];

            if ($enrolled_1 >= $cupo_max_1) {
                // Cupos llenos en primera opción. Asignar a la Segunda Opción
                $stmt = $pdo->prepare("SELECT nombre_carrera FROM Carreras WHERE ID_carrera = :carrera");
                $stmt->execute(['carrera' => $carrera_segunda]);
                $carrera2_info = $stmt->fetch();
                $carrera_admitida = $carrera_segunda;
                $carrera_nombre = $carrera2_info['nombre_carrera'];
            }

            // 7. Lógica de Asignación Automática de Grupo (Límite 70 estudiantes)
            $stmt = $pdo->query("SELECT id_grupo, nombre_grupo, cantidad_estudiantes FROM Grupos 
                                 WHERE cantidad_estudiantes < 70 AND estado = TRUE 
                                 ORDER BY id_grupo ASC LIMIT 1");
            $grupo_disponible = $stmt->fetch();

            if ($grupo_disponible) {
                $id_grupo = (int)$grupo_disponible['id_grupo'];
                $nombre_grupo = $grupo_disponible['nombre_grupo'];
            } else {
                // Si no hay grupos disponibles, crear uno nuevo
                $stmt = $pdo->query("SELECT COUNT(*) FROM Grupos");
                $total_grupos = (int)$stmt->fetchColumn();
                $letra_grupo = chr(65 + $total_grupos);
                $nombre_grupo = "Grupo " . $letra_grupo;

                $stmt = $pdo->prepare("INSERT INTO Grupos (nombre_grupo, capacidad_maxima, cantidad_estudiantes) VALUES (:nombre, 70, 0)");
                $stmt->execute(['nombre' => $nombre_grupo]);
                $id_grupo = $pdo->lastInsertId();
            }

            // 8. Matricular en la tabla Estudiantes
            $stmt = $pdo->prepare("INSERT INTO Estudiantes (ID_persona, ID_user, ID_carrera, ID_grupo) 
                                   VALUES (:id_persona, :id_user, :id_carrera, :id_grupo)");
            $stmt->execute([
                'id_persona' => $id_persona,
                'id_user' => $id_user,
                'id_carrera' => $carrera_admitida,
                'id_grupo' => $id_grupo
            ]);
            $id_estudiante = $pdo->lastInsertId();

            // 9. Registrar Notas en Cero para sus 4 materias básicas
            $materias = ['COMP', 'MATE', 'INGL', 'FISI'];
            $stmt = $pdo->prepare("INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3) 
                                   VALUES (:id_estudiante, :materia, :id_grupo, 0.00, 0.00, 0.00)");
            foreach ($materias as $materia) {
                $stmt->execute([
                    'id_estudiante' => $id_estudiante,
                    'materia' => $materia,
                    'id_grupo' => $id_grupo
                ]);
            }

            $pdo->commit();

            // Guardar datos del éxito para la vista final
            $success_data = [
                'nombre' => $nombre . ' ' . $apellido,
                'ci' => $ci,
                'carrera' => $carrera_nombre,
                'grupo' => $nombre_grupo,
                'username' => $username,
                'password' => $plain_password
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al registrar el postulante: " . $e->getMessage();
        }
    }
}

// Cargar Carreras para los select
$carreras = [];
try {
    $stmt = $pdo->query("SELECT id_carrera, nombre_carrera, cupo_maximo FROM Carreras WHERE estado = TRUE ORDER BY id_carrera");
    $carreras = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error de conexión a la BD: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripción CUP | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container" style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;">
        
        <div class="card step-container" style="width: 100%; padding: 40px;">
            
            <!-- HEADER DE REGISTRO -->
            <div class="logo-container" style="margin-bottom: 25px;">
                <h2 class="gradient-text" style="font-size: 1.8rem;">Registro de Admisión FICCT</h2>
            </div>

            <!-- MENSAJES DE ALERTA -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success_data): ?>
                <!-- PANTALLA DE ÉXITO DE REGISTRO -->
                <div style="text-align: center;">
                    <h3 style="font-size: 1.8rem; margin-bottom: 10px; color: var(--primary);">¡Registro Completado con Éxito!</h3>
                    <p style="color: var(--text-muted); margin-bottom: 30px;">
                        El pago se ha procesado exitosamente y has sido admitido automáticamente en el sistema del curso preuniversitario.
                    </p>

                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; margin-bottom: 35px; text-align: left; display: flex; flex-direction: column; gap: 12px;">
                        <h4 style="font-weight: 600; color: var(--primary); border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 5px;">Detalles de Admisión</h4>
                        <div><strong>Estudiante:</strong> <?= htmlspecialchars($success_data['nombre']) ?></div>
                        <div><strong>CI / Carnet:</strong> <?= htmlspecialchars($success_data['ci']) ?></div>
                        <div><strong>Carrera Admitida:</strong> <span class="badge badge-success"><?= htmlspecialchars($success_data['carrera']) ?></span></div>
                        <div><strong>Grupo Asignado:</strong> <span class="badge badge-info"><?= htmlspecialchars($success_data['grupo']) ?></span></div>
                        
                        <h4 style="font-weight: 600; color: var(--secondary); border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-top: 15px; margin-bottom: 5px;">Credenciales de Acceso</h4>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px;">Utiliza estos datos para iniciar sesión como Estudiante en el portal principal.</p>
                        <div><strong>Usuario:</strong> <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 6px; color: var(--primary); font-family: monospace; font-size: 1rem; border: 1px solid #e0e0e0;"><?= htmlspecialchars($success_data['username']) ?></code></div>
                        <div><strong>Contraseña:</strong> <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 6px; color: var(--secondary); font-family: monospace; font-size: 1rem; border: 1px solid #e0e0e0;"><?= htmlspecialchars($success_data['password']) ?></code> <span style="font-size: 0.8rem; color: var(--text-muted);">(Primera letra de tu apellido + tu CI)</span></div>
                    </div>

                    <a href="../index.php" class="btn btn-primary" style="width: 100%;">Finalizar e Ir al Inicio</a>
                </div>

            <?php else: ?>
                <!-- FORMULARIO PASO A PASO -->
                
                <div class="step-indicator">
                    <div class="step-line-active" style="width: 0%;"></div>
                    <div class="step-dot active">1</div>
                    <div class="step-dot">2</div>
                    <div class="step-dot">3</div>
                </div>

                <form id="register-multi-step" method="POST" action="register.php">
                    <input type="hidden" name="action" value="register">

                    <!-- PASO 1: CARNET DE IDENTIDAD (CI) -->
                    <div id="step-1" class="step-content active">
                        <h3 style="font-size: 1.3rem; margin-bottom: 10px; font-weight: 600; color: var(--primary);">Paso 1: Validación de Documento</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 25px;">
                            Ingresa tu número de Carnet de Identidad para iniciar el proceso de registro del curso preuniversitario.
                        </p>

                        <div class="form-group">
                            <label for="ci">Carnet de Identidad (CI) *</label>
                            <input type="text" id="ci" name="ci" class="form-control" placeholder="Ej: 8765432-SC" required>
                            <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 5px; display: block;">Ejemplo: 1234567, 1234567-SC, 8765432-LP</small>
                        </div>

                        <div style="display: flex; justify-content: flex-end; margin-top: 30px;">
                            <button type="button" id="btn-next-1" class="btn btn-primary">Siguiente</button>
                        </div>
                    </div>

                    <!-- PASO 2: DATOS PERSONALES Y ACADÉMICOS -->
                    <div id="step-2" class="step-content">
                        <h3 style="font-size: 1.3rem; margin-bottom: 10px; font-weight: 600; color: var(--primary);">Paso 2: Información Personal & Carreras</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 25px;">
                            Ingresa tu información de contacto, datos de tu colegio y las carreras de tu elección.
                        </p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="nombre">Nombres *</label>
                                <input type="text" id="nombre" name="nombre" class="form-control" placeholder="Ingresa tus nombres">
                            </div>
                            <div class="form-group">
                                <label for="apellido">Apellidos *</label>
                                <input type="text" id="apellido" name="apellido" class="form-control" placeholder="Ingresa tus apellidos">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="fecha_nacimiento">Fecha de Nacimiento *</label>
                                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="genero">Género / Sexo *</label>
                                <select id="genero" name="genero" class="form-control">
                                    <option value="">Seleccionar...</option>
                                    <option value="M">Masculino (M)</option>
                                    <option value="F">Femenino (F)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="telefono">Teléfono de Contacto *</label>
                                <input type="text" id="telefono" name="telefono" class="form-control" placeholder="Ej: 77012345">
                            </div>
                            <div class="form-group">
                                <label for="correo_personal">Correo Electrónico *</label>
                                <input type="email" id="correo_personal" name="correo_personal" class="form-control" placeholder="ejemplo@correo.com">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="direccion">Dirección Domiciliaria</label>
                            <input type="text" id="direccion" name="direccion" class="form-control" placeholder="Ej: Av. Bush, Calle Flores #45">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="colegio_procedencia">Colegio de Procedencia *</label>
                                <input type="text" id="colegio_procedencia" name="colegio_procedencia" class="form-control" placeholder="Ej: Colegio San Agustín">
                            </div>
                            <div class="form-group">
                                <label for="ciudad">Ciudad de Residencia *</label>
                                <input type="text" id="ciudad" name="ciudad" class="form-control" placeholder="Ej: Santa Cruz de la Sierra">
                            </div>
                        </div>

                        <div class="form-row" style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <div class="form-group">
                                <label for="carrera_primera">Primera Opción de Carrera *</label>
                                <select id="carrera_primera" name="carrera_primera" class="form-control">
                                    <option value="">Seleccionar carrera...</option>
                                    <?php foreach ($carreras as $c): ?>
                                        <option value="<?= $c['id_carrera'] ?>"><?= htmlspecialchars($c['nombre_carrera']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="carrera_segunda">Segunda Opción de Carrera *</label>
                                <select id="carrera_segunda" name="carrera_segunda" class="form-control">
                                    <option value="">Seleccionar carrera...</option>
                                    <?php foreach ($carreras as $c): ?>
                                        <option value="<?= $c['id_carrera'] ?>"><?= htmlspecialchars($c['nombre_carrera']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                            <button type="button" id="btn-prev-2" class="btn btn-secondary">Atrás</button>
                            <button type="button" id="btn-next-2" class="btn btn-primary">Siguiente</button>
                        </div>
                    </div>

                    <!-- PASO 3: PASARELA DE PAGO SIMULADA -->
                    <div id="step-3" class="step-content">
                        <h3 style="font-size: 1.3rem; margin-bottom: 10px; font-weight: 600; color: var(--primary);">Paso 3: Pasarela de Pago Seguro</h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 25px;">
                            El costo de inscripción al Curso Preuniversitario (CUP) es de **350.00 BOB**. Selecciona tu método de pago preferido.
                        </p>

                        <input type="hidden" name="metodo_pago" id="metodo_pago" value="qr">

                        <div class="payment-selector">
                            <div class="payment-option active" data-method="qr">
                                <h4 style="font-weight: 600; font-size: 0.95rem;">Pago con Código QR</h4>
                            </div>
                            <div class="payment-option" data-method="card">
                                <h4 style="font-weight: 600; font-size: 0.95rem;">Tarjeta de Crédito/Débito</h4>
                            </div>
                        </div>

                        <!-- PANEL QR -->
                        <div id="qr-payment-section">
                            <div class="qr-box" style="background: white; padding: 15px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 10px; border: 2px solid var(--primary); width: fit-content; margin: 20px auto;">
                                <img src="../assets/img/qr_pago.png" alt="Código QR de Pago" style="width: 200px; height: 200px; object-fit: contain; display: block;">
                                <p style="color: #333333; font-size: 0.9rem; font-weight: 600; text-align: center;">Escanear Código QR</p>
                            </div>
                            <p style="text-align: center; font-size: 0.85rem; color: var(--text-muted);">
                                Abre la aplicación móvil de tu banco y escanea el código QR de arriba para transferir **350.00 BOB**.
                            </p>
                        </div>

                        <!-- PANEL TARJETA -->
                        <div id="card-payment-section" style="display: none;">
                            <div class="card-form">
                                <div class="form-group">
                                    <label>Nombre impreso en la tarjeta</label>
                                    <input type="text" class="form-control" placeholder="Ej: JUAN PEREZ PEREZ" style="text-transform: uppercase;">
                                </div>
                                <div class="form-group">
                                    <label>Número de Tarjeta</label>
                                    <input type="text" class="form-control" placeholder="4557 •••• •••• 1234">
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Fecha de Exp.</label>
                                        <input type="text" class="form-control" placeholder="MM/AA">
                                    </div>
                                    <div class="form-group">
                                        <label>CVV / Cód. Seguridad</label>
                                        <input type="text" class="form-control" placeholder="123">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                            <button type="button" id="btn-prev-3" class="btn btn-secondary">Atrás</button>
                            <button type="submit" class="btn btn-success">Confirmar Registro y Pago</button>
                        </div>
                    </div>

                </form>
            <?php endif; ?>

        </div>

    </div>

    <script src="../assets/js/app.js"></script>
</body>
</html>
