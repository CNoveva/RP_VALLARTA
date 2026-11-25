<?php
require_once '../includes/db.php';
session_start();

// Este endpoint puede ser llamado via GET (escaneo directo) o POST (desde el escáner del hostes)
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$es_ajax = isset($_GET['ajax']) || $_SERVER['REQUEST_METHOD'] === 'POST';

// Si se accede directamente por navegador (cámara del celular), redirigir al login
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$es_ajax) {
    header('Location: ../login.php');
    exit;
}

header('Content-Type: application/json');

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Token no proporcionado.']);
    exit;
}

// Buscar la reservación por token
$stmt = $pdo->prepare("
    SELECT re.*, e.nombre AS nombre_evento 
    FROM reservas_evento re 
    JOIN eventos e ON re.id_evento = e.id 
    WHERE re.qr_token = :token
");
$stmt->execute([':token' => $token]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    echo json_encode(['success' => false, 'message' => 'QR no válido. No se encontró ninguna reservación con este código.', 'tipo' => 'no_encontrado']);
    exit;
}

// Verificar si ya fue usada
if ($reserva['estado'] !== 'pendiente') {
    echo json_encode([
        'success' => false,
        'message' => '⚠️ Este QR ya se usó anteriormente',
        'tipo' => 'ya_usado',
        'estado_actual' => $reserva['estado'],
        'fecha_escaneo' => $reserva['fecha_escaneo'],
        'nombre_cliente' => $reserva['nombre_cliente']
    ]);
    exit;
}

// Si es una petición POST desde el escáner del hostes, iniciar la reserva
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar que sea hostes
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'hostes') {
        echo json_encode(['success' => false, 'message' => 'Solo el hostes puede escanear QR de eventos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Verificar estado con bloqueo
        $stmt_check = $pdo->prepare("SELECT estado FROM reservas_evento WHERE id = :id FOR UPDATE");
        $stmt_check->execute([':id' => $reserva['id']]);
        $check = $stmt_check->fetch();

        if ($check && $check['estado'] === 'pendiente') {
            $stmt_update = $pdo->prepare("
                UPDATE reservas_evento 
                SET estado = 'iniciada', fecha_escaneo = NOW() 
                WHERE id = :id
            ");
            $stmt_update->execute([':id' => $reserva['id']]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Reserva iniciada correctamente.',
                'id_reserva_evento' => $reserva['id'],
                'nombre_cliente' => $reserva['nombre_cliente'],
                'nombre_evento' => $reserva['nombre_evento'],
                'num_personas' => $reserva['num_personas'],
                'folio_stripe' => $reserva['folio_stripe'],
                'monto_consumo' => $reserva['monto_consumo']
            ]);
        } else {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => '⚠️ Este QR ya se usó anteriormente',
                'tipo' => 'ya_usado'
            ]);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
    }
} else {
    // GET con ajax - devolver info
    echo json_encode([
        'success' => true,
        'message' => 'QR válido. Listo para escanear.',
        'nombre_cliente' => $reserva['nombre_cliente'],
        'nombre_evento' => $reserva['nombre_evento'],
        'num_personas' => $reserva['num_personas'],
        'estado' => $reserva['estado']
    ]);
}
?>