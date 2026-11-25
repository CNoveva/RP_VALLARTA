<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['hostes', 'gerente', 'caja'])) {
    die('Acceso denegado.');
}

$id_reserva = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_reserva === 0) die('ID no válido.');

$stmt = $pdo->prepare("
    SELECT r.*, u.nombre_completo AS nombre_rp, u.tipo_rp 
    FROM reservas r
    JOIN usuarios u ON r.id_rp = u.id
    WHERE r.id = :id
");
$stmt->execute([':id' => $id_reserva]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) die('Reserva no encontrada.');

$folio = "VTA" . $reserva['id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket <?php echo $folio; ?></title>
    <style>
        @media print {
            body, html { width: 58mm; margin: 0; padding: 0; }
            @page { margin: 2mm; }
            
            /* ======================================================= */
            /* ==== ¡AQUÍ ESTÁ LA LÍNEA QUE DEBES AGREGAR! ==== */
            /* ======================================================= */
            body { padding-bottom: 50; /* Ajusta este valor si es necesario */ }
            /* ======================================================= */
            /* ======================================================= */
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            font-weight: bold;
            width: 210px; /* Simula 58mm */
            margin: 0 auto;
            padding: 5px;
            color: #000;
        }
        .ticket-header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; margin-bottom: 5px; }
        .ticket-header h1 { font-size: 15px; margin: 0; }
        .ticket-header p { margin: 2px 0; }
        .ticket-body { font-size: 12px; }
        .ticket-body p { margin: 3px 0; }
        .ticket-body strong { display: inline-block; width: 70px; }
        .folio { font-size: 17px; font-weight: bold; text-align: center; margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; }
        .footer { text-align: center; margin-top: 10px; font-size: 10px; }
    </style>
</head>
<body onload="window.print();">

    <div class="ticket-header">
        <h1>TAPANKO VALLARTA</h1>
        <p>Confirmación de Reserva</p>
        <p><?php echo htmlspecialchars($reserva['fecha_llegada']); ?></p>
    </div>

    <div class="folio">
        FOLIO: <?php echo $folio; ?>
    </div>

    <div class="ticket-body">
        <p><strong>CLIENTE:</strong> <?php echo htmlspecialchars($reserva['nombre_cliente']); ?></p>
        <p><strong>PERSONAS:</strong> <?php echo $reserva['num_personas']; ?></p>
        <p><strong>H RESERVA:</strong> <?php echo date('h:i A', strtotime($reserva['fecha_reserva'])); ?></p>
        <p><strong>RP:</strong> <?php echo htmlspecialchars($reserva['nombre_rp']); ?></p>
        <p><strong>TIPO:</strong> <?php echo htmlspecialchars($reserva['tipo_rp'] ?? '-'); ?></p>
    </div>
    
    <div class="footer">
        ** Conservar este ticket **
        <br>
        (Para uso de Caja)
    </div>

</body>
</html>