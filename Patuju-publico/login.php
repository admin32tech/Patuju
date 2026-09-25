<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['usuario_rol'] === 'admin') {
        header('Location: admin.php');
    } elseif ($_SESSION['usuario_rol'] === 'encargado') {
        header('Location: encargado.php');
    } else {
        header('Location: index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Patujú POS</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background-color: var(--color-bg);
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            background: var(--color-surface);
            border-radius: var(--radius-lg);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            border: 1px solid var(--color-surface-2);
            text-align: center;
        }
        .login-logo {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-primary);
            margin-bottom: 0.5rem;
        }
        .login-subtitle {
            color: var(--color-text-dim);
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            text-align: left;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .form-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--color-text-dim);
        }
        .form-group input {
            padding: 0.75rem 1rem;
            background: var(--color-surface-2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            color: var(--color-text);
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        .btn-login {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            padding: 0.875rem;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: opacity 0.2s;
            margin-top: 0.5rem;
        }
        .btn-login:hover {
            opacity: 0.9;
        }
        .btn-login:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .error-message {
            color: var(--color-danger);
            background: rgba(239, 68, 68, 0.1);
            padding: 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            display: none;
            margin-bottom: 1rem;
        }
        .back-link {
            display: inline-block;
            margin-top: 1.5rem;
            color: var(--color-text-dim);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: var(--color-primary);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">🥟 PATUJU POS</div>
        <div class="login-subtitle">Ingresa a tu sucursal</div>
        
        <div id="error-message" class="error-message"></div>
        
        <form id="login-form" class="login-form">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login" id="btn-submit">Iniciar Sesión</button>
        </form>
        
        <a href="landing.php" class="back-link">← Volver al inicio</a>
    </div>

    <script>
        // Opción 3: Recordar último usuario logueado (cliente-side)
        document.addEventListener('DOMContentLoaded', () => {
            const userInput = document.getElementById('username');
            const pwdInput  = document.getElementById('password');
            const ultimo    = localStorage.getItem('ultimo_usuario');
            if (ultimo && userInput) {
                userInput.value = ultimo;
                if (pwdInput) pwdInput.focus();
            } else if (userInput) {
                userInput.focus();
            }
        });

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const form = e.target;
            const btnSubmit = document.getElementById('btn-submit');
            const errorDiv = document.getElementById('error-message');
            const username = form.username.value.trim();
            const password = form.password.value;
            
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Verificando...';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('ajax/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ username, password })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Recordar último usuario para próximos accesos
                    localStorage.setItem('ultimo_usuario', username);
                    window.location.href = data.redirect;
                } else {
                    errorDiv.textContent = data.error || 'Error al iniciar sesión';
                    errorDiv.style.display = 'block';
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = 'Iniciar Sesión';
                }
            } catch (err) {
                console.error(err);
                errorDiv.textContent = 'Error de conexión';
                errorDiv.style.display = 'block';
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Iniciar Sesión';
            }
        });

        // Show permissions error if passed in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === 'sin_permisos') {
            const errorDiv = document.getElementById('error-message');
            errorDiv.textContent = 'No tienes permisos para acceder a esta área';
            errorDiv.style.display = 'block';
        }
    </script>
</body>
</html>
