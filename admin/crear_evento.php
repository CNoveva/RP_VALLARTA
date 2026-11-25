<?php
require_once '../includes/header.php';
check_role(['admin']);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';

    // Validaciones
    if (empty($nombre)) {
        $error = 'El nombre del evento es obligatorio.';
    } elseif (empty($fecha_inicio) || empty($fecha_fin)) {
        $error = 'Las fechas de inicio y fin son obligatorias.';
    } elseif (strtotime($fecha_fin) <= strtotime($fecha_inicio)) {
        $error = 'La fecha de fin debe ser posterior a la fecha de inicio.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO eventos (nombre, fecha_inicio, fecha_fin) VALUES (:nombre, :fecha_inicio, :fecha_fin)");
            $stmt->execute([
                ':nombre' => $nombre,
                ':fecha_inicio' => $fecha_inicio,
                ':fecha_fin' => $fecha_fin
            ]);
            $nuevo_id = $pdo->lastInsertId();
            header("Location: ver_evento.php?id=$nuevo_id&msg=evento_creado");
            exit;
        } catch (PDOException $e) {
            $error = 'Error al crear el evento: ' . $e->getMessage();
        }
    }
}
?>

<div class="px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="eventos.php" class="text-neutral-300 hover:text-white text-sm">
            ← Volver a Eventos
        </a>
    </div>

    <div class="max-w-2xl mx-auto">
        <div class="bg-neutral-700 shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold text-white mb-6">Crear Nuevo Evento</h2>

            <?php if ($error): ?>
                <div class="mb-4 rounded-md bg-red-900 p-4">
                    <p class="text-sm text-red-200"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="space-y-6">
                    <div>
                        <label for="nombre" class="block text-sm font-medium text-white">Nombre del Evento</label>
                        <input type="text" name="nombre" id="nombre" required
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white"
                               placeholder="Ej: Noche de Año Nuevo 2027">
                    </div>

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label for="fecha_inicio" class="block text-sm font-medium text-white">Fecha y Hora de Inicio</label>
                            <input type="datetime-local" name="fecha_inicio" id="fecha_inicio" required
                                   value="<?php echo htmlspecialchars($_POST['fecha_inicio'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white">
                        </div>
                        <div>
                            <label for="fecha_fin" class="block text-sm font-medium text-white">Fecha y Hora de Fin</label>
                            <input type="datetime-local" name="fecha_fin" id="fecha_fin" required
                                   value="<?php echo htmlspecialchars($_POST['fecha_fin'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-neutral-500 focus:ring-neutral-500 text-sm p-2 bg-neutral-600 text-white">
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <a href="eventos.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-white bg-neutral-600 hover:bg-neutral-500">
                            Cancelar
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-neutral-500">
                            Crear Evento
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
