<?php
require_once '../includes/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['hostes', 'gerente', 'admin']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado o método incorrecto.']);
    exit;
}

$id_reserva = isset($_POST['id_reserva']) ? intval($_POST['id_reserva']) : 0;

if ($id_reserva > 0) {
    try {
        $pdo->beginTransaction();
        
        $stmt_check = $pdo->prepare("SELECT estado FROM reservas WHERE id = :id FOR UPDATE");
        $stmt_check->execute([':id' => $id_reserva]);
        $reserva = $stmt_check->fetch();

        if ($reserva && $reserva['estado'] === 'iniciada') {
            $sql = "UPDATE reservas 
                    SET estado = 'nueva', fecha_llegada = NULL 
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id_reserva]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'La reserva no se puede regresar a nueva (ya fue finalizada o no está iniciada).']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID de reserva no válido.']);
}
?>
