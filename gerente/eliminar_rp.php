<?php
// Eliminar un RP (con o sin usuario). Las reservas apuntan al RP, así que si tiene
// reservas primero se reasignan a otro RP y después se borra el usuario.
require_once '../includes/header.php';
require_once '../includes/rps.php';
check_role(['gerente', 'admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id_rp = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$success = '';

// Datos del RP y cuántas reservas tiene
$stmt = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM reservas r WHERE r.id_rp = u.id) AS reservas
                       FROM usuarios u WHERE u.id = :id AND u.rol = 'rp'");
$stmt->execute(['id' => $id_rp]);
$rp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rp) {
    $error = 'RP no encontrado (solo se pueden eliminar usuarios con rol RP).';
}

// Otros RPs a los que se pueden pasar sus reservas
$listas = ['sin_usuario' => [], 'con_usuario' => []];
if ($rp) {
    try {
        $listas = lista_rps($pdo);
    } catch (PDOException $e) {
        $stmt = $pdo->query("SELECT id, nombre_completo, tipo_rp, activo, 0 AS sin_acceso FROM usuarios WHERE rol = 'rp' ORDER BY nombre_completo");
        $listas['con_usuario'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($listas as &$grupo) {
        $grupo = array_values(array_filter($grupo, fn($u) => $u['id'] != $rp['id']));
    }
    unset($grupo);
}

if ($rp && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirmar'])) {
    $destino = intval($_POST['destino'] ?? 0);
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif ($rp['reservas'] > 0 && $destino <= 0) {
        $error = 'Elige a qué RP se pasan sus reservas.';
    } else {
        try {
            $pdo->beginTransaction();
            $nombre_destino = null;
            if ($rp['reservas'] > 0) {
                $stmt = $pdo->prepare("SELECT id, nombre_completo FROM usuarios WHERE id = :id AND rol = 'rp' AND id <> :actual");
                $stmt->execute(['id' => $destino, 'actual' => $rp['id']]);
                $rp_destino = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rp_destino) throw new RuntimeException('El RP destino no es válido.');
                $nombre_destino = $rp_destino['nombre_completo'];
                $pdo->prepare("UPDATE reservas SET id_rp = :destino WHERE id_rp = :origen")
                    ->execute(['destino' => $rp_destino['id'], 'origen' => $rp['id']]);
            }
            $pdo->prepare("DELETE FROM usuarios WHERE id = :id AND rol = 'rp'")->execute(['id' => $rp['id']]);
            $pdo->commit();
            $success = 'RP ' . htmlspecialchars($rp['nombre_completo']) . ' eliminado.'
                . ($rp['reservas'] > 0 ? ' Sus ' . $rp['reservas'] . ' reserva(s) ahora son de ' . htmlspecialchars($nombre_destino) . '.' : '');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'No se pudo eliminar: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Eliminar RP</h1>
        <p class="mt-1 text-sm text-neutral-500">El RP se borra del sistema. Sus reservas no se pierden: se pasan al RP que elijas.</p>
    </div>

    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?> <a href="usuarios.php" class="font-bold underline">Volver a la lista</a></div>
    <?php elseif ($rp): ?>
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="text-lg font-bold text-white"><?php echo htmlspecialchars($rp['nombre_completo']); ?></p>
            <p class="text-sm text-neutral-400">
                <?php echo htmlspecialchars($rp['tipo_rp'] ?: 'Sin tipo'); ?>
                · <?php echo !empty($rp['sin_acceso']) ? 'RP sin usuario' : 'Usuario: ' . htmlspecialchars($rp['usuario']) . ($rp['activo'] ? ' (activo)' : ' (inactivo)'); ?>
            </p>
            <p class="mt-2 text-sm <?php echo $rp['reservas'] > 0 ? 'text-amber-400' : 'text-neutral-400'; ?>">
                <?php echo $rp['reservas'] > 0 ? 'Tiene ' . $rp['reservas'] . ' reserva(s) registradas.' : 'No tiene reservas.'; ?>
            </p>
        </div>

        <form method="POST" action="eliminar_rp.php" class="glass-card rounded-2xl p-6 space-y-4" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('¿Eliminar definitivamente a ' . $rp['nombre_completo'] . '? Esta acción no se puede deshacer.'), ENT_QUOTES); ?>)">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="id" value="<?php echo $rp['id']; ?>">
            <input type="hidden" name="confirmar" value="1">
            <?php if ($rp['reservas'] > 0): ?>
                <div>
                    <label for="destino" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Pasar sus <?php echo $rp['reservas']; ?> reserva(s) a</label>
                    <select name="destino" id="destino" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
                        <option value="">-- Selecciona el RP --</option>
                        <?php echo opciones_rps($listas); ?>
                    </select>
                    <p class="mt-1 text-xs text-neutral-500">Las reservas, cierres y comisiones quedarán a nombre de ese RP.</p>
                </div>
            <?php endif; ?>
            <div class="flex gap-3">
                <button type="submit" class="py-2.5 px-6 rounded-xl text-sm font-semibold text-white bg-red-600 hover:bg-red-500 transition-colors">Eliminar RP</button>
                <a href="usuarios.php" class="btn-secondary py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Cancelar</a>
            </div>
        </form>
    <?php else: ?>
        <a href="usuarios.php" class="btn-secondary inline-block py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Volver a la lista</a>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
