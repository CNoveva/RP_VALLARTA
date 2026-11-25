<?php
// Restablecer contraseñas olvidadas. El gerente (solo cuentas de RP) o el admin identifican
// a la persona y generan una contraseña temporal; al entrar con ella, el sistema la obliga a cambiarla.
require_once '../includes/auth.php';
require_once '../includes/db.php';
check_role(['admin', 'gerente']);

$es_admin = ($_SESSION['rol'] === 'admin');
// El gerente solo puede restablecer cuentas de RP
$filtro_rol = $es_admin ? "" : " AND rol = 'rp'";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Sin 0/o ni 1/l/i para poder dictarla sin confusiones
function generar_password_temporal($largo = 8) {
    $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $largo; $i++) {
        $password .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $password;
}

$error = '';
$success = '';
$temporal = null; // ['nombre', 'usuario', 'password']: se muestra una sola vez
$falta_migracion = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $accion = $_POST['accion'] ?? '';
    $id_usuario = intval($_POST['id_usuario'] ?? 0);

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre_completo, usuario FROM usuarios WHERE id = :id AND activo = 1" . $filtro_rol);
            $stmt->execute(['id' => $id_usuario]);
            $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                $error = 'Usuario no encontrado o sin permiso para restablecerlo.';
            } elseif ($cuenta['id'] == $_SESSION['id']) {
                $error = 'Para tu propia cuenta usa "Cambiar contraseña".';
            } elseif ($accion == 'generar') {
                $password_temporal = generar_password_temporal();
                $stmt_update = $pdo->prepare("UPDATE usuarios SET password = :pass, debe_cambiar_password = 1, reset_solicitado_en = NULL WHERE id = :id");
                $stmt_update->execute([
                    'pass' => password_hash($password_temporal, PASSWORD_DEFAULT),
                    'id' => $cuenta['id']
                ]);
                $temporal = [
                    'nombre' => $cuenta['nombre_completo'],
                    'usuario' => $cuenta['usuario'],
                    'password' => $password_temporal
                ];
            } elseif ($accion == 'descartar') {
                $pdo->prepare("UPDATE usuarios SET reset_solicitado_en = NULL WHERE id = :id")->execute(['id' => $cuenta['id']]);
                $success = 'Solicitud de ' . htmlspecialchars($cuenta['nombre_completo']) . ' descartada. Su contraseña no cambió.';
            }
        } catch (PDOException $e) {
            $error = 'No se pudo completar la operación. Verifica que se haya ejecutado ajustes_2026-09-17.sql en la base de datos.';
        }
    }
}

// Solicitudes pendientes (de la más antigua a la más reciente)
$pendientes = [];
try {
    $stmt = $pdo->query("SELECT id, nombre_completo, usuario, rol, tipo_rp, reset_solicitado_en FROM usuarios
                         WHERE reset_solicitado_en IS NOT NULL AND activo = 1" . $filtro_rol . "
                         ORDER BY reset_solicitado_en ASC");
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $falta_migracion = true;
}

// Búsqueda directa (la persona llegó con el gerente sin haber enviado solicitud)
$buscar = trim($_GET['buscar'] ?? '');
$encontrados = [];
if ($buscar !== '') {
    $like = '%' . addcslashes($buscar, '%_\\') . '%';
    $stmt = $pdo->prepare("SELECT id, nombre_completo, usuario, rol, tipo_rp FROM usuarios
                           WHERE activo = 1" . $filtro_rol . " AND id <> :yo
                             AND (nombre_completo LIKE :b_nombre OR usuario LIKE :b_usuario)
                           ORDER BY nombre_completo ASC
                           LIMIT 25");
    $stmt->execute(['yo' => $_SESSION['id'], 'b_nombre' => $like, 'b_usuario' => $like]);
    $encontrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Botones de acción de una cuenta (generar temporal / descartar solicitud)
function formulario_restablecer($cuenta, $con_descartar) {
    $confirmar = '¿Ya verificaste que esta persona es ' . $cuenta['nombre_completo'] . '? Se generará una contraseña temporal y la anterior dejará de funcionar.';
    ?>
    <div class="flex items-center justify-end gap-4">
        <form action="restablecer.php" method="POST" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($confirmar), ENT_QUOTES); ?>)">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="accion" value="generar">
            <input type="hidden" name="id_usuario" value="<?php echo $cuenta['id']; ?>">
            <button type="submit" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">Generar contraseña temporal</button>
        </form>
        <?php if ($con_descartar): ?>
        <form action="restablecer.php" method="POST" onsubmit="return confirm('¿Descartar la solicitud? La contraseña del usuario no cambia.')">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="accion" value="descartar">
            <input type="hidden" name="id_usuario" value="<?php echo $cuenta['id']; ?>">
            <button type="submit" class="text-neutral-500 hover:text-neutral-300 text-xs transition-colors duration-200">Descartar</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

require_once '../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Restablecer Contraseñas</h1>
        <p class="mt-1 text-sm text-neutral-500">
            Antes de generar una contraseña temporal, verifica que la persona sea la dueña de la cuenta:
            que la conozcas en persona o que conteste una llamada al teléfono registrado. No la generes por mensaje a alguien que no puedas identificar.
            <?php if (!$es_admin): ?>Como Gerente solo puedes restablecer cuentas de RP; las demás las atiende el Administrador.<?php endif; ?>
        </p>
    </div>

    <?php if ($falta_migracion): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">Falta ejecutar <strong>ajustes_2026-09-17.sql</strong> en la base de datos para activar esta función.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?></div>
    <?php endif; ?>

    <?php if ($temporal): ?>
        <div class="glass-card rounded-2xl p-6 sm:p-8 mb-6 border-amber-500/40 text-center">
            <p class="text-sm text-neutral-400">Contraseña temporal de</p>
            <p class="text-lg font-bold text-white"><?php echo htmlspecialchars($temporal['nombre']); ?> <span class="text-neutral-500 font-normal">(usuario: <?php echo htmlspecialchars($temporal['usuario']); ?>)</span></p>
            <p class="my-5 text-4xl font-mono font-bold tracking-widest text-amber-400 select-all"><?php echo htmlspecialchars($temporal['password']); ?></p>
            <p class="text-xs text-neutral-500">Muéstrasela o díctasela a la persona. <strong class="text-neutral-300">Solo se muestra esta vez.</strong> Al entrar con ella, el sistema le pedirá elegir su nueva contraseña.</p>
        </div>
    <?php endif; ?>

    <!-- Solicitudes pendientes -->
    <h2 class="text-sm font-semibold text-neutral-400 uppercase tracking-wider mb-3">Solicitudes pendientes (<?php echo count($pendientes); ?>)</h2>
    <div class="glass-card rounded-2xl overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Nombre</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Teléfono / Usuario</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Rol</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Solicitó</th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-5"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($pendientes)): ?>
                        <tr><td colspan="5" class="px-3 py-8 text-sm text-neutral-500 text-center">No hay solicitudes pendientes.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pendientes as $cuenta): ?>
                        <tr>
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($cuenta['nombre_completo']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 font-mono"><?php echo htmlspecialchars($cuenta['usuario']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400"><?php echo htmlspecialchars(ucfirst($cuenta['rol']) . (!empty($cuenta['tipo_rp']) ? ' · ' . $cuenta['tipo_rp'] : '')); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($cuenta['reset_solicitado_en'])); ?></td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-5 text-sm"><?php formulario_restablecer($cuenta, true); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Búsqueda directa -->
    <h2 class="text-sm font-semibold text-neutral-400 uppercase tracking-wider mb-3">Buscar usuario</h2>
    <form method="GET" action="restablecer.php" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row items-end gap-3 mb-4">
        <div class="w-full sm:flex-1">
            <label for="buscar" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre o teléfono</label>
            <input type="search" name="buscar" id="buscar" value="<?php echo htmlspecialchars($buscar); ?>" placeholder="Si la persona no envió solicitud, búscala aquí" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white" required>
        </div>
        <button type="submit" class="btn-amber w-full sm:w-auto py-2 px-5 rounded-xl text-sm font-semibold text-white">Buscar</button>
    </form>

    <?php if ($buscar !== ''): ?>
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($encontrados)): ?>
                        <tr><td colspan="4" class="px-3 py-8 text-sm text-neutral-500 text-center">No se encontraron usuarios activos con "<?php echo htmlspecialchars($buscar); ?>".</td></tr>
                    <?php endif; ?>
                    <?php foreach ($encontrados as $cuenta): ?>
                        <tr>
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($cuenta['nombre_completo']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 font-mono"><?php echo htmlspecialchars($cuenta['usuario']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400"><?php echo htmlspecialchars(ucfirst($cuenta['rol']) . (!empty($cuenta['tipo_rp']) ? ' · ' . $cuenta['tipo_rp'] : '')); ?></td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-5 text-sm"><?php formulario_restablecer($cuenta, false); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
