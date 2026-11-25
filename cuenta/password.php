<?php
// Cambio de contraseña para cualquier usuario con sesión iniciada.
// Normal: la identidad se verifica pidiendo la contraseña actual.
// Forzado: entró con la contraseña temporal que le dio el gerente/admin (acaba de escribirla
// en el login), así que solo elige la nueva; no puede usar el sistema hasta hacerlo.
require_once '../includes/auth.php';
require_once '../includes/db.php';

$forzado = !empty($_SESSION['debe_cambiar_password']);
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Se usa trim igual que en el login y el registro
    $password_actual = trim($_POST['password_actual'] ?? '');
    $password_nueva = trim($_POST['password_nueva'] ?? '');
    $password_confirmar = trim($_POST['password_confirmar'] ?? '');

    $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = :id AND activo = 1");
    $stmt->execute(['id' => $_SESSION['id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $error = 'Tu cuenta ya no está activa.';
    } elseif (!$forzado && !password_verify($password_actual, $usuario['password'])) {
        $error = 'La contraseña actual no es correcta.';
    } elseif (strlen($password_nueva) < 4) {
        $error = 'La nueva contraseña debe tener al menos 4 caracteres.';
    } elseif ($password_nueva !== $password_confirmar) {
        $error = 'La nueva contraseña y su confirmación no coinciden.';
    } elseif (password_verify($password_nueva, $usuario['password'])) {
        $error = $forzado ? 'Elige una contraseña distinta a la temporal.' : 'La nueva contraseña debe ser diferente a la actual.';
    } else {
        // debe_cambiar_password solo existe tras ejecutar ajustes_2026-09-17.sql; el modo forzado implica que ya existe
        $sql = $forzado
            ? "UPDATE usuarios SET password = :pass, debe_cambiar_password = 0 WHERE id = :id"
            : "UPDATE usuarios SET password = :pass WHERE id = :id";
        $stmt_update = $pdo->prepare($sql);
        $stmt_update->execute([
            'pass' => password_hash($password_nueva, PASSWORD_DEFAULT),
            'id' => $_SESSION['id']
        ]);
        unset($_SESSION['debe_cambiar_password']);
        session_regenerate_id(true);
        $success = 'Tu contraseña se actualizó correctamente.';
    }
}

require_once '../includes/header.php';
?>

<div class="max-w-md mx-auto glass-card rounded-2xl p-6 sm:p-8">
    <div class="flex items-center mb-6">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-700/20 flex items-center justify-center mr-3">
            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        </div>
        <div>
            <h2 class="text-xl font-bold text-white">Cambiar Contraseña</h2>
            <p class="text-xs text-neutral-500"><?php echo htmlspecialchars($_SESSION['nombre']); ?></p>
        </div>
    </div>

    <?php if ($forzado && empty($success)): ?>
        <div class="rounded-xl px-4 py-3 mb-5 text-sm bg-amber-500/10 border border-amber-500/30 text-amber-300">
            Entraste con una contraseña temporal. Elige tu nueva contraseña para continuar.
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm">
            <?php echo $success; ?>
            <a href="<?php echo $nav_base; ?>index.php" class="font-bold underline">Ir a mi panel</a>
        </div>
    <?php endif; ?>

    <?php if (empty($success)): ?>
    <form action="password.php" method="POST" autocomplete="off" class="space-y-4">
        <?php if (!$forzado): ?>
        <div>
            <label for="password_actual" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Contraseña Actual</label>
            <input type="password" name="password_actual" id="password_actual" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
        </div>
        <?php endif; ?>
        <div>
            <label for="password_nueva" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nueva Contraseña</label>
            <input type="password" name="password_nueva" id="password_nueva" minlength="4" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
        </div>
        <div>
            <label for="password_confirmar" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Confirmar Nueva Contraseña</label>
            <input type="password" name="password_confirmar" id="password_confirmar" minlength="4" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
        </div>
        <div class="pt-2">
            <button type="submit" class="btn-amber w-full py-3 px-4 rounded-xl text-sm font-semibold text-white">
                Actualizar Contraseña
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
