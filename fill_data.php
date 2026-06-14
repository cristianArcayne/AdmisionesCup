<?php
header('Content-Type: text/plain; charset=utf-8');
require_once 'config/db.php';

echo "Starting data seeding...\n";

try {
    $pdo->beginTransaction();

    // 1. Asegurar la existencia de los 4 grupos: Grupo A, Grupo B, Grupo C, Grupo D
    $grupo_ids = [];
    $nombres_grupos = ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D'];
    
    foreach ($nombres_grupos as $nombre_g) {
        $stmt = $pdo->prepare("SELECT ID_grupo FROM Grupos WHERE nombre_grupo = :nombre");
        $stmt->execute(['nombre' => $nombre_g]);
        $id_grupo = $stmt->fetchColumn();
        
        if (!$id_grupo) {
            $stmt_ins = $pdo->prepare("INSERT INTO Grupos (nombre_grupo, capacidad_maxima, cantidad_estudiantes) VALUES (:nombre, 70, 0) RETURNING ID_grupo");
            $stmt_ins->execute(['nombre' => $nombre_g]);
            $id_grupo = $stmt_ins->fetchColumn();
            echo "Created group: $nombre_g\n";
        }
        $grupo_ids[$nombre_g] = (int)$id_grupo;
    }

    // 2. Obtener IDs de Carreras
    $carrera_ids = $pdo->query("SELECT ID_carrera FROM Carreras")->fetchAll(PDO::FETCH_COLUMN);
    if (count($carrera_ids) === 0) {
        throw new Exception("No hay carreras en la base de datos.");
    }

    // 3. Nombres y Apellidos aleatorios para generar postulantes
    $nombres_m = ['Juan', 'Pedro', 'Carlos', 'Luis', 'Miguel', 'Andrés', 'Jose', 'Fernando', 'Ricardo', 'Javier', 'Hugo', 'Daniel', 'Diego', 'Gabriel', 'Alejandro'];
    $nombres_f = ['Ana', 'María', 'Luisa', 'Patricia', 'Gabriela', 'Andrea', 'Sofía', 'Camila', 'Laura', 'Natalia', 'Carmen', 'Elena', 'Beatriz', 'Raquel', 'Julia'];
    $apellidos = ['Gomez', 'Flores', 'Quispe', 'Mamani', 'Vargas', 'Rojas', 'Silva', 'Guzman', 'Gutierrez', 'Mendoza', 'Alvarez', 'Suarez', 'Fernandez', 'Perez', 'Ortiz', 'Torres', 'Chavez', 'Rios', 'Castro', 'Pinto'];

    // 4. Generar 20 postulantes/estudiantes para cada uno de los 4 grupos (80 en total)
    $estudiante_rol_id = 3; // Estudiante
    $total_creados = 0;

    foreach ($nombres_grupos as $nombre_g) {
        $id_grupo = $grupo_ids[$nombre_g];
        echo "Seeding 20 students for $nombre_g...\n";
        
        for ($i = 0; $i < 20; $i++) {
            $genero = (rand(0, 1) === 0) ? 'M' : 'F';
            $nombre = ($genero === 'M') ? $nombres_m[array_rand($nombres_m)] : $nombres_f[array_rand($nombres_f)];
            $apellido = $apellidos[array_rand($apellidos)] . ' ' . $apellidos[array_rand($apellidos)];
            $ci = (string)rand(6000000, 8999999) . "-SC";
            $correo = strtolower(str_replace(' ', '', $nombre)) . rand(10, 99) . "@gmail.com";
            $colegio = "Colegio Nacional " . $apellidos[array_rand($apellidos)];
            
            // Carreras opciones
            $c1 = $carrera_ids[array_rand($carrera_ids)];
            $c2 = $carrera_ids[array_rand($carrera_ids)];
            while ($c1 === $c2) {
                $c2 = $carrera_ids[array_rand($carrera_ids)];
            }

            // A. Insertar Persona
            $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, fecha_nacimiento, genero, direccion, telefono, correo_personal) 
                                   VALUES (:nombre, :apellido, :ci, :fecha, :genero, :dir, :tel, :correo)");
            $stmt->execute([
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $ci,
                'fecha' => date('Y-m-d', strtotime('-' . rand(17, 22) . ' years')),
                'genero' => $genero,
                'dir' => 'Av. Santa Cruz #' . rand(10, 999),
                'tel' => (string)rand(60000000, 79999999),
                'correo' => $correo
            ]);
            $id_persona = $pdo->lastInsertId();

            // B. Insertar Postulante en estado 'Aprobado'
            $stmt = $pdo->prepare("INSERT INTO Postulantes (ID_persona, Colegio_procedencia, Ciudad, Titulo_bachiller, ID_carrera_primera, ID_carrera_segunda, Estado_postulacion) 
                                   VALUES (:id_persona, :colegio, 'Santa Cruz', TRUE, :c1, :c2, 'Aprobado')");
            $stmt->execute([
                'id_persona' => $id_persona,
                'colegio' => $colegio,
                'c1' => $c1,
                'c2' => $c2
            ]);
            $id_postulante = $pdo->lastInsertId();

            // C. Insertar Pago Completado
            $stmt = $pdo->prepare("INSERT INTO Pagos (ID_postulante, monto, comprobante, estado_pago) 
                                   VALUES (:id_postulante, 350.00, :comprobante, 'Completado')");
            $stmt->execute([
                'id_postulante' => $id_postulante,
                'comprobante' => 'TRX-' . rand(100000, 999999)
            ]);

            // D. Crear Usuario Estudiante (Pass = CI)
            $pass_hash = password_hash($ci, PASSWORD_DEFAULT);
            $username = strtolower(str_replace(' ', '', $nombre)) . rand(10, 99);
            
            $stmt = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES (:user, :pass, :correo, :rol)");
            $stmt->execute([
                'user' => $username,
                'pass' => $pass_hash,
                'correo' => $correo,
                'rol' => $estudiante_rol_id
            ]);
            $id_user = $pdo->lastInsertId();

            // E. Crear Estudiante matriculado en el grupo
            $stmt = $pdo->prepare("INSERT INTO Estudiantes (ID_persona, ID_user, ID_carrera, ID_grupo) VALUES (:id_persona, :id_user, :carrera, :grupo)");
            $stmt->execute([
                'id_persona' => $id_persona,
                'id_user' => $id_user,
                'carrera' => $c1,
                'grupo' => $id_grupo
            ]);
            $id_estudiante = $pdo->lastInsertId();

            // F. Registrar notas iniciales (para que tengan promedio general y aparezcan en admisiones/reportes)
            $materias = ['COMP', 'MATE', 'INGL', 'FISI'];
            foreach ($materias as $mat) {
                $n1 = rand(45, 95);
                $n2 = rand(45, 95);
                $n3 = rand(45, 95);
                
                $stmt = $pdo->prepare("INSERT INTO Notas (ID_estudiante, ID_materia, ID_grupo, nota1, nota2, nota3) 
                                       VALUES (:id_est, :mat, :grupo, :n1, :n2, :n3)");
                $stmt->execute([
                    'id_est' => $id_estudiante,
                    'mat' => $mat,
                    'grupo' => $id_grupo,
                    'n1' => $n1,
                    'n2' => $n2,
                    'n3' => $n3
                ]);
            }

            $total_creados++;
        }
        
        // Actualizar contador del grupo
        $stmt_upd_g = $pdo->prepare("UPDATE Grupos SET cantidad_estudiantes = cantidad_estudiantes + 20 WHERE ID_grupo = :id_grupo");
        $stmt_upd_g->execute(['id_grupo' => $id_grupo]);
    }

    // 5. Crear 4 nuevos Docentes capacitados
    echo "Creating 4 qualified teachers...\n";
    $docentes_datos = [
        ['Roberto', 'Flores', '9000001-SC', 'roberto.flores@univ.edu', 'Lic. Ciencias Matemáticas'],
        ['María', 'Gutierrez', '9000002-SC', 'maria.gutierrez@univ.edu', 'Lic. Informática'],
        ['Patricia', 'Rojas', '9000003-SC', 'patricia.rojas@univ.edu', 'Lic. Filología Inglesa'],
        ['Jorge', 'Silva', '9000004-SC', 'jorge.silva@univ.edu', 'Lic. Ciencias Físicas']
    ];

    foreach ($docentes_datos as $d) {
        // Verificar si la persona ya existe
        $stmt = $pdo->prepare("SELECT ID_persona FROM Personas WHERE CI = :ci");
        $stmt->execute(['ci' => $d[2]]);
        $id_persona = $stmt->fetchColumn();

        if (!$id_persona) {
            // A. Persona
            $stmt = $pdo->prepare("INSERT INTO Personas (nombre, apellido, CI, correo_personal) VALUES (:nombre, :apellido, :ci, :correo)");
            $stmt->execute([
                'nombre' => $d[0],
                'apellido' => $d[1],
                'ci' => $d[2],
                'correo' => $d[3]
            ]);
            $id_persona = $pdo->lastInsertId();

            // B. Usuario (Rol = 2 (docente))
            $pass_hash = password_hash($d[2], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO Usuarios (Username, Password, Correo, ID_rol) VALUES (:user, :pass, :correo, 2)");
            $stmt->execute([
                'user' => strtolower($d[0] . '_' . substr($d[1], 0, 1)),
                'pass' => $pass_hash,
                'correo' => $d[3]
            ]);
            $id_user = $pdo->lastInsertId();

            // C. Docente
            $stmt = $pdo->prepare("INSERT INTO Docentes (ID_persona, ID_user, Especialidad, tiene_maestria, tiene_diplomado, max_grupos) 
                                   VALUES (:id_persona, :id_user, :esp, TRUE, TRUE, 4)");
            $stmt->execute([
                'id_persona' => $id_persona,
                'id_user' => $id_user,
                'esp' => $d[4]
            ]);
            echo "Created teacher: {$d[0]} {$d[1]}\n";
        }
    }

    $pdo->commit();
    echo "\nSeeding finished successfully! Total students created: $total_creados.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error seeding data: " . $e->getMessage() . "\n";
}
?>
