<?php
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
require_once '../includes/turno.php';
check_role(['gerente']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// --- Revertir una devolución de comisión (solo gerente; caja no puede deshacerla) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST['accion'] ?? '') === 'revertir_devolucion') {
    $id_cierre = intval($_POST['id_cierre'] ?? 0);
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif ($id_cierre <= 0) {
        $error = 'Cierre no válido.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT folio_cierre, comision_devuelta FROM cuentas_cerradas WHERE id = :id");
            $stmt->execute(['id' => $id_cierre]);
            $cierre = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cierre) {
                $error = 'Cierre no encontrado.';
            } elseif (empty($cierre['comision_devuelta'])) {
                $error = "El cierre {$cierre['folio_cierre']} no tiene una devolución registrada.";
            } else {
                $pdo->prepare("UPDATE cuentas_cerradas SET comision_devuelta = 0, fecha_devolucion = NULL, devuelto_por = NULL, motivo_devolucion = NULL WHERE id = :id")
                    ->execute(['id' => $id_cierre]);
                $success = "Devolución revertida: el cierre {$cierre['folio_cierre']} vuelve a estar como comisión pagada.";
            }
        } catch (PDOException $e) {
            $error = 'No se pudo revertir la devolución. Verifica que se haya ejecutado ajustes_2026-11-10.sql.';
        }
    }
}

// Cargar RPs para el filtro
// Incluye a los RPs sin usuario (registrados por el gerente), que están inactivos pero tienen reservas
$stmt_rps = $pdo->query("SELECT id, nombre_completo FROM usuarios WHERE rol = 'rp' AND (activo = 1 OR sin_acceso = 1) ORDER BY nombre_completo");
$rps = $stmt_rps->fetchAll(PDO::FETCH_ASSOC);

// Tipos de RP para el filtro: catálogo actual + tipos anteriores que sigan en uso (ej. "Personal Operativo")
$stmt_tipos = $pdo->query("SELECT DISTINCT tipo_rp FROM usuarios WHERE tipo_rp IS NOT NULL AND tipo_rp <> ''");
$tipos_rp_filtro = array_unique(array_merge($TIPOS_RP, $stmt_tipos->fetchAll(PDO::FETCH_COLUMN)));

// --- Lógica de Filtros ---
$where_clauses = [];
$params = [];

// Filtro de estado
$estado_filtro = $_GET['estado'] ?? 'todas';
if ($estado_filtro != 'todas') {
    $where_clauses[] = "r.estado = :estado";
    $params['estado'] = $estado_filtro;
}

// Filtro de RP
$rp_filtro = $_GET['id_rp'] ?? 'todos';
if ($rp_filtro != 'todos') {
    $where_clauses[] = "r.id_rp = :id_rp";
    $params['id_rp'] = $rp_filtro;
}

// Filtro de tipo de RP
$tipo_rp_filtro = $_GET['tipo_rp'] ?? 'todos';
if ($tipo_rp_filtro != 'todos') {
    $where_clauses[] = "u.tipo_rp = :tipo_rp";
    $params['tipo_rp'] = $tipo_rp_filtro;
}

// Filtro de rango de fechas (sobre fecha_reserva)
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $where_clauses[] = "r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
    $params['fecha_inicio'] = turno_inicio($fecha_inicio);
    $params['fecha_fin'] = turno_fin($fecha_fin);
}

// Búsqueda por cliente, RP, folio VTA, folio de cierre o folio POS
$busqueda = trim($_GET['q'] ?? '');
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $where_clauses[] = "(r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR CONCAT('VTA', r.id) LIKE :q_folio OR cc.folio_cierre LIKE :q_cierre OR cc.folio_pos LIKE :q_pos)";
    $params['q_cliente'] = $like;
    $params['q_rp'] = $like;
    $params['q_folio'] = $like;
    $params['q_cierre'] = $like;
    $params['q_pos'] = $like;
}

// Filtro de pago de comisión al RP (solo aplica a reservas con cierre)
$pago_filtro = $_GET['pago'] ?? 'todos';
if ($pago_filtro == 'pagada') {
    $where_clauses[] = "cc.comision_pagada = 1 AND cc.comision_devuelta = 0";
} elseif ($pago_filtro == 'pendiente') {
    $where_clauses[] = "cc.id IS NOT NULL AND cc.comision_pagada = 0";
} elseif ($pago_filtro == 'devuelta') {
    $where_clauses[] = "cc.comision_devuelta = 1";
}

// Construir la consulta
$sql = "SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp,
               cc.id AS id_cierre, cc.comision_pagada, cc.fecha_pago_comision, cc.comision_devuelta, cc.fecha_devolucion, cc.motivo_devolucion, cc.folio_cierre, cc.folio_pos, cc.venta_total, cc.porcentaje_comision, cc.monto_comision, cc.propina, cc.fecha_cierre,
               (SELECT nombre_completo FROM usuarios WHERE id = cc.devuelto_por) AS devuelto_por
        FROM reservas r
        JOIN usuarios u ON r.id_rp = u.id
        LEFT JOIN cuentas_cerradas cc ON r.id = cc.id_reserva";

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}
$sql .= " ORDER BY r.fecha_reserva DESC, cc.id ASC LIMIT 400"; // Limitar resultados por paginación

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// URL para exportar (mismos filtros)
$export_url = "exportar_excel.php?" . http_build_query($_GET);
?>

<?php
// Totales de lo filtrado (una fila por cierre, así que la venta se suma por cierre)
$total_venta = 0; $total_comision = 0; $total_propina = 0; $total_personas = 0; $ids_contados = [];
foreach ($reservas as $r) {
    $total_venta += floatval($r['venta_total'] ?? 0);
    $total_comision += floatval($r['monto_comision'] ?? 0);
    $total_propina += floatval($r['propina'] ?? 0);
    if (!isset($ids_contados[$r['id']])) { $ids_contados[$r['id']] = true; $total_personas += intval($r['num_personas']); }
}
$total_reservas = count($ids_contados);
?>

<!-- Filtros -->
<div class="mb-6">
    <form action="index.php" method="GET" class="glass-card rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 gap-3 items-end">
        <div class="sm:col-span-2 lg:col-span-4 xl:col-span-8">
            <label for="q" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Buscar</label>
            <input type="search" name="q" id="q" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Cliente, RP, folio VTA, folio de cierre o folio POS" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label for="estado" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Estado</label>
            <select id="estado" name="estado" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todas" <?php echo $estado_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                <option value="nueva" <?php echo $estado_filtro == 'nueva' ? 'selected' : ''; ?>>Nueva</option>
                <option value="iniciada" <?php echo $estado_filtro == 'iniciada' ? 'selected' : ''; ?>>Iniciada</option>
                <option value="finalizada" <?php echo $estado_filtro == 'finalizada' ? 'selected' : ''; ?>>Finalizada</option>
                <option value="cancelada" <?php echo $estado_filtro == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                <option value="inasistente" <?php echo $estado_filtro == 'inasistente' ? 'selected' : ''; ?>>Inasistente</option>
            </select>
        </div>
        <div>
            <label for="id_rp" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">RP</label>
            <select id="id_rp" name="id_rp" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todos">Todos los RPs</option>
                <?php foreach ($rps as $rp): ?>
                    <option value="<?php echo $rp['id']; ?>" <?php echo $rp_filtro == $rp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($rp['nombre_completo']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="tipo_rp" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Tipo de RP</label>
            <select id="tipo_rp" name="tipo_rp" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todos">Todos los tipos</option>
                <?php foreach ($tipos_rp_filtro as $tipo): ?>
                    <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $tipo_rp_filtro == $tipo ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="fecha_inicio" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Turno inicio</label>
            <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label for="fecha_fin" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Turno fin</label>
            <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label for="pago" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Pago RP</label>
            <select id="pago" name="pago" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todos" <?php echo $pago_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                <option value="pendiente" <?php echo $pago_filtro == 'pendiente' ? 'selected' : ''; ?>>Pendiente de pago</option>
                <option value="pagada" <?php echo $pago_filtro == 'pagada' ? 'selected' : ''; ?>>Pagada</option>
                <option value="devuelta" <?php echo $pago_filtro == 'devuelta' ? 'selected' : ''; ?>>Devuelta</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-4 xl:col-span-2">
            <button type="submit" class="btn-amber flex-1 py-2 px-4 rounded-xl text-sm font-semibold text-white">Filtrar</button>
            <a href="index.php" class="btn-secondary flex-1 py-2 px-4 rounded-xl text-sm font-medium text-neutral-300 text-center">Limpiar</a>
        </div>
        <p class="sm:col-span-2 lg:col-span-4 xl:col-span-8 text-xs text-neutral-600">Cada turno va de las 5:00 pm a las 4:59 pm del día siguiente.</p>
    </form>
</div>

<?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<!-- Encabezado y totales -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-5">
    <div>
        <h1 class="text-xl font-bold text-white">Reporte de Reservas</h1>
        <p class="mt-1 text-sm text-neutral-500"><?php echo $total_reservas; ?> reservas · <?php echo $total_personas; ?> personas<?php if (count($reservas) >= 400): ?> · se muestran las 400 más recientes, usa los filtros o exporta<?php endif; ?></p>
    </div>
    <div class="mt-4 sm:mt-0">
        <a href="<?php echo htmlspecialchars($export_url); ?>" class="btn-secondary inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-medium text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/10 transition-colors duration-200">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Exportar a Excel
        </a>
    </div>
</div>

<div class="grid grid-cols-3 gap-3 mb-5">
    <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Venta</p><p class="text-lg font-bold text-white">$<?php echo number_format($total_venta, 2); ?></p></div>
    <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Comisiones</p><p class="text-lg font-bold text-emerald-400">$<?php echo number_format($total_comision, 2); ?></p></div>
    <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Propinas</p><p class="text-lg font-bold text-white">$<?php echo number_format($total_propina, 2); ?></p></div>
</div>

<!-- Tabla -->
<div class="glass-card rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full table-premium table-fit">
            <thead>
                <tr class="border-b border-neutral-700/50">
                    <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Fecha Reserva</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">RP / Tipo</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                    <th scope="col" class="px-3 py-3.5 text-right text-xs font-semibold text-neutral-400 uppercase tracking-wider">Venta</th>
                    <th scope="col" class="px-3 py-3.5 text-right text-xs font-semibold text-neutral-400 uppercase tracking-wider">Comisión</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Pago RP</th>
                    <th scope="col" class="px-3 py-3.5 text-right text-xs font-semibold text-neutral-400 uppercase tracking-wider">Propina</th>
                    <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">POS</th>
                    <th scope="col" class="relative py-3.5 pl-3 pr-5"><span class="sr-only">Acciones</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800/50">
                <?php if (empty($reservas)): ?>
                    <tr><td colspan="11" class="px-3 py-10 text-sm text-neutral-500 text-center">No se encontraron reservas con esos filtros.</td></tr>
                <?php endif; ?>
                <?php foreach ($reservas as $reserva): ?>
                    <?php
                        $color_span = 'bg-blue-500/15 text-blue-400'; // nueva
                        if ($reserva['estado'] == 'iniciada') $color_span = 'bg-amber-500/15 text-amber-400';
                        if ($reserva['estado'] == 'finalizada') $color_span = 'bg-emerald-500/15 text-emerald-400';
                        if ($reserva['estado'] == 'cancelada' || $reserva['estado'] == 'inasistente') $color_span = 'bg-red-500/15 text-red-400';
                        $cerrada = ($reserva['estado'] == 'finalizada' && !empty($reserva['id_cierre']));
                    ?>
                    <tr class="transition-colors duration-150">
                        <td class="py-3.5 pl-5 pr-3 text-sm font-medium text-white font-mono"><?php echo htmlspecialchars($reserva['folio_cierre'] ?? ('VTA' . $reserva['id'])); ?></td>
                        <td class="px-3 py-3.5 text-sm font-medium text-white"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?> <span class="text-neutral-500 font-normal whitespace-nowrap">(<?php echo $reserva['num_personas']; ?>p)</span></td>
                        <td class="px-3 py-3.5 text-sm text-neutral-300"><span class="whitespace-nowrap"><?php echo date('d/m/y', strtotime($reserva['fecha_reserva'])); ?></span> <span class="text-neutral-500 whitespace-nowrap"><?php echo date('h:i A', strtotime($reserva['fecha_reserva'])); ?></span></td>
                        <td class="px-3 py-3.5 text-sm">
                            <span class="font-semibold text-white"><?php echo htmlspecialchars($reserva['nombre_rp']); ?></span>
                            <span class="block text-xs text-neutral-500"><?php echo htmlspecialchars($reserva['tipo_rp'] ?? '-'); ?></span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                            <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $color_span; ?>"><?php echo htmlspecialchars(ucfirst($reserva['estado'])); ?></span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-right text-white"><?php echo $cerrada ? '$' . number_format($reserva['venta_total'], 2) : '<span class="text-neutral-600">-</span>'; ?></td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-right"><?php echo $cerrada ? '<span class="text-emerald-400">$' . number_format($reserva['monto_comision'] ?? 0, 2) . '</span> <span class="text-neutral-500 text-xs">' . number_format($reserva['porcentaje_comision'] ?? 0, 0) . '%</span>' : '<span class="text-neutral-600">-</span>'; ?></td>
                        <td class="px-3 py-3.5 text-sm">
                            <?php if (!$cerrada): ?>
                                <span class="text-neutral-600">-</span>
                            <?php elseif (!empty($reserva['comision_devuelta'])): ?>
                                <span class="inline-flex rounded-lg bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-400">Devuelta</span>
                                <span class="block text-xs text-neutral-500 whitespace-nowrap"><?php echo date('d/m/y h:i A', strtotime($reserva['fecha_devolucion'])); ?></span>
                                <?php if (!empty($reserva['devuelto_por'])): ?><span class="block text-xs text-neutral-600">Por <?php echo htmlspecialchars($reserva['devuelto_por']); ?></span><?php endif; ?>
                                <?php if (!empty($reserva['motivo_devolucion'])): ?><span class="block text-xs text-neutral-400">Motivo: <?php echo htmlspecialchars($reserva['motivo_devolucion']); ?></span><?php endif; ?>
                                <form method="POST" action="index.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="mt-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="id_cierre" value="<?php echo intval($reserva['id_cierre']); ?>">
                                    <button type="submit" name="accion" value="revertir_devolucion" onclick="return confirm(<?php echo htmlspecialchars(json_encode("¿Revertir la devolución del cierre {$reserva['folio_cierre']}? La comisión de {$reserva['nombre_rp']} (\$" . number_format($reserva['monto_comision'] ?? 0, 2) . ") volverá a quedar como pagada."), ENT_QUOTES); ?>)" class="text-amber-500 hover:text-amber-400 text-xs font-semibold whitespace-nowrap transition-colors duration-200">Revertir devolución</button>
                                </form>
                            <?php elseif (!empty($reserva['comision_pagada'])): ?>
                                <span class="inline-flex rounded-lg bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-400">Pagada</span>
                                <span class="block text-xs text-neutral-500 whitespace-nowrap"><?php echo date('d/m/y h:i A', strtotime($reserva['fecha_pago_comision'])); ?></span>
                            <?php else: ?>
                                <span class="inline-flex rounded-lg bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-400">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-right text-neutral-300"><?php echo $cerrada ? '$' . number_format($reserva['propina'] ?? 0, 2) : '<span class="text-neutral-600">-</span>'; ?></td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 font-mono"><?php echo ($cerrada && !empty($reserva['folio_pos'])) ? htmlspecialchars($reserva['folio_pos']) : '<span class="text-neutral-600">-</span>'; ?></td>
                        <td class="relative py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                            <div class="flex flex-col items-end space-y-1.5">
                                <a href="editar_reserva.php?id=<?php echo $reserva['id']; ?>" class="text-amber-500 hover:text-amber-400 font-semibold whitespace-nowrap transition-colors duration-200">Ver / Editar</a>
                                <?php if ($reserva['estado'] == 'finalizada'): ?>
                                    <a href="eliminar_reserva.php?id=<?php echo $reserva['id']; ?>&cierre=<?php echo intval($reserva['id_cierre']); ?>" class="text-red-500/60 hover:text-red-400 text-xs whitespace-nowrap transition-colors duration-200" title="Solo si está duplicada">Eliminar duplicada</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
