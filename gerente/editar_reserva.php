<?php
require_once '../includes/header.php';
require_once '../includes/rps.php';
require_once '../includes/tipos_rp.php';
require_once '../includes/folio.php';
check_role(['gerente']); // Solo Gerente puede editar todo

$error = '';
$success = '';
$id_reserva = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_reserva <= 0) {
    header('Location: index.php');
    exit;
}

// --- Cierres de caja: el gerente puede corregir el % de comisión y el folio POS ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['accion'] ?? '') === 'guardar_cierres') {
    try {
        $pdo->beginTransaction();
        $stmt_cierres = $pdo->prepare("SELECT id, venta_total FROM cuentas_cerradas WHERE id_reserva = :id FOR UPDATE");
        $stmt_cierres->execute(['id' => $id_reserva]);
        $stmt_upd = $pdo->prepare("UPDATE cuentas_cerradas SET porcentaje_comision = :pct, monto_comision = :monto, propina = :propina, folio_pos = :pos, folio_cierre = :folio, id_reserva = :id_reserva WHERE id = :id");
        $stmt_destino = $pdo->prepare("SELECT id, estado FROM reservas WHERE id = :id");
        $cambios = 0;
        $movidos = [];
        foreach ($stmt_cierres->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (!isset($_POST['pct'][$c['id']])) continue;
            $pct = floatval($_POST['pct'][$c['id']]);
            $pos = trim($_POST['pos'][$c['id']] ?? '');
            if ($pct < 0 || $pct > 100) throw new RuntimeException('El porcentaje debe estar entre 0 y 100.');
            if ($pos === '') throw new RuntimeException('El folio POS no puede quedar vacío.');

            // Folio VTA del cierre: si cambia el número, el cierre pasa a esa reserva (caja lo cerró con el folio equivocado)
            $folio = parsear_folio($_POST['folio'][$c['id']] ?? '');
            if (!$folio || $folio['es_evento']) throw new RuntimeException('El folio del cierre debe ser VTA + número de reserva (y sufijo opcional), ej. VTA355 o VTA355B.');
            $destino = $id_reserva;
            if ($folio['id'] != $id_reserva) {
                $stmt_destino->execute(['id' => $folio['id']]);
                $r_destino = $stmt_destino->fetch(PDO::FETCH_ASSOC);
                if (!$r_destino) throw new RuntimeException("No existe la reserva {$folio['folio']} para mover el cierre.");
                if (!in_array($r_destino['estado'], ['iniciada', 'finalizada'])) throw new RuntimeException("La reserva VTA{$folio['id']} está en estado '{$r_destino['estado']}'; solo se puede mover un cierre a una reserva iniciada o finalizada.");
                $destino = intval($folio['id']);
                $movidos[] = $folio['folio'];
            }

            // Mismo cálculo que caja: comisión = venta × %, propina 5% solo si hay comisión
            $venta = floatval($c['venta_total']);
            $stmt_upd->execute([
                'pct' => $pct, 'monto' => round($venta * $pct / 100, 2), 'propina' => $pct > 0 ? round($venta * 0.05, 2) : 0,
                'pos' => $pos, 'folio' => $folio['folio'], 'id_reserva' => $destino, 'id' => $c['id'],
            ]);
            $cambios++;
        }
        $pdo->commit();
        $success = "Cierres actualizados ($cambios). La comisión y la propina se recalcularon con el porcentaje indicado."
            . ($movidos ? ' Se movieron a otra reserva: ' . implode(', ', $movidos) . '.' : '');
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Error al actualizar los cierres: ' . htmlspecialchars($e->getMessage());
    }
}

// Lógica de actualización (Gerente)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['accion'] ?? '') === '') {
    $nombre_cliente = trim($_POST['nombre_cliente']);
    $num_personas = intval($_POST['num_personas']);
    $fecha_reserva_str = trim($_POST['fecha_reserva']);
    $estado = trim($_POST['estado']);
    $nuevo_id_rp = intval($_POST['id_rp'] ?? 0);
    $rp_nuevo = (($_POST['id_rp'] ?? '') === 'nuevo');
    $nuevo_nombre = trim($_POST['nuevo_nombre'] ?? '');
    $nuevo_tipo = trim($_POST['nuevo_tipo'] ?? '');

    try {
        if ($rp_nuevo) {
            // Alta de un RP sin usuario desde aquí mismo (queda en la lista para después)
            if ($nuevo_nombre === '') throw new RuntimeException('Escribe el nombre del nuevo RP.');
            if (!in_array($nuevo_tipo, $TIPOS_RP, true)) throw new RuntimeException('Selecciona el tipo del nuevo RP.');
            $nuevo_id_rp = crear_rp_sin_usuario($pdo, $nuevo_nombre, $nuevo_tipo);
        } else {
            // El RP se puede reasignar (por ejemplo a un RP sin usuario); debe existir y ser RP
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = :id AND rol = 'rp'");
            $stmt->execute(['id' => $nuevo_id_rp]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('El RP seleccionado no existe.');
            }
        }

        $sql = "UPDATE reservas
                SET nombre_cliente = :nombre,
                    num_personas = :personas,
                    fecha_reserva = :fecha,
                    estado = :estado,
                    id_rp = :id_rp
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nombre' => $nombre_cliente,
            'personas' => $num_personas,
            'fecha' => (new DateTime($fecha_reserva_str))->format('Y-m-d H:i:s'),
            'estado' => $estado,
            'id_rp' => $nuevo_id_rp,
            'id' => $id_reserva
        ]);

        $success = "Reserva actualizada. <a href='index.php' class='font-bold underline'>Volver al reporte</a>";

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Exception $e) {
        $error = 'Error al actualizar: ' . $e->getMessage();
    }
}

// Cargar datos de la reserva con su RP y sus cierres
$stmt = $pdo->prepare("SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp FROM reservas r JOIN usuarios u ON r.id_rp = u.id WHERE r.id = :id");
$stmt->execute(['id' => $id_reserva]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

$cierres = [];
$listas = ['sin_usuario' => [], 'con_usuario' => []];
if ($reserva) {
    try {
        $listas = lista_rps($pdo);
    } catch (PDOException $e) {
        // Aún no se ejecuta ajustes_2026-10-28.sql: el selector muestra solo el RP actual
        $listas['con_usuario'][] = ['id' => $reserva['id_rp'], 'nombre_completo' => $reserva['nombre_rp'], 'tipo_rp' => $reserva['tipo_rp'], 'activo' => 1, 'sin_acceso' => 0];
    }
    $stmt = $pdo->prepare("SELECT * FROM cuentas_cerradas WHERE id_reserva = :id ORDER BY id ASC");
    $stmt->execute(['id' => $id_reserva]);
    $cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $error = "Reserva no encontrada.";
}
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Reserva VTA<?php echo $id_reserva; ?></h1>
        <p class="mt-1 text-sm text-neutral-500">Edición de datos de la reserva. Los cierres de caja solo se consultan.</p>
    </div>

    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?></div><?php endif; ?>

    <?php if ($reserva): ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <form action="editar_reserva.php?id=<?php echo $id_reserva; ?>" method="POST" class="glass-card rounded-2xl p-6 space-y-4">
            <div>
                <label for="id_rp" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">RP</label>
                <select name="id_rp" id="id_rp" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required onchange="mostrarNuevoRp()">
                    <option value="nuevo">+ Nuevo RP sin usuario</option>
                    <?php echo opciones_rps($listas, $reserva['id_rp']); ?>
                </select>
                <div id="nuevo_rp" class="hidden mt-2 rounded-xl bg-neutral-900/60 p-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="nuevo_nombre" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del RP</label>
                        <input type="text" name="nuevo_nombre" id="nuevo_nombre" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                    </div>
                    <div>
                        <label for="nuevo_tipo" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Tipo de RP</label>
                        <select name="nuevo_tipo" id="nuevo_tipo" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($TIPOS_RP as $t): ?><option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <p class="sm:col-span-2 text-xs text-neutral-500">El RP nuevo no podrá entrar al sistema; queda en la lista para futuras reservas.</p>
                </div>
                <p class="mt-1 text-xs text-neutral-500">Puedes reasignar la reserva a otro RP, incluidos los RPs sin usuario, o dar de alta uno nuevo. Creada el <?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_creacion'])); ?><?php if (!empty($reserva['fecha_llegada'])): ?> · Llegada <?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_llegada'])); ?><?php endif; ?></p>
            </div>
            <div>
                <label for="nombre_cliente" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del Cliente</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente" value="<?php echo htmlspecialchars($reserva['nombre_cliente']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="num_personas" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Número de Personas</label>
                <input type="number" name="num_personas" id="num_personas" value="<?php echo $reserva['num_personas']; ?>" min="1" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="fecha_reserva" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha y Hora</label>
                <input type="datetime-local" name="fecha_reserva" id="fecha_reserva" value="<?php echo date('Y-m-d\TH:i', strtotime($reserva['fecha_reserva'])); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="estado" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Estado</label>
                <select id="estado" name="estado" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
                    <?php foreach (['nueva' => 'Nueva', 'iniciada' => 'Iniciada', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada', 'inasistente' => 'Inasistente'] as $valor => $etiqueta): ?>
                        <option value="<?php echo $valor; ?>" <?php echo $reserva['estado'] == $valor ? 'selected' : ''; ?>><?php echo $etiqueta; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-amber flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-white">Actualizar Reserva</button>
                <a href="index.php" class="btn-secondary py-2.5 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">Volver</a>
            </div>
        </form>

        <div class="glass-card rounded-2xl p-6">
            <h2 class="text-sm font-semibold text-white mb-3">Cierres en caja</h2>
            <?php if (empty($cierres)): ?>
                <p class="text-sm text-neutral-500">Esta reserva no tiene cierres registrados.</p>
            <?php else: ?>
                <form method="POST" action="editar_reserva.php?id=<?php echo $id_reserva; ?>" id="form-cierres">
                <input type="hidden" name="accion" value="guardar_cierres">
                <div class="overflow-x-auto">
                <table class="w-full text-sm whitespace-nowrap">
                    <thead><tr class="text-xs text-neutral-500 uppercase tracking-wider"><th class="text-left py-1">Folio</th><th class="text-left px-2">POS</th><th class="text-right px-2">Venta</th><th class="text-right px-2">% Com.</th><th class="text-right px-2">Comisión</th><th class="text-right px-2">Propina</th><th class="text-right pl-2">Fecha</th></tr></thead>
                    <tbody class="divide-y divide-neutral-800/50 text-neutral-300">
                        <?php $suma = 0; foreach ($cierres as $c): $suma += floatval($c['venta_total']); ?>
                            <tr>
                                <td class="py-2"><input type="text" name="folio[<?php echo $c['id']; ?>]" value="<?php echo htmlspecialchars($c['folio_cierre']); ?>" class="input-premium rounded-lg py-1 px-2 text-sm text-white font-mono uppercase w-28" required oninput="marcarCambio()" title="Folio VTA del cierre. Si cambias el número, el cierre se mueve a esa reserva"></td>
                                <td class="px-2"><input type="text" name="pos[<?php echo $c['id']; ?>]" value="<?php echo htmlspecialchars($c['folio_pos']); ?>" class="input-premium rounded-lg py-1 px-2 text-sm text-white font-mono w-24" required oninput="marcarCambio()"></td>
                                <td class="text-right px-2">$<?php echo number_format($c['venta_total'], 2); ?></td>
                                <td class="text-right px-2"><input type="number" name="pct[<?php echo $c['id']; ?>]" value="<?php echo number_format($c['porcentaje_comision'], 0, '.', ''); ?>" min="0" max="100" step="1" class="input-premium rounded-lg py-1 px-2 text-sm text-white text-right w-16" required oninput="marcarCambio()"></td>
                                <td class="text-right px-2 text-emerald-400">$<?php echo number_format($c['monto_comision'], 2); ?></td>
                                <td class="text-right px-2">$<?php echo number_format($c['propina'], 2); ?></td>
                                <td class="text-right pl-2 text-xs"><?php echo date('d/m/Y H:i', strtotime($c['fecha_cierre'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <button type="submit" id="btn-cierres" class="hidden btn-amber py-2 px-4 rounded-xl text-sm font-semibold text-white">Guardar cierres</button>
                    <p class="ml-auto text-right text-base font-bold text-white">Total: $<?php echo number_format($suma, 2); ?></p>
                </div>
                <p class="mt-2 text-xs text-neutral-500">Al cambiar el % se recalculan la comisión (venta × %) y la propina (5% de la venta si hay comisión). Si cambias el número del folio VTA, el cierre se pasa a esa reserva.</p>
                </form>
                <script>function marcarCambio() { document.getElementById('btn-cierres').classList.remove('hidden'); }</script>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <a href="index.php" class="btn-secondary inline-block py-2.5 px-6 rounded-xl text-sm font-medium text-neutral-300">Volver al reporte</a>
    <?php endif; ?>
</div>

<script>
function mostrarNuevoRp() {
    const sel = document.getElementById('id_rp');
    if (!sel) return;
    const esNuevo = sel.value === 'nuevo';
    document.getElementById('nuevo_rp').classList.toggle('hidden', !esNuevo);
    document.getElementById('nuevo_nombre').required = esNuevo;
    document.getElementById('nuevo_tipo').required = esNuevo;
}
mostrarNuevoRp();
</script>

<?php require_once '../includes/footer.php'; ?>
