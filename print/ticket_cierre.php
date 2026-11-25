<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['caja', 'gerente', 'hostes', 'admin'])) {
    die('Acceso denegado.');
}

$id_cierre = isset($_GET['id_cierre']) ? intval($_GET['id_cierre']) : 0;
if ($id_cierre === 0) die('ID no válido.');

$stmt = $pdo->prepare("
    SELECT cc.*, r.nombre_cliente, u.nombre_completo AS nombre_rp, u.tipo_rp 
    FROM cuentas_cerradas cc
    JOIN reservas r ON cc.id_reserva = r.id
    JOIN usuarios u ON r.id_rp = u.id
    WHERE cc.id = :id_cierre
");
$stmt->execute([':id_cierre' => $id_cierre]);
$cierre = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cierre) die('Cierre no encontrado.');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket Cierre <?php echo htmlspecialchars($cierre['folio_cierre']); ?></title>
    <style>
        @media print {
            body, html { width: 58mm; margin: 0; padding: 0; }
            @page { margin: 2mm; }
            
            /* ======================================================= */
            /* ==== ¡AQUÍ ESTÁ LA LÍNEA QUE DEBES AGREGAR! ==== */
            /* ======================================================= */
            body { padding-bottom: 50px; /* Ajusta este valor si es necesario */ }
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
        .ticket-body strong { display: inline-block; width: 80px; }
        .folio { font-size: 17px; font-weight: bold; text-align: center; margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; }
        .footer { text-align: center; margin-top: 10px; font-size: 10px; }
    </style>
</head>
<body onload="window.print();">

    <div class="ticket-header">
        <h1>TAPANKO VALLARTA</h1>
        <p>Cierre de Cuenta</p>
        <p><?php echo date('d/m/Y h:i A', strtotime($cierre['fecha_cierre'])); ?></p>
    </div>

    <div class="folio">
        FOLIO: <?php echo htmlspecialchars($cierre['folio_cierre']); ?><br>
        <span style="font-size: 13px; font-weight: bold;">POS: <?php echo htmlspecialchars($cierre['folio_pos']); ?></span>
    </div>

    <div class="ticket-body">
        <p><strong>CLIENTE:</strong> <?php echo htmlspecialchars($cierre['nombre_cliente']); ?></p>
        <p><strong>RP:</strong> <?php echo htmlspecialchars($cierre['nombre_rp']); ?></p>
        <p><strong>TIPO RP:</strong> <?php echo htmlspecialchars($cierre['tipo_rp'] ?? '-'); ?></p>
        <p><strong>MESERO:</strong> <?php echo htmlspecialchars($cierre['mesero_asignado'] ?? '-'); ?></p>
        <br>
        <p><strong>VENTA TOTAL:</strong> $<?php echo number_format($cierre['venta_total'], 2); ?></p>
        <p><strong>COMISIÓN:</strong> <?php echo number_format($cierre['porcentaje_comision'], 0); ?>% ($<?php echo number_format($cierre['monto_comision'], 2); ?>)</p>
    </div>
    
    <div class="footer">
        <p>¡Gracias por su visita!</p>
    </div>

</body>
</html>
