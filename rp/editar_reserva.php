<?php
require_once '../includes/header.php';
check_role(['rp']); // Solo RP puede editar

$error = '';
$success = '';
$id_reserva = isset($_GET['id']) ? intval($_GET['id']) : 0;
$id_rp = $_SESSION['id'];

if ($id_reserva <= 0) {
    header('Location: index.php');
    exit;
}

// Lógica de actualización
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_cliente = trim($_POST['nombre_cliente']);
    $num_personas = intval($_POST['num_personas']);

    $fecha_reserva = $_POST['fecha_reserva'];

    try {
        // RP solo puede editar nombre, personas y fecha, y SOLO si la reserva es 'nueva' y es SUYA
        $sql = "UPDATE reservas 
                SET nombre_cliente = :nombre, num_personas = :personas, fecha_reserva = :fecha
                WHERE id = :id AND id_rp = :id_rp AND estado = 'nueva'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nombre' => $nombre_cliente,
            'personas' => $num_personas,
            'fecha' => $fecha_reserva,
            'id' => $id_reserva,
            'id_rp' => $id_rp
        ]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Reserva actualizada. <a href='index.php' class='font-bold underline text-emerald-300 hover:text-emerald-200'>Volver al listado</a>";
        } else {
            $error = "No se pudo actualizar la reserva. (Quizás ya no está en estado 'nueva' o no te pertenece).";
        }
        
    } catch (PDOException $e) {
        $error = 'Error al actualizar: ' . $e->getMessage();
    }
}

// Cargar datos de la reserva
$stmt = $pdo->prepare("SELECT * FROM reservas WHERE id = :id AND id_rp = :id_rp AND estado = 'nueva'");
$stmt->execute(['id' => $id_reserva, 'id_rp' => $id_rp]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    $error = "Reserva no encontrada, no te pertenece o ya no se puede editar.";
}
?>

<div class="max-w-lg mx-auto">
    <div class="glass-card rounded-2xl p-6 sm:p-8">
        
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-blue-700/20 flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                </div>
                <h2 class="text-xl font-bold text-white">Editar Reserva</h2>
            </div>
            <a href="index.php" class="inline-flex items-center text-sm font-medium text-neutral-500 hover:text-white transition-colors duration-200">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Volver
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">
                <div class="flex items-start">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo $error; ?></span>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm">
                <div class="flex items-start">
                    <svg class="w-5 h-5 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo $success; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($reserva && empty($success)): ?>
        <form action="editar_reserva.php?id=<?php echo $id_reserva; ?>" method="POST" class="space-y-4">
            <div>
                <label for="nombre_cliente" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del Cliente</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente" value="<?php echo htmlspecialchars($reserva['nombre_cliente']); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="num_personas" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Número de Personas</label>
                <input type="number" name="num_personas" id="num_personas" value="<?php echo $reserva['num_personas']; ?>" min="1" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="fecha_reserva" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha y Hora de Reserva</label>
                <input type="datetime-local" name="fecha_reserva" id="fecha_reserva" value="<?php echo date('Y-m-d\TH:i', strtotime($reserva['fecha_reserva'])); ?>" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div class="pt-2">
                <button type="submit" class="btn-amber w-full py-3 px-4 rounded-xl text-sm font-semibold text-white">
                    Actualizar Reserva
                </button>
            </div>
        </form>
        <?php elseif (empty($success)): ?>
            <div class="text-center py-6">
                <p class="text-neutral-500 mb-4">No se puede cargar la reserva para edición.</p>
                <a href="index.php" class="text-amber-500 hover:text-amber-400 font-semibold transition-colors duration-200">Volver al listado</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>