<?php
// Panel del coordinador: las reservas que ha registrado (a nombre de otros RPs).
require_once '../includes/header.php';
require_once '../includes/turno.php';
check_role(['coordinador']);

$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$busqueda = trim($_GET['q'] ?? '');
$estado_filtro = $_GET['estado'] ?? 'todas';

$where = ["r.creada_por = :yo"];
$params = ['yo' => $_SESSION['id']];
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $where[] = "r.fecha_reserva >= :ini AND r.fecha_reserva < :fin";
    $params['ini'] = turno_inicio($fecha_inicio);
    $params['fin'] = turno_fin($fecha_fin);
}
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $where[] = "(r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR CONCAT('VTA', r.id) LIKE :q_folio)";
    $params['q_cliente'] = $like;
    $params['q_rp'] = $like;
    $params['q_folio'] = $like;
}
if (in_array($estado_filtro, ['nueva', 'iniciada', 'finalizada', 'cancelada', 'inasistente'])) {
    $where[] = "r.estado = :estado";
    $params['estado'] = $estado_filtro;
}

$reservas = [];
$falta_migracion = false;
try {
    $stmt = $pdo->prepare("SELECT r.id, r.nombre_cliente, r.num_personas, r.fecha_reserva, r.estado, r.fecha_creacion,
                u.nombre_completo AS nombre_rp, u.tipo_rp, u.sin_acceso
            FROM reservas r
            JOIN usuarios u ON r.id_rp = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.fecha_reserva DESC
            LIMIT 300");
    $stmt->execute($params);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $falta_migracion = true;
}

// Resumen de lo mostrado
$resumen = ['reservas' => count($reservas), 'personas' => 0, 'nuevas' => 0, 'finalizadas' => 0];
foreach ($reservas as $r) {
    $resumen['personas'] += intval($r['num_personas']);
    if ($r['estado'] == 'nueva') $resumen['nuevas']++;
    if ($r['estado'] == 'finalizada') $resumen['finalizadas']++;
}
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-white">Mis reservas registradas</h1>
            <p class="mt-1 text-sm text-neutral-500">Reservas que registraste a nombre de otros RPs. Hostess confirma la llegada y caja las cierra como cualquier otra.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="crear_reserva.php" class="btn-amber inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Nueva reserva
            </a>
        </div>
    </div>

    <?php if ($falta_migracion): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">Falta ejecutar <strong>ajustes_2026-11-09.sql</strong> en la base de datos para activar el panel del coordinador.</div>
    <?php endif; ?>

    <form method="GET" action="index.php" class="glass-card rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end mb-6">
        <div class="lg:col-span-2">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Buscar</label>
            <input type="search" name="q" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Cliente, RP o folio (VTA123)" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div>
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Estado</label>
            <select name="estado" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todas">Todas</option>
                <?php foreach (['nueva' => 'Nueva', 'iniciada' => 'Iniciada', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada', 'inasistente' => 'Inasistente'] as $v => $et): ?>
                    <option value="<?php echo $v; ?>" <?php echo $estado_filtro == $v ? 'selected' : ''; ?>><?php echo $et; ?></option>
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
        <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
            <button type="submit" class="btn-amber py-2 px-5 rounded-xl text-sm font-semibold text-white">Filtrar</button>
            <a href="index.php" class="btn-secondary py-2 px-5 rounded-xl text-sm font-medium text-neutral-300">Limpiar</a>
            <span class="ml-auto self-center text-xs text-neutral-600">Cada turno va de las 5:00 pm a las 4:59 pm del día siguiente.</span>
        </div>
    </form>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Reservas</p><p class="text-lg font-bold text-white"><?php echo $resumen['reservas']; ?></p></div>
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Personas</p><p class="text-lg font-bold text-white"><?php echo $resumen['personas']; ?></p></div>
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Pendientes</p><p class="text-lg font-bold text-blue-400"><?php echo $resumen['nuevas']; ?></p></div>
        <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Finalizadas</p><p class="text-lg font-bold text-emerald-400"><?php echo $resumen['finalizadas']; ?></p></div>
    </div>

    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">RP / Tipo</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Fecha reserva</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Personas</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                        <th class="py-3.5 pl-3 pr-5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden md:table-cell">Registrada</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($reservas)): ?>
                        <tr><td colspan="7" class="px-3 py-10 text-sm text-neutral-500 text-center">Aún no has registrado reservas<?php echo ($busqueda !== '' || $estado_filtro !== 'todas' || $fecha_inicio !== '') ? ' con esos filtros' : ''; ?>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($reservas as $r): ?>
                        <?php
                            $color_span = 'bg-blue-500/15 text-blue-400'; // nueva
                            if ($r['estado'] == 'iniciada') $color_span = 'bg-amber-500/15 text-amber-400';
                            if ($r['estado'] == 'finalizada') $color_span = 'bg-emerald-500/15 text-emerald-400';
                            if ($r['estado'] == 'cancelada' || $r['estado'] == 'inasistente') $color_span = 'bg-red-500/15 text-red-400';
                        ?>
                        <tr class="transition-colors duration-150">
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white font-mono">VTA<?php echo $r['id']; ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm font-medium text-white"><?php echo htmlspecialchars($r['nombre_cliente']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                <span class="font-semibold text-white"><?php echo htmlspecialchars($r['nombre_rp']); ?></span>
                                <span class="block text-xs text-neutral-500"><?php echo htmlspecialchars($r['tipo_rp'] ?? '-'); ?><?php echo !empty($r['sin_acceso']) ? ' · sin usuario' : ''; ?></span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($r['fecha_reserva'])); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 hidden sm:table-cell"><?php echo $r['num_personas']; ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $color_span; ?>"><?php echo htmlspecialchars(ucfirst($r['estado'])); ?></span>
                            </td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-5 text-xs text-neutral-500 hidden md:table-cell"><?php echo date('d/m/y h:i A', strtotime($r['fecha_creacion'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (count($reservas) >= 300): ?><p class="mt-3 text-xs text-neutral-600">Se muestran las 300 más recientes; usa los filtros para acotar.</p><?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
