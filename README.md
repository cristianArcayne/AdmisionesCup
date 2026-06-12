# Sistema de Admisión Universitaria (CUP) - FICCT UAGRM

Este proyecto es una plataforma web desarrollada para la gestión, control y registro del proceso de nivelación académica preuniversitaria (CUP) de la **Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones (UAGRM)**.

El sistema está desarrollado con **PHP** (nativo), **PostgreSQL** para la persistencia de datos (con triggers, vistas y columnas matemáticas auto-calculadas) y un diseño visual ultra-simplificado, limpio, ligero y adaptado para dispositivos móviles, 100% libre de animaciones y emojis.

---

## Estructura de Arquitectura por Paquetes y Casos de Uso

A continuación se detalla cómo se organizan los archivos del código fuente y los procedimientos de acuerdo a los tres paquetes de diseño requeridos:

### 🔐 1. Paquete: Seguridad y Accesos (`P_Seguridad`)

Este paquete gestiona el control de accesos, cifrado de credenciales y la autenticación basada en perfiles (Administrador, Docente y Estudiante).

* **CU1: Iniciar Sesión Seguro**
  * **Ubicación:** [login.php](login.php) / [config/db.php](config/db.php)
  * **Operación:** Recibe las credenciales de la interfaz gráfica y valida campos vacíos. Consulta a PostgreSQL con coincidencia case-insensitive (`LOWER(Username) = LOWER(:username)`). Verifica el hash cifrado de la contraseña almacenada con `password_verify()`. Al ser exitoso, genera la sesión global y redirige de acuerdo al rol del usuario.
* **CU2: Cerrar Sesión**
  * **Ubicación:** [logout.php](logout.php)
  * **Operación:** Destruye el árbol de sesiones activas en el servidor web (`session_destroy()`), invalida las cookies de sesión y redirecciona de forma inmediata a la pantalla de Login.
* **CU3: Controlar Privilegios de Acceso (Middleware)**
  * **Ubicación:** Encabezado de todos los archivos PHP (`admin_dashboard.php`, `admin_estudiantes.php`, `admin_docentes.php`, `admin_asignaciones.php`, `docente_dashboard.php`, `estudiante_dashboard.php`).
  * **Operación:** Intercepta la petición HTTP y verifica las variables `$_SESSION['user_id']` y `$_SESSION['user_role']`. Si el usuario no tiene la sesión activa o su rol no cuenta con la autorización requerida para esa ruta, aborta la carga y redirige con un mensaje de error.

---

### 📝 2. Paquete: Gestión de Postulantes (`P_Postulantes`)

Paquete enfocado en la inscripción, control de duplicados de carnet de identidad, validación de correos y la administración del historial del estudiante.

* **CU5: Registrar Postulante**
  * **Ubicación:** [register.php](register.php) / [assets/js/app.js](assets/js/app.js)
  * **Operación:** Formulario de inscripción multipaso. Incluye una validación en tiempo real mediante una petición AJAX para verificar que el CI no esté duplicado en la base de datos antes de avanzar. Valida mediante expresión regular el formato del correo. Almacena de manera transaccional los registros en las tablas `Personas` y `Postulantes` de PostgreSQL.
* **CU6: Gestionar Pago**
  * **Ubicación:** [register.php](register.php) (Paso 3)
  * **Operación:** Presenta al postulante una pasarela de pago para transferir la matrícula de **350 BOB**. Integra el código QR de pago real (`assets/img/qr_pago.png`) y simulación por tarjeta. Al completarse, inserta un registro en la tabla `Pagos` y actualiza automáticamente el estado a 'Pagado' para habilitar al estudiante en el sistema.
* **CU7: Modificar Datos del Postulante**
  * **Ubicación:** [admin_estudiantes.php](admin_estudiantes.php)
  * **Operación:** Permite corregir en caliente errores de digitación en la información personal del estudiante (Nombre, Apellido, CI, Dirección, Teléfono, Correo). Ejecuta una instrucción SQL `UPDATE` sobre las tablas `Personas` y `Postulantes`.
* **CU8: Eliminar Registro de Postulante**
  * **Ubicación:** [admin_estudiantes.php](admin_estudiantes.php)
  * **Operación:** Permite al administrador dar de baja a un alumno del sistema. Ejecuta una transacción coordinada que borra en cascada registros relacionados para proteger la integridad referencial (Notas -> Pagos -> Postulante -> Estudiante -> Usuario -> Persona).
* **CU9: Buscar y Listar Postulantes**
  * **Ubicación:** [admin_estudiantes.php](admin_estudiantes.php)
  * **Operación:** Implementa un buscador veloz en la parte superior del listado general indexando coincidencias por carnet (CI), nombre o carrera sobre el total de admitidos.

---

### 📊 3. Paquete: Control Académico y Logística (`P_Academico`)

Este paquete abarca el control y registro de notas, cálculo matemático en base de datos, lógica de reasignación por cupos saturados, divisiones logísticas de grupos y reportes.

* **CU10: Registrar y Editar Notas por Materia**
  * **Ubicación:** [docente_dashboard.php](docente_dashboard.php)
  * **Operación:** Planilla interactiva donde el docente ingresa las notas parciales (Parcial 1: 30%, Parcial 2: 30%, Examen Final: 40%). Valida estrictamente en PHP y JS que los valores se encuentren en el rango matemático de **0 a 100**. Inserta o actualiza los registros mediante una sentencia `UPSERT` (`INSERT ... ON CONFLICT DO UPDATE`) en la tabla `Notas`.
* **CU11: Calcular Promedio y Estado de Admisión**
  * **Ubicación:** [estudiante_dashboard.php](estudiante_dashboard.php) / Base de Datos
  * **Operación:** Se calcula en caliente el promedio aritmético de las tres notas. En la base de datos se utiliza una columna matemática autogenerada (`promedio GENERATED ALWAYS AS ((nota1 + nota2 + nota3) / 3) STORED`) y define el estatus de admisión (`estado GENERATED ALWAYS AS (CASE WHEN promedio >= 60 THEN 'APROBADO' ELSE 'REPROBADO' END) STORED`). El portal del estudiante calcula el promedio general de todas sus materias para definir si su estado de ingreso global es APROBADO o REPROBADO.
* **CU12: Reasignar por Cupo Saturado**
  * **Ubicación:** [register.php](register.php)
  * **Operación:** Al guardar la ficha de registro, el sistema verifica mediante `COUNT` los cupos ocupados de la primera opción de carrera elegida. Si los cupos de la primera opción están saturados (`inscritos >= cupo_maximo`), el sistema deriva de manera automática e inmediata al postulante a su carrera de respaldo (Segunda Opción).
* **CU13: Calcular Cantidad de Grupos Automatizado**
  * **Ubicación:** [register.php](register.php) / Base de Datos
  * **Operación:** Lógica matemática que limita los grupos de estudio a un máximo de **70 personas**. Si un grupo disponible excede esta cantidad, el sistema genera de forma automática un nuevo grupo secuencial (e.g. Grupo B, Grupo C) e inscribe al postulante en el mismo.
* **CU14: Asignar Docentes y Carga Horaria**
  * **Ubicación:** [admin_asignaciones.php](admin_asignaciones.php)
  * **Operación:** Interfaz administrativa para asignar docentes a materias y grupos. Valida que el docente posea grado académico de posgrado (`tiene_maestria = TRUE` o `tiene_diplomado = TRUE`) y restringe que no pueda tener más de **4 aulas/grupos** asignados simultáneamente.
* **CU15: Visualizar Dashboard con Indicadores Clave**
  * **Ubicación:** [admin_dashboard.php](admin_dashboard.php)
  * **Operación:** Muestra de forma ejecutiva en tiempo real cuatro KPIs: Total Estudiantes, Aprobados, Reprobados y Cantidad de Grupos Activos en el sistema.
* **CU16: Generar Reportes Académicos Obligatorios**
  * **Ubicación:** [admin_dashboard.php](admin_dashboard.php)
  * **Operación:** Sirve seis reportes específicos mediante consultas SQL estructuradas con uniones (`JOINS` complejos):
    1. Lista General de Estudiantes
    2. Postulantes Aprobados
    3. Postulantes Reprobados
    4. Rendimiento Promedio por Materia
    5. Carga de Asignación de Docentes
    6. Ranking de Éxito de los Grupos
    
    Permite la impresión física y descarga mediante una hoja de estilos de impresión simplificada (`window.print()`).
