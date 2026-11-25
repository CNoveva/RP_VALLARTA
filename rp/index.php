<?php
require_once '../includes/header.php';
check_role(['rp']);

$id_rp = $_SESSION['id'];

// --- 1. Lógica de Filtros ---
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

// Construcción de la consulta
$sql = "SELECT r.*, 
               cc.folio_cierre, cc.folio_pos, cc.venta_total, cc.porcentaje_comision, cc.monto_comision, cc.propina, cc.fecha_cierre,
               cc.comision_pagada, cc.fecha_pago_comision, cc.comision_devuelta, cc.fecha_devolucion
        FROM reservas r
        LEFT JOIN cuentas_cerradas cc ON r.id = cc.id_reserva
        WHERE r.id_rp = :id_rp";
$params = ['id_rp' => $id_rp];

if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $sql .= " AND DATE(r.fecha_reserva) BETWEEN :inicio AND :fin";
    $params['inicio'] = $fecha_inicio;
    $params['fin'] = $fecha_fin;
}

$sql .= " ORDER BY r.fecha_reserva DESC, cc.id ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-white">Mis Reservas</h1>
            <p class="mt-1 text-sm text-neutral-500">Gestiona tus reservas y revisa tus comisiones.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="crear_reserva.php" class="btn-amber inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Crear Nueva Reserva
            </a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="mb-6">
        <form action="index.php" method="GET" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row items-end gap-3">
            <div class="w-full sm:w-auto">
                <label for="fecha_inicio" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Inicio</label>
                <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div class="w-full sm:w-auto">
                <label for="fecha_fin" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Fin</label>
                <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div class="flex gap-2 w-full sm:w-auto">
                <button type="submit" class="btn-amber flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-semibold text-white">
                    Filtrar
                </button>
                <?php if (!empty($fecha_inicio)): ?>
                    <a href="index.php" class="btn-secondary flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">
                        Limpiar
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Content -->
    <?php if (empty($reservas)): ?>
        <div class="glass-card rounded-2xl text-center py-14">
            <svg class="w-12 h-12 mx-auto text-neutral-700 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
            <p class="text-sm text-neutral-500">No se encontraron reservas en este periodo.</p>
        </div>
    <?php else: ?>

        <!-- Mobile cards -->
        <div class="block md:hidden space-y-3">
            <?php foreach ($reservas as $reserva): ?>
                <?php
                    $color = 'bg-blue-500/15 text-blue-400'; 
                    if ($reserva['estado'] == 'iniciada') $color = 'bg-amber-500/15 text-amber-400';
                    if ($reserva['estado'] == 'finalizada') $color = 'bg-emerald-500/15 text-emerald-400';
                    if ($reserva['estado'] == 'cancelada' || $reserva['estado'] == 'inasistente') $color = 'bg-red-500/15 text-red-400';
                ?>
                <div class="glass-card rounded-2xl p-4">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="text-base font-bold text-white"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></h3>
                            <p class="text-xs text-neutral-500 mt-0.5"><?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_reserva'])); ?></p>
                        </div>
                        <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $color; ?>">
                            <?php echo htmlspecialchars(ucfirst($reserva['estado'])); ?>
                        </span>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-y-2.5 gap-x-4 text-sm">
                        <div>
                            <span class="block text-xs text-neutral-500 uppercase tracking-wider">Personas</span>
                            <span class="font-medium text-white"><?php echo $reserva['num_personas']; ?></span>
                        </div>
                        <div>
                            <span class="block text-xs text-neutral-500 uppercase tracking-wider">Folio VTA</span>
                            <span class="font-mono text-neutral-300"><?php echo htmlspecialchars($reserva['folio_cierre'] ?? ('VTA' . $reserva['id'])); ?></span>
                        </div>
                        
                        <?php if ($reserva['estado'] == 'finalizada'): ?>
                            <?php if (($reserva['porcentaje_comision'] ?? 0) > 0): ?>
                            <div class="col-span-2 border-t border-neutral-700/50 pt-2 mt-1">
                                <span class="block text-xs text-neutral-500 uppercase tracking-wider">Venta Total</span>
                                <span class="text-lg font-bold text-emerald-400">$<?php echo number_format($reserva['venta_total'] ?? 0, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="block text-xs text-neutral-500 uppercase tracking-wider">Comisión ($)</span>
                                <span class="font-medium text-emerald-400">$<?php echo number_format($reserva['monto_comision'] ?? 0, 2); ?></span>
                                <?php if ($reserva['folio_cierre'] !== null && ($reserva['monto_comision'] ?? 0) > 0): ?>
                                    <?php if (!empty($reserva['comision_devuelta'])): ?>
                                        <span class="block text-xs text-red-400">Devuelta el <?php echo date('d/m/Y', strtotime($reserva['fecha_devolucion'])); ?></span>
                                    <?php elseif (!empty($reserva['comision_pagada'])): ?>
                                        <span class="block text-xs text-emerald-400">Pagada el <?php echo date('d/m/Y', strtotime($reserva['fecha_pago_comision'])); ?></span>
                                    <?php else: ?>
                                        <span class="block text-xs text-amber-400">Pendiente de pago</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="block text-xs text-neutral-500 uppercase tracking-wider">Comisión (%)</span>
                                <span class="font-medium text-emerald-400"><?php echo number_format($reserva['porcentaje_comision'] ?? 0, 0); ?>%</span>
                            </div>
                            <div class="col-span-2">
                                <span class="block text-xs text-neutral-500 uppercase tracking-wider">Folio POS</span>
                                <span class="font-medium text-neutral-300"><?php echo htmlspecialchars($reserva['folio_pos'] ?? '-'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($reserva['estado'] == 'nueva'): ?>
                        <div class="pt-3 border-t border-neutral-700/50 mt-3 text-right">
                            <a href="editar_reserva.php?id=<?php echo $reserva['id']; ?>" class="inline-flex items-center text-sm font-semibold text-amber-500 hover:text-amber-400 transition-colors duration-200">
                                Editar Reserva
                                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop table -->
        <div class="hidden md:block glass-card rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full table-premium">
                    <thead>
                        <tr class="border-b border-neutral-700/50">
                            <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Fecha/Hora</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Personas</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Venta Total</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Comisión ($)</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Comisión (%)</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio VTA</th>
                            <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio POS</th>
                            <th scope="col" class="relative py-3.5 pl-3 pr-5">
                                <span class="sr-only">Editar</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-800/50">
                        <?php foreach ($reservas as $reserva): ?>
                            <?php
                                $color = 'bg-blue-500/15 text-blue-400';
                                if ($reserva['estado'] == 'iniciada') $color = 'bg-amber-500/15 text-amber-400';
                                if ($reserva['estado'] == 'finalizada') $color = 'bg-emerald-500/15 text-emerald-400';
                                if ($reserva['estado'] == 'cancelada' || $reserva['estado'] == 'inasistente') $color = 'bg-red-500/15 text-red-400';
                            ?>
                            <tr class="transition-colors duration-150">
                                <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_reserva'])); ?></td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo $reserva['num_personas']; ?></td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                    <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $color; ?>">
                                        <?php echo htmlspecialchars(ucfirst($reserva['estado'])); ?>
                                    </span>
                                </td>
                                
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm font-medium">
                                    <?php if ($reserva['estado'] == 'finalizada' && ($reserva['porcentaje_comision'] ?? 0) > 0): ?>
                                        <span class="text-emerald-400">$<?php echo number_format($reserva['venta_total'] ?? 0, 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-neutral-600">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="whitespace-nowrap px-3 py-3.5 text-sm font-medium">
                                    <?php if ($reserva['estado'] == 'finalizada'): ?>
                                        <span class="text-emerald-400">$<?php echo number_format($reserva['monto_comision'] ?? 0, 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-neutral-600">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                    <?php if ($reserva['estado'] == 'finalizada'): ?>
                                        <span class="text-emerald-400"><?php echo number_format($reserva['porcentaje_comision'] ?? 0, 0); ?>%</span>
                                    <?php else: ?>
                                        <span class="text-neutral-600">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400 font-mono">
                                    <?php echo htmlspecialchars($reserva['folio_cierre'] ?? ('VTA' . $reserva['id'])); ?>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400">
                                    <?php if ($reserva['estado'] == 'finalizada' && !empty($reserva['folio_pos'])): ?>
                                        <?php echo htmlspecialchars($reserva['folio_pos']); ?>
                                    <?php else: ?>
                                        <span class="text-neutral-600">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="relative whitespace-nowrap py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                                    <?php if ($reserva['estado'] == 'nueva'): ?>
                                        <a href="editar_reserva.php?id=<?php echo $reserva['id']; ?>" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">Editar</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>