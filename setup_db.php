<?php
/**
 * Script de Configuración e Inicialización de Base de Datos
 * Sistema de Admisión Universitaria (CUP) - FICCT
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador de Base de Datos | FICCT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(255, 255, 255, 0.03);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-color: #f3f4f6;
            --primary: #00f2fe;
            --secondary: #7f00ff;
            --success: #10b981;
            --error: #ef4444;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-image: radial-gradient(circle at 10% 20%, rgba(0, 242, 254, 0.05) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(127, 0, 255, 0.05) 0%, transparent 40%);
        }
        .container {
            width: 100%;
            max-width: 800px;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }
        h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-top: 0;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-align: center;
        }
        .status-box {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 350px;
            overflow-y: auto;
            margin-bottom: 25px;
            color: #a7f3d0;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .status-box .error {
            color: var(--error);
            font-weight: bold;
        }
        .status-box .success {
            color: var(--success);
            font-weight: bold;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(0, 242, 254, 0.2);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 242, 254, 0.4);
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Instalación de la Base de Datos</h1>
    
    <div class="status-box">
<?php
// Cargar configuraciones centralizadas
require_once 'config/config.php';

echo "🔌 Conectando al servidor PostgreSQL (host: " . DB_HOST . ", puerto: " . DB_PORT . ")...\n";
flush();

$database_created = false;
$pdo = null;

// Intentar conectar directamente a la base de datos destino
try {
    $dsn_app = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn_app, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "📁 Conexión establecida con la base de datos '" . DB_NAME . "'. Procediendo a recrear las tablas...\n";
    $database_created = true;
} catch (PDOException $e) {
    // Si no conecta, podría ser porque la base de datos no existe (común en local)
    echo "⚠️ No se pudo conectar directamente a '" . DB_NAME . "'. Error: " . $e->getMessage() . "\n";
}

if (!$database_created) {
    try {
        // Conectar a la base de datos 'postgres' del sistema para crear la base de datos
        $dsn_base = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=postgres";
        $pdo_base = new PDO($dsn_base, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Verificar si la base de datos existe
        $stmt = $pdo_base->query("SELECT 1 FROM pg_database WHERE datname = '" . DB_NAME . "'");
        $exists = $stmt->fetchColumn();

        if (!$exists) {
            echo "📁 Creando la base de datos '" . DB_NAME . "'...\n";
            $pdo_base->exec("CREATE DATABASE " . DB_NAME);
            echo "<span class='success'>✓ Base de datos '" . DB_NAME . "' creada exitosamente.</span>\n";
        }

        // Conectarse ahora sí a la nueva base de datos
        $dsn_app = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn_app, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        echo "<span class='error'>❌ Error conectando a PostgreSQL: " . $e->getMessage() . "</span>\n";
        echo "Por favor verifica que PostgreSQL esté ejecutándose y que tu usuario/contraseña en config/config.php sean los correctos.\n";
        echo "</div><button onclick='window.location.reload()' class='btn'>Reintentar Conexión</button></div></body></html>";
        exit;
    }
}
try {
    echo "⚙️ Ejecutando sentencias DDL para estructura de tablas...\n";
    flush();

    // Estructura SQL
    $sql = "
    -- 0. LIMPIEZA: Eliminar tablas si existen (Orden inverso por FK)
    DROP VIEW IF EXISTS vista_total_inscritos_grupo CASCADE;
    DROP VIEW IF EXISTS vista_postulantes_aprobados CASCADE;
    DROP TABLE IF EXISTS Notas CASCADE;
    DROP TABLE IF EXISTS Asignaciones CASCADE;
    DROP TABLE IF EXISTS Horarios CASCADE;
    DROP TABLE IF EXISTS Pagos CASCADE;
    DROP TABLE IF EXISTS Postulantes CASCADE;
    DROP TABLE IF EXISTS Estudiantes CASCADE;
    DROP TABLE IF EXISTS Docentes CASCADE;
    DROP TABLE IF EXISTS Administrativos CASCADE;
    DROP TABLE IF EXISTS Grupos CASCADE;
    DROP TABLE IF EXISTS Aulas CASCADE;
    DROP TABLE IF EXISTS Materias CASCADE;
    DROP TABLE IF EXISTS Carreras CASCADE;
    DROP TABLE IF EXISTS Personas CASCADE;
    DROP TABLE IF EXISTS Usuarios CASCADE;
    DROP TABLE IF EXISTS Roles CASCADE;

    -- 1. TABLAS MAESTRAS E INDEPENDIENTES
    CREATE TABLE Roles (
        ID_rol SERIAL PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL UNIQUE,
        descripcion TEXT,
        estado BOOLEAN DEFAULT TRUE
    );

    CREATE TABLE Personas (
        ID_persona SERIAL PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL,
        apellido VARCHAR(50) NOT NULL,
        CI VARCHAR(20) NOT NULL UNIQUE,
        fecha_nacimiento DATE,
        genero CHAR(1) CHECK (genero IN ('M', 'F')),
        direccion VARCHAR(200),
        telefono VARCHAR(20),
        correo_personal VARCHAR(100),
        estado BOOLEAN DEFAULT TRUE
    );

    CREATE TABLE Carreras (
        ID_carrera SERIAL PRIMARY KEY,
        nombre_carrera VARCHAR(100) NOT NULL,
        descripcion TEXT,
        cupo_maximo INT DEFAULT 50,
        estado BOOLEAN DEFAULT TRUE
    );

    CREATE TABLE Materias (
        ID_materia VARCHAR(10) PRIMARY KEY,
        nombre_materia VARCHAR(100) NOT NULL,
        descripcion TEXT,
        CHECK (nombre_materia IN ('Computación', 'Matemáticas', 'Inglés', 'Física'))
    );

    CREATE TABLE Aulas (
        ID_aula SERIAL PRIMARY KEY,
        nombre_aula VARCHAR(50) NOT NULL,
        capacidad INT NOT NULL,
        ubicacion VARCHAR(100)
    );

    CREATE TABLE Grupos (
        ID_grupo SERIAL PRIMARY KEY,
        nombre_grupo VARCHAR(20) NOT NULL,
        capacidad_maxima INT DEFAULT 70,
        cantidad_estudiantes INT DEFAULT 0,
        estado BOOLEAN DEFAULT TRUE,
        CHECK (cantidad_estudiantes <= capacidad_maxima)
    );

    -- 2. TABLAS CON DEPENDENCIAS DIRECTAS (NIVEL 1)
    CREATE TABLE Usuarios (
        ID_user SERIAL PRIMARY KEY,
        Username VARCHAR(50) NOT NULL UNIQUE,
        Password VARCHAR(255) NOT NULL,
        Correo VARCHAR(100) NOT NULL UNIQUE,
        ID_rol INT NOT NULL,
        Estado BOOLEAN DEFAULT TRUE,
        Fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ID_rol) REFERENCES Roles(ID_rol)
    );

    CREATE TABLE Horarios (
        ID_horario SERIAL PRIMARY KEY,
        ID_grupo INT NOT NULL,
        ID_aula INT NOT NULL,
        dia_semana VARCHAR(15) CHECK (dia_semana IN ('Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado')),
        hora_inicio TIME NOT NULL,
        hora_fin TIME NOT NULL,
        FOREIGN KEY (ID_grupo) REFERENCES Grupos(ID_grupo),
        FOREIGN KEY (ID_aula) REFERENCES Aulas(ID_aula)
    );

    -- 3. TABLAS DE ENTIDADES ESPECÍFICAS (NIVEL 2)
    CREATE TABLE Administrativos (
        ID_adm SERIAL PRIMARY KEY,
        ID_user INT NOT NULL,
        Nombre VARCHAR(50) NOT NULL,
        Apellido VARCHAR(50) NOT NULL,
        Ci VARCHAR(20) NOT NULL UNIQUE,
        Cargo VARCHAR(100),
        Fecha_ingreso DATE DEFAULT CURRENT_DATE,
        Estado BOOLEAN DEFAULT TRUE,
        FOREIGN KEY (ID_user) REFERENCES Usuarios(ID_user)
    );

    CREATE TABLE Docentes (
        ID_docente SERIAL PRIMARY KEY,
        ID_persona INT NOT NULL,
        ID_user INT NOT NULL,
        Especialidad VARCHAR(100),
        tiene_maestria BOOLEAN DEFAULT FALSE,
        tiene_diplomado BOOLEAN DEFAULT FALSE,
        Fecha_contratacion DATE DEFAULT CURRENT_DATE,
        max_grupos INT DEFAULT 4 CHECK (max_grupos BETWEEN 1 AND 4),
        Estado BOOLEAN DEFAULT TRUE,
        FOREIGN KEY (ID_persona) REFERENCES Personas(ID_persona),
        FOREIGN KEY (ID_user) REFERENCES Usuarios(ID_user)
    );

    CREATE TABLE Postulantes (
        ID_postulante SERIAL PRIMARY KEY,
        ID_persona INT NOT NULL,
        Colegio_procedencia VARCHAR(100),
        Ciudad VARCHAR(50),
        Titulo_bachiller BOOLEAN DEFAULT FALSE,
        Fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ID_carrera_primera INT NOT NULL,
        ID_carrera_segunda INT NOT NULL,
        Estado_postulacion VARCHAR(20) DEFAULT 'Registrado' CHECK (Estado_postulacion IN ('Registrado', 'Pagado', 'Aprobado', 'Rechazado')),
        FOREIGN KEY (ID_persona) REFERENCES Personas(ID_persona),
        FOREIGN KEY (ID_carrera_primera) REFERENCES Carreras(ID_carrera),
        FOREIGN KEY (ID_carrera_segunda) REFERENCES Carreras(ID_carrera)
    );

    CREATE TABLE Estudiantes (
        ID_estudiante SERIAL PRIMARY KEY,
        ID_persona INT NOT NULL,
        ID_user INT NOT NULL,
        ID_carrera INT NOT NULL,
        ID_grupo INT,
        Fecha_ingreso DATE DEFAULT CURRENT_DATE,
        Estado BOOLEAN DEFAULT TRUE,
        FOREIGN KEY (ID_persona) REFERENCES Personas(ID_persona),
        FOREIGN KEY (ID_user) REFERENCES Usuarios(ID_user),
        FOREIGN KEY (ID_carrera) REFERENCES Carreras(ID_carrera),
        FOREIGN KEY (ID_grupo) REFERENCES Grupos(ID_grupo)
    );

    -- 4. TABLAS TRANSACCIONALES Y FINALES (NIVEL 3)
    CREATE TABLE Pagos (
        ID_pago SERIAL PRIMARY KEY,
        ID_postulante INT NOT NULL,
        monto DECIMAL(10,2) DEFAULT 350.00,
        fecha_pago DATE DEFAULT CURRENT_DATE,
        comprobante VARCHAR(255),
        estado_pago VARCHAR(20) DEFAULT 'Pendiente' CHECK (estado_pago IN ('Pendiente', 'Completado', 'Fallido')),
        FOREIGN KEY (ID_postulante) REFERENCES Postulantes(ID_postulante)
    );

    CREATE TABLE Asignaciones (
        ID_asignacion SERIAL PRIMARY KEY,
        ID_docente INT NOT NULL,
        ID_grupo INT NOT NULL,
        ID_materia VARCHAR(10) NOT NULL,
        gestion_academica VARCHAR(9) DEFAULT TO_CHAR(CURRENT_DATE, 'YYYY'),
        FOREIGN KEY (ID_docente) REFERENCES Docentes(ID_docente),
        FOREIGN KEY (ID_grupo) REFERENCES Grupos(ID_grupo),
        FOREIGN KEY (ID_materia) REFERENCES Materias(ID_materia),
        UNIQUE(ID_docente, ID_grupo, ID_materia, gestion_academica)
    );

    CREATE TABLE Notas (
        ID_nota SERIAL PRIMARY KEY,
        ID_estudiante INT NOT NULL,
        ID_materia VARCHAR(10) NOT NULL,
        ID_grupo INT NOT NULL,
        nota1 DECIMAL(5,2) CHECK (nota1 BETWEEN 0 AND 100),
        nota2 DECIMAL(5,2) CHECK (nota2 BETWEEN 0 AND 100),
        nota3 DECIMAL(5,2) CHECK (nota3 BETWEEN 0 AND 100),
        promedio DECIMAL(5,2) GENERATED ALWAYS AS ((nota1 + nota2 + nota3) / 3) STORED,
        estado VARCHAR(10) GENERATED ALWAYS AS (CASE WHEN (nota1 + nota2 + nota3) / 3 >= 60 THEN 'APROBADO' ELSE 'REPROBADO' END) STORED,
        Fecha_registro DATE DEFAULT CURRENT_DATE,
        FOREIGN KEY (ID_estudiante) REFERENCES Estudiantes(ID_estudiante),
        FOREIGN KEY (ID_materia) REFERENCES Materias(ID_materia),
        FOREIGN KEY (ID_grupo) REFERENCES Grupos(ID_grupo),
        UNIQUE(ID_estudiante, ID_materia, ID_grupo)
    );

    -- 5. ÍNDICES, COMPONENTES PROGRAMABLES Y VISTAS
    CREATE INDEX idx_usuarios_rol ON Usuarios(ID_rol);
    CREATE INDEX idx_usuarios_estado ON Usuarios(Estado);
    CREATE INDEX idx_postulantes_carrera1 ON Postulantes(ID_carrera_primera);
    CREATE INDEX idx_postulantes_carrera2 ON Postulantes(ID_carrera_segunda);
    CREATE INDEX idx_notas_estudiante ON Notas(ID_estudiante);
    CREATE INDEX idx_notas_materia ON Notas(ID_materia);
    CREATE INDEX idx_estudiantes_grupo ON Estudiantes(ID_grupo);
    CREATE INDEX idx_asignaciones_docente ON Asignaciones(ID_docente);
    CREATE INDEX idx_asignaciones_grupo ON Asignaciones(ID_grupo);
    CREATE INDEX idx_horarios_grupo ON Horarios(ID_grupo);

    CREATE OR REPLACE FUNCTION actualizar_cantidad_estudiantes()
    RETURNS TRIGGER AS $$
    BEGIN
        IF TG_OP = 'INSERT' THEN
            UPDATE Grupos SET cantidad_estudiantes = cantidad_estudiantes + 1 WHERE ID_grupo = NEW.ID_grupo;
        ELSIF TG_OP = 'DELETE' THEN
            UPDATE Grupos SET cantidad_estudiantes = cantidad_estudiantes - 1 WHERE ID_grupo = OLD.ID_grupo;
        END IF;
        RETURN NULL;
    END;
    $$ LANGUAGE plpgsql;

    CREATE OR REPLACE TRIGGER trigger_actualizar_grupo
    AFTER INSERT OR DELETE ON Estudiantes
    FOR EACH ROW EXECUTE FUNCTION actualizar_cantidad_estudiantes();

    CREATE VIEW vista_total_inscritos_grupo AS
    SELECT g.ID_grupo, g.nombre_grupo, g.capacidad_maxima, COUNT(e.ID_estudiante) AS total_inscritos,
           CEIL(COUNT(e.ID_estudiante)::DECIMAL / 70) AS grupos_necesarios
    FROM Grupos g
    LEFT JOIN Estudiantes e ON g.ID_grupo = e.ID_grupo
    GROUP BY g.ID_grupo, g.nombre_grupo, g.capacidad_maxima;

    CREATE VIEW vista_postulantes_aprobados AS
    SELECT p.ID_postulante, pe.nombre, pe.apellido, pe.CI, AVG(n.promedio) AS promedio_general, 'APROBADO' AS estado
    FROM Postulantes p
    JOIN Personas pe ON p.ID_persona = pe.ID_persona
    JOIN Estudiantes e ON pe.ID_persona = e.ID_persona
    JOIN Notas n ON e.ID_estudiante = n.ID_estudiante
    GROUP BY p.ID_postulante, pe.nombre, pe.apellido, pe.CI
    HAVING AVG(n.promedio) >= 60;
    ";

    $pdo->exec($sql);
    echo "<span class='success'>✓ Tablas, Triggers, Índices y Vistas creados exitosamente.</span>\n";
    flush();

    // 3. Insertar datos maestros y sembrar usuarios
    echo "🌱 Sembrando datos maestros y de prueba...\n";
    flush();

    // Insertar Roles
    $pdo->exec("INSERT INTO Roles (nombre, descripcion) VALUES
    ('admin', 'Administrador del sistema'),
    ('docente', 'Profesor del curso preuniversitario'),
    ('estudiante', 'Alumno del curso'),
    ('postulante', 'Aspirante registrado')");

    // Insertar Materias
    $pdo->exec("INSERT INTO Materias (ID_materia, nombre_materia) VALUES
    ('COMP', 'Computación'),
    ('MATE', 'Matemáticas'),
    ('INGL', 'Inglés'),
    ('FISI', 'Física')");

    // Insertar Carreras
    $pdo->exec("INSERT INTO Carreras (nombre_carrera, cupo_maximo) VALUES
    ('Ing. Informática', 40),
    ('Ing. Sistemas', 40),
    ('Ing. Redes y Telecomunicaciones', 35),
    ('Ing. Robótica', 35)");

    // Insertar Aulas
    $pdo->exec("INSERT INTO Aulas (nombre_aula, capacidad, ubicacion) VALUES
    ('Aula 101', 70, 'Piso 1'),
    ('Aula 102', 70, 'Piso 1'),
    ('Laboratorio 1', 35, 'Piso 3')");

    // Insertar Grupos
    $pdo->exec("INSERT INTO Grupos (nombre_grupo, capacidad_maxima) VALUES
    ('Grupo A', 70),
    ('Grupo B', 70)");

    // 4. Crear personas y usuarios con contraseñas seguras PHP (password_hash)
    $pass_admin = password_hash('admin123', PASSWORD_DEFAULT);
    $pass_docente = password_hash('docente123', PASSWORD_DEFAULT);
    $pass_estudiante = password_hash('estudiante123', PASSWORD_DEFAULT);

    // Persona 1: Admin
    $pdo->exec("INSERT INTO Personas (nombre, apellido, CI, genero, direccion, telefono, correo_personal) VALUES
    ('Admin', 'FICCT', '0000001-SC', 'M', 'Campus FICCT UAGRM', '3345678', 'admin@univ.edu')");
    $id_persona_admin = $pdo->lastInsertId();

    // Persona 2: Docente (Carlos)
    $pdo->exec("INSERT INTO Personas (nombre, apellido, CI, genero, direccion, telefono, correo_personal) VALUES
    ('Carlos', 'Mendoza', '1234567-SC', 'M', 'Av. Bush 2do Anillo', '77012345', 'carlos@univ.edu')");
    $id_persona_docente = $pdo->lastInsertId();

    // Persona 3: Estudiante (Ana)
    $pdo->exec("INSERT INTO Personas (nombre, apellido, CI, genero, direccion, telefono, correo_personal) VALUES
    ('Ana', 'Gomez', '8765432-LP', 'F', 'Calle Flores #45', '65098765', 'ana@gmail.com')");
    $id_persona_estudiante = $pdo->lastInsertId();

    // Crear Cuentas de Usuario en tabla Usuarios
    $pdo->exec("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES
    ('admin', '$pass_admin', 'admin@univ.edu', 1)");
    $id_user_admin = $pdo->lastInsertId();

    $pdo->exec("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES
    ('carlos_m', '$pass_docente', 'carlos@univ.edu', 2)");
    $id_user_docente = $pdo->lastInsertId();

    $pdo->exec("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES
    ('ana_g', '$pass_estudiante', 'ana@gmail.com', 3)");
    $id_user_estudiante = $pdo->lastInsertId();

    // Insertar Administrativo
    $pdo->exec("INSERT INTO Administrativos (ID_user, Nombre, Apellido, Ci, Cargo) VALUES
    ($id_user_admin, 'Admin', 'FICCT', '0000001-SC', 'Coordinador General')");

    // Insertar Docente
    $pdo->exec("INSERT INTO Docentes (ID_persona, ID_user, Especialidad, tiene_maestria, tiene_diplomado, max_grupos) VALUES
    ($id_persona_docente, $id_user_docente, 'Ciencias de la Computación', TRUE, TRUE, 4)");
    $id_docente_carlos = $pdo->lastInsertId();

    // Insertar Postulante
    $pdo->exec("INSERT INTO Postulantes (ID_persona, Colegio_procedencia, Ciudad, Titulo_bachiller, ID_carrera_primera, ID_carrera_segunda, Estado_postulacion) VALUES
    ($id_persona_estudiante, 'Colegio Nacional', 'Santa Cruz', TRUE, 1, 2, 'Pagado')");
    $id_postulante_ana = $pdo->lastInsertId();

    // Registrar Pago de Ana
    $pdo->exec("INSERT INTO Pagos (ID_postulante, monto, estado_pago) VALUES
    ($id_postulante_ana, 350.00, 'Completado')");

    // Matricular en Estudiantes
    $pdo->exec("INSERT INTO Estudiantes (ID_persona, ID_user, ID_carrera, ID_grupo) VALUES
    ($id_persona_estudiante, $id_user_estudiante, 1, 1)");
    $id_estudiante_ana = $pdo->lastInsertId();

    // Insertar Horarios
    $pdo->exec("INSERT INTO Horarios (ID_grupo, ID_aula, dia_semana, hora_inicio, hora_fin) VALUES
    (1, 1, 'Lunes', '08:00:00', '10:00:00'),
    (1, 1, 'Miércoles', '08:00:00', '10:00:00')");

    // Asignar docente Carlos
    $pdo->exec("INSERT INTO Asignaciones (ID_docente, ID_grupo, ID_materia) VALUES
    ($id_docente_carlos, 1, 'COMP')");

    // Registrar Notas Iniciales para Ana
    $pdo->exec("INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3) VALUES
    ($id_estudiante_ana, 'COMP', 1, 75.00, 80.00, 85.00)");

    $pdo->exec("INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3) VALUES
    ($id_estudiante_ana, 'MATE', 1, 65.00, 70.00, 60.00),
    ($id_estudiante_ana, 'INGL', 1, 80.00, 85.00, 90.00),
    ($id_estudiante_ana, 'FISI', 1, 55.00, 60.00, 58.00)");

    echo "<span class='success'>✓ Sembrado de datos finalizado exitosamente.</span>\n";
    echo "\n🏆 Base de datos lista para pruebas!\n";
    echo "--------------------------------------------------------\n";
    echo "🔐 Cuentas de Acceso creadas:\n";
    echo "1. Administrador:\n";
    echo "   - Usuario: admin\n";
    echo "   - Contraseña: admin123\n";
    echo "2. Docente (Carlos Mendoza):\n";
    echo "   - Usuario: carlos_m\n";
    echo "   - Contraseña: docente123\n";
    echo "3. Estudiante (Ana Gomez):\n";
    echo "   - Usuario: ana_g\n";
    echo "   - Contraseña: estudiante123\n";
    echo "--------------------------------------------------------\n";

} catch (PDOException $e) {
    echo "<span class='error'>❌ Error en la ejecución SQL: " . $e->getMessage() . "</span>\n";
}
?>
    </div>
    
    <a href="index.php" class="btn">Ir a la Pantalla de Inicio</a>
</div>
</body>
</html>
