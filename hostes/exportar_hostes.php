<?php
require_once '../includes/db.php';
require_once '../includes/turno.php';
session_start();

// Seguridad: solo gerente o admin (hostess ya no exporta)
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['gerente', 'admin'])) {
    die('Acceso no autorizado.');
}

// Recibir parámetros
$tab = $_GET['tab'] ?? 'activas';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

// Construir consulta según pestaña
$where_clause = "";
$order_by = "ORDER BY r.fecha_reserva ASC, r.nombre_cliente ASC";

if ($tab == 'activas') {
    $where_clause = "WHERE (r.estado = 'nueva' OR r.estado = 'iniciada')";
} elseif ($tab == 'nuevas') {
    $where_clause = "WHERE r.estado = 'nueva'";
} elseif ($tab == 'iniciadas') {
    $where_clause = "WHERE r.estado = 'iniciada'";
} elseif ($tab == 'finalizadas') {
    $where_clause = "WHERE r.estado = 'finalizada'";
    $order_by = "ORDER BY r.fecha_reserva DESC, r.nombre_cliente ASC";
} elseif ($tab == 'historial') {
    $where_clause = "WHERE (r.estado = 'inasistente' OR r.estado = 'cancelada')";
    $order_by = "ORDER BY r.fecha_reserva DESC, r.nombre_cliente ASC";
} else {
    die('Pestaña no válida para exportar.');
}

$params = [];
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $where_clause .= " AND r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
    $params['fecha_inicio'] = turno_inicio($fecha_inicio);
    $params['fecha_fin'] = turno_fin($fecha_fin);
}

// Misma búsqueda que el panel (cliente, RP o folio)
$busqueda = trim($_GET['q'] ?? '');
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $where_clause .= " AND (r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR CONCAT('VTA', r.id) LIKE :q_folio)";
    $params['q_cliente'] = $like;
    $params['q_rp'] = $like;
    $params['q_folio'] = $like;
}

$sql = "SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp
        FROM reservas r
        JOIN usuarios u ON r.id_rp = u.id
        $where_clause
        $order_by
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nombre del archivo
$tab_label = [
    'activas' => 'Activas',
    'nuevas' => 'Nuevas',
    'iniciadas' => 'Iniciadas',
    'finalizadas' => 'Finalizadas',
    'historial' => 'Historial',
][$tab] ?? 'Reservas';

$suffix = '';
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $suffix = "_{$fecha_inicio}_a_{$fecha_fin}";
}
$filename = "Reservas_{$tab_label}{$suffix}.csv";

// Cabeceras para descarga CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM para que Excel reconozca acentos
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Encabezados
fputcsv($output, [
    'Folio',
    'Cliente',
    'RP',
    'Tipo RP',
    'Personas',
    'Fecha Reserva',
    'Hora Reserva',
    'Estado',
    'Fecha Llegada',
]);

// Datos
foreach ($reservas as $r) {
    fputcsv($output, [
        'VTA' . $r['id'],
        $r['nombre_cliente'],
        $r['nombre_rp'],
        $r['tipo_rp'] ?? '-',
        $r['num_personas'],
        date('d/m/Y', strtotime($r['fecha_reserva'])),
        date('h:i A', strtotime($r['fecha_reserva'])),
        ucfirst($r['estado']),
        !empty($r['fecha_llegada']) ? date('d/m/Y h:i A', strtotime($r['fecha_llegada'])) : '-',
    ]);
}

fclose($output);
exit;
?>
