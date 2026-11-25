<?php
// El gerente registra reservas a nombre de RPs que no tienen usuario en el sistema
// (o de cualquier RP). Los RPs sin usuario se crean aquí mismo y quedan guardados
// para elegirlos después de la lista.
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
require_once '../includes/rps.php';
// Hostess y coordinador entran por hostes/crear_reserva.php y coordinador/crear_reserva.php, que incluyen este archivo
check_role(['gerente', 'hostes', 'coordinador']);
$es_hostes = ($_SESSION['rol'] === 'hostes');
$es_coordinador = ($_SESSION['rol'] === 'coordinador');
// El coordinador solo registra reservas por venir; el estado real lo ponen hostess y caja
$puede_elegir_estado = !$es_coordinador;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$falta_migracion = false;
$estados = ['nueva' => 'Nueva (hostess confirmará la llegada)', 'iniciada' => 'Iniciada', 'finalizada' => 'Finalizada', 'inasistente' => 'Inasistente', 'cancelada' => 'Cancelada'];

// Valores del formulario (se conservan si hay error)
$v = [
    'id_rp' => $_POST['id_rp'] ?? '',
    'nuevo_nombre' => trim($_POST['nuevo_nombre'] ?? ''),
    'nuevo_tipo' => trim($_POST['nuevo_tipo'] ?? ''),
    'nombre_cliente' => trim($_POST['nombre_cliente'] ?? ''),
    'num_personas' => $_POST['num_personas'] ?? '2',
    'fecha_reserva' => $_POST['fecha_reserva'] ?? date('Y-m-d\T22:00'),
    'estado' => $puede_elegir_estado ? ($_POST['estado'] ?? 'nueva') : 'nueva',
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $num_personas = intval($v['num_personas']);
    $fecha = DateTime::createFromFormat('Y-m-d\TH:i', $v['fecha_reserva']);

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif ($v['id_rp'] === '') {
        $error = 'Selecciona el RP o registra uno nuevo.';
    } elseif ($v['id_rp'] === 'nuevo' && $v['nuevo_nombre'] === '') {
        $error = 'Escribe el nombre del nuevo RP.';
    } elseif ($v['id_rp'] === 'nuevo' && !in_array($v['nuevo_tipo'], $TIPOS_RP, true)) {
        $error = 'Selecciona el tipo del nuevo RP.';
    } elseif ($v['nombre_cliente'] === '') {
        $error = 'Escribe el nombre del cliente.';
    } elseif ($num_personas < 1 || $num_personas > 500) {
        $error = 'Indica un número de personas válido (1 a 500).';
    } elseif (!$fecha) {
        $error = 'Indica la fecha y hora de la reserva.';
    } elseif (!isset($estados[$v['estado']])) {
        $error = 'Estado no válido.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($v['id_rp'] === 'nuevo') {
                $id_rp = crear_rp_sin_usuario($pdo, $v['nuevo_nombre'], $v['nuevo_tipo']);
                $nombre_rp = $v['nuevo_nombre'];
            } else {
                $stmt = $pdo->prepare("SELECT id, nombre_completo FROM usuarios WHERE id = :id AND rol = 'rp'");
                $stmt->execute(['id' => intval($v['id_rp'])]);
                $rp = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rp) throw new RuntimeException('El RP seleccionado no existe.');
                $id_rp = intval($rp['id']);
                $nombre_rp = $rp['nombre_completo'];
            }

            // Reservas capturadas como ya atendidas llevan hora de llegada para que cuenten en el aforo
            $fecha_llegada = in_array($v['estado'], ['iniciada', 'finalizada']) ? $fecha->format('Y-m-d H:i:s') : null;
            // creada_por: quién la registró (gerente, hostess o coordinador)
            $stmt = $pdo->prepare("INSERT INTO reservas (id_rp, nombre_cliente, num_personas, fecha_reserva, estado, fecha_llegada, creada_por)
                                   VALUES (:id_rp, :nombre, :personas, :fecha, :estado, :llegada, :creada_por)");
            $stmt->execute([
                'id_rp' => $id_rp, 'nombre' => $v['nombre_cliente'], 'personas' => $num_personas,
                'fecha' => $fecha->format('Y-m-d H:i:s'), 'estado' => $v['estado'], 'llegada' => $fecha_llegada,
                'creada_por' => $_SESSION['id'],
            ]);
            $id_reserva = $pdo->lastInsertId();
            $pdo->commit();

            $success = "Reserva VTA$id_reserva creada para " . htmlspecialchars($v['nombre_cliente']) . " a nombre de " . htmlspecialchars($nombre_rp)
                . ($v['id_rp'] === 'nuevo' ? ' (RP nuevo, ya queda en la lista).' : '.');
            // Se conserva el RP para registrar varias reservas seguidas
            $v = ['id_rp' => $id_rp, 'nuevo_nombre' => '', 'nuevo_tipo' => '', 'nombre_cliente' => '', 'num_personas' => '2', 'fecha_reserva' => $v['fecha_reserva'], 'estado' => $v['estado']];
        } catch (PDOException $e) {
            $pdo->rollBack();
            $falta_migracion = (stripos($e->getMessage(), 'sin_acceso') !== false || stripos($e->getMessage(), 'creada_por') !== false);
            $error = $falta_migracion ? 'Falta ejecutar en la base de datos: ' . (stripos($e->getMessage(), 'creada_por') !== false ? 'ajustes_2026-11-09.sql' : 'ajustes_2026-10-28.sql') . '.' : 'No se pudo guardar: ' . htmlspecialchars($e->getMessage());
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

try {
    $listas = lista_rps($pdo);
} catch (PDOException $e) {
    $listas = ['sin_usuario' => [], 'con_usuario' => []];
    $falta_migracion = true;
}
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Nueva reserva</h1>
        <p class="mt-1 text-sm text-neutral-500">Para RPs que no usan el sistema. El RP queda guardado y después solo lo eliges de la lista. También puedes capturar reservas de fechas pasadas con su estado real.</p>
    </div>

    <?php if ($falta_migracion): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">Falta ejecutar <strong>ajustes_2026-10-28.sql</strong> en la base de datos para activar los RPs sin usuario.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?> <a href="index.php" class="font-bold underline"><?php echo $es_hostes ? 'Ver reservas' : ($es_coordinador ? 'Ver mis registros' : 'Ver reporte'); ?></a></div><?php endif; ?>

    <form method="POST" action="crear_reserva.php" class="glass-card rounded-2xl p-6 sm:p-8 space-y-4" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div>
            <label for="id_rp" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">RP</label>
            <select name="id_rp" id="id_rp" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required onchange="mostrarNuevoRp()">
                <option value="">-- Selecciona --</option>
                <option value="nuevo" <?php echo $v['id_rp'] === 'nuevo' ? 'selected' : ''; ?>>+ Nuevo RP sin usuario</option>
                <?php echo opciones_rps($listas, $v['id_rp']); ?>
            </select>
        </div>

        <div id="nuevo_rp" class="hidden rounded-xl bg-neutral-900/60 p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="nuevo_nombre" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del RP</label>
                <input type="text" name="nuevo_nombre" id="nuevo_nombre" value="<?php echo htmlspecialchars($v['nuevo_nombre']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white">
            </div>
            <div>
                <label for="nuevo_tipo" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Tipo de RP</label>
                <select name="nuevo_tipo" id="nuevo_tipo" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white">
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($TIPOS_RP as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $v['nuevo_tipo'] === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="sm:col-span-2 text-xs text-neutral-500">Este RP no podrá entrar al sistema. Si después quiere cuenta, el administrador le asigna teléfono y contraseña desde Usuarios y conserva su historial.</p>
        </div>

        <div>
            <label for="nombre_cliente" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del cliente</label>
            <input type="text" name="nombre_cliente" id="nombre_cliente" value="<?php echo htmlspecialchars($v['nombre_cliente']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="num_personas" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Personas</label>
                <input type="number" name="num_personas" id="num_personas" min="1" max="500" value="<?php echo htmlspecialchars($v['num_personas']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="fecha_reserva" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha y hora</label>
                <input type="datetime-local" name="fecha_reserva" id="fecha_reserva" value="<?php echo htmlspecialchars($v['fecha_reserva']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
        </div>

        <?php if ($puede_elegir_estado): ?>
        <div>
            <label for="estado" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Estado</label>
            <select name="estado" id="estado" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white">
                <?php foreach ($estados as $valor => $etiqueta): ?>
                    <option value="<?php echo $valor; ?>" <?php echo $v['estado'] === $valor ? 'selected' : ''; ?>><?php echo $etiqueta; ?></option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-neutral-500">Deja "Nueva" para reservas por venir. Para capturar una reserva que ya pasó, elige el estado que tuvo.</p>
        </div>
        <?php else: ?>
        <p class="text-xs text-neutral-500">La reserva queda como <span class="text-blue-400 font-semibold">Nueva</span>; hostess confirmará la llegada y caja la cerrará.</p>
        <?php endif; ?>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn-amber flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-white" <?php echo $falta_migracion ? 'disabled' : ''; ?>>Guardar reserva</button>
            <a href="index.php" class="btn-secondary py-2.5 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">Volver</a>
        </div>
    </form>
</div>

<script>
function mostrarNuevoRp() {
    const esNuevo = document.getElementById('id_rp').value === 'nuevo';
    document.getElementById('nuevo_rp').classList.toggle('hidden', !esNuevo);
    document.getElementById('nuevo_nombre').required = esNuevo;
    document.getElementById('nuevo_tipo').required = esNuevo;
}
mostrarNuevoRp();
</script>

<?php require_once '../includes/footer.php'; ?>
