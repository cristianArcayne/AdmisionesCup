<?php
/**
 * Pantalla de Inicio Simplificada - Sistema de Admisión Universitaria (CUP) - FICCT
 */
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CUP Admisión | FICCT UAGRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #ffffff;
            color: #333333;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            width: 100%;
            max-width: 1100px;
            text-align: center;
        }
        .title {
            font-size: 3rem;
            font-weight: 800;
            color: #5E35B1; /* Púrpura como en la imagen */
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        .subtitle {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1E88E5; /* Azul como en la imagen */
            margin-bottom: 50px;
        }
        
        .alert {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 0.95rem;
            font-weight: 500;
            max-width: 600px;
            width: 100%;
            text-align: left;
        }
        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .cards-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 40px;
            margin-bottom: 50px;
            width: 100%;
        }
        
        .card {
            background-color: #f8f9fa; /* Fondo gris/blanco claro como en la imagen */
            border: 1px solid #e0e0e0;
            border-radius: 28px; /* Bordes redondeados suaves como la imagen */
            width: 290px;
            padding: 40px 20px;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            cursor: pointer;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
            background-color: #ffffff;
            border-color: #5E35B1;
        }
        
        .icon-container {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 140px;
        }
        
        .card-label {
            font-size: 1.25rem;
            font-weight: 700;
            color: #000000;
            margin-top: 10px;
            text-align: center;
        }

        .bottom-actions {
            margin-top: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .btn-register {
            font-size: 1.05rem;
            font-weight: 600;
            color: #ffffff;
            background-color: #5E35B1;
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 10px rgba(94, 53, 177, 0.2);
        }

        .btn-register:hover {
            background-color: #4527A0;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(94, 53, 177, 0.3);
        }

        .footer-links {
            margin-top: 50px;
            font-size: 0.85rem;
            color: #757575;
        }
        
        .footer-links a {
            color: #5E35B1;
            text-decoration: none;
            font-weight: 600;
        }
        
        .footer-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .title {
                font-size: 2.2rem;
            }
            .subtitle {
                font-size: 1.2rem;
                margin-bottom: 30px;
            }
            .cards-grid {
                gap: 20px;
            }
            .card {
                width: 100%;
                max-width: 320px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <!-- Título y Subtítulo estilo la imagen -->
        <h1 class="title">Admisión Universitaria</h1>
        <h2 class="subtitle">Elige tu tipo de cuenta</h2>

        <!-- Alertas del sistema -->
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($_GET['err']) ?>
            </div>
        <?php endif; ?>

        <!-- Tarjetas de Selección de Rol -->
        <div class="cards-grid">
            
            <!-- Tarjeta 1: Estudiante -->
            <a href="P_Seguridad/login.php?role=estudiante" class="card">
                <div class="icon-container">
                    <svg width="120" height="120" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" fill="#5E35B1"/>
                        <path d="M12 14C7.58172 14 4 17.5817 4 22H20C20 17.5817 16.4183 14 12 14Z" fill="#5E35B1"/>
                    </svg>
                </div>
                <div class="card-label">Estudiante</div>
            </a>

            <!-- Tarjeta 2: Docente -->
            <a href="P_Seguridad/login.php?role=docente" class="card">
                <div class="icon-container">
                    <svg width="120" height="120" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Pizarra estilo Vector -->
                        <rect x="3" y="4" width="18" height="11" rx="2" fill="#00897B" stroke="#5D4037" stroke-width="1.5"/>
                        <rect x="4.5" y="5.5" width="15" height="8" fill="#00796B"/>
                        <!-- Soporte de la pizarra -->
                        <path d="M6 15L4 20" stroke="#5D4037" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M18 15L20 20" stroke="#5D4037" stroke-width="1.5" stroke-linecap="round"/>
                        <!-- Líneas de escritura -->
                        <line x1="6.5" y1="8" x2="12.5" y2="8" stroke="#E0F2F1" stroke-width="1.2" stroke-linecap="round"/>
                        <line x1="6.5" y1="10" x2="10.5" y2="10" stroke="#E0F2F1" stroke-width="1.2" stroke-linecap="round"/>
                        <!-- Pequeño borrador amarillo -->
                        <rect x="15" y="11.5" width="3" height="1" rx="0.3" fill="#FFB300"/>
                    </svg>
                </div>
                <div class="card-label">Docente</div>
            </a>

            <!-- Tarjeta 3: Administrador -->
            <a href="P_Seguridad/login.php?role=admin" class="card">
                <div class="icon-container">
                    <svg width="120" height="120" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Laptop estilo la imagen -->
                        <rect x="4" y="4" width="16" height="11" rx="1.5" fill="#42A5F5" stroke="#78909C" stroke-width="1.5"/>
                        <path d="M5.5 5.5H18.5V13.5H5.5V5.5Z" fill="url(#screenGrad)"/>
                        <path d="M2 16.5C2 15.6716 2.67157 15 3.5 15H20.5C21.3284 15 22 15.6716 22 16.5V18.5C22 19.3284 21.3284 20 20.5 20H3.5C2.67157 20 2 19.3284 2 18.5V16.5Z" fill="#B0BEC5"/>
                        <rect x="10" y="18" width="4" height="1.5" rx="0.5" fill="#90A4AE"/>
                        <line x1="5" y1="16.5" x2="19" y2="16.5" stroke="#90A4AE" stroke-width="1" stroke-dasharray="2 1"/>
                        <line x1="5" y1="17.5" x2="19" y2="17.5" stroke="#90A4AE" stroke-width="1" stroke-dasharray="1 1"/>
                        <defs>
                            <linearGradient id="screenGrad" x1="5.5" y1="5.5" x2="18.5" y2="13.5" gradientUnits="userSpaceOnUse">
                                <stop offset="0%" stop-color="#29B6F6"/>
                                <stop offset="100%" stop-color="#1E88E5"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div class="card-label">Administrador</div>
            </a>

        </div>

        <!-- Botón de Registro de Postulantes -->
        <div class="bottom-actions">
            <a href="P_Postulantes/register.php" class="btn-register">Registrarse como Postulante</a>
        </div>

        <!-- Enlaces técnicos del pie de página -->
        <footer class="footer-links">
            <p>&copy; <?= date('Y') ?> Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones - UAGRM</p>
        </footer>

    </div>
</body>
</html>
