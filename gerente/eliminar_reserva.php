<?php
// Eliminación de reservas finalizadas duplicadas (Gerente).
// Flujo: 1) se indica el folio de la reserva ORIGINAL, 2) se comparan ambas y solo si
// los totales de venta coinciden se habilita el borrado, 3) se borra la duplicada con
// sus cierres y se guarda una copia en reservas_eliminadas.
// Si el folio original es la MISMA reserva, se trata de un cierre capturado dos veces:
// se borra solo ese cierre (debe tener otro cierre idéntico en folio POS y venta).
require_once '../includes/header.php';
require_once '../includes/folio.php';
check_role(['gerente']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id_duplicada = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$id_cierre = intval($_GET['cierre'] ?? $_POST['cierre'] ?? 0);
$folio_original = trim($_GET['original'] ?? $_POST['original'] ?? '');

$error = '';
$success = '';

// Carga una reserva finalizada con sus cierres y el total de venta
function cargar_reserva($pdo, $id) {
    $stmt = $pdo->prepare("SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp
                           FROM reservas r JOIN usuarios u ON r.id_rp = u.id
                           WHERE r.id = :id");
    $stmt->execute(['id' => $id]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reserva) return null;

    $stmt = $pdo->prepare("SELECT * FROM cuentas_cerradas WHERE id_reserva = :id ORDER BY id ASC");
    $stmt->execute(['id' => $id]);
    $reserva['cierres'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reserva['total_venta'] = 0;
    $reserva['total_comision'] = 0;
    $reserva['total_propina'] = 0;
    foreach ($reserva['cierres'] as $c) {
        $reserva['total_venta'] += floatval($c['venta_total']);
        $reserva['total_comision'] += floatval($c['monto_comision']);
        $reserva['total_propina'] += floatval($c['propina']);
    }
    return $reserva;
}

$duplicada = $id_duplicada > 0 ? cargar_reserva($pdo, $id_duplicada) : null;
if (!$duplicada) {
    $error = 'Reserva no encontrada.';
} elseif ($duplicada['estado'] !== 'finalizada') {
    $error = 'Solo se pueden eliminar reservas finalizadas.';
}

// Cierre concreto desde el que se llegó (fila del reporte)
$cierre = null;
if ($duplicada && $id_cierre > 0) {
    foreach ($duplicada['cierres'] as $c) {
        if ($c['id'] == $id_cierre) $cierre = $c;
    }
}

// --- Comparación con la original ---
$original = null;
$modo = null;            // 'reserva' = borrar reserva completa; 'cierre' = borrar solo un cierre repetido
$coincide = false;
$sin_original = false;   // el folio original indicado no existe en el sistema
$motivo = '';
$cierre_gemelo = null;

if (!$error && $folio_original !== '') {
    $folio = parsear_folio($folio_original);
    if (!$folio || $folio['es_evento']) {
        $error = 'El folio original debe ser de reserva (VTA + número), ej. VTA355.';
    } elseif ($folio['id'] == $duplicada['id']) {
        // Mismo folio: cierre capturado dos veces
        $modo = 'cierre';
        $original = $duplicada;
        if (!$cierre) {
            $motivo = 'Para borrar un cierre repetido entra desde la fila del cierre en el reporte.';
        } else {
            foreach ($duplicada['cierres'] as $c) {
                if ($c['id'] != $cierre['id'] && $c['folio_pos'] === $cierre['folio_pos']
                    && floatval($c['venta_total']) == floatval($cierre['venta_total'])) {
                    $cierre_gemelo = $c;
                    break;
                }
            }
            if ($cierre_gemelo) {
                $coincide = true;
            } else {
                $motivo = 'Este cierre no tiene otro igual (mismo folio POS y misma venta) en la reserva ' . $folio['folio'] . '. No se puede borrar.';
            }
        }
    } else {
        $modo = 'reserva';
        $original = cargar_reserva($pdo, $folio['id']);
        if (!$original) {
            // La original no existe (quizá ya se borró): se permite eliminar, pero con confirmación explícita
            $sin_original = true;
            $coincide = true;
        } elseif ($original['estado'] !== 'finalizada') {
            $motivo = 'La reserva original ' . $folio['folio'] . " está en estado '{$original['estado']}', no finalizada.";
        } elseif (abs($original['total_venta'] - $duplicada['total_venta']) > 0.005) {
            $motivo = 'Los totales no coinciden: original $' . number_format($original['total_venta'], 2) . ' vs duplicada $' . number_format($duplicada['total_venta'], 2) . '. No se puede borrar.';
        } else {
            $coincide = true;
        }
    }
}

// --- Borrado ---
if (!$error && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirmar'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif (!$coincide) {
        $error = 'No se cumplen las condiciones para borrar.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($modo === 'cierre') {
                $pdo->prepare("INSERT INTO reservas_eliminadas (tipo, id_reserva, id_cierre, id_original, folio, venta_total, datos, eliminado_por, fecha_eliminacion)
                               VALUES ('cierre', :id_reserva, :id_cierre, :id_original, :folio, :venta, :datos, :por, NOW())")
                    ->execute([
                        'id_reserva' => $duplicada['id'], 'id_cierre' => $cierre['id'], 'id_original' => $cierre_gemelo['id'],
                        'folio' => $cierre['folio_cierre'], 'venta' => $cierre['venta_total'],
                        'datos' => json_encode($cierre, JSON_UNESCAPED_UNICODE), 'por' => $_SESSION['id']
                    ]);
                $pdo->prepare("DELETE FROM cuentas_cerradas WHERE id = :id")->execute(['id' => $cierre['id']]);
                $success = "Cierre {$cierre['folio_cierre']} (POS {$cierre['folio_pos']}, \$" . number_format($cierre['venta_total'], 2) . ") eliminado. Se conservó el cierre #{$cierre_gemelo['id']}.";
            } else {
                $pdo->prepare("INSERT INTO reservas_eliminadas (tipo, id_reserva, id_cierre, id_original, folio, venta_total, datos, eliminado_por, fecha_eliminacion)
                               VALUES ('reserva', :id_reserva, NULL, :id_original, :folio, :venta, :datos, :por, NOW())")
                    ->execute([
                        'id_reserva' => $duplicada['id'], 'id_original' => $sin_original ? $folio['id'] : $original['id'],
                        'folio' => 'VTA' . $duplicada['id'], 'venta' => $duplicada['total_venta'],
                        'datos' => json_encode($duplicada, JSON_UNESCAPED_UNICODE), 'por' => $_SESSION['id']
                    ]);
                $pdo->prepare("DELETE FROM cuentas_cerradas WHERE id_reserva = :id")->execute(['id' => $duplicada['id']]);
                $pdo->prepare("DELETE FROM reservas WHERE id = :id")->execute(['id' => $duplicada['id']]);
                $success = "Reserva VTA{$duplicada['id']} eliminada con sus " . count($duplicada['cierres']) . " cierre(s). "
                    . ($sin_original ? "Se indicó como original {$folio['folio']}, que no existe en el sistema." : "Se conservó VTA{$original['id']}.");
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'No se pudo eliminar. Verifica que se haya ejecutado ajustes_2026-10-15.sql en la base de datos. (' . htmlspecialchars($e->getMessage()) . ')';
        }
    }
}

function tarjeta_reserva($titulo, $r, $resaltar_cierre = 0) {
    ?>
    <div class="glass-card rounded-2xl p-5">
        <p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider mb-2"><?php echo $titulo; ?></p>
        <p class="text-lg font-bold text-white">VTA<?php echo $r['id']; ?> · <?php echo htmlspecialchars($r['nombre_cliente']); ?></p>
        <p class="text-sm text-neutral-400"><?php echo htmlspecialchars($r['nombre_rp']); ?> (<?php echo htmlspecialchars($r['tipo_rp'] ?? '-'); ?>) · <?php echo $r['num_personas']; ?> personas · <?php echo date('d/m/Y h:i A', strtotime($r['fecha_reserva'])); ?></p>
        <p class="text-sm text-neutral-400">Estado: <?php echo htmlspecialchars($r['estado']); ?> · Creada: <?php echo date('d/m/Y h:i A', strtotime($r['fecha_creacion'])); ?></p>
        <div class="overflow-x-auto">
        <table class="mt-3 w-full text-sm whitespace-nowrap">
            <thead><tr class="text-xs text-neutral-500 uppercase"><th class="text-left py-1">Cierre</th><th class="text-left px-2">POS</th><th class="text-left px-2">Mesero</th><th class="text-right px-2">Venta</th><th class="text-right px-2">Comisión</th><th class="text-right px-2">Propina</th><th class="text-right pl-2">Fecha</th></tr></thead>
            <tbody>
            <?php if (empty($r['cierres'])): ?>
                <tr><td colspan="7" class="py-2 text-neutral-500">Sin cierres en caja.</td></tr>
            <?php endif; ?>
            <?php foreach ($r['cierres'] as $c): ?>
                <tr class="<?php echo ($c['id'] == $resaltar_cierre) ? 'text-red-300' : 'text-neutral-300'; ?>">
                    <td class="py-1 font-mono"><?php echo htmlspecialchars($c['folio_cierre']); ?> <span class="text-neutral-600">#<?php echo $c['id']; ?></span></td>
                    <td class="font-mono px-2"><?php echo htmlspecialchars($c['folio_pos']); ?></td>
                    <td class="px-2"><?php echo htmlspecialchars($c['mesero_asignado'] ?? '-'); ?></td>
                    <td class="text-right px-2">$<?php echo number_format($c['venta_total'], 2); ?></td>
                    <td class="text-right px-2 text-emerald-400">$<?php echo number_format($c['monto_comision'], 2); ?> <span class="text-neutral-500 text-xs"><?php echo number_format($c['porcentaje_comision'], 0); ?>%</span></td>
                    <td class="text-right px-2">$<?php echo number_format($c['propina'], 2); ?></td>
                    <td class="text-right pl-2 text-xs"><?php echo date('d/m/Y H:i', strtotime($c['fecha_cierre'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="mt-3 flex flex-wrap justify-end gap-x-5 gap-y-1 text-sm">
            <span class="text-neutral-400">Comisión: <span class="font-semibold text-emerald-400">$<?php echo number_format($r['total_comision'], 2); ?></span></span>
            <span class="text-neutral-400">Propina: <span class="font-semibold text-white">$<?php echo number_format($r['total_propina'], 2); ?></span></span>
            <span class="text-base font-bold text-white">Total venta: $<?php echo number_format($r['total_venta'], 2); ?></span>
        </div>
    </div>
    <?php
}
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Eliminar reserva duplicada</h1>
        <p class="mt-1 text-sm text-neutral-500">Solo para reservas finalizadas capturadas dos veces. Indica el folio de la original; se borra la duplicada únicamente si los totales de venta coinciden. Lo borrado queda guardado en la bitácora.</p>
    </div>

    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?> <a href="index.php?estado=finalizada" class="font-bold underline">Volver al reporte</a></div>
    <?php endif; ?>

    <?php if ($duplicada && !$success && $duplicada['estado'] === 'finalizada'): ?>

        <?php if ($modo === 'cierre'): ?>
            <div class="mb-6"><?php tarjeta_reserva('Reserva (cierre en rojo = el que se borraría)', $duplicada, $cierre ? $cierre['id'] : 0); ?></div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <?php tarjeta_reserva('Duplicada (se borraría)', $duplicada); ?>
                <?php if ($original): tarjeta_reserva('Original (se conserva)', $original); elseif ($sin_original): ?>
                    <div class="glass-card rounded-2xl p-5 border-amber-500/40 flex flex-col items-center justify-center text-center">
                        <svg class="w-10 h-10 text-amber-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>
                        <p class="text-base font-bold text-amber-300">NO SE ENCONTRÓ LA RESERVA ORIGINAL</p>
                        <p class="mt-1 text-sm text-neutral-400">No existe ninguna reserva con el folio <?php echo htmlspecialchars($folio['folio']); ?>. Puedes eliminar la duplicada de todas formas, pero no habrá comparación de totales.</p>
                    </div>
                <?php else: ?>
                    <div class="glass-card rounded-2xl p-5 flex items-center justify-center text-sm text-neutral-500">Indica el folio de la reserva original para compararlas.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="eliminar_reserva.php" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row items-end gap-3 mb-6">
            <input type="hidden" name="id" value="<?php echo $duplicada['id']; ?>">
            <input type="hidden" name="cierre" value="<?php echo $id_cierre; ?>">
            <div class="w-full sm:flex-1">
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Folio de la reserva original</label>
                <input type="text" name="original" value="<?php echo htmlspecialchars($folio_original); ?>" placeholder="Ej. VTA355 (o VTA<?php echo $duplicada['id']; ?> si es el mismo cierre repetido)" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white uppercase" required>
            </div>
            <button type="submit" class="btn-secondary w-full sm:w-auto py-2 px-5 rounded-xl text-sm font-semibold text-white">Comparar</button>
        </form>

        <?php if ($motivo): ?>
            <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $motivo; ?></div>
        <?php endif; ?>

        <?php if ($coincide): ?>
            <div class="rounded-xl px-4 py-3 mb-5 text-sm <?php echo $sin_original ? 'bg-amber-500/10 border border-amber-500/30 text-amber-300' : 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-300'; ?>">
                <?php if ($sin_original): ?>
                    NO SE ENCONTRÓ LA RESERVA ORIGINAL (<?php echo htmlspecialchars($folio['folio']); ?>). Si eliminas, se borrará VTA<?php echo $duplicada['id']; ?> con sus <?php echo count($duplicada['cierres']); ?> cierre(s) por $<?php echo number_format($duplicada['total_venta'], 2); ?> sin verificar totales.
                <?php elseif ($modo === 'cierre'): ?>
                    El cierre #<?php echo $cierre['id']; ?> es idéntico al #<?php echo $cierre_gemelo['id']; ?> (POS <?php echo htmlspecialchars($cierre['folio_pos']); ?>, $<?php echo number_format($cierre['venta_total'], 2); ?>). Se puede borrar.
                <?php else: ?>
                    Los totales coinciden ($<?php echo number_format($original['total_venta'], 2); ?>). Se borrará VTA<?php echo $duplicada['id']; ?> con sus cierres y se conservará VTA<?php echo $original['id']; ?>.
                <?php endif; ?>
            </div>
            <form method="POST" action="eliminar_reserva.php" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($sin_original ? 'NO SE ENCONTRÓ LA RESERVA ORIGINAL, ¿DESEA ELIMINAR DE TODAS FORMAS?' : 'Esta acción no se puede deshacer desde el sistema. ¿Eliminar?'), ENT_QUOTES); ?>)">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="id" value="<?php echo $duplicada['id']; ?>">
                <input type="hidden" name="cierre" value="<?php echo $id_cierre; ?>">
                <input type="hidden" name="original" value="<?php echo htmlspecialchars($folio_original); ?>">
                <input type="hidden" name="confirmar" value="1">
                <div class="flex gap-3">
                    <button type="submit" class="py-2.5 px-6 rounded-xl text-sm font-semibold text-white bg-red-600 hover:bg-red-500 transition-colors">
                        <?php echo $modo === 'cierre' ? 'Eliminar cierre repetido' : ($sin_original ? 'Eliminar de todas formas' : 'Eliminar reserva duplicada'); ?>
                    </button>
                    <a href="index.php?estado=finalizada" class="btn-secondary py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Cancelar</a>
                </div>
            </form>
        <?php else: ?>
            <a href="index.php?estado=finalizada" class="btn-secondary inline-block py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Volver al reporte</a>
        <?php endif; ?>

    <?php elseif (!$success): ?>
        <a href="index.php" class="btn-secondary inline-block py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Volver al reporte</a>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
