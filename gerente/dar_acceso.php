<?php
// Enlazar un número de teléfono a un RP "sin usuario" (registrado por gerente/hostess/coordinador).
// Dos casos según el teléfono:
//  - Nadie lo tiene: el RP sin usuario se convierte en usuario real con contraseña temporal
//    (debe cambiarla al entrar) y conserva todas sus reservas.
//  - Ya pertenece a un RP registrado: se fusionan. Las reservas del RP sin usuario pasan
//    al usuario registrado y el RP sin usuario se elimina.
require_once '../includes/header.php';
require_once '../includes/rps.php';
check_role(['gerente', 'admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id_rp = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$telefono = preg_replace('/\D+/', '', $_POST['telefono'] ?? '');
$accion = $_POST['accion'] ?? '';
$error = '';
$success = '';
$temporal = null;      // contraseña temporal, se muestra una sola vez
$existente = null;     // usuario que ya tiene ese teléfono
$telefono_libre = false;

$stmt = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM reservas r WHERE r.id_rp = u.id) AS reservas
                       FROM usuarios u WHERE u.id = :id AND u.rol = 'rp' AND u.sin_acceso = 1");
$stmt->execute(['id' => $id_rp]);
$rp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rp) {
    $error = 'RP no encontrado o ya tiene usuario.';
}

if ($rp && $_SERVER["REQUEST_METHOD"] == "POST") {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif (!preg_match('/^[0-9]{10}$/', $telefono)) {
        $error = 'El número telefónico debe tener 10 dígitos.';
    } else {
        // ¿A quién pertenece ese teléfono?
        $stmt = $pdo->prepare("SELECT id, nombre_completo, rol, tipo_rp, activo FROM usuarios WHERE usuario = :tel AND id <> :id");
        $stmt->execute(['tel' => $telefono, 'id' => $rp['id']]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);
        $telefono_libre = !$existente;

        if ($existente && $existente['rol'] !== 'rp') {
            $error = 'Ese teléfono pertenece a un usuario con rol ' . htmlspecialchars(ucfirst($existente['rol'])) . ', no a un RP. No se puede enlazar.';
            $existente = null;
        } elseif ($accion === 'crear' && $telefono_libre) {
            try {
                $password_temporal = generar_password_temporal();
                $pdo->prepare("UPDATE usuarios SET usuario = :tel, password = :pass, activo = 1, sin_acceso = 0, debe_cambiar_password = 1 WHERE id = :id AND sin_acceso = 1")
                    ->execute(['tel' => $telefono, 'pass' => password_hash($password_temporal, PASSWORD_DEFAULT), 'id' => $rp['id']]);
                $temporal = $password_temporal;
                $success = htmlspecialchars($rp['nombre_completo']) . " ya puede entrar con el teléfono $telefono. Conserva sus {$rp['reservas']} reserva(s).";
            } catch (PDOException $e) {
                $error = 'No se pudo crear el acceso: ' . htmlspecialchars($e->getMessage());
            }
        } elseif ($accion === 'fusionar' && $existente) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE reservas SET id_rp = :destino WHERE id_rp = :origen")->execute(['destino' => $existente['id'], 'origen' => $rp['id']]);
                if (empty($existente['tipo_rp']) && !empty($rp['tipo_rp'])) {
                    $pdo->prepare("UPDATE usuarios SET tipo_rp = :tipo WHERE id = :id")->execute(['tipo' => $rp['tipo_rp'], 'id' => $existente['id']]);
                }
                $pdo->prepare("DELETE FROM usuarios WHERE id = :id AND sin_acceso = 1")->execute(['id' => $rp['id']]);
                $pdo->commit();
                $success = 'Fusionado: las ' . $rp['reservas'] . ' reserva(s) de ' . htmlspecialchars($rp['nombre_completo']) . ' ahora son de ' . htmlspecialchars($existente['nombre_completo']) . " ($telefono). El RP sin usuario se eliminó.";
                $rp = null;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'No se pudo fusionar: ' . htmlspecialchars($e->getMessage());
            }
        }
        // Si la acción fue solo "verificar", se muestra el resultado y el botón correspondiente
    }
}
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Enlazar teléfono a RP sin usuario</h1>
        <p class="mt-1 text-sm text-neutral-500">Si el teléfono está libre, el RP recibe acceso al sistema. Si ya pertenece a un RP registrado, se fusionan y las reservas pasan a ese usuario.</p>
    </div>

    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?> <a href="usuarios.php" class="font-bold underline">Volver a RPs</a></div>
        <?php if ($temporal): ?>
            <div class="glass-card rounded-2xl p-6 sm:p-8 mb-6 border-amber-500/40 text-center">
                <p class="text-sm text-neutral-400">Contraseña temporal de <span class="text-white font-semibold"><?php echo htmlspecialchars($rp['nombre_completo']); ?></span> (usuario: <?php echo $telefono; ?>)</p>
                <p class="my-5 text-4xl font-mono font-bold tracking-widest text-amber-400 select-all"><?php echo htmlspecialchars($temporal); ?></p>
                <p class="text-xs text-neutral-500">Díctasela o muéstrasela al RP. <strong class="text-neutral-300">Solo se muestra esta vez.</strong> Al entrar, el sistema le pedirá elegir su propia contraseña.</p>
            </div>
        <?php endif; ?>
    <?php elseif ($rp): ?>
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="text-lg font-bold text-white"><?php echo htmlspecialchars($rp['nombre_completo']); ?></p>
            <p class="text-sm text-neutral-400"><?php echo htmlspecialchars($rp['tipo_rp'] ?: 'Sin tipo'); ?> · RP sin usuario · <?php echo $rp['reservas']; ?> reserva(s)</p>
        </div>

        <form method="POST" action="dar_acceso.php" class="glass-card rounded-2xl p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="id" value="<?php echo $rp['id']; ?>">
            <div>
                <label for="telefono" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Número telefónico (será su usuario)</label>
                <input type="tel" name="telefono" id="telefono" value="<?php echo htmlspecialchars($telefono); ?>" placeholder="10 dígitos" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>

            <?php if ($telefono_libre): ?>
                <div class="rounded-xl px-4 py-3 text-sm bg-emerald-500/10 border border-emerald-500/30 text-emerald-300">
                    El teléfono <?php echo $telefono; ?> está libre. Se le dará acceso a <?php echo htmlspecialchars($rp['nombre_completo']); ?> con una contraseña temporal.
                </div>
                <div class="flex gap-3">
                    <button type="submit" name="accion" value="crear" class="btn-amber py-2.5 px-6 rounded-xl text-sm font-semibold text-white">Crear acceso</button>
                    <a href="usuarios.php" class="btn-secondary py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Cancelar</a>
                </div>
            <?php elseif ($existente): ?>
                <div class="rounded-xl px-4 py-3 text-sm bg-amber-500/10 border border-amber-500/30 text-amber-300">
                    El teléfono <?php echo $telefono; ?> ya es de <strong><?php echo htmlspecialchars($existente['nombre_completo']); ?></strong> (<?php echo htmlspecialchars($existente['tipo_rp'] ?: 'sin tipo'); ?><?php echo $existente['activo'] ? '' : ', inactivo'; ?>).
                    Si fusionas, sus <?php echo $rp['reservas']; ?> reserva(s) pasan a ese usuario y el RP sin usuario desaparece de la lista.
                </div>
                <div class="flex gap-3">
                    <button type="submit" name="accion" value="fusionar" onclick="return confirm(<?php echo htmlspecialchars(json_encode('¿Fusionar ' . $rp['nombre_completo'] . ' con ' . $existente['nombre_completo'] . '? Esta acción no se puede deshacer.'), ENT_QUOTES); ?>)" class="py-2.5 px-6 rounded-xl text-sm font-semibold text-white bg-amber-600 hover:bg-amber-500 transition-colors">Fusionar con ese usuario</button>
                    <a href="usuarios.php" class="btn-secondary py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Cancelar</a>
                </div>
            <?php else: ?>
                <div class="flex gap-3">
                    <button type="submit" name="accion" value="verificar" class="btn-amber py-2.5 px-6 rounded-xl text-sm font-semibold text-white">Verificar teléfono</button>
                    <a href="usuarios.php" class="btn-secondary py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Cancelar</a>
                </div>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <a href="usuarios.php" class="btn-secondary inline-block py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Volver a RPs</a>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
