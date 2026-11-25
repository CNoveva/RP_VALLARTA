#!/usr/local/bin/php
<?php
// Marca como inasistentes las reservas "nuevas" cuya hora ya pasó.
// Tarea programada en Coolify (Scheduled Tasks), una vez al día:
//   php /var/www/html/scripts/verificar_inasistencias.php
// La conexión toma las variables de entorno del contenedor (ver includes/db.php).
require_once __DIR__ . "/../includes/db.php";

echo "Iniciando verificación de inasistencias...\n";

try {
    // Corte a las 7:00 am: si ya pasaron las 7, hoy; si no, ayer.
    $hora_actual = (int)date("H");
    $limite = $hora_actual >= 7 ? date("Y-m-d 07:00:00") : date("Y-m-d 07:00:00", strtotime("-1 day"));

    $stmt = $pdo->prepare("UPDATE reservas SET estado = \x27inasistente\x27 WHERE estado = \x27nueva\x27 AND fecha_reserva < :limite");
    $stmt->execute(["limite" => $limite]);
    $afectadas = $stmt->rowCount();

    echo $afectadas > 0 ? "Se marcaron $afectadas reservas como inasistentes.\n" : "No hay reservas pendientes por marcar.\n";
} catch (Exception $e) {
    echo "ERROR en el script: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Script finalizado.\n";
