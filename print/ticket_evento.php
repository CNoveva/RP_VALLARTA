<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['hostes', 'gerente', 'admin', 'caja'])) {
    die('Acceso denegado.');
}

$id_reserva_evento = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_reserva_evento === 0) die('ID no v¨¢lido.');

$stmt = $pdo->prepare("
    SELECT re.*, e.nombre AS nombre_evento 
    FROM reservas_evento re 
    JOIN eventos e ON re.id_evento = e.id 
    WHERE re.id = :id
");
$stmt->execute([':id' => $id_reserva_evento]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) die('Reservaci¨®n de evento no encontrada.');

$folio = "EV" . $reserva['id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket Evento <?php echo $folio; ?></title>
    <style>
        @media print {
            body, html { width: 58mm; margin: 0; padding: 0; }
            @page { margin: 2mm; }
            body { padding-bottom: 50; }
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            font-weight: bold;
            width: 210px;
            margin: 0 auto;
            padding: 5px;
            color: #000;
        }
        .ticket-header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; margin-bottom: 5px; }
        .ticket-header h1 { font-size: 15px; margin: 0; }
        .ticket-header p { margin: 2px 0; }
        .ticket-body { font-size: 12px; }
        .ticket-body p { margin: 3px 0; }
        .ticket-body strong { display: inline-block; width: 80px; }
        .folio { font-size: 17px; font-weight: bold; text-align: center; margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; }
        .evento-nombre { font-size: 14px; font-weight: bold; text-align: center; margin: 8px 0; text-transform: uppercase; }
        .consumo-box { 
            border: 2px solid #000; 
            padding: 8px; 
            margin: 10px 0; 
            text-align: center; 
            font-size: 13px; 
            font-weight: bold; 
        }
        .consumo-monto { font-size: 17px; margin: 4px 0; }
        .stripe-folio { 
            border-top: 1px dashed #000; 
            border-bottom: 1px dashed #000; 
            padding: 5px 0; 
            margin: 8px 0; 
            text-align: center; 
            font-size: 12px; 
            font-weight: bold;
        }
        .footer { text-align: center; margin-top: 10px; font-size: 10px; }
    </style>
</head>
<body onload="window.print();">

    <div class="ticket-header">
        <h1>TAPANKO VALLARTA</h1>
        <p>Ticket de Evento</p>
        <p><?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_escaneo'] ?? date('Y-m-d H:i:s'))); ?></p>
    </div>

    <div class="evento-nombre">
        <?php echo htmlspecialchars($reserva['nombre_evento']); ?>
    </div>

    <div class="folio">
        FOLIO: <?php echo $folio; ?>
    </div>

    <div class="ticket-body">
        <p><strong>CLIENTE:</strong> <?php echo htmlspecialchars($reserva['nombre_cliente']); ?></p>
        <p><strong>PERSONAS:</strong> <?php echo $reserva['num_personas']; ?></p>
        <p><strong>H RESERVA:</strong> <?php echo date('h:i A', strtotime($reserva['fecha_hora'])); ?></p>
    </div>

    <div class="stripe-folio">
        FOLIO STRIPE: <?php echo htmlspecialchars($reserva['folio_stripe']); ?>
    </div>

    <div class="consumo-box">
        <div>A FAVOR PARA CONSUMO:</div>
        <div class="consumo-monto">$<?php echo number_format($reserva['monto_consumo'], 2); ?> pesos</div>
    </div>
    
    <div class="footer">
        ** Conservar este ticket **
        <br>
        (Para uso de Caja)
        
    </div>

</body>
</html>
