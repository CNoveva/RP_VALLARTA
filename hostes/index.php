<?php
require_once '../includes/header.php';
require_once '../includes/turno.php';
check_role(['hostes']);

// --- 1. Lógica de Pestañas y Filtros ---
$filtro_tab = $_GET['tab'] ?? 'nuevas';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$busqueda = trim($_GET['q'] ?? '');

$error = '';
$success = '';

// --- Reimpresión Manual de Cierres ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'reimprimir_ticket_cierre') {
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

// --- 2. Consulta a BD ---
$reservas = [];
if ($filtro_tab !== 'cerrar' && $filtro_tab !== 'escanear_qr') {
    // Siempre acomodadas por fecha de reserva (y por cliente dentro de la misma hora):
    // pendientes de la más próxima a la más lejana; cerradas/historial de la más reciente a la más antigua.
    $where_clause = "";
    $order_by = "ORDER BY r.fecha_reserva ASC, r.nombre_cliente ASC";

    if ($filtro_tab == 'nuevas') {
        $where_clause = "WHERE r.estado = 'nueva'";
    } elseif ($filtro_tab == 'iniciadas') {
        $where_clause = "WHERE r.estado = 'iniciada'";
    } elseif ($filtro_tab == 'finalizadas') {
        $where_clause = "WHERE r.estado = 'finalizada'";
        $order_by = "ORDER BY r.fecha_reserva DESC, r.nombre_cliente ASC";
    } elseif ($filtro_tab == 'historial') {
        $where_clause = "WHERE (r.estado = 'inasistente' OR r.estado = 'cancelada')";
        $order_by = "ORDER BY r.fecha_reserva DESC, r.nombre_cliente ASC";
    }

    $params = [];
    if (!empty($fecha_inicio) && !empty($fecha_fin)) {
        // Rango por turno (5 pm a 4:59 pm del día siguiente)
        $where_clause .= " AND r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
        $params['fecha_inicio'] = turno_inicio($fecha_inicio);
        $params['fecha_fin'] = turno_fin($fecha_fin);
    }

    // Búsqueda por cliente, RP o folio dentro de la pestaña
    if ($busqueda !== '') {
        $like = '%' . addcslashes($busqueda, '%_\\') . '%';
        $where_clause .= " AND (r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR CONCAT('VTA', r.id) LIKE :q_folio)";
        $params['q_cliente'] = $like;
        $params['q_rp'] = $like;
        $params['q_folio'] = $like;
    }

    $sql = "
        SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp, cc.id AS id_cierre
        FROM reservas r
        JOIN usuarios u ON r.id_rp = u.id
        LEFT JOIN cuentas_cerradas cc ON r.id = cc.id_reserva
        $where_clause
        $order_by
        LIMIT 100 
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Consulta de Reservas de Evento (para tab escanear_qr) ---
$reservas_evento = [];
if ($filtro_tab == 'escanear_qr') {
    $where_ev = "WHERE 1=1";
    $params_ev = [];
    if (!empty($fecha_inicio) && !empty($fecha_fin)) {
        $where_ev .= " AND re.fecha_hora >= :fecha_inicio AND re.fecha_hora < :fecha_fin";
        $params_ev['fecha_inicio'] = turno_inicio($fecha_inicio);
        $params_ev['fecha_fin'] = turno_fin($fecha_fin);
    }

    $stmt_ev = $pdo->prepare("
        SELECT re.*, e.nombre AS nombre_evento 
        FROM reservas_evento re 
        JOIN eventos e ON re.id_evento = e.id 
        $where_ev
        ORDER BY CASE WHEN re.estado IN ('pendiente', 'iniciada') THEN 1 ELSE 2 END, re.fecha_creacion DESC
        LIMIT 100
    ");
    $stmt_ev->execute($params_ev);
    $reservas_evento = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);
}

// La búsqueda se conserva al cambiar de pestaña (buscar al mismo cliente en Nuevas, Iniciadas, etc.)
$q_url = ($busqueda !== '') ? '&q=' . urlencode($busqueda) : '';
$dias_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
?>

<!-- Tabs -->
<div class="mb-6">
    <!-- Mobile select -->
    <div class="sm:hidden">
        <label for="tabs" class="sr-only">Selecciona una pestaña</label>
        <select id="tabs" name="tabs" class="input-premium block w-full rounded-xl py-2.5 px-4 text-sm text-white" 
                onchange="window.location.href = this.value;">
            <option value="index.php?tab=nuevas<?php echo htmlspecialchars($q_url); ?>" <?php echo ($filtro_tab == 'nuevas') ? 'selected' : ''; ?>>
                Nuevas
            </option>
            <option value="index.php?tab=iniciadas<?php echo htmlspecialchars($q_url); ?>" <?php echo ($filtro_tab == 'iniciadas') ? 'selected' : ''; ?>>
                Iniciadas
            </option>
            <option value="index.php?tab=finalizadas<?php echo htmlspecialchars($q_url); ?>" <?php echo ($filtro_tab == 'finalizadas') ? 'selected' : ''; ?>>
                Finalizadas
            </option>
            <option value="index.php?tab=escanear_qr" <?php echo ($filtro_tab == 'escanear_qr') ? 'selected' : ''; ?>>
                Escanear QR Evento
            </option>
            <option value="index.php?tab=historial<?php echo htmlspecialchars($q_url); ?>" <?php echo ($filtro_tab == 'historial') ? 'selected' : ''; ?>>
                Inasistencias
            </option>
            <option value="visitas.php">Sin reserva</option>
        </select>
    </div>
    <!-- Desktop tabs -->
    <div class="hidden sm:block">
        <nav class="flex space-x-1 bg-neutral-900/50 rounded-xl p-1" aria-label="Tabs">
            <?php
            $tabs = [
                'nuevas' => 'Nuevas',
                'iniciadas' => 'Iniciadas',
                'finalizadas' => 'Finalizadas',
                'escanear_qr' => 'Escanear QR',
                'historial' => 'Inasistencias',
            ];
            foreach ($tabs as $key => $label):
                $isActive = ($filtro_tab == $key);
            ?>
                <a href="index.php?tab=<?php echo $key; ?><?php echo ($key != 'escanear_qr') ? htmlspecialchars($q_url) : ''; ?>"
                   class="<?php echo $isActive
                       ? 'bg-amber-600/90 text-white shadow-sm' 
                       : 'text-neutral-400 hover:text-white hover:bg-neutral-800/50'; ?> 
                       rounded-lg px-4 py-2 text-sm font-medium transition-all duration-200 flex-1 text-center">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
            <a href="visitas.php" class="text-neutral-400 hover:text-white hover:bg-neutral-800/50 rounded-lg px-4 py-2 text-sm font-medium transition-all duration-200 flex-1 text-center">
                Sin reserva
            </a>
        </nav>
    </div>
</div>

<!-- Date Filter -->
<div class="mb-6">
    <form method="GET" action="index.php" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row sm:flex-wrap items-end gap-3">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($filtro_tab); ?>">

        <?php if ($filtro_tab != 'escanear_qr'): ?>
        <!-- Buscador: filtra la tabla al escribir; Enter/Filtrar busca en todas las reservas de la pestaña -->
        <div class="w-full sm:flex-1 sm:min-w-[16rem]">
            <label for="buscar_cliente" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Buscar Cliente</label>
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <svg class="w-4 h-4 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
                <input type="search" name="q" id="buscar_cliente" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Nombre del cliente, RP o folio (VTA123)" autocomplete="off" oninput="filtrarTabla()" class="input-premium rounded-xl w-full py-2 pl-9 pr-3 text-sm text-white">
            </div>
        </div>
        <?php endif; ?>

        <div class="w-full sm:w-auto">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Inicio</label>
            <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        
        <div class="w-full sm:w-auto">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha Fin</label>
            <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="btn-amber flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-semibold text-white">
                Filtrar
            </button>
            <a href="index.php?tab=<?php echo htmlspecialchars($filtro_tab); ?>" class="btn-secondary flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">
                Limpiar
            </a>
        </div>
    </form>
</div>

<?php if ($filtro_tab != 'escanear_qr'): ?>
<div>
    <!-- Title -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-5">
        <div>
            <h1 class="text-xl font-bold text-white">
                <?php
                    if ($filtro_tab == 'nuevas') echo 'Reservas Nuevas';
                    if ($filtro_tab == 'iniciadas') echo 'Reservas Iniciadas';
                    if ($filtro_tab == 'finalizadas') echo 'Reservas Finalizadas';
                    if ($filtro_tab == 'historial') echo 'Inasistencias / Canceladas';
                ?>
            </h1>
            <p class="mt-1 text-sm text-neutral-500">
                <?php
                    if ($filtro_tab == 'nuevas') echo 'Reservas pendientes por confirmar llegada.';
                    if ($filtro_tab == 'iniciadas') echo 'Reservas que ya están en curso.';
                    if ($filtro_tab == 'finalizadas') echo 'Reservas que ya pagaron y se cerraron.';
                    if ($filtro_tab == 'historial') echo 'Reservas canceladas o que no llegaron.';
                ?>
            </p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="crear_reserva.php" class="btn-amber inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                Nueva reserva
            </a>
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

    <?php if ($filtro_tab == 'finalizadas'): ?>
    <!-- Reimprimir Ticket en Hostes (Finalizadas) -->
    <div class="glass-card rounded-2xl p-6 sm:p-8 mb-6 max-w-2xl">
        <div class="flex items-center mb-5">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-blue-700/20 flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            </div>
            <div>
                <h3 class="text-lg font-bold text-white">Reimprimir Ticket de Cierre</h3>
                <p class="text-xs text-neutral-500">Ingresa el folio de la reserva (ej. VTA123) para imprimir todos sus cierres</p>
            </div>
        </div>
        
        <form action="index.php?tab=finalizadas" method="POST" autocomplete="off" class="flex flex-col sm:flex-row gap-4">
            <input type="hidden" name="accion" value="reimprimir_ticket_cierre">
            <div class="flex-grow">
                <input type="text" name="folio_reimprimir" placeholder="Ej: VTA355" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <button type="submit" class="btn-amber py-2.5 px-6 rounded-xl text-sm font-semibold text-white flex items-center justify-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                Reimprimir
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio</th>
                        <th scope="col" class="py-3.5 pl-3 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">RP</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Tipo RP</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Hora Reserva</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Personas</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-5">
                            <span class="sr-only">Acción</span>
                        </th>
                    </tr>
                </thead>
                <tbody id="tabla-reservas-body" class="divide-y divide-neutral-800/50">
                    <?php if (empty($reservas)): ?>
                        <tr id="fila-sin-reservas">
                            <td colspan="8" class="px-3 py-10 text-sm text-neutral-500 text-center">
                                <?php if ($busqueda !== ''): ?>
                                    No se encontraron reservas con "<?php echo htmlspecialchars($busqueda); ?>" en esta pestaña.
                                <?php else: ?>
                                    No se encontraron reservas para esta categoría.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr id="fila-sin-coincidencias" class="hidden">
                        <td colspan="8" class="px-3 py-10 text-sm text-neutral-500 text-center">
                            Ninguna reserva de la lista coincide. Presiona Enter para buscar en todas las reservas de la pestaña.
                        </td>
                    </tr>

                    <?php $fecha_grupo_actual = null; ?>
                    <?php foreach ($reservas as $reserva): ?>
                        <?php
                            // Encabezado por día: las reservas vienen acomodadas por fecha
                            // Agrupado por turno: una reserva de la 1:00 am pertenece al día anterior
                            $fecha_grupo = turno_de($reserva['fecha_reserva']);
                            if ($fecha_grupo !== $fecha_grupo_actual):
                                $fecha_grupo_actual = $fecha_grupo;
                        ?>
                        <tr class="fila-fecha bg-neutral-900/60" data-orden="<?php echo $fecha_grupo; ?>">
                            <td colspan="8" class="py-2 pl-5 pr-3 text-xs font-semibold uppercase tracking-wider text-amber-500">
                                <?php echo $dias_semana[date('w', strtotime($fecha_grupo))] . ' ' . date('d/m/Y', strtotime($fecha_grupo)); ?>
                                <?php if ($fecha_grupo == turno_hoy()): ?><span class="ml-2 rounded-md bg-amber-500/15 px-2 py-0.5 text-amber-400">Hoy</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php
                            $color_fondo = '';
                            $color_span = 'bg-blue-500/15 text-blue-400'; // nueva
                            if ($reserva['estado'] == 'iniciada') {
                                $color_fondo = 'bg-amber-500/5';
                                $color_span = 'bg-amber-500/15 text-amber-400';
                            }
                            if ($reserva['estado'] == 'finalizada') $color_span = 'bg-emerald-500/15 text-emerald-400';
                            if ($reserva['estado'] == 'cancelada' || $reserva['estado'] == 'inasistente') $color_span = 'bg-red-500/15 text-red-400';
                        ?>
                        <tr id="reserva-<?php echo $reserva['id']; ?>" class="fila-reserva <?php echo $color_fondo; ?> transition-colors duration-150"
                            data-orden="<?php echo htmlspecialchars($reserva['fecha_reserva']); ?>"
                            data-busqueda="<?php echo htmlspecialchars('VTA' . $reserva['id'] . ' ' . $reserva['nombre_cliente'] . ' ' . $reserva['nombre_rp']); ?>">
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo 'VTA' . $reserva['id']; ?></td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm font-semibold text-white"><?php echo htmlspecialchars($reserva['nombre_rp']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400"><?php echo htmlspecialchars($reserva['tipo_rp'] ?? '-'); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_reserva'])); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 hidden sm:table-cell"><span id="personas-<?php echo $reserva['id']; ?>"><?php echo $reserva['num_personas']; ?></span></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $color_span; ?>">
                                    <?php echo htmlspecialchars(ucfirst($reserva['estado'])); ?>
                                </span>
                            </td>
                            <td class="relative whitespace-nowrap py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                                <?php if ($reserva['estado'] === 'nueva'): ?>
                                    <div class="flex flex-col space-y-1.5">
                                        <button id="btn-conf-<?php echo $reserva['id']; ?>" onclick="this.disabled=true; confirmarLlegada(<?php echo $reserva['id']; ?>)" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">
                                            Confirmar Llegada
                                        </button>
                                        <button onclick="marcarInasistente(<?php echo $reserva['id']; ?>)" class="text-red-500/60 hover:text-red-400 text-xs transition-colors duration-200">
                                            Marcar Inasistente
                                        </button>
                                    </div>
                                <?php elseif ($reserva['estado'] === 'iniciada'): ?>
                                    <div class="flex flex-col space-y-1.5">
                                        <button onclick="cambiarPersonas(<?php echo $reserva['id']; ?>)" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">
                                            Cambiar personas
                                        </button>
                                        <button onclick="reimprimirTicket(<?php echo $reserva['id']; ?>)" class="text-neutral-400 hover:text-white transition-colors duration-200">
                                            Reimprimir Ticket
                                        </button>
                                        <button onclick="regresarANueva(<?php echo $reserva['id']; ?>)" class="text-neutral-500 hover:text-neutral-300 text-xs transition-colors duration-200">
                                            Regresar a Nueva
                                        </button>
                                    </div>
                                <?php elseif ($reserva['estado'] === 'finalizada' && !empty($reserva['id_cierre'])): ?>
                                    <button onclick="window.open('../print/ticket_cierre.php?id_cierre=<?php echo $reserva['id_cierre']; ?>', 'TicketCierre_<?php echo $reserva['id_cierre']; ?>', 'width=300,height=500,scrollbars=yes,resizable=yes')" class="text-neutral-400 hover:text-white transition-colors duration-200">
                                        Reimprimir Cierre
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($filtro_tab == 'escanear_qr'): ?>
<!-- ========================================== -->
<!-- ESCANER QR DE EVENTOS -->
<!-- ========================================== -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
    <div>
        <h1 class="text-xl font-bold text-white">Escanear QR de Evento</h1>
        <p class="mt-1 text-sm text-neutral-500">Escanea el codigo QR del cliente para iniciar su reservacion de evento.</p>
    </div>
</div>

<div class="max-w-lg mx-auto">
    <div class="glass-card rounded-2xl p-6 text-center">
        <div id="qr-reader" class="mb-4" style="width: 100%;"></div>
        <div id="qr-result" class="hidden mt-4 p-4 rounded-lg">
        </div>
        <button id="btn-iniciar-scan" onclick="iniciarEscaner()" class="mt-4 btn-amber inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-semibold text-white">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            Iniciar Camara
        </button>
        <button id="btn-detener-scan" onclick="detenerEscaner()" class="hidden mt-4 btn-secondary inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-semibold text-red-400 border-red-500/30">
            Detener Camara
        </button>
    </div>
</div>

<!-- Modal de resultado -->
<div id="qr-modal-resultado" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl p-8 max-w-sm w-full text-center" id="qr-modal-content">
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let html5QrCode = null;
let escaneando = false;

function iniciarEscaner() {
    if (escaneando) return;
    
    html5QrCode = new Html5Qrcode("qr-reader");
    
    html5QrCode.start(
        { facingMode: "environment" },
        {
            fps: 10,
            qrbox: { width: 250, height: 250 }
        },
        onScanSuccess,
        onScanFailure
    ).then(() => {
        escaneando = true;
        document.getElementById('btn-iniciar-scan').classList.add('hidden');
        document.getElementById('btn-detener-scan').classList.remove('hidden');
    }).catch(err => {
        alert('Error al acceder a la camara: ' + err);
    });
}

function detenerEscaner() {
    if (html5QrCode && escaneando) {
        html5QrCode.stop().then(() => {
            escaneando = false;
            document.getElementById('btn-iniciar-scan').classList.remove('hidden');
            document.getElementById('btn-detener-scan').classList.add('hidden');
        });
    }
}

async function onScanSuccess(decodedText) {
    if (html5QrCode && escaneando) {
        await html5QrCode.stop();
        escaneando = false;
        document.getElementById('btn-iniciar-scan').classList.remove('hidden');
        document.getElementById('btn-detener-scan').classList.add('hidden');
    }

    let token = '';
    try {
        const url = new URL(decodedText);
        token = url.searchParams.get('token');
    } catch (e) {
        token = decodedText;
    }

    if (!token) {
        mostrarResultadoModal('error', 'QR no valido', 'El codigo QR escaneado no contiene un token valido.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('token', token);
        
        const response = await fetch('../api/escanear_qr.php', {
            method: 'POST',
            body: formData
        });
        const resultado = await response.json();

        if (resultado.success) {
            mostrarResultadoModal('success', 'Reserva Iniciada', 
                `<p class="text-neutral-300 mb-2"><strong class="text-white">Cliente:</strong> ${resultado.nombre_cliente}</p>
                 <p class="text-neutral-300 mb-2"><strong class="text-white">Evento:</strong> ${resultado.nombre_evento}</p>
                 <p class="text-neutral-300 mb-2"><strong class="text-white">Personas:</strong> ${resultado.num_personas}</p>
                 <p class="text-neutral-300 mb-2"><strong class="text-white">Folio Stripe:</strong> ${resultado.folio_stripe}</p>
                 <p class="text-neutral-300 mb-4"><strong class="text-white">Monto consumo:</strong> $${parseFloat(resultado.monto_consumo).toLocaleString('es-MX', {minimumFractionDigits: 2})}</p>`,
                resultado.id_reserva_evento
            );
        } else {
            if (resultado.tipo === 'ya_usado') {
                mostrarResultadoModal('warning', 'Este QR ya se uso anteriormente', 
                    `<p class="text-amber-300">Este codigo QR ya fue escaneado previamente.</p>
                     ${resultado.nombre_cliente ? '<p class="text-neutral-300 mt-2">Cliente: ' + resultado.nombre_cliente + '</p>' : ''}
                     ${resultado.fecha_escaneo ? '<p class="text-neutral-300">Escaneado: ' + resultado.fecha_escaneo + '</p>' : ''}`
                );
            } else {
                mostrarResultadoModal('error', 'Error', resultado.message);
            }
        }
    } catch (error) {
        mostrarResultadoModal('error', 'Error de conexion', 'No se pudo conectar con el servidor.');
    }
}

function onScanFailure(error) {
    // Silencioso
}

function mostrarResultadoModal(tipo, titulo, contenido, idReservaEvento) {
    const modal = document.getElementById('qr-modal-resultado');
    const modalContent = document.getElementById('qr-modal-content');
    
    let iconColor, iconSvg, borderColor;
    
    if (tipo === 'success') {
        iconColor = 'text-emerald-400';
        borderColor = 'border-emerald-500/30';
        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
    } else if (tipo === 'warning') {
        iconColor = 'text-amber-400';
        borderColor = 'border-amber-500/30';
        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>';
    } else {
        iconColor = 'text-red-400';
        borderColor = 'border-red-500/30';
        iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
    }

    let ticketBtn = '';
    if (tipo === 'success' && idReservaEvento) {
        ticketBtn = `<button onclick="imprimirTicketEvento(${idReservaEvento})" class="w-full mb-3 btn-amber inline-flex items-center justify-center px-4 py-2.5 rounded-xl text-sm font-semibold text-white">Imprimir Ticket</button>`;
    }

    modalContent.className = `glass-card rounded-2xl p-8 max-w-sm w-full text-center ${borderColor}`;
    modalContent.innerHTML = `
        <svg class="mx-auto h-16 w-16 ${iconColor} mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">${iconSvg}</svg>
        <h3 class="text-xl font-bold text-white mb-4">${titulo}</h3>
        <div class="mb-6 text-left">${contenido}</div>
        ${ticketBtn}
        <button onclick="cerrarModalQR()" class="w-full btn-secondary inline-flex items-center justify-center px-4 py-2.5 rounded-xl text-sm font-medium text-neutral-300">
            Cerrar y Escanear Otro
        </button>
    `;
    
    modal.classList.remove('hidden');
}

function cerrarModalQR() {
    document.getElementById('qr-modal-resultado').classList.add('hidden');
}

function imprimirTicketEvento(idReserva) {
    const urlTicket = `../print/ticket_evento.php?id=${idReserva}`;
    window.open(urlTicket, 'TicketEvento_' + idReserva, 'width=300,height=600,scrollbars=yes,resizable=yes');
}
</script>

<!-- TABLA DE RESERVACIONES DE EVENTO -->
<div class="mt-10">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-5">
        <div>
            <h2 class="text-lg font-bold text-white">Reservaciones de Evento</h2>
            <p class="mt-1 text-sm text-neutral-500">Reservaciones pendientes e iniciadas de eventos especiales.</p>
        </div>
    </div>
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th scope="col" class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Folio</th>
                        <th scope="col" class="py-3.5 pl-3 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Cliente</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Evento</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Personas</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden md:table-cell">Fecha/Hora</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden lg:table-cell">Folio Stripe</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Monto</th>
                        <th scope="col" class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-5">
                            <span class="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($reservas_evento)): ?>
                        <tr>
                            <td colspan="9" class="px-3 py-10 text-sm text-neutral-500 text-center">
                                No hay reservaciones de evento.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($reservas_evento as $rev): ?>
                        <?php
                            $ev_color_fondo = '';
                            $ev_color_span = 'bg-blue-500/15 text-blue-400';
                            if ($rev['estado'] == 'iniciada') {
                                $ev_color_fondo = 'bg-amber-500/5';
                                $ev_color_span = 'bg-amber-500/15 text-amber-400';
                            } elseif ($rev['estado'] == 'finalizada') {
                                $ev_color_span = 'bg-emerald-500/15 text-emerald-400';
                            } elseif ($rev['estado'] == 'cancelada' || $rev['estado'] == 'inasistente') {
                                $ev_color_span = 'bg-red-500/15 text-red-400';
                            }
                        ?>
                        <tr class="<?php echo $ev_color_fondo; ?> transition-colors duration-150">
                            <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white"><?php echo 'EV' . $rev['id']; ?></td>
                            <td class="whitespace-nowrap py-3.5 pl-3 pr-3 text-sm font-medium text-white"><?php echo htmlspecialchars($rev['nombre_cliente']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300"><?php echo htmlspecialchars($rev['nombre_evento']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 hidden sm:table-cell"><?php echo $rev['num_personas']; ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 hidden md:table-cell"><?php echo date('d/m/Y h:i A', strtotime($rev['fecha_hora'])); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400 font-mono hidden lg:table-cell"><?php echo htmlspecialchars($rev['folio_stripe']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm font-semibold text-emerald-400 hidden sm:table-cell">$<?php echo number_format($rev['monto_consumo'], 2); ?></td>
                            <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                                <span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold <?php echo $ev_color_span; ?>">
                                    <?php echo htmlspecialchars(ucfirst($rev['estado'])); ?>
                                </span>
                            </td>
                            <td class="relative whitespace-nowrap py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                                <?php if ($rev['estado'] === 'iniciada'): ?>
                                    <button onclick="imprimirTicketEvento(<?php echo $rev['id']; ?>)" class="text-neutral-400 hover:text-white transition-colors duration-200">
                                        Reimprimir
                                    </button>
                                <?php elseif ($rev['estado'] === 'pendiente'): ?>
                                    <span class="text-neutral-600 text-xs">Esperando escaneo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
const currentTab = '<?php echo $filtro_tab; ?>';

    // --- SCRIPTS PARA LA VISTA DE TABLAS ---
    let ultimoIdConocido = <?php 
        if (!empty($reservas)) {
            $ids = array_column($reservas, 'id');
            echo max($ids);
        } else {
            echo 0;
        }
    ?>;

    const fechaInicio = '<?php echo htmlspecialchars($fecha_inicio); ?>';
    const fechaFin = '<?php echo htmlspecialchars($fecha_fin); ?>';
    const busquedaServidor = <?php echo json_encode($busqueda); ?>;

    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto ?? '';
        return div.innerHTML;
    }

    // --- BUSCADOR DE CLIENTE ---
    // Sin acentos ni mayúsculas, para que "jose" encuentre a "José"
    function normalizarTexto(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    // Filtra al escribir las reservas ya cargadas en la tabla
    function filtrarTabla() {
        const input = document.getElementById('buscar_cliente');
        if (!input) return;
        const q = normalizarTexto(input.value.trim());
        const filas = document.querySelectorAll('#tabla-reservas-body tr.fila-reserva');
        let visibles = 0;

        filas.forEach(tr => {
            const coincide = q === '' || normalizarTexto(tr.dataset.busqueda).includes(q);
            tr.classList.toggle('hidden', !coincide);
            if (coincide) visibles++;
        });

        actualizarEncabezadosFecha();
        document.getElementById('fila-sin-coincidencias').classList.toggle('hidden', !(filas.length > 0 && visibles === 0));
    }

    // Oculta los encabezados de día sin reservas visibles y quita los que se quedaron vacíos
    function actualizarEncabezadosFecha() {
        document.querySelectorAll('#tabla-reservas-body tr.fila-fecha').forEach(encabezado => {
            let fila = encabezado.nextElementSibling;
            let tieneFilas = false;
            let tieneVisibles = false;
            while (fila && fila.classList.contains('fila-reserva')) {
                tieneFilas = true;
                if (!fila.classList.contains('hidden')) tieneVisibles = true;
                fila = fila.nextElementSibling;
            }
            if (!tieneFilas) {
                encabezado.remove();
            } else {
                encabezado.classList.toggle('hidden', !tieneVisibles);
            }
        });
    }

    // Inserta una reserva nueva respetando el acomodo por fecha (data-orden ascendente)
    // y crea el encabezado de su día si todavía no existe
    function insertarFilaOrdenada(tbody, tr, reserva) {
        let siguiente = null;
        for (const fila of tbody.querySelectorAll('tr[data-orden]')) {
            if (fila.dataset.orden > tr.dataset.orden) {
                siguiente = fila;
                break;
            }
        }
        tbody.insertBefore(tr, siguiente);

        if (!tbody.querySelector(`tr.fila-fecha[data-orden="${reserva.fecha_grupo}"]`)) {
            const encabezado = document.createElement('tr');
            encabezado.className = 'fila-fecha bg-neutral-900/60';
            encabezado.dataset.orden = reserva.fecha_grupo;
            encabezado.innerHTML = `
                <td colspan="8" class="py-2 pl-5 pr-3 text-xs font-semibold uppercase tracking-wider text-amber-500">
                    ${escaparHtml(reserva.fecha_grupo_etiqueta)}
                    ${reserva.es_hoy ? '<span class="ml-2 rounded-md bg-amber-500/15 px-2 py-0.5 text-amber-400">Hoy</span>' : ''}
                </td>
            `;
            tbody.insertBefore(encabezado, tr);
        }
    }

    // Función para buscar nuevas reservas (Polling)
    async function checkNuevasReservas() {
        try {
            let url = `../api/check_nuevas_reservas.php?desde_id=${ultimoIdConocido}`;
            if (fechaInicio) url += `&fecha_inicio=${fechaInicio}`;
            if (fechaFin) url += `&fecha_fin=${fechaFin}`;
            if (busquedaServidor) url += `&q=${encodeURIComponent(busquedaServidor)}`;

            const response = await fetch(url);
            const nuevasReservas = await response.json();

            if (nuevasReservas.length > 0) {
                const tbody = document.getElementById('tabla-reservas-body');
                const filaSinReservas = document.getElementById('fila-sin-reservas');
                if (filaSinReservas) filaSinReservas.remove();

                nuevasReservas.forEach(reserva => {
                    const tr = document.createElement('tr');
                    tr.id = `reserva-${reserva.id}`;
                    tr.className = 'fila-reserva transition-colors duration-150';
                    tr.dataset.orden = reserva.fecha_reserva;
                    tr.dataset.busqueda = `VTA${reserva.id} ${reserva.nombre_cliente} ${reserva.nombre_rp}`;
                    tr.innerHTML = `
                        <td class="whitespace-nowrap py-3.5 pl-5 pr-3 text-sm font-medium text-white">VTA${reserva.id}</td>
                        <td class="whitespace-nowrap py-3.5 pl-3 pr-3 text-sm font-medium text-white">${escaparHtml(reserva.nombre_cliente)}</td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm font-semibold text-white">${escaparHtml(reserva.nombre_rp)}</td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-400">${escaparHtml(reserva.tipo_rp || '-')}</td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300">${reserva.fecha_reserva_formato}</td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm text-neutral-300 hidden sm:table-cell">${reserva.num_personas}</td>
                        <td class="whitespace-nowrap px-3 py-3.5 text-sm">
                            <span class="inline-flex rounded-lg bg-blue-500/15 px-2.5 py-1 text-xs font-semibold text-blue-400">Nueva</span>
                        </td>
                        <td class="relative whitespace-nowrap py-3.5 pl-3 pr-5 text-right text-sm font-medium">
                            <div class="flex flex-col space-y-1.5">
                                <button id="btn-conf-${reserva.id}" onclick="this.disabled=true; confirmarLlegada(${reserva.id})" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">
                                    Confirmar Llegada
                                </button>
                                <button onclick="marcarInasistente(${reserva.id})" class="text-red-500/60 hover:text-red-400 text-xs transition-colors duration-200">
                                    Marcar Inasistente
                                </button>
                            </div>
                        </td>
                    `;
                    insertarFilaOrdenada(tbody, tr, reserva);
                    ultimoIdConocido = reserva.id;
                });
                filtrarTabla();
            }
        } catch (error) {
            console.error('Error al verificar reservas:', error);
        }
    }

    // Función para el botón "Confirmar Llegada"
    async function confirmarLlegada(idReserva) {
        if (!confirm('¿Confirmar la llegada de esta reserva?')) {
            const btn = document.getElementById('btn-conf-' + idReserva);
            if (btn) btn.disabled = false;
            return;
        }
        try {
            const formData = new FormData();
            formData.append('id_reserva', idReserva);
            const response = await fetch('../api/confirmar_llegada.php', {
                method: 'POST',
                body: formData
            });
            const resultado = await response.json();
            if (resultado.success) {
                const tr = document.getElementById(`reserva-${idReserva}`);
                
                // Si la vista actual es 'nuevas', removemos la fila de la vista
                if (currentTab === 'nuevas') {
                    tr.remove();
                    actualizarEncabezadosFecha();
                } else {
                    tr.classList.add('bg-amber-500/5');
                    tr.cells[6].innerHTML = `<span class="inline-flex rounded-lg bg-amber-500/15 px-2.5 py-1 text-xs font-semibold text-amber-400">Iniciada</span>`;
                    tr.cells[7].innerHTML = `
                        <div class="flex flex-col space-y-1.5">
                            <button onclick="reimprimirTicket(${idReserva})" class="text-neutral-400 hover:text-white transition-colors duration-200">
                                Reimprimir Ticket
                            </button>
                            <button onclick="regresarANueva(${idReserva})" class="text-neutral-500 hover:text-neutral-300 text-xs transition-colors duration-200">
                                Regresar a Nueva
                            </button>
                        </div>
                    `;
                }

                const urlTicket = `../print/ticket.php?id=${idReserva}`;
                window.open(urlTicket, 'Ticket_' + idReserva, 'width=300,height=500,scrollbars=yes,resizable=yes');
            } else {
                alert('Error: ' + resultado.message);
                const btn = document.getElementById('btn-conf-' + idReserva);
                if (btn) btn.disabled = false;
            }
        } catch (error) {
            console.error('Error al confirmar llegada:', error);
            const btn = document.getElementById('btn-conf-' + idReserva);
            if (btn) btn.disabled = false;
        }
    }

    // Hostess ajusta el número de personas de una reserva iniciada
    async function cambiarPersonas(idReserva) {
        const celda = document.getElementById('personas-' + idReserva);
        const actual = celda ? celda.textContent.trim() : '';
        const respuesta = prompt('Número de personas que llegaron (reserva VTA' + idReserva + '):', actual);
        if (respuesta === null) return;
        const n = parseInt(respuesta, 10);
        if (isNaN(n) || n < 1 || n > 500) {
            alert('Indica un número de personas válido (1 a 500).');
            return;
        }
        try {
            const formData = new FormData();
            formData.append('id_reserva', idReserva);
            formData.append('num_personas', n);
            const response = await fetch('../api/actualizar_personas.php', { method: 'POST', body: formData });
            const resultado = await response.json();
            if (resultado.success) {
                if (celda) celda.textContent = resultado.num_personas;
                const tr = document.getElementById(`reserva-${idReserva}`);
                if (tr) { tr.classList.add('bg-emerald-500/10'); setTimeout(() => tr.classList.remove('bg-emerald-500/10'), 1500); }
            } else {
                alert('Error: ' + resultado.message);
            }
        } catch (error) {
            console.error('Error al cambiar personas:', error);
            alert('No se pudo guardar el cambio.');
        }
    }

    function reimprimirTicket(idReserva) {
        const urlTicket = `../print/ticket.php?id=${idReserva}`;
        window.open(urlTicket, 'Ticket_' + idReserva, 'width=300,height=500,scrollbars=yes,resizable=yes');
    }

    // Función para marcar una reserva como inasistente
    async function marcarInasistente(idReserva) {
        if (!confirm('¿Estás seguro de marcar esta reserva como INASISTENTE?')) {
            return;
        }
        try {
            const formData = new FormData();
            formData.append('id_reserva', idReserva);
            const response = await fetch('../api/marcar_inasistente.php', {
                method: 'POST',
                body: formData
            });
            const resultado = await response.json();
            if (resultado.success) {
                const tr = document.getElementById(`reserva-${idReserva}`);
                tr.cells[6].innerHTML = `<span class="inline-flex rounded-lg bg-red-500/15 px-2.5 py-1 text-xs font-semibold text-red-400">Inasistente</span>`;
                tr.cells[7].innerHTML = '';
                tr.classList.add('opacity-40');
            } else {
                alert('Error: ' + resultado.message);
            }
        } catch (error) {
            console.error('Error al marcar inasistente:', error);
        }
    }

    async function regresarANueva(idReserva) {
        if (!confirm('¿Estás seguro de regresar esta reserva a Nueva? (Se borrará la hora de llegada)')) {
            return;
        }
        try {
            const formData = new FormData();
            formData.append('id_reserva', idReserva);
            const response = await fetch('../api/regresar_a_nueva.php', {
                method: 'POST',
                body: formData
            });
            const resultado = await response.json();
            if (resultado.success) {
                const tr = document.getElementById(`reserva-${idReserva}`);
                
                if (currentTab === 'iniciadas') {
                    tr.remove();
                    actualizarEncabezadosFecha();
                } else {
                    tr.classList.remove('bg-amber-500/5');
                    tr.cells[6].innerHTML = `<span class="inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold bg-blue-500/15 text-blue-400">Nueva</span>`;
                    tr.cells[7].innerHTML = `
                        <div class="flex flex-col space-y-1.5">
                            <button id="btn-conf-${idReserva}" onclick="this.disabled=true; confirmarLlegada(${idReserva})" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">
                                Confirmar Llegada
                            </button>
                            <button onclick="marcarInasistente(${idReserva})" class="text-red-500/60 hover:text-red-400 text-xs transition-colors duration-200">
                                Marcar Inasistente
                            </button>
                        </div>
                    `;
                }
            } else {
                alert('Error: ' + resultado.message);
            }
        } catch (error) {
            console.error('Error al regresar a nueva:', error);
        }
    }

    if (currentTab === 'nuevas') {
        console.log('Polling de reservas activado.');
        setInterval(checkNuevasReservas, 15000); 
    } else {
        console.log('Polling desactivado para esta pestaña.');
    }

</script>

<?php require_once '../includes/footer.php'; ?>