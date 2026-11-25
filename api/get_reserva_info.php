<?php
require_once '../includes/db.php';
session_start();

header('Content-Type: application/json');

// Seguridad: solo caja, gerente o admin
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['caja', 'gerente', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$id_reserva = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_reserva > 0) {
    try {
        $stmt = $pdo->prepare("SELECT porcentaje_comision_sugerido FROM reservas WHERE id = :id");
        $stmt->execute(['id' => $id_reserva]);
        $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reserva) {
            echo json_encode([
                'success' => true, 
                'sugerido' => $reserva['porcentaje_comision_sugerido'] ?? 0
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error BD']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
}
?>