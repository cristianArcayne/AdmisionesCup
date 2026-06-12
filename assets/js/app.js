/**
 * Lógica del Lado del Cliente - Sistema de Admisión Universitaria
 * Control de Flujos Multi-paso, Validación de Formularios y Pasarela de Pagos
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Control del Registro Multi-paso (register.php)
    const registerForm = document.getElementById('register-multi-step');
    if (registerForm) {
        setupMultiStepRegister();
    }

    // 2. Control de Pago (Simulación de QR vs Tarjeta)
    setupPaymentGateway();
});

/**
 * Controla el formulario de registro paso a paso
 */
function setupMultiStepRegister() {
    const steps = Array.from(document.querySelectorAll('.step-content'));
    const dots = Array.from(document.querySelectorAll('.step-dot'));
    const lineActive = document.querySelector('.step-line-active');
    const btnNext1 = document.getElementById('btn-next-1');
    const btnNext2 = document.getElementById('btn-next-2');
    const btnPrev2 = document.getElementById('btn-prev-2');
    const btnPrev3 = document.getElementById('btn-prev-3');

    let currentStep = 0;

    // Actualiza la línea de progreso horizontal
    function updateProgressLine() {
        if (lineActive) {
            const percent = (currentStep / (steps.length - 1)) * 80;
            lineActive.style.width = `${percent}%`;
        }
    }

    // Cambia de paso visualmente
    function showStep(stepIndex) {
        steps.forEach((step, idx) => {
            step.classList.toggle('active', idx === stepIndex);
        });
        dots.forEach((dot, idx) => {
            dot.classList.toggle('active', idx === stepIndex);
            dot.classList.toggle('completed', idx < stepIndex);
        });
        currentStep = stepIndex;
        updateProgressLine();
    }

    // Paso 1 -> Paso 2 (Validación de CI)
    if (btnNext1) {
        btnNext1.addEventListener('click', async () => {
            const ciInput = document.getElementById('ci');
            const ci = ciInput.value.trim();

            if (!ci) {
                alertError('El número de Carnet de Identidad (CI) es obligatorio.');
                ciInput.focus();
                return;
            }

            // AJAX: Validar que el CI no exista ya registrado en el sistema
            btnNext1.disabled = true;
            btnNext1.textContent = 'Verificando...';

            try {
                const response = await fetch(`register.php?check_ci=${encodeURIComponent(ci)}`);
                const data = await response.json();

                if (data.exists) {
                    alertError('Este número de CI ya se encuentra registrado en el sistema.');
                    ciInput.classList.add('is-invalid');
                } else {
                    ciInput.classList.remove('is-invalid');
                    // Proceder al Paso 2
                    showStep(1);
                }
            } catch (error) {
                console.error('Error al verificar CI:', error);
                // Si falla la red, procedemos por precaución y dejamos la validación del lado del servidor
                showStep(1);
            } finally {
                btnNext1.disabled = false;
                btnNext1.textContent = 'Siguiente';
            }
        });
    }

    // Paso 2 -> Paso 3 (Validación de Datos Personales y Académicos)
    if (btnNext2) {
        btnNext2.addEventListener('click', () => {
            const requiredFields = [
                { id: 'nombre', name: 'Nombres' },
                { id: 'apellido', name: 'Apellidos' },
                { id: 'fecha_nacimiento', name: 'Fecha de Nacimiento' },
                { id: 'telefono', name: 'Teléfono' },
                { id: 'correo_personal', name: 'Correo Electrónico' },
                { id: 'colegio_procedencia', name: 'Colegio de Procedencia' },
                { id: 'ciudad', name: 'Ciudad' },
                { id: 'carrera_primera', name: 'Primera opción de carrera' },
                { id: 'carrera_segunda', name: 'Segunda opción de carrera' }
            ];

            // Validar campos vacíos
            for (const field of requiredFields) {
                const element = document.getElementById(field.id);
                if (!element || !element.value.trim()) {
                    alertError(`El campo "${field.name}" es obligatorio.`);
                    element.focus();
                    return;
                }
            }

            // Validar formato de Correo
            const emailInput = document.getElementById('correo_personal');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value.trim())) {
                alertError('Por favor ingresa un correo electrónico válido.');
                emailInput.focus();
                return;
            }

            // Validar que las carreras postuladas no sean iguales
            const c1 = document.getElementById('carrera_primera').value;
            const c2 = document.getElementById('carrera_segunda').value;
            if (c1 === c2) {
                alertError('La primera y segunda opción de carrera deben ser diferentes.');
                document.getElementById('carrera_segunda').focus();
                return;
            }

            // Proceder al Paso 3 (Pago)
            showStep(2);
        });
    }

    // Botones Atrás
    if (btnPrev2) {
        btnPrev2.addEventListener('click', () => showStep(0));
    }
    if (btnPrev3) {
        btnPrev3.addEventListener('click', () => showStep(1));
    }
}

/**
 * Controla la selección del método de pago y el formulario correspondiente
 */
function setupPaymentGateway() {
    const payOptions = document.querySelectorAll('.payment-option');
    const qrSection = document.getElementById('qr-payment-section');
    const cardSection = document.getElementById('card-payment-section');
    const methodInput = document.getElementById('metodo_pago');

    if (payOptions.length > 0) {
        payOptions.forEach(option => {
            option.addEventListener('click', () => {
                // Remover clase activa de todos
                payOptions.forEach(opt => opt.classList.remove('active'));
                
                // Agregar clase activa al seleccionado
                option.classList.add('active');
                
                const method = option.getAttribute('data-method');
                if (methodInput) methodInput.value = method;

                if (method === 'qr') {
                    qrSection.style.display = 'block';
                    cardSection.style.display = 'none';
                } else if (method === 'card') {
                    qrSection.style.display = 'none';
                    cardSection.style.display = 'block';
                }
            });
        });
    }
}

/**
 * Muestra una alerta rápida en pantalla
 */
function alertError(message) {
    // Si ya hay una alerta en pantalla, la removemos
    const existingAlert = document.querySelector('.alert-floating');
    if (existingAlert) {
        existingAlert.remove();
    }

    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-error alert-floating';
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '1000';
    alertDiv.style.boxShadow = '0 5px 15px rgba(239, 68, 68, 0.4)';
    alertDiv.innerHTML = `<strong>Error:</strong> ${message}`;

    document.body.appendChild(alertDiv);

    // Auto-ocultar después de 4 segundos
    setTimeout(() => {
        alertDiv.remove();
    }, 4000);
}
