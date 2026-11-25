<?php
session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/tipos_rp.php';
$error = '';
$error_registro = '';
$success_registro = '';
$error_recuperar = '';
$modo = $_GET['modo'] ?? 'login'; // 'login', 'registro' o 'recuperar'

// --- LÓGICA DE LOGIN ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'login') {
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);

    if (!empty($usuario) && !empty($password)) {
        // SELECT * para leer debe_cambiar_password solo si la columna ya existe (ajustes_2026-09-17.sql)
        $sql = "SELECT * FROM usuarios WHERE usuario = :usuario AND activo = 1";
        
        if ($stmt = $pdo->prepare($sql)) {
            $stmt->bindParam(':usuario', $usuario, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                if ($stmt->rowCount() == 1) {
                    $row = $stmt->fetch();
                    if (password_verify($password, $row['password'])) {
                        session_start();
                        $_SESSION['loggedin'] = true;
                        $_SESSION['id'] = $row['id'];
                        $_SESSION['nombre'] = $row['nombre_completo'];
                        $_SESSION['rol'] = $row['rol'];

                        // Entró con contraseña temporal (restablecida por gerente/admin): debe elegir una nueva
                        if (!empty($row['debe_cambiar_password'])) {
                            $_SESSION['debe_cambiar_password'] = true;
                            header('Location: cuenta/password.php');
                            exit;
                        }

                        header('Location: index.php');
                    } else {
                        $error = 'La contraseña no es válida.';
                    }
                } else {
                    $error = 'No se encontró ninguna cuenta con ese usuario.';
                }
            }
            unset($stmt);
        }
    } else {
        $error = 'Por favor, ingrese usuario y contraseña.';
    }
}

// --- LÓGICA DE "OLVIDÉ MI CONTRASEÑA" ---
// Aquí solo se registra la solicitud. La identidad la verifica el gerente/admin en persona,
// quien genera una contraseña temporal desde cuenta/restablecer.php.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'recuperar') {
    $usuario_rec = trim($_POST['usuario_rec'] ?? '');
    $modo = 'recuperar';

    if (empty($usuario_rec)) {
        $error_recuperar = 'Ingresa tu teléfono o usuario.';
    } else {
        try {
            $stmt_rec = $pdo->prepare("SELECT id, rol FROM usuarios WHERE usuario = :usuario AND activo = 1");
            $stmt_rec->execute(['usuario' => $usuario_rec]);
            $cuenta = $stmt_rec->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                $error_recuperar = 'No encontramos una cuenta con ese teléfono o usuario. Revisa que sea el mismo con el que te registraste.';
            } else {
                $pdo->prepare("UPDATE usuarios SET reset_solicitado_en = NOW() WHERE id = :id")->execute(['id' => $cuenta['id']]);
                // El gerente solo restablece cuentas de RP; las demás las ve el administrador
                $quien = ($cuenta['rol'] == 'rp') ? 'el Gerente' : 'el Administrador';
                $success_registro = "Solicitud enviada. Acude con $quien: verificará que la cuenta es tuya y te dará una contraseña temporal para entrar.";
                $modo = 'login';
            }
        } catch (PDOException $e) {
            $error_recuperar = 'No se pudo registrar la solicitud. Acude directamente con el Gerente.';
        }
    }
}

// --- LÓGICA DE AUTOREGISTRO ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'registro') {
    $nombre_completo = trim($_POST['nombre_completo']);
    $telefono = trim($_POST['telefono']);
    $password_reg = trim($_POST['password_reg']);
    $tipo_rp = trim($_POST['tipo_rp'] ?? '');
    $modo = 'registro'; // Mantener en la pestaña de registro

    if (empty($nombre_completo) || empty($telefono) || empty($password_reg) || empty($tipo_rp)) {
        $error_registro = 'Todos los campos son obligatorios.';
    } elseif (!in_array($tipo_rp, $TIPOS_RP, true)) {
        $error_registro = 'Selecciona un tipo de RP válido.';
    } elseif (!preg_match('/^[0-9]{10}$/', $telefono)) {
        $error_registro = 'El número telefónico debe tener 10 dígitos.';
    } elseif (strlen($password_reg) < 4) {
        $error_registro = 'La contraseña debe tener al menos 4 caracteres.';
    } else {
        // Verificar que el teléfono no exista ya como usuario
        $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :usuario");
        $stmt_check->execute(['usuario' => $telefono]);
        
        if ($stmt_check->rowCount() > 0) {
            $error_registro = 'Este número telefónico ya está registrado. Si olvidaste tu contraseña, <a href="?modo=recuperar" class="underline font-semibold">recupérala aquí</a>.';
        } else {
            try {
                $hash = password_hash($password_reg, PASSWORD_DEFAULT);
                $stmt_insert = $pdo->prepare("INSERT INTO usuarios (nombre_completo, usuario, password, rol, tipo_rp, activo) VALUES (:nombre, :usuario, :password, 'rp', :tipo_rp, 1)");
                $stmt_insert->execute([
                    'nombre' => $nombre_completo,
                    'usuario' => $telefono,
                    'password' => $hash,
                    'tipo_rp' => $tipo_rp
                ]);
                $success_registro = 'Registro exitoso. Ya puedes iniciar sesión con tu número telefónico.';
                $modo = 'login'; // Regresar a login tras éxito
            } catch (PDOException $e) {
                $error_registro = 'Error al registrar. Intenta de nuevo.';
            }
        }
    }
    unset($pdo);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Tapanko Vallarta</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="includes/bartola.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #0a0a0a;
            background-image: 
                radial-gradient(ellipse at top, rgba(180, 83, 9, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at bottom right, rgba(30, 30, 30, 0.4) 0%, transparent 60%);
            min-height: 100vh;
        }
        
        .glass-login {
            background: rgba(23, 23, 23, 0.7);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(64, 64, 64, 0.35);
        }
        
        .input-login {
            background: rgba(10, 10, 10, 0.6);
            border: 1px solid rgba(64, 64, 64, 0.5);
            transition: all 0.25s ease;
            color: white;
        }
        .input-login:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.12);
            outline: none;
        }
        .input-login::placeholder {
            color: rgba(163, 163, 163, 0.5);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #d97706, #92400e);
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.2);
        }
        .btn-login:active {
            transform: translateY(0);
        }
        
        .title-gradient {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-8">
    <div class="w-full max-w-sm animate-fade-in-up">
        
        <!-- Logo -->
        <div class="text-center mb-6">
            <img src="includes/bartola.png" alt="Tapanko Vallarta" class="h-14 w-14 mx-auto mb-3 opacity-90">
        </div>

        <?php if (!empty($success_registro)): ?>
            <div class="rounded-xl px-4 py-3 mb-5 text-sm" style="background: rgba(22,101,52,0.2); border: 1px solid rgba(34,197,94,0.3); color: #86efac;">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo $success_registro; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($modo === 'login'): ?>
        <!-- ========== FORMULARIO DE LOGIN ========== -->
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="glass-login rounded-2xl px-7 pt-8 pb-8 shadow-2xl">
            <input type="hidden" name="accion" value="login">
            <h2 class="text-2xl font-bold text-center mb-1 title-gradient">TAPANKO VALLARTA</h2>
            <p class="text-center text-neutral-500 text-sm mb-7">Accede al sistema de reservas</p>
            
            <div class="mb-5">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="usuario">
                    Telefono / Usuario
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="usuario" name="usuario" type="text" placeholder="Tu Teléfono">
            </div>
            <div class="mb-6">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="password">
                    Contraseña
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="password" name="password" type="password" placeholder="••••••••••">
            </div>
            <?php if (!empty($error)): ?>
                <div class="rounded-xl px-4 py-3 mb-5 text-sm" style="background: rgba(127,29,29,0.2); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5;">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span><?php echo $error; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <div>
                <button class="btn-login text-white font-semibold py-3 px-4 rounded-xl w-full text-sm" type="submit">
                    Ingresar
                </button>
            </div>
            <div class="text-center mt-5">
                <a href="?modo=recuperar" class="text-sm text-neutral-500 hover:text-amber-500 transition-colors duration-200">¿Olvidaste tu contraseña?</a>
            </div>
            <div class="text-center mt-3">
                <a href="?modo=registro" class="text-sm text-neutral-500 hover:text-amber-500 transition-colors duration-200">¿No tienes cuenta? <span class="font-semibold text-neutral-400 hover:text-amber-400">Regístrate aquí</span></a>
            </div>
        </form>

        <?php elseif ($modo === 'recuperar'): ?>
        <!-- ========== OLVIDÉ MI CONTRASEÑA ========== -->
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="glass-login rounded-2xl px-7 pt-8 pb-8 shadow-2xl">
            <input type="hidden" name="accion" value="recuperar">
            <h2 class="text-2xl font-bold text-center mb-1 title-gradient">Recuperar Acceso</h2>
            <p class="text-center text-neutral-500 text-sm mb-7">No te registres de nuevo. Envía tu solicitud y el Gerente te dará una contraseña temporal después de identificarte.</p>

            <div class="mb-6">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="usuario_rec">
                    Telefono / Usuario
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="usuario_rec" name="usuario_rec" type="text" placeholder="El teléfono con el que te registraste" required>
            </div>
            <?php if (!empty($error_recuperar)): ?>
                <div class="rounded-xl px-4 py-3 mb-5 text-sm" style="background: rgba(127,29,29,0.2); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5;">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span><?php echo $error_recuperar; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <div>
                <button class="btn-login text-white font-semibold py-3 px-4 rounded-xl w-full text-sm" type="submit">
                    Solicitar Contraseña Temporal
                </button>
            </div>
            <div class="text-center mt-5">
                <a href="?modo=login" class="text-sm text-neutral-500 hover:text-amber-500 transition-colors duration-200">
                    <span class="mr-1">←</span> Volver a <span class="font-semibold text-neutral-400 hover:text-amber-400">iniciar sesión</span>
                </a>
            </div>
        </form>

        <?php else: ?>
        <!-- ========== FORMULARIO DE AUTOREGISTRO ========== -->
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="glass-login rounded-2xl px-7 pt-8 pb-8 shadow-2xl">
            <input type="hidden" name="accion" value="registro">
            <h2 class="text-2xl font-bold text-center mb-1 title-gradient">Registro RP</h2>
            <p class="text-center text-neutral-500 text-sm mb-7">Crea tu cuenta para comenzar a registrar reservas.</p>
            
            <div class="mb-4">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="nombre_completo">
                    Nombre Completo
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="nombre_completo" name="nombre_completo" type="text" placeholder="Tu nombre completo" required>
            </div>
            <div class="mb-4">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="telefono">
                    Número Telefónico
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="telefono" name="telefono" type="tel" placeholder="10 dígitos" maxlength="10" pattern="[0-9]{10}" required>
                <p class="text-neutral-600 text-xs mt-1.5">Este será tu usuario para iniciar sesión.</p>
            </div>
            <div class="mb-4">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="tipo_rp">
                    Tipo de RP
                </label>
                <select class="input-login rounded-xl w-full py-3 px-4 text-sm" id="tipo_rp" name="tipo_rp" required>
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($TIPOS_RP as $tipo): ?>
                        <option value="<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($tipo); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-6">
                <label class="block text-neutral-400 text-xs font-semibold mb-2 uppercase tracking-wider" for="password_reg">
                    Contraseña
                </label>
                <input class="input-login rounded-xl w-full py-3 px-4 text-sm" id="password_reg" name="password_reg" type="password" placeholder="••••••••••" required>
            </div>
            <?php if (!empty($error_registro)): ?>
                <div class="rounded-xl px-4 py-3 mb-5 text-sm" style="background: rgba(127,29,29,0.2); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5;">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span><?php echo $error_registro; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <div>
                <button class="btn-login text-white font-semibold py-3 px-4 rounded-xl w-full text-sm" type="submit">
                    Registrarme
                </button>
            </div>
            <div class="text-center mt-5">
                <a href="?modo=login" class="text-sm text-neutral-500 hover:text-amber-500 transition-colors duration-200">
                    <span class="mr-1">←</span> Ya tengo cuenta, <span class="font-semibold text-neutral-400 hover:text-amber-400">iniciar sesión</span>
                </a>
            </div>
        </form>
        <?php endif; ?>

    </div>
</body>
</html>