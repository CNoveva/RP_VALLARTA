<?php
require_once '../includes/db.php';
require_once '../includes/turno.php';
session_start();

// Seguridad: solo caja o admin/gerente
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['caja', 'gerente', 'admin', 'hostes'])) {
    die('Acceso no autorizado.');
}

// Recibir la fecha
$fecha = $_GET['fecha_exportar'] ?? date('Y-m-d');
// Turno: de las 5:00 pm de la fecha a las 4:59 pm del día siguiente
$fecha_inicio = turno_inicio($fecha);
$fecha_fin = turno_fin($fecha);

// Consulta SQL para obtener las cuentas cerradas de esa fecha
$sql = "SELECT 
            cc.id,
            r.nombre_cliente,
            r.fecha_llegada,
            u.nombre_completo as nombre_rp,
            u.tipo_rp,
            cc.folio_cierre as folio_btla,
            cc.folio_pos,
            cc.mesero_asignado,
            cc.fecha_cierre,
            cc.venta_total,
            cc.porcentaje_comision,
            cc.monto_comision,
            cc.propina,
            cc.comision_pagada,
            cc.fecha_pago_comision,
            cc.comision_devuelta,
            cc.fecha_devolucion
        FROM cuentas_cerradas cc
        JOIN reservas r ON cc.id_reserva = r.id
        JOIN usuarios u ON r.id_rp = u.id
        WHERE cc.fecha_cierre >= :fecha_inicio AND cc.fecha_cierre < :fecha_fin
        ORDER BY cc.folio_cierre ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute(['fecha_inicio' => $fecha_inicio, 'fecha_fin' => $fecha_fin]);
$cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Configurar cabeceras para descarga CSV
$filename = "Cierre_Caja_" . $fecha . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Abrir salida
$output = fopen('php://output', 'w');

// BOM para que Excel reconozca acentos y caracteres especiales
fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Encabezados de columnas
fputcsv($output, [
    'Folio VTA',
    'Cliente',
    'RP',
    'Tipo RP',
    'Apertura',
    'Cierre',
    'Folio POS',
    'Venta Total',
    '$ Comision',
    'Mesero',
    'Propina (5%)',
    'Comisión pagada'
]);

// Rellenar datos
foreach ($cuentas as $c) {
    fputcsv($output, [
        $c['folio_btla'],
        $c['nombre_cliente'],
        $c['nombre_rp'],
        $c['tipo_rp'] ?? '-',
        // Apertura = confirmación de llegada en hostess; Cierre = cierre de la cuenta en caja
        !empty($c['fecha_llegada']) ? date('d/m/Y h:i A', strtotime($c['fecha_llegada'])) : '-',
        date('d/m/Y h:i A', strtotime($c['fecha_cierre'])),
        $c['folio_pos'],
        '$' . number_format($c['venta_total'], 2),
        '$' . number_format($c['monto_comision'], 2),
        $c['mesero_asignado'] ?? '-',
        '$' . number_format($c['propina'], 2),
        !empty($c['comision_devuelta']) ? 'Devuelta ' . date('d/m/Y h:i A', strtotime($c['fecha_devolucion'])) : (!empty($c['comision_pagada']) ? date('d/m/Y h:i A', strtotime($c['fecha_pago_comision'])) : 'Pendiente')
    ]);
}

fclose($output);
exit;
?>