<?php
// Conexión a la base de datos por VARIABLES DE ENTORNO (Coolify / Docker).
// Se definen en Coolify > la aplicación > Environment Variables:
//   DB_HOST      host del servicio MySQL/MariaDB de Coolify
//   DB_PORT      3306 (por defecto)
//   DB_NAME      nombre de la base de datos de VALLARTA
//   DB_USER      usuario
//   DB_PASS      contraseña
//   DB_TIMEZONE  zona horaria de la sesión MySQL; -06:00 = Vallarta (sin horario de verano)
//                Importante: NOW() y los turnos de 5 pm dependen de esto; el contenedor de MySQL por defecto está en UTC.
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: '';
$db_user = getenv('DB_USER') ?: '';
$db_pass = getenv('DB_PASS') ?: '';
$db_tz   = getenv('DB_TIMEZONE') ?: '-06:00';

if ($db_name === '' || $db_user === '') {
    http_response_code(500);
    die('ERROR: Faltan las variables de entorno DB_NAME y DB_USER (configúralas en Coolify).');
}

try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    // Misma hora en PHP y en MySQL (fecha_cierre, visitas, pagos usan NOW())
    $pdo->exec("SET time_zone = " . $pdo->quote($db_tz));
} catch (PDOException $e) {
    // El detalle va al log del contenedor, no al navegador (no exponer credenciales)
    error_log('Error de conexión a la base de datos: ' . $e->getMessage());
    http_response_code(500);
    die('ERROR: No se pudo conectar a la base de datos.');
}
