<?php
require_once '../includes/header.php';
require_once '../includes/folio.php';
require_once '../includes/turno.php';
check_role(['caja']);

$error = '';
$success = '';

// LÓGICA PHP
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Si viene del formulario de cerrar cuenta
    if (isset($_POST['accion']) && $_POST['accion'] == 'cerrar_cuenta') {
        // Sin prefijo VTA/EV no se cierra nada (antes "355" o "M355" cerraban la reserva 355)
        $folio = parsear_folio($_POST['folio_btla'] ?? '');
        $folio_btla = $folio ? $folio['folio'] : '';
        $folio_pos = trim($_POST['folio_pos']);
        $mesero = trim($_POST['mesero_asignado']);
        $venta_total = floatval($_POST['venta_total']);
        
        // Campos de comisión
        $porcentaje_comision = floatval($_POST['porcentaje_comision']);
        $monto_comision = floatval($_POST['monto_comision']);
        
        // Cálculo automático de propina (5%) - pero 0 si comisión es 0%
        $propina = ($porcentaje_comision > 0) ? $venta_total * 0.05 : 0;
        
        $id_reserva = $folio ? $folio['id'] : 0;

        if (!$folio) {
            $error = "El Folio no es válido. Debe iniciar con VTA (o EV si es evento) seguido del número de reserva, ej: 'VTA123' o 'VTA123A'.";
        } elseif (empty($folio_pos)) {
            $error = "El número de cuenta (Folio POS) es obligatorio.";
        } elseif ($venta_total <= 0) {
            $error = "El total de la venta debe ser mayor a cero.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // 1. Verificar reserva (puede ser Evento o Normal)
                $is_evento = $folio['es_evento'];

                if ($is_evento) {
                    $stmt_check = $pdo->prepare("SELECT estado FROM reservas_evento WHERE id = :id FOR UPDATE");
                } else {
                    $stmt_check = $pdo->prepare("SELECT estado FROM reservas WHERE id = :id FOR UPDATE");
                }
                
                $stmt_check->execute(['id' => $id_reserva]);
                $reserva = $stmt_check->fetch();

                if (!$reserva) {
                    $error = "No se encontro ninguna reserva asociada al Folio $folio_btla.";
                    $pdo->rollBack();
                } elseif (!in_array($reserva['estado'], ['iniciada', 'finalizada'])) {
                    $error = "La reserva $folio_btla no se puede cerrar: su estado es '{$reserva['estado']}'. Solo se cierran folios iniciados o finalizados; Hostess debe confirmar la llegada primero.";
                    $pdo->rollBack();
                } else {
                    // 2. Insertar en cuentas_cerradas
                    $sql_insert = "INSERT INTO cuentas_cerradas 
                        (id_reserva, folio_cierre, folio_pos, mesero_asignado, venta_total, porcentaje_comision, monto_comision, propina, fecha_cierre)
                        VALUES (:id_reserva, :folio_cierre, :folio_pos, :mesero, :venta_total, :por_com, :monto_com, :propina, NOW())";
                    
                    $stmt_insert = $pdo->prepare($sql_insert);
                    $stmt_insert->execute([
                        'id_reserva' => $id_reserva,
                        'folio_cierre' => strtoupper($folio_btla),
                        'folio_pos' => $folio_pos,
                        'mesero' => $mesero,
                        'venta_total' => $venta_total,
                        'por_com' => $porcentaje_comision,
                        'monto_com' => $monto_comision,
                        'propina' => $propina
                    ]);
                    $id_cierre = $pdo->lastInsertId();

                    // Actualizar el estado de la reserva a finalizada si no lo estaba
                    if ($reserva['estado'] === 'iniciada') {
                        if ($is_evento) {
                            $sql_update = "UPDATE reservas_evento SET estado = 'finalizada' WHERE id = :id";
                        } else {
                            $sql_update = "UPDATE reservas SET estado = 'finalizada' WHERE id = :id";
                        }
                        $pdo->prepare($sql_update)->execute(['id' => $id_reserva]);
                    }
                    $pdo->commit();
                    $success = "Cuenta $folio_btla cerrada exitosamente (Propina calculada: $" . number_format($propina, 2) . "). <br><a href='../print/ticket_cierre.php?id_cierre={$id_cierre}' target='_blank' class='font-bold underline text-black'>Haz clic aquí si el ticket no se abre automáticamente.</a>";
                    
                    // Disparar ticket de cierre
                    echo "<script>
                        setTimeout(function() {
                            window.open('../print/ticket_cierre.php?id_cierre={$id_cierre}', 'TicketCierre_{$id_cierre}', 'width=300,height=500,scrollbars=yes,resizable=yes');
                        }, 500);
                    </script>";
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error al cerrar la cuenta: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] == 'reimprimir_ticket') {
        $folio_reimprimir = strtoupper(trim($_POST['folio_reimprimir']));
        $stmt_reimprimir = $pdo->prepare("SELECT id FROM cuentas_cerradas WHERE folio_cierre LIKE :folio");
        $stmt_reimprimir->execute(['folio' => $folio_reimprimir . '%']);
        $cuentas_reimprimir = $stmt_reimprimir->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($cuentas_reimprimir) > 0) {
            echo "<script>";
            $delay = 500;
            foreach ($cuentas_reimprimir as $index => $cuenta) {
                $id_cierre = $cuenta['id'];
                echo "setTimeout(function() {
                    window.open('../print/ticket_cierre.php?id_cierre={$id_cierre}', 'TicketCierre_{$id_cierre}_{$index}', 'width=300,height=500,scrollbars=yes,resizable=yes');
                }, {$delay});\n";
                $delay += 1000;
            }
            echo "</script>";
            $success = "Reimprimiendo " . count($cuentas_reimprimir) . " tickets del folio " . htmlspecialchars($folio_reimprimir) . ".";
        } else {
            $error = "No se encontró ninguna cuenta cerrada con el folio " . htmlspecialchars($folio_reimprimir);
        }
    }
}

// LÓGICA PARA REIMPRIMIR RESERVAS NO CERRADAS
$filtro_tab_reservas = $_GET['tab_reservas'] ?? 'iniciadas';
$fecha_inicio_reservas = $_GET['fecha_inicio_reservas'] ?? '';
$fecha_fin_reservas = $_GET['fecha_fin_reservas'] ?? '';

$estado_filtro = 'iniciada';
if ($filtro_tab_reservas == 'nuevas') $estado_filtro = 'nueva';
if ($filtro_tab_reservas == 'finalizadas') $estado_filtro = 'finalizada';

$where_extra_r = "";
$where_extra_e = "";
$params_activas = ['estado' => $estado_filtro];

if (!empty($fecha_inicio_reservas) && !empty($fecha_fin_reservas)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $where_extra_r = " AND r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
    $where_extra_e = " AND re.fecha_hora >= :fecha_inicio AND re.fecha_hora < :fecha_fin";
    $params_activas['fecha_inicio'] = turno_inicio($fecha_inicio_reservas);
    $params_activas['fecha_fin'] = turno_fin($fecha_fin_reservas);
}

$stmt_activas = $pdo->prepare("
    SELECT r.id, r.nombre_cliente, u.nombre_completo AS nombre_rp, r.fecha_reserva, r.num_personas, r.estado, 'normal' as tipo 
    FROM reservas r
    JOIN usuarios u ON r.id_rp = u.id
    WHERE r.estado = :estado $where_extra_r
    UNION ALL
    SELECT re.id, re.nombre_cliente, e.nombre AS nombre_rp, re.fecha_hora as fecha_reserva, re.num_personas, re.estado, 'evento' as tipo
    FROM reservas_evento re
    JOIN eventos e ON re.id_evento = e.id
    WHERE re.estado = :estado $where_extra_e
    ORDER BY fecha_reserva ASC
");
$stmt_activas->execute($params_activas);
$reservas_activas = $stmt_activas->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Columna Izquierda: Cerrar Cuenta -->
    <div class="glass-card rounded-2xl p-6 sm:p-8">
        <div class="flex items-center mb-6">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-700/20 flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-white">Cerrar Cuenta</h2>
                <p class="text-xs text-neutral-500">Datos del ticket de Hostes y POS</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">
                <div class="flex items-start">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo $error; ?></span>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm">
                <div class="flex items-start">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo $success; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" autocomplete="off" class="space-y-4">
            <input type="hidden" name="accion" value="cerrar_cuenta">
            
            <div>
                <label for="folio_btla" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Folio del Ticket</label>
                <input type="text" name="folio_btla" id="folio_btla" placeholder="Ej: VTA355A" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white uppercase" required
                       pattern="\s*([Vv][Tt][Aa]|[Ee][Vv])\s*[0-9]+\s*(-\s*[A-Za-z0-9]{1,3}|[A-Za-z][A-Za-z0-9]{0,2})?\s*"
                       title="Debe iniciar con VTA (o EV si es evento) y el número de reserva. Ej: VTA355 o VTA355A"
                       oninput="verificarFolio()">
                <p id="folio_estado" class="hidden mt-1.5 text-xs"></p>
            </div>
            <div>
                <label for="folio_pos" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Número de Cuenta (POS)</label>
                <input type="text" name="folio_pos" id="folio_pos" placeholder="Ej: 00456" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            
            <div>
                <label for="mesero_asignado" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Mesero Asignado</label>
                <input type="text" name="mesero_asignado" id="mesero_asignado" placeholder="Nombre del mesero" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            
            <div>
                <label for="venta_total" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Venta Total ($)</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                        <span class="text-neutral-500 text-sm">$</span>
                    </div>
                    <input type="number" name="venta_total" id="venta_total" step="0.01" min="0.01" class="input-premium rounded-xl w-full py-2.5 pl-8 pr-4 text-sm text-white" placeholder="0.00" required oninput="calcularPropina(); calcularComision();">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">% Comisión</label>
                    <select name="porcentaje_comision" id="porcentaje_comision" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" onchange="calcularComision()">
                        <option value="0">0%</option>
                        <option value="10">10%</option>
                        <option value="15">15%</option>
                        <option value="30">30%</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">$ Comisión</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                            <span class="text-neutral-600 text-sm">$</span>
                        </div>
                        <input type="number" name="monto_comision" id="monto_comision" step="0.01" placeholder="0.00" readonly class="input-premium rounded-xl w-full py-2.5 pl-8 pr-4 text-sm text-neutral-500 cursor-not-allowed opacity-60">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Propina Retenida (5% Auto)</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                        <span class="text-neutral-600 text-sm">$</span>
                    </div>
                    <input type="text" id="propina_visual" readonly class="input-premium rounded-xl w-full py-2.5 pl-8 pr-4 text-sm text-neutral-500 cursor-not-allowed opacity-60" placeholder="0.00">
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" id="btn_cerrar_cuenta" class="btn-amber w-full py-3 px-4 rounded-xl text-sm font-semibold text-white disabled:opacity-40 disabled:cursor-not-allowed">
                    Cerrar Reserva
                </button>
            </div>
        </form>
    </div>

    <!-- Columna Derecha: Exportar y Reimprimir -->
    <div class="flex flex-col gap-6">
        <!-- Exportar Reporte -->
        <div class="glass-card rounded-2xl p-6 sm:p-8">
            <div class="flex items-center mb-5">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-emerald-700/20 flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Exportar Reporte</h3>
                    <p class="text-xs text-neutral-500">Descarga el Excel de cuentas cerradas</p>
                </div>
            </div>
            
            <form action="exportar_caja.php" method="GET" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha del turno (5:00 pm a 4:59 pm del día siguiente)</label>
                    <input type="date" name="fecha_exportar" value="<?php echo turno_hoy(); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
                </div>
                <button type="submit" class="btn-secondary w-full py-2.5 px-4 rounded-xl text-sm font-semibold text-white flex items-center justify-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Descargar Excel
                </button>
            </form>
        </div>

        <!-- Reimprimir Ticket -->
        <div class="glass-card rounded-2xl p-6 sm:p-8">
            <div class="flex items-center mb-5">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-blue-700/20 flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Reimprimir Ticket</h3>
                    <p class="text-xs text-neutral-500">Ingresa el folio para reimprimir</p>
                </div>
            </div>
            
            <form action="index.php" method="POST" autocomplete="off" class="space-y-4">
                <input type="hidden" name="accion" value="reimprimir_ticket">
                <div>
                    <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Folio del Ticket</label>
                    <input type="text" name="folio_reimprimir" placeholder="Ej: VTA355A" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
                </div>
                <button type="submit" class="btn-amber w-full py-2.5 px-4 rounded-xl text-sm font-semibold text-white flex items-center justify-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Reimprimir Ticket
                </button>
            </form>
        </div>
    </div>
</div>

<!-- TABLA DE RESERVAS NO CERRADAS -->
<div class="max-w-6xl mx-auto mt-10">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-bold text-white">Reimprimir Tickets de Reservas (No Cerradas)</h2>
    </div>

    <!-- Tabs -->
    <div class="mb-6">
        <div class="flex space-x-2">
            <a href="index.php?tab_reservas=iniciadas" class="<?php echo $filtro_tab_reservas == 'iniciadas' ? 'bg-amber-600/90 text-white shadow-sm' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/50'; ?> rounded-lg px-5 py-2.5 text-sm font-medium transition-all duration-200">
                Iniciadas
            </a>
            <a href="index.php?tab_reservas=nuevas" class="<?php echo $filtro_tab_reservas == 'nuevas' ? 'bg-amber-600/90 text-white shadow-sm' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/50'; ?> rounded-lg px-5 py-2.5 text-sm font-medium transition-all duration-200">
                Nuevas
            </a>
            <a href="index.php?tab_reservas=finalizadas" class="<?php echo $filtro_tab_reservas == 'finalizadas' ? 'bg-amber-600/90 text-white shadow-sm' : 'text-neutral-400 hover:text-white hover:bg-neutral-800/50'; ?> rounded-lg px-5 py-2.5 text-sm font-medium transition-all duration-200">
                Finalizadas
            </a>
        </div>
    </div>

    <!-- Filtros de fecha -->
    <div class="mb-6">
        <form method="GET" action="index.php" class="flex flex-col sm:flex-row items-end gap-3 bg-neutral-900/40 p-4 rounded-xl border border-neutral-800">
            <input type="hidden" name="tab_reservas" value="<?php echo htmlspecialchars($filtro_tab_reservas); ?>">
            <div class="w-full sm:w-auto">
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Inicio</label>
                <input type="date" name="fecha_inicio_reservas" value="<?php echo htmlspecialchars($fecha_inicio_reservas); ?>" class="input-premium rounded-lg py-2 px-3 text-sm w-full">
            </div>
            <div class="w-full sm:w-auto">
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Fin</label>
                <input type="date" name="fecha_fin_reservas" value="<?php echo htmlspecialchars($fecha_fin_reservas); ?>" class="input-premium rounded-lg py-2 px-3 text-sm w-full">
            </div>
            <div class="flex gap-2 w-full sm:w-auto">
                <button type="submit" class="btn-secondary py-2 px-4 rounded-lg text-sm text-white border border-neutral-600">Filtrar</button>
                <a href="index.php?tab_reservas=<?php echo htmlspecialchars($filtro_tab_reservas); ?>" class="btn-secondary opacity-70 py-2 px-4 rounded-lg text-sm text-white text-center">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden mb-10">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio</th>
                        <th scope="col" class="py-3.5 pl-3 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">RP / Evento</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Hora Reserva</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Personas</th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-5">
                            <span class="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($reservas_activas)): ?>
                        <tr>
                            <td colspan="6" class="px-3 py-10 text-sm text-neutral-500 text-center">No hay reservas <?php echo $filtro_tab_reservas; ?>.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($reservas_activas as $res): ?>
                        <tr class="transition-colors duration-150">
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo ($res['tipo'] == 'evento' ? 'EV' : 'VTA') . $res['id']; ?></td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($res['nombre_cliente']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm font-semibold text-white"><?php echo htmlspecialchars($res['nombre_rp']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($res['fecha_reserva'])); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo $res['num_personas']; ?></td>
                            <td class="relative whitespace-nowrap py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                                <?php if ($res['tipo'] == 'normal'): ?>
                                    <button onclick="window.open('../print/ticket.php?id=<?php echo $res['id']; ?>', 'Ticket_<?php echo $res['id']; ?>', 'width=300,height=500,scrollbars=yes,resizable=yes')" class="text-neutral-400 hover:text-white transition-colors duration-200">Reimprimir Ticket</button>
                                <?php else: ?>
                                    <button onclick="window.open('../print/ticket_evento.php?id=<?php echo $res['id']; ?>', 'TicketEvento_<?php echo $res['id']; ?>', 'width=300,height=600,scrollbars=yes,resizable=yes')" class="text-neutral-400 hover:text-white transition-colors duration-200">Reimprimir Evento</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Verifica el folio mientras se escribe: muestra a quién pertenece y bloquea el cierre
// si no existe o no está iniciado/finalizado. El servidor valida de nuevo al enviar.
let folioTimer = null;
let folioConsulta = 0;

function verificarFolio() {
    const input = document.getElementById('folio_btla');
    const folio = input.value.trim().toUpperCase();
    const consulta = ++folioConsulta; // invalida respuestas de folios anteriores

    clearTimeout(folioTimer);
    if (folio === '') {
        mostrarEstadoFolio('', '', true);
        return;
    }
    // Mismo formato que parsear_folio() en includes/folio.php
    if (!/^(VTA|EV)\s*\d+\s*(-\s*[A-Z0-9]{1,3}|[A-Z][A-Z0-9]{0,2})?$/.test(folio)) {
        mostrarEstadoFolio('El folio debe iniciar con VTA (o EV si es evento) y el número de reserva. Ej: VTA355 o VTA355A', 'text-red-400', false);
        return;
    }

    folioTimer = setTimeout(async () => {
        try {
            const response = await fetch(`../api/verificar_folio.php?folio=${encodeURIComponent(folio)}`);
            const r = await response.json();
            if (consulta !== folioConsulta) return; // ya se escribió otro folio

            if (!r.success) {
                mostrarEstadoFolio(r.message, 'text-red-400', false);
            } else if (!r.cerrable) {
                mostrarEstadoFolio(`${r.folio} · ${r.nombre_cliente} (${r.nombre_rp}) está en estado "${r.estado}". Solo se cierran folios iniciados o finalizados.`, 'text-red-400', false);
            } else {
                let texto = `${r.folio} · ${r.nombre_cliente} · ${r.nombre_rp} · ${r.estado}`;
                let color = 'text-emerald-400';
                if (r.cierres.length > 0) {
                    texto += ` — ya tiene ${r.cierres.length} cierre(s): ` + r.cierres.map(c => `${c.folio_cierre} (POS ${c.folio_pos})`).join(', ');
                    color = 'text-amber-400';
                }
                mostrarEstadoFolio(texto, color, true);
            }
        } catch (error) {
            // Sin respuesta del servidor no se bloquea: el cierre se valida al enviar
            if (consulta === folioConsulta) mostrarEstadoFolio('', '', true);
        }
    }, 350);
}

function mostrarEstadoFolio(texto, color, permitirCierre) {
    const estado = document.getElementById('folio_estado');
    estado.textContent = texto;
    estado.className = (texto ? '' : 'hidden ') + 'mt-1.5 text-xs ' + color;
    document.getElementById('btn_cerrar_cuenta').disabled = !permitirCierre;
}

function calcularPropina() {
    const ventaInput = document.getElementById('venta_total').value;
    const venta = parseFloat(ventaInput);
    const porcentaje = parseFloat(document.getElementById('porcentaje_comision').value);

    let propina = 0;
    if (!isNaN(venta) && venta > 0 && porcentaje > 0) {
        propina = venta * 0.05;
    }

    document.getElementById('propina_visual').value = propina.toFixed(2);
}

function calcularComision() {
    const porcentajeSelect = document.getElementById('porcentaje_comision');
    const montoInput = document.getElementById('monto_comision');
    const ventaInput = document.getElementById('venta_total').value;
    const venta = parseFloat(ventaInput);
    
    if (!isNaN(venta) && venta > 0) {
        const porcentaje = parseFloat(porcentajeSelect.value);
        const monto = venta * (porcentaje / 100);
        montoInput.value = monto.toFixed(2);
    } else {
        montoInput.value = '';
    }
    // Recalcular propina al cambiar comisión
    calcularPropina();
}
</script>

<?php require_once '../includes/footer.php'; ?>