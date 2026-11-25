<?php
// Hostess ajusta el número de personas de una reserva ya iniciada
// (llegaron más o menos de los reservados).
require_once '../includes/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'hostes' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado o método incorrecto.']);
    exit;
}

$id_reserva = intval($_POST['id_reserva'] ?? 0);
$num_personas = intval($_POST['num_personas'] ?? 0);

if ($id_reserva <= 0 || $num_personas < 1 || $num_personas > 500) {
    echo json_encode(['success' => false, 'message' => 'Indica un número de personas válido (1 a 500).']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT estado, num_personas FROM reservas WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $id_reserva]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reserva) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Reserva no encontrada.']);
        exit;
    }
    if ($reserva['estado'] !== 'iniciada') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Solo se puede cambiar el número de personas de reservas iniciadas.']);
        exit;
    }

    $pdo->prepare("UPDATE reservas SET num_personas = :n WHERE id = :id")
        ->execute(['n' => $num_personas, 'id' => $id_reserva]);
    $pdo->commit();

    echo json_encode(['success' => true, 'num_personas' => $num_personas, 'anterior' => intval($reserva['num_personas'])]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
