<?php
require_once '../includes/db.php';
require_once '../includes/turno.php';
session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'hostes') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso no autorizado']);
    exit;
}

header('Content-Type: application/json');

$desde_id = isset($_GET['desde_id']) ? intval($_GET['desde_id']) : 0;
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

$sql_extra = "";
$params = [':desde_id' => $desde_id];

if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    // Rango por turno (5 pm a 4:59 pm del día siguiente)
    $sql_extra = " AND r.fecha_reserva >= :fecha_inicio AND r.fecha_reserva < :fecha_fin";
    $params[':fecha_inicio'] = turno_inicio($fecha_inicio);
    $params[':fecha_fin'] = turno_fin($fecha_fin);
}

// Misma búsqueda que el panel de hostes (cliente, RP o folio)
$busqueda = trim($_GET['q'] ?? '');
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $sql_extra .= " AND (r.nombre_cliente LIKE :q_cliente OR u.nombre_completo LIKE :q_rp OR CONCAT('VTA', r.id) LIKE :q_folio)";
    $params[':q_cliente'] = $like;
    $params[':q_rp'] = $like;
    $params[':q_folio'] = $like;
}

$dias_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

try {
    $sql = "SELECT r.id, r.nombre_cliente, r.num_personas, r.fecha_reserva, u.nombre_completo AS nombre_rp, u.tipo_rp
            FROM reservas r
            JOIN usuarios u ON r.id_rp = u.id
            WHERE r.estado = 'nueva' AND r.id > :desde_id $sql_extra
            ORDER BY r.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reservas as $key => $reserva) {
        $ts = strtotime($reserva['fecha_reserva']);
        $reservas[$key]['fecha_reserva_formato'] = date('d/m/Y h:i A', $ts);
        // Datos del encabezado por día en el panel de hostes
        // Agrupado por turno: las reservas de madrugada pertenecen al día anterior
        $fecha_grupo = turno_de($reserva['fecha_reserva']);
        $reservas[$key]['fecha_grupo'] = $fecha_grupo;
        $reservas[$key]['fecha_grupo_etiqueta'] = $dias_semana[date('w', strtotime($fecha_grupo))] . ' ' . date('d/m/Y', strtotime($fecha_grupo));
        $reservas[$key]['es_hoy'] = ($fecha_grupo == turno_hoy());
    }

    echo json_encode($reservas);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>