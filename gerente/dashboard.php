<?php
require_once '../includes/header.php';
require_once '../includes/turno.php';
require_once '../includes/tipos_rp.php';
check_role(['gerente', 'admin']);

// =====================================================================
// Dashboard comparativo: MES A vs MES B (por turno, 5 pm a 4:59 pm).
// Las reservas de ambos meses se cargan completas; los indicadores y tablas
// se calculan en PHP con los filtros generales, y las gráficas se calculan
// en el navegador para que cada una tenga además sus propios filtros.
// =====================================================================

$meses_es = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
function nombre_mes($ym, $meses_es) {
    return $meses_es[intval(substr($ym, 5, 2))] . ' ' . substr($ym, 0, 4);
}
function mes_valido($ym) {
    return is_string($ym) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym);
}
// Inicio y fin (exclusivo) del mes en turnos
function rango_mes($ym) {
    $primer_dia = $ym . '-01';
    return [turno_inicio($primer_dia), turno_inicio(date('Y-m-d', strtotime($primer_dia . ' +1 month')))];
}

// --- Meses A y B ---
$mes_turno_actual = substr(turno_hoy(), 0, 7);
$mes_a = mes_valido($_GET['mes'] ?? null) ? $_GET['mes'] : $mes_turno_actual;
$mes_b_default = date('Y-m', strtotime($mes_a . '-01 -1 month'));
$mes_b = mes_valido($_GET['mes_b'] ?? null) ? $_GET['mes_b'] : $mes_b_default;
if ($mes_b == $mes_a) {
    $mes_b = $mes_b_default;
}
$nombre_a = nombre_mes($mes_a, $meses_es);
$nombre_b = nombre_mes($mes_b, $meses_es);
$corto_a = substr($nombre_a, 0, 3) . ' ' . substr($nombre_a, -2);
$corto_b = substr($nombre_b, 0, 3) . ' ' . substr($nombre_b, -2);
[$ini_a, $fin_a] = rango_mes($mes_a);
[$ini_b, $fin_b] = rango_mes($mes_b);

// --- Filtros generales (aplican a todo el dashboard, en ambos meses) ---
$f = [
    'cliente' => trim($_GET['cliente'] ?? ''),
    'personas_min' => $_GET['personas_min'] ?? '',
    'personas_max' => $_GET['personas_max'] ?? '',
    'estado' => array_values(array_intersect((array)($_GET['estado'] ?? []), ['nueva', 'iniciada', 'finalizada', 'cancelada', 'inasistente'])),
    'id_rp' => $_GET['id_rp'] ?? 'todos',
    'tipo_rp' => $_GET['tipo_rp'] ?? 'todos',
    'venta_min' => $_GET['venta_min'] ?? '',
    'venta_max' => $_GET['venta_max'] ?? '',
    'com_min' => $_GET['com_min'] ?? '',
    'com_max' => $_GET['com_max'] ?? '',
];
$hay_filtros = $f['cliente'] !== '' || $f['personas_min'] !== '' || $f['personas_max'] !== '' || !empty($f['estado'])
    || $f['id_rp'] !== 'todos' || $f['tipo_rp'] !== 'todos' || $f['venta_min'] !== '' || $f['venta_max'] !== '' || $f['com_min'] !== '' || $f['com_max'] !== '';

$where_filtros = [];
$params_filtros = [];
if ($f['cliente'] !== '') {
    $where_filtros[] = "r.nombre_cliente LIKE :cliente";
    $params_filtros['cliente'] = '%' . addcslashes($f['cliente'], '%_\\') . '%';
}
if ($f['personas_min'] !== '') { $where_filtros[] = "r.num_personas >= :personas_min"; $params_filtros['personas_min'] = intval($f['personas_min']); }
if ($f['personas_max'] !== '') { $where_filtros[] = "r.num_personas <= :personas_max"; $params_filtros['personas_max'] = intval($f['personas_max']); }
if (!empty($f['estado'])) {
    $marcas = [];
    foreach ($f['estado'] as $i => $e) { $marcas[] = ":estado$i"; $params_filtros["estado$i"] = $e; }
    $where_filtros[] = "r.estado IN (" . implode(',', $marcas) . ")";
}
if ($f['id_rp'] !== 'todos') { $where_filtros[] = "r.id_rp = :id_rp"; $params_filtros['id_rp'] = intval($f['id_rp']); }
if ($f['tipo_rp'] !== 'todos') {
    if ($f['tipo_rp'] === 'Sin tipo') { $where_filtros[] = "(u.tipo_rp IS NULL OR u.tipo_rp = '')"; }
    else { $where_filtros[] = "u.tipo_rp = :tipo_rp"; $params_filtros['tipo_rp'] = $f['tipo_rp']; }
}
if ($f['venta_min'] !== '') { $where_filtros[] = "COALESCE(v.venta, 0) >= :venta_min"; $params_filtros['venta_min'] = floatval($f['venta_min']); }
if ($f['venta_max'] !== '') { $where_filtros[] = "COALESCE(v.venta, 0) <= :venta_max"; $params_filtros['venta_max'] = floatval($f['venta_max']); }
if ($f['com_min'] !== '') { $where_filtros[] = "COALESCE(v.comision, 0) >= :com_min"; $params_filtros['com_min'] = floatval($f['com_min']); }
if ($f['com_max'] !== '') { $where_filtros[] = "COALESCE(v.comision, 0) <= :com_max"; $params_filtros['com_max'] = floatval($f['com_max']); }
$sql_filtros = $where_filtros ? ' AND ' . implode(' AND ', $where_filtros) : '';

// --- Reservas de un mes (una fila por reserva, con la suma de sus cierres) ---
function reservas_mes($pdo, $ini, $fin, $sql_filtros, $params_filtros) {
    $stmt = $pdo->prepare("SELECT r.id, r.nombre_cliente, r.num_personas, r.fecha_reserva, r.estado, r.id_rp,
                u.nombre_completo AS rp, COALESCE(NULLIF(u.tipo_rp, ''), 'Sin tipo') AS tipo,
                COALESCE(v.venta, 0) AS venta, COALESCE(v.comision, 0) AS comision, COALESCE(v.propina, 0) AS propina
            FROM reservas r
            JOIN usuarios u ON r.id_rp = u.id
            LEFT JOIN (SELECT id_reserva, SUM(venta_total) AS venta, SUM(monto_comision) AS comision, SUM(propina) AS propina
                       FROM cuentas_cerradas GROUP BY id_reserva) v ON v.id_reserva = r.id
            WHERE r.fecha_reserva >= :ini AND r.fecha_reserva < :fin $sql_filtros
            ORDER BY r.fecha_reserva ASC");
    $stmt->execute(array_merge(['ini' => $ini, 'fin' => $fin], $params_filtros));
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['num_personas'] = intval($r['num_personas']);
        $r['venta'] = floatval($r['venta']);
        $r['comision'] = floatval($r['comision']);
        $r['propina'] = floatval($r['propina']);
        $r['turno'] = turno_de($r['fecha_reserva']);
        $filas[] = $r;
    }
    return $filas;
}
$res_a = reservas_mes($pdo, $ini_a, $fin_a, $sql_filtros, $params_filtros);
$res_b = reservas_mes($pdo, $ini_b, $fin_b, $sql_filtros, $params_filtros);

// --- Cálculos ---
function kpis($rows) {
    $k = ['reservas' => 0, 'personas' => 0, 'finalizadas' => 0, 'inasistentes' => 0, 'canceladas' => 0, 'pendientes' => 0, 'personas_finalizadas' => 0, 'venta' => 0, 'comision' => 0, 'propina' => 0];
    foreach ($rows as $r) {
        $k['reservas']++;
        $k['personas'] += $r['num_personas'];
        if ($r['estado'] == 'finalizada') { $k['finalizadas']++; $k['personas_finalizadas'] += $r['num_personas']; }
        elseif ($r['estado'] == 'inasistente') $k['inasistentes']++;
        elseif ($r['estado'] == 'cancelada') $k['canceladas']++;
        else $k['pendientes']++;
        $k['venta'] += $r['venta'];
        $k['comision'] += $r['comision'];
        $k['propina'] += $r['propina'];
    }
    $k['con_estado'] = $k['finalizadas'] + $k['inasistentes'] + $k['canceladas'];
    $k['tasa_finalizada'] = $k['con_estado'] > 0 ? $k['finalizadas'] / $k['con_estado'] * 100 : 0;
    $k['tasa_inasistencia'] = $k['con_estado'] > 0 ? $k['inasistentes'] / $k['con_estado'] * 100 : 0;
    $k['ticket_persona'] = $k['personas_finalizadas'] > 0 ? $k['venta'] / $k['personas_finalizadas'] : 0;
    $k['ticket_reserva'] = $k['finalizadas'] > 0 ? $k['venta'] / $k['finalizadas'] : 0;
    return $k;
}
function agrupar($rows, $clave) {
    $g = [];
    foreach ($rows as $r) {
        $k = $r[$clave];
        if (!isset($g[$k])) $g[$k] = ['reservas' => 0, 'personas' => 0, 'finalizadas' => 0, 'inasistentes' => 0, 'venta' => 0, 'comision' => 0, 'rp' => $r['rp'], 'tipo' => $r['tipo']];
        $g[$k]['reservas']++;
        $g[$k]['personas'] += $r['num_personas'];
        if ($r['estado'] == 'finalizada') $g[$k]['finalizadas']++;
        if ($r['estado'] == 'inasistente') $g[$k]['inasistentes']++;
        $g[$k]['venta'] += $r['venta'];
        $g[$k]['comision'] += $r['comision'];
    }
    foreach ($g as &$x) {
        $base = $x['finalizadas'] + $x['inasistentes'];
        $x['efectividad'] = $base > 0 ? $x['finalizadas'] / $base * 100 : null;
    }
    unset($x);
    uasort($g, fn($p, $q) => $q['venta'] <=> $p['venta'] ?: $q['reservas'] <=> $p['reservas']);
    return $g;
}
$kpi_a = kpis($res_a);
$kpi_b = kpis($res_b);
$tipos_a = agrupar($res_a, 'tipo');
$tipos_b = agrupar($res_b, 'tipo');
$rps_a = agrupar($res_a, 'id_rp');
$rps_b = agrupar($res_b, 'id_rp');
// Tipos presentes en cualquiera de los dos meses, ordenados por venta de A y luego de B
$tipos_todos = array_keys($tipos_a);
foreach (array_keys($tipos_b) as $t) if (!in_array($t, $tipos_todos)) $tipos_todos[] = $t;

// --- Visitas sin reserva (hostess), por concepto ---
function visitas_mes($pdo, $ini, $fin) {
    $stmt = $pdo->prepare("SELECT concepto, COUNT(*) AS visitas, COALESCE(SUM(num_personas), 0) AS personas
                           FROM visitas WHERE fecha_hora >= :ini AND fecha_hora < :fin
                           GROUP BY concepto ORDER BY personas DESC");
    $stmt->execute(['ini' => $ini, 'fin' => $fin]);
    $filas = ['_total' => ['visitas' => 0, 'personas' => 0]];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $filas[$x['concepto']] = $x;
        $filas['_total']['visitas'] += intval($x['visitas']);
        $filas['_total']['personas'] += intval($x['personas']);
    }
    return $filas;
}
$visitas_a = null; // null = tabla aún no creada (ajustes_2026-10-27.sql)
$visitas_b = null;
try {
    $visitas_a = visitas_mes($pdo, $ini_a, $fin_a);
    $visitas_b = visitas_mes($pdo, $ini_b, $fin_b);
} catch (PDOException $e) {
    $visitas_a = null;
}

// --- Listas para los selectores ---
$stmt = $pdo->query("SELECT DISTINCT DATE_FORMAT(fecha_reserva - INTERVAL " . TURNO_HORA_INICIO . " HOUR, '%Y-%m') AS ym FROM reservas ORDER BY ym DESC");
$meses_disponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ([$mes_a, $mes_b] as $m) if (!in_array($m, $meses_disponibles)) $meses_disponibles[] = $m;
rsort($meses_disponibles);

$stmt = $pdo->query("SELECT id, nombre_completo FROM usuarios WHERE rol = 'rp' ORDER BY nombre_completo");
$rps_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->query("SELECT DISTINCT tipo_rp FROM usuarios WHERE tipo_rp IS NOT NULL AND tipo_rp <> ''");
$tipos_lista = array_values(array_unique(array_merge($TIPOS_RP, $stmt->fetchAll(PDO::FETCH_COLUMN))));
$tipos_lista[] = 'Sin tipo';

// Variación de A respecto a B
function variacion($a, $b, $puntos = false, $invertir = false) {
    if ($puntos) {
        $dif = $a - $b;
        $texto = ($dif >= 0 ? '+' : '') . number_format($dif, 1) . ' pts';
    } else {
        if ($b == 0) return ['texto' => $a > 0 ? 'nuevo' : '—', 'clase' => 'text-neutral-500'];
        $dif = ($a - $b) / $b * 100;
        $texto = ($dif >= 0 ? '+' : '') . number_format($dif, 1) . '%';
    }
    $bueno = $invertir ? $dif <= 0 : $dif >= 0;
    return ['texto' => $texto, 'clase' => abs($dif) < 0.05 ? 'text-neutral-500' : ($bueno ? 'text-emerald-400' : 'text-red-400')];
}
function dinero($n) { return '$' . number_format($n, 0); }
function pct($n) { return $n === null ? '—' : number_format($n, 0) . '%'; }

// Datos para las gráficas (se calculan en el navegador con filtros por gráfica)
$a_json = fn($rows) => array_map(fn($r) => [
    'id' => intval($r['id']), 'cliente' => $r['nombre_cliente'], 'personas' => $r['num_personas'], 'fecha' => $r['turno'],
    'hora' => substr($r['fecha_reserva'], 11, 5), 'estado' => $r['estado'], 'rp' => $r['rp'], 'id_rp' => intval($r['id_rp']),
    'tipo' => $r['tipo'], 'venta' => $r['venta'], 'comision' => $r['comision'],
], $rows);
$chart = [
    'a' => $a_json($res_a), 'b' => $a_json($res_b),
    'nombre_a' => $nombre_a, 'nombre_b' => $nombre_b, 'corto_a' => $corto_a, 'corto_b' => $corto_b,
    'dias_a' => intval(date('t', strtotime($mes_a . '-01'))), 'dias_b' => intval(date('t', strtotime($mes_b . '-01'))),
    'tipos' => $tipos_todos,
];
$url_base = http_build_query(array_filter(['mes' => $mes_a, 'mes_b' => $mes_b] + $f, fn($v) => $v !== '' && $v !== 'todos' && $v !== []));
?>

<div class="max-w-7xl mx-auto">
    <!-- Selección de meses -->
    <form method="GET" action="dashboard.php" id="form-dashboard">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-5">
        <div>
            <h1 class="text-xl font-bold text-white">Dashboard · <?php echo $nombre_a; ?> <span class="text-neutral-500 font-normal">vs</span> <?php echo $nombre_b; ?></h1>
            <p class="mt-1 text-sm text-neutral-500">Por turno (5:00 pm a 4:59 pm del día siguiente), según la fecha de la reserva; ventas de los cierres de caja. Las variaciones son de <?php echo $corto_a; ?> respecto a <?php echo $corto_b; ?>.</p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Mes A</label>
                <select name="mes" class="input-premium rounded-xl py-2 px-3 text-sm text-white" onchange="this.form.submit()">
                    <?php foreach ($meses_disponibles as $ym): ?>
                        <option value="<?php echo $ym; ?>" <?php echo $ym == $mes_a ? 'selected' : ''; ?>><?php echo nombre_mes($ym, $meses_es); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Comparar con (Mes B)</label>
                <select name="mes_b" class="input-premium rounded-xl py-2 px-3 text-sm text-white" onchange="this.form.submit()">
                    <?php foreach ($meses_disponibles as $ym): if ($ym == $mes_a) continue; ?>
                        <option value="<?php echo $ym; ?>" <?php echo $ym == $mes_b ? 'selected' : ''; ?>><?php echo nombre_mes($ym, $meses_es); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="index.php?fecha_inicio=<?php echo $mes_a; ?>-01&fecha_fin=<?php echo date('Y-m-t', strtotime($mes_a . '-01')); ?>" class="btn-secondary py-2 px-4 rounded-xl text-sm font-medium text-neutral-300">Ver detalle</a>
        </div>
    </div>

    <!-- Filtros generales -->
    <details class="glass-card rounded-xl mb-6" <?php echo $hay_filtros ? 'open' : ''; ?>>
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-white flex items-center justify-between">
            <span>Filtros generales <span class="text-xs font-normal text-neutral-500">(aplican a todas las secciones, en ambos meses)</span></span>
            <?php if ($hay_filtros): ?><span class="rounded-md bg-amber-500/15 px-2 py-0.5 text-xs text-amber-400">Activos</span><?php endif; ?>
        </summary>
        <div class="px-4 pb-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 items-end">
            <div class="col-span-2">
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Cliente</label>
                <input type="search" name="cliente" value="<?php echo htmlspecialchars($f['cliente']); ?>" placeholder="Nombre del cliente" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Personas de</label>
                <input type="number" name="personas_min" min="1" value="<?php echo htmlspecialchars($f['personas_min']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Personas a</label>
                <input type="number" name="personas_max" min="1" value="<?php echo htmlspecialchars($f['personas_max']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">RP</label>
                <select name="id_rp" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                    <option value="todos">Todos</option>
                    <?php foreach ($rps_lista as $rp): ?>
                        <option value="<?php echo $rp['id']; ?>" <?php echo $f['id_rp'] == $rp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($rp['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Tipo de RP</label>
                <select name="tipo_rp" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                    <option value="todos">Todos</option>
                    <?php foreach ($tipos_lista as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $f['tipo_rp'] == $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Venta de $</label>
                <input type="number" name="venta_min" min="0" step="0.01" value="<?php echo htmlspecialchars($f['venta_min']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Venta a $</label>
                <input type="number" name="venta_max" min="0" step="0.01" value="<?php echo htmlspecialchars($f['venta_max']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Comisión de $</label>
                <input type="number" name="com_min" min="0" step="0.01" value="<?php echo htmlspecialchars($f['com_min']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Comisión a $</label>
                <input type="number" name="com_max" min="0" step="0.01" value="<?php echo htmlspecialchars($f['com_max']); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
            </div>
            <div class="col-span-2 lg:col-span-6">
                <span class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Estado de la reserva <span class="font-normal normal-case">(ninguno = todos)</span></span>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <?php foreach (['nueva' => 'Nueva', 'iniciada' => 'Iniciada', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada', 'inasistente' => 'Inasistente'] as $v => $et): ?>
                        <label class="inline-flex items-center gap-1.5 text-sm text-neutral-300"><input type="checkbox" name="estado[]" value="<?php echo $v; ?>" <?php echo in_array($v, $f['estado']) ? 'checked' : ''; ?> class="h-4 w-4 rounded border-neutral-600 bg-neutral-800 text-amber-500"> <?php echo $et; ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-span-2 lg:col-span-6 flex gap-2">
                <button type="submit" class="btn-amber py-2 px-5 rounded-xl text-sm font-semibold text-white">Aplicar filtros</button>
                <a href="dashboard.php?mes=<?php echo $mes_a; ?>&mes_b=<?php echo $mes_b; ?>" class="btn-secondary py-2 px-5 rounded-xl text-sm font-medium text-neutral-300">Quitar filtros</a>
            </div>
        </div>
    </details>
    </form>

    <!-- KPIs A vs B -->
    <?php
    $tarjetas = [
        ['Reservas', number_format($kpi_a['reservas']), number_format($kpi_b['reservas']), variacion($kpi_a['reservas'], $kpi_b['reservas']), $kpi_a['pendientes'] > 0 ? $kpi_a['pendientes'] . ' pendientes en ' . $corto_a : ''],
        ['Personas', number_format($kpi_a['personas']), number_format($kpi_b['personas']), variacion($kpi_a['personas'], $kpi_b['personas']), number_format($kpi_a['personas_finalizadas']) . ' llegaron en ' . $corto_a],
        ['Tasa finalizada', pct($kpi_a['tasa_finalizada']), pct($kpi_b['tasa_finalizada']), variacion($kpi_a['tasa_finalizada'], $kpi_b['tasa_finalizada'], true), $kpi_a['finalizadas'] . ' de ' . $kpi_a['con_estado'] . ' con estado'],
        ['Inasistencia', pct($kpi_a['tasa_inasistencia']), pct($kpi_b['tasa_inasistencia']), variacion($kpi_a['tasa_inasistencia'], $kpi_b['tasa_inasistencia'], true, true), $kpi_a['inasistentes'] . ' no llegaron · ' . $kpi_a['canceladas'] . ' canceladas'],
        ['Venta total', dinero($kpi_a['venta']), dinero($kpi_b['venta']), variacion($kpi_a['venta'], $kpi_b['venta']), 'comisiones ' . dinero($kpi_a['comision']) . ' · propinas ' . dinero($kpi_a['propina'])],
        ['Ticket por persona', dinero($kpi_a['ticket_persona']), dinero($kpi_b['ticket_persona']), variacion($kpi_a['ticket_persona'], $kpi_b['ticket_persona']), dinero($kpi_a['ticket_reserva']) . ' por reserva'],
    ];
    ?>
    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-8">
        <?php foreach ($tarjetas as [$titulo, $va, $vb, $var, $nota]): ?>
            <div class="glass-card rounded-2xl p-4">
                <p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider"><?php echo $titulo; ?></p>
                <p class="mt-1 text-2xl font-bold text-white"><?php echo $va; ?></p>
                <p class="text-xs text-neutral-500"><?php echo $corto_b; ?>: <span class="text-neutral-300"><?php echo $vb; ?></span> <span class="font-semibold <?php echo $var['clase']; ?>"><?php echo $var['texto']; ?></span></p>
                <?php if ($nota): ?><p class="mt-1 text-xs text-neutral-500"><?php echo $nota; ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($visitas_a !== null): ?>
    <!-- Visitas sin reserva (aforo adicional registrado por hostess; no les aplican los filtros) -->
    <div class="glass-card rounded-2xl p-5 mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
            <div>
                <h2 class="text-sm font-semibold text-white">Visitas sin reserva</h2>
                <p class="text-xs text-neutral-500">Clientes registrados por hostess sin reserva · solo aforo, sin venta asociada · no les aplican los filtros</p>
            </div>
            <?php $v_var = variacion($visitas_a['_total']['personas'], $visitas_b['_total']['personas']); ?>
            <p class="text-sm text-neutral-400"><span class="text-2xl font-bold text-white"><?php echo number_format($visitas_a['_total']['personas']); ?></span> personas en <?php echo number_format($visitas_a['_total']['visitas']); ?> visitas · <?php echo $corto_b; ?>: <?php echo number_format($visitas_b['_total']['personas']); ?> <span class="font-semibold <?php echo $v_var['clase']; ?>"><?php echo $v_var['texto']; ?></span></p>
        </div>
        <?php $conceptos = array_unique(array_merge(array_keys($visitas_a), array_keys($visitas_b))); ?>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <?php foreach ($conceptos as $concepto): if ($concepto === '_total') continue; $pa = intval($visitas_a[$concepto]['personas'] ?? 0); $pb = intval($visitas_b[$concepto]['personas'] ?? 0); $vv = variacion($pa, $pb); ?>
                <div class="rounded-xl bg-neutral-900/60 px-4 py-3">
                    <p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider"><?php echo htmlspecialchars($concepto); ?></p>
                    <p class="text-lg font-bold text-white"><?php echo number_format($pa); ?> <span class="text-xs font-normal text-neutral-500"><?php echo intval($visitas_a[$concepto]['visitas'] ?? 0); ?> visitas</span></p>
                    <p class="text-xs text-neutral-500"><?php echo $corto_b; ?>: <?php echo number_format($pb); ?> <span class="font-semibold <?php echo $vv['clase']; ?>"><?php echo $vv['texto']; ?></span></p>
                </div>
            <?php endforeach; ?>
            <?php if (count($conceptos) == 1): ?><p class="col-span-full text-sm text-neutral-500">Sin visitas registradas en ninguno de los dos meses.</p><?php endif; ?>
        </div>
        <p class="text-xs text-neutral-500">Aforo total (llegaron con reserva + sin reserva): <?php echo $corto_a; ?> <span class="font-semibold text-white"><?php echo number_format($kpi_a['personas_finalizadas'] + $visitas_a['_total']['personas']); ?></span> · <?php echo $corto_b; ?> <span class="font-semibold text-white"><?php echo number_format($kpi_b['personas_finalizadas'] + $visitas_b['_total']['personas']); ?></span> personas.</p>
    </div>
    <?php endif; ?>

    <?php if ($kpi_a['reservas'] == 0 && $kpi_b['reservas'] == 0): ?>
        <div class="glass-card rounded-2xl p-10 text-center text-sm text-neutral-500">No hay reservas en <?php echo $nombre_a; ?> ni en <?php echo $nombre_b; ?><?php echo $hay_filtros ? ' con los filtros activos' : ''; ?>.</div>
    <?php else: ?>

    <!-- Gráficas: cada una con sus propios filtros de datos y casillas de qué mostrar -->
<?php
    // medidas = casillas "Mostrar" (personas / venta / comisión); null = gráfica de porcentaje sin medidas
    $graficas = [
        ['id' => 'tipo', 'titulo' => 'Por tipo de RP', 'sub' => 'Personas, venta y comisión por tipo de RP', 'medidas' => true, 'defecto' => ['venta']],
        ['id' => 'rp', 'titulo' => 'Por RP (top 10)', 'sub' => 'Los 10 RPs con más venta en ' . $corto_a, 'medidas' => true, 'defecto' => ['venta']],
        ['id' => 'dias', 'titulo' => 'Por día del mes', 'sub' => 'Cada día es un turno (5 pm a 4:59 pm)', 'medidas' => true, 'defecto' => ['venta']],
        ['id' => 'efectividad', 'titulo' => 'Efectividad por tipo de RP', 'sub' => 'Finalizadas entre finalizadas + inasistentes', 'medidas' => false, 'defecto' => []],
    ];
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <?php foreach ($graficas as $g): ?>
        <div class="glass-card rounded-2xl p-5" data-grafica="<?php echo $g['id']; ?>">
            <div class="flex items-start justify-between gap-3 mb-1">
                <div>
                    <h2 class="text-sm font-semibold text-white"><?php echo $g['titulo']; ?></h2>
                    <p class="text-xs text-neutral-500"><?php echo $g['sub']; ?> · <?php echo $corto_a; ?> vs <?php echo $corto_b; ?></p>
                </div>
                <button type="button" onclick="toggleFiltros('<?php echo $g['id']; ?>')" class="btn-secondary shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-300">Filtros <span class="filtros-activos hidden ml-1 rounded bg-amber-500/20 px-1.5 text-amber-400">●</span></button>
            </div>
            <p class="text-xs text-neutral-600 mb-3 conteo"></p>
            <form class="filtros hidden rounded-xl bg-neutral-900/60 p-3 mb-3 text-xs" onsubmit="return false" oninput="aplicarFiltros('<?php echo $g['id']; ?>')" onchange="aplicarFiltros('<?php echo $g['id']; ?>')">
                <?php if ($g['medidas']): ?>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-3 text-neutral-300">
                    <span class="font-semibold text-neutral-400 uppercase tracking-wider">Mostrar:</span>
                    <?php foreach (['personas' => 'Personas', 'venta' => 'Venta total', 'comision' => 'Comisión'] as $m => $et): ?>
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="medida" value="<?php echo $m; ?>" <?php echo in_array($m, $g['defecto']) ? 'checked' : ''; ?> class="h-3.5 w-3.5 rounded border-neutral-600 bg-neutral-800 text-amber-500"> <?php echo $et; ?></label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <input type="search" name="cliente" placeholder="Cliente" class="input-premium rounded-lg py-1.5 px-2 text-xs text-white col-span-2">
                    <label class="text-neutral-500 flex items-center gap-1">Del <input type="date" name="fecha_min" class="input-premium rounded-lg py-1.5 px-2 text-xs text-white w-full"></label>
                    <label class="text-neutral-500 flex items-center gap-1">Al <input type="date" name="fecha_max" class="input-premium rounded-lg py-1.5 px-2 text-xs text-white w-full"></label>
                    <select name="id_rp" class="input-premium rounded-lg py-1.5 px-2 text-xs text-white col-span-2">
                        <option value="">RP: todos</option>
                        <?php foreach ($rps_lista as $rp): ?><option value="<?php echo $rp['id']; ?>"><?php echo htmlspecialchars($rp['nombre_completo']); ?></option><?php endforeach; ?>
                    </select>
                    <select name="tipo" class="input-premium rounded-lg py-1.5 px-2 text-xs text-white col-span-2">
                        <option value="">Tipo RP: todos</option>
                        <?php foreach ($tipos_lista as $t): ?><option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option><?php endforeach; ?>
                    </select>
                    <div class="col-span-2 md:col-span-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-neutral-400">
                        <span>Estado:</span>
                        <?php foreach (['nueva' => 'Nueva', 'iniciada' => 'Iniciada', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada', 'inasistente' => 'Inasistente'] as $v => $et): ?>
                            <label class="inline-flex items-center gap-1"><input type="checkbox" name="estado" value="<?php echo $v; ?>" class="h-3.5 w-3.5 rounded border-neutral-600 bg-neutral-800 text-amber-500"> <?php echo $et; ?></label>
                        <?php endforeach; ?>
                        <button type="button" onclick="limpiarFiltros('<?php echo $g['id']; ?>')" class="ml-auto text-amber-500 hover:text-amber-400 font-semibold">Limpiar</button>
                    </div>
                </div>
            </form>
            <?php if ($g['medidas']): ?>
                <!-- Dos paneles porque pesos y personas no comparten eje -->
                <div class="panel-dinero relative" style="height: <?php echo $g['id'] == 'dias' ? 240 : 'auto'; ?>"><canvas id="chart-<?php echo $g['id']; ?>-dinero" role="img" aria-label="<?php echo $g['titulo']; ?> (pesos)"></canvas></div>
                <div class="panel-personas relative mt-3" style="height: <?php echo $g['id'] == 'dias' ? 200 : 'auto'; ?>"><canvas id="chart-<?php echo $g['id']; ?>-personas" role="img" aria-label="<?php echo $g['titulo']; ?> (personas)"></canvas></div>
                <p class="sin-medidas hidden py-8 text-center text-sm text-neutral-500">Marca al menos una casilla en Mostrar.</p>
            <?php else: ?>
                <div class="panel-dinero relative" style="height: auto"><canvas id="chart-<?php echo $g['id']; ?>-dinero" role="img" aria-label="<?php echo $g['titulo']; ?>"></canvas></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

<!-- Comparativo general y por tipo -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="glass-card rounded-2xl overflow-hidden">
            <div class="px-5 pt-5 pb-3"><h2 class="text-sm font-semibold text-white"><?php echo $nombre_a; ?> vs <?php echo $nombre_b; ?></h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full table-premium text-sm">
                    <thead><tr class="border-b border-neutral-700/50 text-xs text-neutral-400 uppercase tracking-wider">
                        <th class="py-2.5 pl-5 pr-3 text-left">Indicador</th><th class="px-3 py-2.5 text-right"><?php echo $corto_a; ?></th><th class="px-3 py-2.5 text-right"><?php echo $corto_b; ?></th><th class="py-2.5 pl-3 pr-5 text-right">Variación</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-800/50 text-neutral-300">
                        <?php
                        $comp = [
                            ['Reservas', number_format($kpi_a['reservas']), number_format($kpi_b['reservas']), variacion($kpi_a['reservas'], $kpi_b['reservas'])],
                            ['Personas', number_format($kpi_a['personas']), number_format($kpi_b['personas']), variacion($kpi_a['personas'], $kpi_b['personas'])],
                            ['Finalizadas', $kpi_a['finalizadas'] . ' (' . pct($kpi_a['tasa_finalizada']) . ')', $kpi_b['finalizadas'] . ' (' . pct($kpi_b['tasa_finalizada']) . ')', variacion($kpi_a['tasa_finalizada'], $kpi_b['tasa_finalizada'], true)],
                            ['Inasistentes', $kpi_a['inasistentes'] . ' (' . pct($kpi_a['tasa_inasistencia']) . ')', $kpi_b['inasistentes'] . ' (' . pct($kpi_b['tasa_inasistencia']) . ')', variacion($kpi_a['tasa_inasistencia'], $kpi_b['tasa_inasistencia'], true, true)],
                            ['Canceladas', $kpi_a['canceladas'], $kpi_b['canceladas'], variacion($kpi_a['canceladas'], $kpi_b['canceladas'], false, true)],
                            ['Personas que llegaron', number_format($kpi_a['personas_finalizadas']), number_format($kpi_b['personas_finalizadas']), variacion($kpi_a['personas_finalizadas'], $kpi_b['personas_finalizadas'])],
                            ['Venta total', dinero($kpi_a['venta']), dinero($kpi_b['venta']), variacion($kpi_a['venta'], $kpi_b['venta'])],
                            ['Comisiones', dinero($kpi_a['comision']), dinero($kpi_b['comision']), variacion($kpi_a['comision'], $kpi_b['comision'])],
                            ['Propinas', dinero($kpi_a['propina']), dinero($kpi_b['propina']), variacion($kpi_a['propina'], $kpi_b['propina'])],
                            ['Ticket por persona', dinero($kpi_a['ticket_persona']), dinero($kpi_b['ticket_persona']), variacion($kpi_a['ticket_persona'], $kpi_b['ticket_persona'])],
                            ['Ticket por reserva', dinero($kpi_a['ticket_reserva']), dinero($kpi_b['ticket_reserva']), variacion($kpi_a['ticket_reserva'], $kpi_b['ticket_reserva'])],
                        ];
                        foreach ($comp as [$n, $va, $vb, $v]): ?>
                            <tr><td class="py-2 pl-5 pr-3"><?php echo $n; ?></td><td class="px-3 py-2 text-right text-white font-semibold"><?php echo $va; ?></td><td class="px-3 py-2 text-right"><?php echo $vb; ?></td><td class="py-2 pl-3 pr-5 text-right font-semibold <?php echo $v['clase']; ?>"><?php echo $v['texto']; ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="glass-card rounded-2xl overflow-hidden">
            <div class="px-5 pt-5 pb-3"><h2 class="text-sm font-semibold text-white">Por tipo de RP · <?php echo $corto_a; ?> vs <?php echo $corto_b; ?></h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full table-premium text-sm">
                    <thead><tr class="border-b border-neutral-700/50 text-xs text-neutral-400 uppercase tracking-wider">
                        <th class="py-2.5 pl-5 pr-3 text-left">Tipo</th><th class="px-3 py-2.5 text-right">Reservas</th><th class="px-3 py-2.5 text-right">Personas</th><th class="px-3 py-2.5 text-right">Efect.</th><th class="px-3 py-2.5 text-right">Venta <?php echo $corto_a; ?></th><th class="px-3 py-2.5 text-right">Venta <?php echo $corto_b; ?></th><th class="py-2.5 pl-3 pr-5 text-right">Var.</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-800/50 text-neutral-300">
                        <?php foreach ($tipos_todos as $t): $ta = $tipos_a[$t] ?? null; $tb = $tipos_b[$t] ?? null; $v = variacion($ta['venta'] ?? 0, $tb['venta'] ?? 0); ?>
                            <tr>
                                <td class="py-2 pl-5 pr-3 text-white"><?php echo htmlspecialchars($t); ?></td>
                                <td class="whitespace-nowrap px-3 py-2 text-right"><span class="text-white"><?php echo $ta['reservas'] ?? 0; ?></span> <span class="text-neutral-500">/ <?php echo $tb['reservas'] ?? 0; ?></span></td>
                                <td class="whitespace-nowrap px-3 py-2 text-right"><span class="text-white"><?php echo $ta['personas'] ?? 0; ?></span> <span class="text-neutral-500">/ <?php echo $tb['personas'] ?? 0; ?></span></td>
                                <td class="whitespace-nowrap px-3 py-2 text-right"><span class="text-white"><?php echo pct($ta['efectividad'] ?? null); ?></span> <span class="text-neutral-500">/ <?php echo pct($tb['efectividad'] ?? null); ?></span></td>
                                <td class="px-3 py-2 text-right text-white font-semibold"><?php echo dinero($ta['venta'] ?? 0); ?></td>
                                <td class="px-3 py-2 text-right"><?php echo dinero($tb['venta'] ?? 0); ?></td>
                                <td class="py-2 pl-3 pr-5 text-right font-semibold <?php echo $v['clase']; ?>"><?php echo $v['texto']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 text-xs text-neutral-600">En cada celda: <?php echo $corto_a; ?> / <?php echo $corto_b; ?>.</p>
        </div>
    </div>

    <!-- Top RPs -->
    <div class="glass-card rounded-2xl overflow-hidden mb-8">
        <div class="px-5 pt-5 pb-3">
            <h2 class="text-sm font-semibold text-white">Top RPs por venta · <?php echo $corto_a; ?> vs <?php echo $corto_b; ?></h2>
            <p class="text-xs text-neutral-500">Ordenados por venta en <?php echo $corto_a; ?>. Efectividad = finalizadas entre finalizadas + inasistentes. En cada celda: <?php echo $corto_a; ?> / <?php echo $corto_b; ?>.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium text-sm">
                <thead><tr class="border-b border-neutral-700/50 text-xs text-neutral-400 uppercase tracking-wider">
                    <th class="py-2.5 pl-5 pr-3 text-left">#</th><th class="px-3 py-2.5 text-left">RP</th><th class="px-3 py-2.5 text-left">Tipo</th><th class="px-3 py-2.5 text-right">Reservas</th><th class="px-3 py-2.5 text-right">Personas</th><th class="px-3 py-2.5 text-right">Finalizadas</th><th class="px-3 py-2.5 text-right">Efect.</th><th class="px-3 py-2.5 text-right">Venta <?php echo $corto_a; ?></th><th class="px-3 py-2.5 text-right">Venta <?php echo $corto_b; ?></th><th class="px-3 py-2.5 text-right">Var.</th><th class="py-2.5 pl-3 pr-5 text-right">Comisión <?php echo $corto_a; ?></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-800/50 text-neutral-300">
                    <?php $i = 0; foreach ($rps_a as $id_rp => $ra): if (++$i > 15) break; $rb = $rps_b[$id_rp] ?? null; $v = variacion($ra['venta'], $rb['venta'] ?? 0); ?>
                        <tr>
                            <td class="py-2 pl-5 pr-3 text-neutral-500"><?php echo $i; ?></td>
                            <td class="px-3 py-2 text-white font-medium whitespace-nowrap"><?php echo htmlspecialchars($ra['rp']); ?></td>
                            <td class="px-3 py-2"><?php echo htmlspecialchars($ra['tipo']); ?></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right"><?php echo $ra['reservas']; ?> <span class="text-neutral-500">/ <?php echo $rb['reservas'] ?? 0; ?></span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right"><?php echo $ra['personas']; ?> <span class="text-neutral-500">/ <?php echo $rb['personas'] ?? 0; ?></span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right"><?php echo $ra['finalizadas']; ?> <span class="text-neutral-500">/ <?php echo $rb['finalizadas'] ?? 0; ?></span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right"><?php echo pct($ra['efectividad']); ?> <span class="text-neutral-500">/ <?php echo pct($rb['efectividad'] ?? null); ?></span></td>
                            <td class="px-3 py-2 text-right text-white font-semibold"><?php echo dinero($ra['venta']); ?></td>
                            <td class="px-3 py-2 text-right"><?php echo dinero($rb['venta'] ?? 0); ?></td>
                            <td class="px-3 py-2 text-right font-semibold <?php echo $v['clase']; ?>"><?php echo $v['texto']; ?></td>
                            <td class="py-2 pl-3 pr-5 text-right"><?php echo dinero($ra['comision']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rps_a)): ?><tr><td colspan="11" class="px-3 py-8 text-sm text-neutral-500 text-center">Sin reservas en <?php echo $nombre_a; ?>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-neutral-600 mb-6">Nota: el canal de origen solo se registra en las visitas sin reserva; las reservas se desglosan por tipo de RP. Las ventas incluyen todos los cierres de caja de cada reserva.</p>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    const datos = <?php echo json_encode($chart, JSON_UNESCAPED_UNICODE); ?>;
    // El color identifica la medida; el mes B va en la misma tonalidad pero atenuado
    const MEDIDAS = {
        venta:    { etiqueta: 'Venta',    panel: 'dinero',   a: '#3987e5', b: 'rgba(57,135,229,0.45)',  formato: n => '$' + Math.round(n).toLocaleString('es-MX') },
        comision: { etiqueta: 'Comisión', panel: 'dinero',   a: '#199e70', b: 'rgba(25,158,112,0.45)',  formato: n => '$' + Math.round(n).toLocaleString('es-MX') },
        personas: { etiqueta: 'Personas', panel: 'personas', a: '#d95926', b: 'rgba(217,89,38,0.45)',   formato: n => n },
    };
    const C = { texto: '#c3c2b7', grid: 'rgba(255,255,255,0.08)', efA: '#3987e5', efB: 'rgba(195,194,183,0.5)' };
    const pesos = MEDIDAS.venta.formato;
    const charts = {};

    Chart.defaults.color = C.texto;
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(23,23,23,0.95)';
    Chart.defaults.plugins.tooltip.borderColor = 'rgba(100,100,100,0.5)';
    Chart.defaults.plugins.tooltip.borderWidth = 1;

    // ---- Filtros de datos por gráfica ----
    function leerFiltros(id) {
        const form = document.querySelector(`[data-grafica="${id}"] form.filtros`);
        const v = n => (form.elements[n].value || '').trim();
        return {
            cliente: v('cliente').toLowerCase(), fecha_min: v('fecha_min'), fecha_max: v('fecha_max'), id_rp: v('id_rp'), tipo: v('tipo'),
            estados: [...form.querySelectorAll('input[name="estado"]:checked')].map(c => c.value),
            medidas: [...form.querySelectorAll('input[name="medida"]:checked')].map(c => c.value),
        };
    }
    function hayFiltros(f) {
        return f.cliente !== '' || f.fecha_min !== '' || f.fecha_max !== '' || f.id_rp !== '' || f.tipo !== '' || f.estados.length > 0;
    }
    function filtrar(rows, f) {
        return rows.filter(r =>
            (f.cliente === '' || r.cliente.toLowerCase().includes(f.cliente)) &&
            (f.fecha_min === '' || r.fecha >= f.fecha_min) && (f.fecha_max === '' || r.fecha <= f.fecha_max) &&
            (f.id_rp === '' || String(r.id_rp) === f.id_rp) && (f.tipo === '' || r.tipo === f.tipo) &&
            (f.estados.length === 0 || f.estados.includes(r.estado))
        );
    }
    function toggleFiltros(id) {
        document.querySelector(`[data-grafica="${id}"] form.filtros`).classList.toggle('hidden');
    }
    function limpiarFiltros(id) {
        const form = document.querySelector(`[data-grafica="${id}"] form.filtros`);
        form.reset();
        aplicarFiltros(id);
    }

    // ---- Agrupaciones ----
    const sumar = (rows, clave) => {
        const g = {};
        rows.forEach(r => {
            const k = clave(r);
            const t = g[k] || (g[k] = { venta: 0, comision: 0, personas: 0, reservas: 0, fin: 0, ina: 0, nombre: r.rp });
            t.venta += r.venta; t.comision += r.comision; t.personas += r.personas; t.reservas++;
            if (r.estado === 'finalizada') t.fin++; if (r.estado === 'inasistente') t.ina++;
        });
        return g;
    };
    const efect = t => (!t || t.fin + t.ina === 0) ? null : Math.round(t.fin / (t.fin + t.ina) * 1000) / 10;
    const diasMax = Math.max(datos.dias_a, datos.dias_b);

    // Etiquetas (categorías) y agrupaciones A/B de cada gráfica
    const GRUPOS = {
        tipo: (a, b) => ({ labels: datos.tipos, ga: sumar(a, r => r.tipo), gb: sumar(b, r => r.tipo) }),
        rp: (a, b) => {
            const ga = sumar(a, r => r.id_rp), gb = sumar(b, r => r.id_rp);
            const top = Object.keys(ga).sort((x, y) => ga[y].venta - ga[x].venta || ga[y].personas - ga[x].personas).slice(0, 10);
            return { labels: top.map(k => ga[k].nombre), claves: top, ga, gb };
        },
        dias: (a, b) => ({ labels: Array.from({ length: diasMax }, (_, i) => i + 1), ga: sumar(a, r => parseInt(r.fecha.slice(8), 10)), gb: sumar(b, r => parseInt(r.fecha.slice(8), 10)) }),
        efectividad: (a, b) => ({ labels: datos.tipos, ga: sumar(a, r => r.tipo), gb: sumar(b, r => r.tipo) }),
    };
    // Valor de una medida para cada etiqueta; en "dias" los días que no existen en ese mes quedan en null
    function serie(grupo, medida, g, claves, dias) {
        return claves.map((k, i) => {
            if (dias !== undefined && i >= dias) return null;
            return g[k] ? g[k][medida] : 0;
        });
    }

    function aplicarFiltros(id) {
        const f = leerFiltros(id);
        const a = filtrar(datos.a, f), b = filtrar(datos.b, f);
        const card = document.querySelector(`[data-grafica="${id}"]`);
        card.querySelector('.filtros-activos').classList.toggle('hidden', !hayFiltros(f));
        card.querySelector('.conteo').textContent = `${a.length} de ${datos.a.length} reservas de ${datos.corto_a} · ${b.length} de ${datos.b.length} de ${datos.corto_b}` + (hayFiltros(f) ? ' (filtros de esta gráfica activos)' : '');

        const grupo = GRUPOS[id](a, b);
        const claves = grupo.claves || grupo.labels;

        if (id === 'efectividad') {
            const ch = charts['efectividad-dinero'];
            ch.data.labels = grupo.labels;
            ch.data.datasets[0].data = claves.map(k => efect(grupo.ga[k]));
            ch.data.datasets[1].data = claves.map(k => efect(grupo.gb[k]));
            ajustarAlto(card.querySelector('.panel-dinero'), grupo.labels.length, 2);
            ch.update();
            return;
        }

        // Paneles: pesos (venta/comisión) y personas, según las casillas "Mostrar"
        ['dinero', 'personas'].forEach(panel => {
            const medidas = Object.keys(MEDIDAS).filter(m => MEDIDAS[m].panel === panel && f.medidas.includes(m));
            const cont = card.querySelector('.panel-' + panel);
            cont.hidden = medidas.length === 0;
            if (medidas.length === 0) return;
            const ch = charts[id + '-' + panel];
            ch.data.labels = grupo.labels;
            ch.data.datasets = [];
            medidas.forEach(m => {
                ch.data.datasets.push(dataset(id, m, 'a', serie(grupo, m, grupo.ga, claves, id === 'dias' ? datos.dias_a : undefined)));
                ch.data.datasets.push(dataset(id, m, 'b', serie(grupo, m, grupo.gb, claves, id === 'dias' ? datos.dias_b : undefined)));
            });
            if (id !== 'dias') ajustarAlto(cont, grupo.labels.length, ch.data.datasets.length);
            ch.update();
        });
        card.querySelector('.sin-medidas').classList.toggle('hidden', f.medidas.length > 0);
    }
    function dataset(id, medida, mes, data) {
        const M = MEDIDAS[medida];
        const base = { label: M.etiqueta + ' ' + (mes === 'a' ? datos.corto_a : datos.corto_b), data, medida };
        if (id === 'dias') {
            return { ...base, borderColor: mes === 'a' ? M.a : M.b, backgroundColor: mes === 'a' ? M.a : M.b, borderWidth: 2, borderDash: mes === 'a' ? [] : [4, 3], pointRadius: mes === 'a' ? 3 : 2, pointHoverRadius: 5, tension: 0.2, spanGaps: false };
        }
        return { ...base, backgroundColor: mes === 'a' ? M.a : M.b, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 14, borderWidth: 0 };
    }
    // Alto del panel según cuántas barras hay (horizontales)
    function ajustarAlto(cont, categorias, series) {
        cont.style.height = Math.max(160, categorias * (16 * series + 14) + 60) + 'px';
    }

    // ---- Gráficas ----
    const etiquetas = {
        id: 'etiquetas',
        afterDatasetsDraw(chart) {
            if (chart.config.type !== 'bar') return;
            const { ctx } = chart;
            ctx.save(); ctx.fillStyle = C.texto; ctx.font = '600 10px Inter, system-ui, sans-serif';
            chart.data.datasets.forEach((ds, i) => {
                if (!chart.isDatasetVisible(i)) return;
                const formato = ds.medida ? MEDIDAS[ds.medida].formato : (v => v + '%');
                chart.getDatasetMeta(i).data.forEach((barra, j) => {
                    const v = ds.data[j];
                    if (v === null || v === undefined || v === 0) return;
                    ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
                    ctx.fillText(formato(v), barra.x + 5, barra.y);
                });
            });
            ctx.restore();
        }
    };
    const leyenda = { position: 'top', align: 'end' };
    const tooltipMedida = c => c.dataset.label + ': ' + (c.raw === null ? 'no aplica' : (c.dataset.medida ? MEDIDAS[c.dataset.medida].formato(c.raw) : c.raw + '%'));

    function barrasH(id, formato, max) {
        charts[id] = new Chart(document.getElementById('chart-' + id), {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: { indexAxis: 'y', maintainAspectRatio: false, layout: { padding: { right: 60 } },
                scales: { x: { grid: { color: C.grid }, border: { display: false }, ticks: { callback: formato, maxTicksLimit: 6 }, beginAtZero: true, max },
                          y: { grid: { display: false }, border: { display: false } } },
                plugins: { legend: leyenda, tooltip: { callbacks: { label: tooltipMedida } } } },
            plugins: [etiquetas]
        });
    }
    function lineas(id, formato) {
        charts[id] = new Chart(document.getElementById('chart-' + id), {
            type: 'line',
            data: { labels: [], datasets: [] },
            options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                scales: { x: { grid: { display: false }, border: { display: false }, title: { display: true, text: 'Día del mes' } },
                          y: { grid: { color: C.grid }, border: { display: false }, ticks: { callback: formato, maxTicksLimit: 6 }, beginAtZero: true } },
                plugins: { legend: leyenda, tooltip: { callbacks: { title: c => 'Día ' + c[0].label, label: tooltipMedida } } } }
        });
    }
    ['tipo', 'rp'].forEach(id => { barrasH(id + '-dinero', pesos); barrasH(id + '-personas', v => v); });
    lineas('dias-dinero', pesos);
    lineas('dias-personas', v => v);
    barrasH('efectividad-dinero', v => v + '%', 100);
    charts['efectividad-dinero'].data.datasets = [
        { label: datos.corto_a, data: [], backgroundColor: C.efA, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 14, borderWidth: 0 },
        { label: datos.corto_b, data: [], backgroundColor: C.efB, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 14, borderWidth: 0 },
    ];

    ['tipo', 'rp', 'dias', 'efectividad'].forEach(aplicarFiltros);
    </script>

    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
