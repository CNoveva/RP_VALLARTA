<?php
// Pago de comisiones a RPs (caja). Una fila por cierre de caja: cada cierre se marca
// como pagado por separado, con la fecha/hora y quién lo registró.
// Devolución: cuando el RP regresa una comisión ya pagada, se registra con motivo.
// Una devolución registrada solo la puede revertir el gerente (gerente/index.php).
require_once '../includes/header.php';
require_once '../includes/turno.php';
check_role(['caja']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$falta_migracion = false;

// --- Acciones sobre un cierre: pagar, desmarcar, devolver (revertir una devolución es solo del gerente) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $accion = $_POST['accion'] ?? '';
    $id_cierre = intval($_POST['id_cierre'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif ($id_cierre <= 0) {
        $error = 'Cierre no válido.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT folio_cierre, comision_pagada, comision_devuelta FROM cuentas_cerradas WHERE id = :id");
            $stmt->execute(['id' => $id_cierre]);
            $cierre = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cierre) {
                $error = 'Cierre no encontrado.';
            } else {
                $folio = $cierre['folio_cierre'];
                if ($accion === 'pagar') {
                    $stmt = $pdo->prepare("UPDATE cuentas_cerradas SET comision_pagada = 1, fecha_pago_comision = NOW(), pagado_por = :por
                                           WHERE id = :id AND comision_pagada = 0");
                    $stmt->execute(['por' => $_SESSION['id'], 'id' => $id_cierre]);
                    $success = $stmt->rowCount() > 0 ? "Comisión del cierre $folio marcada como pagada." : "El cierre $folio ya estaba marcado como pagado.";
                } elseif ($accion === 'desmarcar') {
                    if ($cierre['comision_devuelta']) {
                        $error = "El cierre $folio tiene una devolución registrada; solo el gerente puede revertirla.";
                    } else {
                        $pdo->prepare("UPDATE cuentas_cerradas SET comision_pagada = 0, fecha_pago_comision = NULL, pagado_por = NULL WHERE id = :id")
                            ->execute(['id' => $id_cierre]);
                        $success = "El cierre $folio regresó a pendiente de pago.";
                    }
                } elseif ($accion === 'devolver') {
                    if (!$cierre['comision_pagada']) {
                        $error = "El cierre $folio no se ha pagado; no hay comisión que devolver.";
                    } elseif ($motivo === '') {
                        $error = 'Escribe el motivo de la devolución.';
                    } else {
                        $pdo->prepare("UPDATE cuentas_cerradas SET comision_devuelta = 1, fecha_devolucion = NOW(), devuelto_por = :por, motivo_devolucion = :motivo
                                       WHERE id = :id AND comision_pagada = 1")
                            ->execute(['por' => $_SESSION['id'], 'motivo' => function_exists('mb_substr') ? mb_substr($motivo, 0, 200) : substr($motivo, 0, 200), 'id' => $id_cierre]);
                        $success = "Devolución registrada para el cierre $folio.";
                    }
                } elseif ($accion === 'cancelar_devolucion') {
                    // Caja no puede deshacer una devolución: lo hace el gerente desde Reportes.
                    $error = "Solo el gerente puede revertir la devolución del cierre $folio.";
                } else {
                    $error = 'Acción no válida.';
                }
            }
        } catch (PDOException $e) {
            $falta_migracion = true;
        }
    }
}

// --- Filtros ---
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$busqueda = trim($_GET['q'] ?? '');
$vista = $_GET['vista'] ?? 'pendientes';
if (!in_array($vista, ['pendientes', 'pagadas', 'devueltas', 'todas'])) $vista = 'pendientes';

$where = ["r.estado = 'finalizada'"];
$params = [];
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $where[] = "r.fecha_reserva >= :ini AND r.fecha_reserva < :fin";
    $params['ini'] = turno_inicio($fecha_inicio);
    $params['fin'] = turno_fin($fecha_fin);
}
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $where[] = "(r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR cc.folio_cierre LIKE :q_folio OR cc.folio_pos LIKE :q_pos)";
    $params['q_cliente'] = $like;
    $params['q_rp'] = $like;
    $params['q_folio'] = $like;
    $params['q_pos'] = $like;
}
if ($vista === 'pendientes') $where[] = "cc.comision_pagada = 0";
elseif ($vista === 'pagadas') $where[] = "cc.comision_pagada = 1 AND cc.comision_devuelta = 0";
elseif ($vista === 'devueltas') $where[] = "cc.comision_devuelta = 1";

$filas = [];
$totales = ['pendiente' => 0, 'pagado' => 0, 'devuelto' => 0];
try {
    $stmt = $pdo->prepare("SELECT cc.id, cc.folio_cierre, cc.folio_pos, cc.mesero_asignado, cc.venta_total, cc.porcentaje_comision, cc.monto_comision, cc.fecha_cierre,
                cc.comision_pagada, cc.fecha_pago_comision, cc.comision_devuelta, cc.fecha_devolucion, cc.motivo_devolucion,
                r.id AS id_reserva, r.nombre_cliente, r.fecha_reserva, r.num_personas,
                u.nombre_completo AS nombre_rp, u.tipo_rp,
                (SELECT nombre_completo FROM usuarios WHERE id = cc.pagado_por) AS pagado_por,
                (SELECT nombre_completo FROM usuarios WHERE id = cc.devuelto_por) AS devuelto_por
            FROM cuentas_cerradas cc
            JOIN reservas r ON cc.id_reserva = r.id
            JOIN usuarios u ON r.id_rp = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY cc.comision_pagada ASC, cc.fecha_cierre DESC
            LIMIT 300");
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $f) {
        $monto = floatval($f['monto_comision']);
        if ($f['comision_devuelta']) $totales['devuelto'] += $monto;
        elseif ($f['comision_pagada']) $totales['pagado'] += $monto;
        else $totales['pendiente'] += $monto;
    }
} catch (PDOException $e) {
    $falta_migracion = true;
}
$url_filtros = http_build_query(['fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin, 'vista' => $vista, 'q' => $busqueda]);
?>

<div>
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Pago de comisiones</h1>
        <p class="mt-1 text-sm text-neutral-500">Una fila por cierre de caja. Marca cada cierre cuando le entregues su comisión al RP; si después la regresa, registra la devolución con su motivo. Una devolución registrada ya no se puede deshacer desde caja: solo el gerente puede revertirla.</p>
    </div>

    <?php if ($falta_migracion): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">Falta ejecutar en la base de datos <strong>ajustes_2026-10-27.sql</strong> (pago de comisiones) y <strong>ajustes_2026-11-10.sql</strong> (devoluciones).</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?></div><?php endif; ?>

    <form method="GET" action="comisiones.php" class="glass-card rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end mb-6">
        <div class="lg:col-span-2">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Buscar</label>
            <input type="search" name="q" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="RP, cliente, folio de cierre o POS" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Mostrar</label>
            <select name="vista" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white" onchange="this.form.submit()">
                <?php foreach (['pendientes' => 'Pendientes de pago', 'pagadas' => 'Pagadas', 'devueltas' => 'Devueltas', 'todas' => 'Todas'] as $v => $et): ?>
                    <option value="<?php echo $v; ?>" <?php echo $vista == $v ? 'selected' : ''; ?>><?php echo $et; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Turno inicio</label>
            <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Turno fin</label>
            <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-amber flex-1 py-2 px-4 rounded-xl text-sm font-semibold text-white">Filtrar</button>
            <a href="comisiones.php" class="btn-secondary flex-1 py-2 px-4 rounded-xl text-sm font-medium text-neutral-300 text-center">Limpiar</a>
        </div>
    </form>

    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Pendiente de pagar</p><p class="text-lg font-bold text-amber-400">$<?php echo number_format($totales['pendiente'], 2); ?></p></div>
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Pagado</p><p class="text-lg font-bold text-emerald-400">$<?php echo number_format($totales['pagado'], 2); ?></p></div>
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Devuelto</p><p class="text-lg font-bold text-red-400">$<?php echo number_format($totales['devuelto'], 2); ?></p></div>
    </div>
    <p class="mb-3 text-xs text-neutral-600">Los totales corresponden a lo que se muestra con los filtros actuales.</p>

    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium table-fit">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cierre</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">POS</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">RP / Tipo</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cerrada</th>
                        <th class="px-3 py-3.5 text-right text-xs font-semibold text-neutral-400 uppercase tracking-wider">Venta</th>
                        <th class="px-3 py-3.5 text-right text-xs font-semibold text-neutral-400 uppercase tracking-wider">Comisión</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Pago</th>
                        <th class="py-3.5 pl-3 pr-5"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($filas)): ?>
                        <tr><td colspan="9" class="px-3 py-10 text-sm text-neutral-500 text-center">No hay cierres que mostrar con esos filtros.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($filas as $f): $pendiente = empty($f['comision_pagada']); $devuelta = !empty($f['comision_devuelta']); ?>
                        <tr class="<?php echo $pendiente ? '' : 'opacity-80'; ?>">
                            <td class="py-3.5 pl-5 pr-3 text-sm font-medium text-white font-mono"><?php echo htmlspecialchars($f['folio_cierre']); ?> <span class="text-xs text-neutral-600 whitespace-nowrap">#<?php echo $f['id']; ?></span></td>
                            <td class="px-3 py-3.5 text-sm text-neutral-300 font-mono"><?php echo htmlspecialchars($f['folio_pos']); ?></td>
                            <td class="px-3 py-3.5 text-sm"><span class="font-semibold text-white"><?php echo htmlspecialchars($f['nombre_rp']); ?></span><span class="block text-xs text-neutral-500"><?php echo htmlspecialchars($f['tipo_rp'] ?? '-'); ?></span></td>
                            <td class="px-3 py-3.5 text-sm text-neutral-300"><?php echo htmlspecialchars($f['nombre_cliente']); ?> <span class="text-neutral-500 whitespace-nowrap">(<?php echo $f['num_personas']; ?>p)</span><span class="block text-xs text-neutral-600 whitespace-nowrap">VTA<?php echo $f['id_reserva']; ?> · <?php echo date('d/m/y', strtotime($f['fecha_reserva'])); ?></span></td>
                            <td class="px-3 py-3.5 text-sm text-neutral-300"><span class="whitespace-nowrap"><?php echo date('d/m/y', strtotime($f['fecha_cierre'])); ?></span> <span class="whitespace-nowrap text-neutral-500"><?php echo date('h:i A', strtotime($f['fecha_cierre'])); ?></span></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-right text-white">$<?php echo number_format($f['venta_total'], 2); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-right"><span class="font-semibold <?php echo $devuelta ? 'text-red-400 line-through' : 'text-emerald-400'; ?>">$<?php echo number_format($f['monto_comision'], 2); ?></span> <span class="text-xs text-neutral-500"><?php echo number_format($f['porcentaje_comision'], 0); ?>%</span></td>
                            <td class="px-3 py-3.5 text-sm">
                                <?php if ($devuelta): ?>
                                    <span class="inline-flex rounded-lg bg-red-500/15 px-2.5 py-1 text-xs font-semibold text-red-400">Devuelta</span>
                                    <span class="block mt-1 text-xs text-neutral-500"><span class="whitespace-nowrap"><?php echo date('d/m/y h:i A', strtotime($f['fecha_devolucion'])); ?></span><?php if (!empty($f['devuelto_por'])): ?> · <?php echo htmlspecialchars($f['devuelto_por']); ?><?php endif; ?></span>
                                    <span class="block text-xs text-neutral-400">Motivo: <?php echo htmlspecialchars($f['motivo_devolucion'] ?? ''); ?></span>
                                    <span class="block text-xs text-neutral-600 whitespace-nowrap">Pagada el <?php echo date('d/m/y', strtotime($f['fecha_pago_comision'])); ?></span>
                                <?php elseif ($pendiente): ?>
                                    <span class="inline-flex rounded-lg bg-amber-500/15 px-2.5 py-1 text-xs font-semibold text-amber-400">Pendiente</span>
                                <?php else: ?>
                                    <span class="inline-flex rounded-lg bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-400">Pagada</span>
                                    <span class="block mt-1 text-xs text-neutral-500"><span class="whitespace-nowrap"><?php echo date('d/m/y h:i A', strtotime($f['fecha_pago_comision'])); ?></span><?php if (!empty($f['pagado_por'])): ?> · <?php echo htmlspecialchars($f['pagado_por']); ?><?php endif; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 pl-3 pr-5 text-right text-sm">
                                <form method="POST" action="comisiones.php?<?php echo htmlspecialchars($url_filtros); ?>" id="acc-<?php echo $f['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="id_cierre" value="<?php echo $f['id']; ?>">
                                    <?php if ($devuelta): ?>
                                        <span class="block text-xs text-neutral-600 leading-snug" title="Pídele al gerente que la revierta desde Reportes">Solo el gerente<br>puede revertirla</span>
                                    <?php elseif ($pendiente): ?>
                                        <button type="submit" name="accion" value="pagar" onclick="return confirm(<?php echo htmlspecialchars(json_encode("¿Confirmas que ya se pagó la comisión del cierre {$f['folio_cierre']} (\$" . number_format($f['monto_comision'], 2) . ") a {$f['nombre_rp']}?"), ENT_QUOTES); ?>)" class="text-amber-500 hover:text-amber-400 font-semibold whitespace-nowrap transition-colors duration-200">Marcar pagada</button>
                                    <?php else: ?>
                                        <div class="flex flex-col items-end space-y-1.5">
                                            <button type="button" onclick="document.getElementById('dev-<?php echo $f['id']; ?>').classList.toggle('hidden')" class="text-red-400 hover:text-red-300 font-semibold whitespace-nowrap transition-colors duration-200">Registrar devolución</button>
                                            <div id="dev-<?php echo $f['id']; ?>" class="hidden flex flex-col items-end gap-1.5">
                                                <input type="text" name="motivo" placeholder="Motivo" maxlength="200" class="input-premium rounded-lg py-1 px-2 text-xs text-white w-36">
                                                <button type="submit" name="accion" value="devolver" onclick="return this.form.motivo.value.trim() !== '' && confirm(<?php echo htmlspecialchars(json_encode("¿Registrar que {$f['nombre_rp']} devolvió la comisión del cierre {$f['folio_cierre']} (\$" . number_format($f['monto_comision'], 2) . ")? Después solo el gerente podrá revertirlo."), ENT_QUOTES); ?>)" class="btn-amber rounded-lg px-2.5 py-1 text-xs font-semibold text-white">Guardar</button>
                                            </div>
                                            <button type="submit" name="accion" value="desmarcar" onclick="return confirm('¿Regresar el cierre <?php echo htmlspecialchars($f['folio_cierre']); ?> a pendiente de pago?')" class="text-neutral-500 hover:text-neutral-300 text-xs transition-colors duration-200">Desmarcar</button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (count($filas) >= 300): ?><p class="mt-3 text-xs text-neutral-600">Se muestran los 300 más recientes; usa los filtros para acotar.</p><?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
