<?php
require_once '../includes/header.php';
check_role(['admin']);

$id_evento = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_evento === 0) {
    header('Location: eventos.php');
    exit;
}

// Obtener evento
$stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = :id");
$stmt->execute([':id' => $id_evento]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
    header('Location: eventos.php');
    exit;
}

// Obtener reservaciones del evento
$stmt = $pdo->prepare("SELECT * FROM reservas_evento WHERE id_evento = :id_evento ORDER BY fecha_creacion DESC");
$stmt->execute([':id_evento' => $id_evento]);
$reservaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$msg = $_GET['msg'] ?? '';
?>

<div class="px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="eventos.php" class="text-neutral-300 hover:text-white text-sm">
            ← Volver a Eventos
        </a>
    </div>

    <?php if ($msg === 'evento_creado'): ?>
        <div class="mb-4 rounded-md bg-green-900 p-4">
            <p class="text-sm text-green-200">✅ Evento creado exitosamente. Ahora puedes agregar reservaciones.</p>
        </div>
    <?php elseif ($msg === 'reserva_creada'): ?>
        <div class="mb-4 rounded-md bg-green-900 p-4">
            <p class="text-sm text-green-200">✅ Reservación creada exitosamente. El QR está disponible para descargar.</p>
        </div>
    <?php endif; ?>

    <!-- Detalle del Evento -->
    <div class="bg-neutral-700 shadow rounded-lg p-6 mb-8">
        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($evento['nombre']); ?></h2>
                <div class="mt-2 flex flex-col sm:flex-row sm:space-x-6">
                    <p class="text-sm text-neutral-300">
                        <span class="font-medium">Inicio:</span> <?php echo date('d/m/Y h:i A', strtotime($evento['fecha_inicio'])); ?>
                    </p>
                    <p class="text-sm text-neutral-300">
                        <span class="font-medium">Fin:</span> <?php echo date('d/m/Y h:i A', strtotime($evento['fecha_fin'])); ?>
                    </p>
                    <p class="text-sm text-neutral-300">
                        <span class="font-medium">Reservaciones:</span> <?php echo count($reservaciones); ?>
                    </p>
                </div>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="crear_reserva_evento.php?id_evento=<?php echo $id_evento; ?>" class="inline-flex items-center justify-center rounded-md border border-transparent bg-neutral-800 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Crear Reservación
                </a>
            </div>
        </div>
    </div>

    <!-- Tabla de Reservaciones -->
    <div class="mt-4 flex flex-col">
        <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-300">
                        <thead class="bg-neutral-600">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-white sm:pl-6">Cliente</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Personas</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Fecha/Hora</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Folio Stripe</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Monto Consumo</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Estado</th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Acciones</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-neutral-500">
                            <?php if (empty($reservaciones)): ?>
                                <tr>
                                    <td colspan="7" class="whitespace-nowrap px-3 py-4 text-sm text-white text-center">
                                        No hay reservaciones aún. Haz clic en "Crear Reservación" para agregar una.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($reservaciones as $reserva): ?>
                                <?php
                                    $color_span = 'bg-blue-100 text-blue-800';
                                    if ($reserva['estado'] == 'iniciada') $color_span = 'bg-yellow-200 text-yellow-800';
                                    if ($reserva['estado'] == 'finalizada') $color_span = 'bg-green-100 text-green-800';
                                    if ($reserva['estado'] == 'cancelada') $color_span = 'bg-red-100 text-red-800';
                                ?>
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-white sm:pl-6">
                                        <?php echo htmlspecialchars($reserva['nombre_cliente']); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php echo $reserva['num_personas']; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php echo date('d/m/Y h:i A', strtotime($reserva['fecha_hora'])); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white font-mono">
                                        <?php echo htmlspecialchars($reserva['folio_stripe']); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white font-medium">
                                        $<?php echo number_format($reserva['monto_consumo'], 2); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <span class="inline-flex rounded-full px-2 text-xs font-semibold leading-5 <?php echo $color_span; ?>">
                                            <?php echo htmlspecialchars(ucfirst($reserva['estado'])); ?>
                                        </span>
                                    </td>
                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                        <button onclick="mostrarQR('<?php echo htmlspecialchars($reserva['qr_token']); ?>', '<?php echo htmlspecialchars($reserva['nombre_cliente']); ?>')" class="text-neutral-200 hover:text-white underline mr-3">
                                            Ver QR
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal QR -->
<div id="qr-modal" class="fixed inset-0 bg-black bg-opacity-75 hidden z-50 flex items-center justify-center">
    <div class="bg-neutral-700 rounded-lg p-8 max-w-sm w-full mx-4 text-center">
        <h3 class="text-lg font-bold text-white mb-2" id="qr-modal-title">QR de Reservación</h3>
        <p class="text-neutral-300 text-sm mb-4" id="qr-modal-cliente"></p>
        <div class="bg-white p-4 rounded-lg inline-block mb-4">
            <img id="qr-modal-img" src="" alt="QR Code" class="w-48 h-48">
        </div>
        <div class="flex flex-col space-y-2">
            <a id="qr-modal-download" href="" download="qr_reserva.png" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Descargar QR
            </a>
            <button onclick="cerrarQR()" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-white bg-neutral-600 hover:bg-neutral-500">
                Cerrar
            </button>
        </div>
    </div>
</div>

<script>
function mostrarQR(token, nombreCliente) {
    const url = `https://<?php echo $_SERVER["HTTP_HOST"]; ?>/api/escanear_qr.php?token=${token}`;
    const qrImgUrl = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(url)}`;
    
    document.getElementById('qr-modal-img').src = qrImgUrl;
    document.getElementById('qr-modal-cliente').textContent = 'Cliente: ' + nombreCliente;
    document.getElementById('qr-modal-download').href = qrImgUrl;
    document.getElementById('qr-modal-download').download = `qr_${nombreCliente.replace(/\s+/g, '_')}.png`;
    document.getElementById('qr-modal').classList.remove('hidden');
}

function cerrarQR() {
    document.getElementById('qr-modal').classList.add('hidden');
}

// Cerrar modal con click fuera
document.getElementById('qr-modal').addEventListener('click', function(e) {
    if (e.target === this) cerrarQR();
});
</script>

<?php require_once '../includes/footer.php'; ?>
