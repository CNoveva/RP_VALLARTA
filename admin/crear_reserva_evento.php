<?php
require_once '../includes/header.php';
check_role(['admin']);

$id_evento = isset($_GET['id_evento']) ? intval($_GET['id_evento']) : 0;
if ($id_evento === 0) {
    header('Location: eventos.php');
    exit;
}

// Verificar que el evento existe
$stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = :id");
$stmt->execute([':id' => $id_evento]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
    header('Location: eventos.php');
    exit;
}

$error = '';
$qr_generado = false;
$qr_token_generado = '';
$nombre_cliente_guardado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
    $num_personas = intval($_POST['num_personas'] ?? 0);
    $fecha_hora = $_POST['fecha_hora'] ?? '';
    $folio_stripe = trim($_POST['folio_stripe'] ?? '');
    $monto_consumo = floatval($_POST['monto_consumo'] ?? 0);

    // Validaciones
    if (empty($nombre_cliente)) {
        $error = 'El nombre del cliente es obligatorio.';
    } elseif ($num_personas <= 0) {
        $error = 'El número de personas debe ser mayor a 0.';
    } elseif (empty($fecha_hora)) {
        $error = 'La fecha y hora son obligatorias.';
    } elseif (empty($folio_stripe)) {
        $error = 'El número de folio Stripe es obligatorio.';
    } elseif ($monto_consumo <= 0) {
        $error = 'El monto a favor para consumo debe ser mayor a 0.';
    } else {
        // Generar token único para el QR
        $qr_token = hash('sha256', $id_evento . $nombre_cliente . $folio_stripe . time() . random_bytes(16));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO reservas_evento (id_evento, nombre_cliente, num_personas, fecha_hora, folio_stripe, monto_consumo, qr_token) 
                VALUES (:id_evento, :nombre_cliente, :num_personas, :fecha_hora, :folio_stripe, :monto_consumo, :qr_token)
            ");
            $stmt->execute([
                ':id_evento' => $id_evento,
                ':nombre_cliente' => $nombre_cliente,
                ':num_personas' => $num_personas,
                ':fecha_hora' => $fecha_hora,
                ':folio_stripe' => $folio_stripe,
                ':monto_consumo' => $monto_consumo,
                ':qr_token' => $qr_token
            ]);

            $qr_generado = true;
            $qr_token_generado = $qr_token;
            $nombre_cliente_guardado = $nombre_cliente;

        } catch (PDOException $e) {
            $error = 'Error al crear la reservación: ' . $e->getMessage();
        }
    }
}
?>

<div class="px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="ver_evento.php?id=<?php echo $id_evento; ?>" class="text-neutral-300 hover:text-white text-sm">
            ← Volver a <?php echo htmlspecialchars($evento['nombre']); ?>
        </a>
    </div>

    <?php if ($qr_generado): ?>
        <!-- QR Generado exitosamente -->
        <div class="max-w-2xl mx-auto">
            <div class="bg-neutral-700 shadow rounded-lg p-8 text-center">
                <div class="mb-4">
                    <svg class="mx-auto h-12 w-12 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">¡Reservación Creada!</h2>
                <p class="text-neutral-300 mb-6">Cliente: <strong><?php echo htmlspecialchars($nombre_cliente_guardado); ?></strong></p>
                
                <div class="bg-white p-4 rounded-lg inline-block mb-6">
                    <?php 
                    $qr_url = "https://" . $_SERVER["HTTP_HOST"] . "/api/escanear_qr.php?token=" . $qr_token_generado;
                    $qr_img_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_url);
                    ?>
                    <img src="<?php echo $qr_img_url; ?>" alt="QR Code" class="w-64 h-64">
                </div>

                <p class="text-neutral-400 text-xs mb-6">Descarga este QR y envíalo al cliente. Al escanearlo en puerta, se iniciará la reserva automáticamente.</p>

                <div class="flex flex-col sm:flex-row justify-center space-y-2 sm:space-y-0 sm:space-x-3">
                    <a href="<?php echo $qr_img_url; ?>" download="qr_<?php echo preg_replace('/\s+/', '_', $nombre_cliente_guardado); ?>.png" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Descargar QR
                    </a>
                    <a href="crear_reserva_evento.php?id_evento=<?php echo $id_evento; ?>" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-white bg-neutral-600 hover:bg-neutral-500">
                        Crear Otra Reservación
                    </a>
                    <a href="ver_evento.php?id=<?php echo $id_evento; ?>&msg=reserva_creada" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-white bg-neutral-600 hover:bg-neutral-500">
                        Ver Todas las Reservaciones
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Formulario de creación -->
        <div class="max-w-2xl mx-auto">
            <div class="bg-neutral-700 shadow rounded-lg p-6">
                <h2 class="text-xl font-semibold text-white mb-2">Crear Reservación para Evento</h2>
                <p class="text-neutral-300 text-sm mb-6">Evento: <strong><?php echo htmlspecialchars($evento['nombre']); ?></strong></p>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-md bg-red-900 p-4">
                        <p class="text-sm text-red-200"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="space-y-6">
                        <div>
                            <label for="nombre_cliente" class="block text-sm font-medium text-white">Nombre del Cliente</label>
                            <input type="text" name="nombre_cliente" id="nombre_cliente" required
                                   value="<?php echo htmlspecialchars($_POST['nombre_cliente'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white"
                                   placeholder="Nombre completo del cliente">
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label for="num_personas" class="block text-sm font-medium text-white">Número de Personas</label>
                                <input type="number" name="num_personas" id="num_personas" required min="1"
                                       value="<?php echo htmlspecialchars($_POST['num_personas'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white"
                                       placeholder="Ej: 4">
                            </div>
                            <div>
                                <label for="fecha_hora" class="block text-sm font-medium text-white">Fecha y Hora</label>
                                <input type="datetime-local" name="fecha_hora" id="fecha_hora" required
                                       value="<?php echo htmlspecialchars($_POST['fecha_hora'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label for="folio_stripe" class="block text-sm font-medium text-white">Número de Folio Stripe</label>
                                <input type="text" name="folio_stripe" id="folio_stripe" required
                                       value="<?php echo htmlspecialchars($_POST['folio_stripe'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white"
                                       placeholder="Ej: pi_3PxQz2...">
                            </div>
                            <div>
                                <label for="monto_consumo" class="block text-sm font-medium text-white">Monto a Favor para Consumo ($)</label>
                                <input type="number" name="monto_consumo" id="monto_consumo" required min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($_POST['monto_consumo'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white"
                                       placeholder="Ej: 4000">
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="ver_evento.php?id=<?php echo $id_evento; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-white bg-neutral-600 hover:bg-neutral-500">
                                Cancelar
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-neutral-500">
                                Crear Reservación y Generar QR
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
