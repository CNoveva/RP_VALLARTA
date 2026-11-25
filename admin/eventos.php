<?php
require_once '../includes/header.php';
check_role(['admin']);

// Obtener todos los eventos con conteo de reservaciones
$stmt = $pdo->query("
    SELECT e.*, 
           (SELECT COUNT(*) FROM reservas_evento re WHERE re.id_evento = e.id) AS total_reservas,
           (SELECT COUNT(*) FROM reservas_evento re WHERE re.id_evento = e.id AND re.estado = 'iniciada') AS reservas_iniciadas
    FROM eventos e 
    ORDER BY e.fecha_inicio DESC
");
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="px-4 sm:px-6 lg:px-8">
    <div class="sm:flex sm:items-center">
        <div class="sm:flex-auto">
            <h1 class="text-xl font-semibold text-white">Gestión de Eventos</h1>
            <p class="mt-2 text-sm text-white">Crea y administra eventos especiales con reservaciones QR.</p>
        </div>
        <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
            <a href="crear_evento.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-neutral-800 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:w-auto">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Crear Evento
            </a>
        </div>
    </div>
    <div class="mt-8 flex flex-col">
        <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-300">
                        <thead class="bg-neutral-600">
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-white sm:pl-6">Nombre del Evento</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Fecha Inicio</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Fecha Fin</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Reservaciones</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Estado</th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Acciones</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-neutral-500">
                            <?php if (empty($eventos)): ?>
                                <tr>
                                    <td colspan="6" class="whitespace-nowrap px-3 py-4 text-sm text-white text-center">
                                        No hay eventos creados aún.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($eventos as $evento): ?>
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-white sm:pl-6">
                                        <?php echo htmlspecialchars($evento['nombre']); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php echo date('d/m/Y h:i A', strtotime($evento['fecha_inicio'])); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php echo date('d/m/Y h:i A', strtotime($evento['fecha_fin'])); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                            <?php echo $evento['total_reservas']; ?> total
                                        </span>
                                        <?php if ($evento['reservas_iniciadas'] > 0): ?>
                                        <span class="ml-1 inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
                                            <?php echo $evento['reservas_iniciadas']; ?> iniciadas
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php if ($evento['activo']): ?>
                                            <span class="inline-flex rounded-full bg-green-100 px-2 text-xs font-semibold leading-5 text-green-800">Activo</span>
                                        <?php else: ?>
                                            <span class="inline-flex rounded-full bg-red-100 px-2 text-xs font-semibold leading-5 text-red-800">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                        <a href="ver_evento.php?id=<?php echo $evento['id']; ?>" class="text-neutral-200 hover:text-white underline">
                                            Ver Reservaciones
                                        </a>
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

<?php require_once '../includes/footer.php'; ?>
