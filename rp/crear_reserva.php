<?php
require_once '../includes/header.php';
check_role(['rp']);

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_cliente = trim($_POST['nombre_cliente']);
    $num_personas = intval($_POST['num_personas']);
    $fecha_reserva_str = trim($_POST['fecha_reserva']);
    
    $id_rp = $_SESSION['id'];
    
    // Validar fecha
    $fecha_reserva = new DateTime($fecha_reserva_str);
    $ahora = new DateTime();
    
    if ($fecha_reserva < $ahora) {
        $error = "La fecha y hora de la reserva no pueden ser en el pasado.";
    } elseif ($num_personas <= 0) {
        $error = "El número de personas debe ser mayor a cero.";
    } else {
        try {
            $sql = "INSERT INTO reservas (id_rp, nombre_cliente, num_personas, fecha_reserva) 
                    VALUES (:id_rp, :nombre, :personas, :fecha)";
            $stmt = $pdo->prepare($sql);
            
            $stmt->execute([
                'id_rp' => $id_rp,
                'nombre' => $nombre_cliente,
                'personas' => $num_personas,
                'fecha' => $fecha_reserva->format('Y-m-d H:i:s')
            ]);
            
            $success = "Reserva creada exitosamente para " . htmlspecialchars($nombre_cliente) . ". <a href='index.php' class='font-bold underline text-emerald-300 hover:text-emerald-200'>Volver al listado</a>";
            
        } catch (PDOException $e) {
            $error = 'Error al crear la reserva: ' . $e->getMessage();
        }
    }
}
?>

<div class="max-w-lg mx-auto">
    <div class="glass-card rounded-2xl p-6 sm:p-8">
        
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-amber-700/20 flex items-center justify-center mr-3">
                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                </div>
                <h2 class="text-xl font-bold text-white">Crear Reserva</h2>
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

        <?php if (empty($success)): ?>
        <form action="crear_reserva.php" method="POST" id="reserva-form" autocomplete="off" class="space-y-4">
            <div>
                <label for="nombre_cliente" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Nombre del Cliente</label>
                <input type="text" name="nombre_cliente" id="nombre_cliente" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>

            <div>
                <label for="num_personas" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Número de Personas</label>
                <input type="number" name="num_personas" id="num_personas" min="1" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div>
                <label for="fecha_reserva" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Fecha y Hora</label>
                <input type="datetime-local" name="fecha_reserva" id="fecha_reserva" class="input-premium rounded-xl w-full py-2.5 px-4 text-sm text-white" required>
            </div>
            <div class="pt-2">
                <button type="submit" class="btn-amber w-full py-3 px-4 rounded-xl text-sm font-semibold text-white">
                    Crear Reserva
                </button>
            </div>
        </form>
        
        <script>
            // JS para validación de fecha mínima en el cliente
            document.addEventListener('DOMContentLoaded', function() {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                const minDateTime = now.toISOString().slice(0,16);
                
                const fechaInput = document.getElementById('fecha_reserva');
                fechaInput.setAttribute('min', minDateTime);
            });
        </script>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>