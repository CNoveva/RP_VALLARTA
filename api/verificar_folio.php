<?php
require_once '../includes/db.php';
require_once '../includes/folio.php';
session_start();

header('Content-Type: application/json');

// Seguridad: solo caja
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'caja') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$folio = parsear_folio($_GET['folio'] ?? '');

if (!$folio) {
    echo json_encode(['success' => false, 'message' => "Folio inválido: debe iniciar con VTA (o EV) y el número de reserva, ej. VTA355 o VTA355A."]);
    exit;
}

try {
    if ($folio['es_evento']) {
        $stmt = $pdo->prepare("SELECT re.nombre_cliente, e.nombre AS nombre_rp, re.estado
                               FROM reservas_evento re
                               JOIN eventos e ON re.id_evento = e.id
                               WHERE re.id = :id");
    } else {
        $stmt = $pdo->prepare("SELECT r.nombre_cliente, u.nombre_completo AS nombre_rp, r.estado
                               FROM reservas r
                               JOIN usuarios u ON r.id_rp = u.id
                               WHERE r.id = :id");
    }
    $stmt->execute(['id' => $folio['id']]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reserva) {
        echo json_encode(['success' => false, 'message' => "No existe ninguna reserva con el folio {$folio['folio']}."]);
        exit;
    }

    // Cierres ya registrados para esa reserva (cuentas divididas o capturas repetidas)
    $stmt_cierres = $pdo->prepare("SELECT folio_cierre, folio_pos FROM cuentas_cerradas
                                   WHERE id_reserva = :id AND folio_cierre LIKE :prefijo
                                   ORDER BY id ASC");
    $stmt_cierres->execute([
        'id' => $folio['id'],
        'prefijo' => ($folio['es_evento'] ? 'EV' : 'VTA') . '%'
    ]);
    $cierres = $stmt_cierres->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'folio' => $folio['folio'],
        'cerrable' => in_array($reserva['estado'], ['iniciada', 'finalizada']),
        'estado' => $reserva['estado'],
        'nombre_cliente' => $reserva['nombre_cliente'],
        'nombre_rp' => $reserva['nombre_rp'],
        'cierres' => $cierres
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error BD']);
}
?>