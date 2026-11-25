<?php
// No usar header.php aquí, ya que es una descarga de archivo
require_once '../includes/db.php';
require_once '../includes/turno.php';
session_start();

// Seguridad: solo gerente
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'gerente') {
    die('Acceso no autorizado.');
}

// --- Repetir Lógica de Filtros ---
$where_clauses = [];
$params = [];

$estado_filtro = $_GET['estado'] ?? 'todas';
if ($estado_filtro != 'todas') {
    $where_clauses[] = "r.estado = :estado";
    $params['estado'] = $estado_filtro;
}
$rp_filtro = $_GET['id_rp'] ?? 'todos';
if ($rp_filtro != 'todos') {
    $where_clauses[] = "r.id_rp = :id_rp";
    $params['id_rp'] = $rp_filtro;
}
$tipo_rp_filtro = $_GET['tipo_rp'] ?? 'todos';
if ($tipo_rp_filtro != 'todos') {
    $where_clauses[] = "u.tipo_rp = :tipo_rp";
    $params['tipo_rp'] = $tipo_rp_filtro;
}
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $where_clauses[] = "r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
    $params['fecha_inicio'] = turno_inicio($fecha_inicio);
    $params['fecha_fin'] = turno_fin($fecha_fin);
}
// Búsqueda y filtro de pago, iguales al reporte
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
$pago_filtro = $_GET['pago'] ?? 'todos';
if ($pago_filtro == 'pagada') {
    $where_clauses[] = "cc.comision_pagada = 1 AND cc.comision_devuelta = 0";
} elseif ($pago_filtro == 'pendiente') {
    $where_clauses[] = "cc.id IS NOT NULL AND cc.comision_pagada = 0";
} elseif ($pago_filtro == 'devuelta') {
    $where_clauses[] = "cc.comision_devuelta = 1";
}

$sql = "SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp,
               cc.folio_cierre, cc.folio_pos, cc.venta_total, cc.porcentaje_comision, cc.monto_comision, cc.propina, cc.fecha_cierre,
               cc.comision_pagada, cc.fecha_pago_comision, cc.comision_devuelta, cc.fecha_devolucion
        FROM reservas r
        JOIN usuarios u ON r.id_rp = u.id
        LEFT JOIN cuentas_cerradas cc ON r.id = cc.id_reserva";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}
$sql .= " ORDER BY r.fecha_reserva DESC, cc.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Generar CSV ---
$filename = "Reporte_Gerencia_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Encabezados del CSV (UTF-8 BOM para Excel)
fputs($output, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

// AGREGAMOS LOS NUEVOS ENCABEZADOS AL FINAL
fputcsv($output, [
    'Folio VTA',
    'Cliente',
    'Personas',
    'Fecha Reserva',
    'Estado',
    'RP',
    'Tipo RP',
    'Fecha Llegada',
    'Fecha Cierre',
    'Folio POS',
    'Venta Total',
    'Comisión (%)',
    'Comisión ($)',
    'Propina',
    'Comisión pagada'
]);

// Datos
foreach ($reservas as $reserva) {
    fputcsv($output, [
        $reserva['folio_cierre'] ?? ('VTA' . $reserva['id']),
        $reserva['nombre_cliente'],
        $reserva['num_personas'],
        $reserva['fecha_reserva'],
        ucfirst($reserva['estado']),
        $reserva['nombre_rp'],
        $reserva['tipo_rp'] ?? '-',
        $reserva['fecha_llegada'],
        $reserva['fecha_cierre'],
        $reserva['folio_pos'],
        // Formato de moneda simple sin el símbolo para facilitar cálculos en Excel si se requiere
        $reserva['venta_total'],
        $reserva['porcentaje_comision'],
        $reserva['monto_comision'],
        $reserva['propina'],
        // Solo aplica a reservas con cierre: fecha del pago al RP o "Pendiente"
        $reserva['folio_cierre'] === null ? '' : (!empty($reserva['comision_devuelta']) ? 'Devuelta ' . date('d/m/Y h:i A', strtotime($reserva['fecha_devolucion'])) : (!empty($reserva['comision_pagada']) ? date('d/m/Y h:i A', strtotime($reserva['fecha_pago_comision'])) : 'Pendiente'))
    ]);
}

fclose($output);
exit;
?>