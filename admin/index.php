<?php
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
check_role(['admin']);

// Lógica para obtener todos los usuarios
$stmt = $pdo->query("SELECT id, nombre_completo, usuario, rol, tipo_rp, activo, sin_acceso FROM usuarios ORDER BY nombre_completo");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="px-4 sm:px-6 lg:px-8">
    <div class="sm:flex sm:items-center">
        <div class="sm:flex-auto">
            <h1 class="text-xl font-semibold text-white">Gestión de Usuarios</h1>
            <p class="mt-2 text-sm text-white">Crear, editar o desactivar usuarios del sistema.</p>
        </div>
        <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
            <a href="eventos.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-neutral-800 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:w-auto mr-2">
                Crear Evento
            </a>
            <a href="crear_usuario.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-neutral-800 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 sm:w-auto">
                Crear Usuario
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
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-white sm:pl-6">Nombre Completo</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Usuario</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Rol</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Tipo RP</th>
                                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Estado</th>
                                <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                    <span class="sr-only">Editar</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-neutral-500">
                            <?php foreach ($usuarios as $usuario): ?>
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-white sm:pl-6"><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white"><?php echo htmlspecialchars($usuario['usuario']); ?></td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white"><?php echo htmlspecialchars(ucfirst($usuario['rol'])); ?></td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php if (empty($usuario['tipo_rp'])): ?>
                                            -
                                        <?php elseif (!in_array($usuario['tipo_rp'], $TIPOS_RP)): // tipo anterior, ej. "Personal Operativo" ?>
                                            <span class="inline-flex rounded-full bg-yellow-100 px-2 text-xs font-semibold leading-5 text-yellow-800" title="Tipo anterior: edita el usuario para reclasificarlo"><?php echo htmlspecialchars($usuario['tipo_rp']); ?></span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($usuario['tipo_rp']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-white">
                                        <?php if (!empty($usuario['sin_acceso'])): ?>
                                            <span class="inline-flex rounded-full bg-blue-100 px-2 text-xs font-semibold leading-5 text-blue-800" title="RP registrado por el gerente; no entra al sistema">Sin usuario</span>
                                        <?php elseif ($usuario['activo']): ?>
                                            <span class="inline-flex rounded-full bg-green-100 px-2 text-xs font-semibold leading-5 text-green-800">Activo</span>
                                        <?php else: ?>
                                            <span class="inline-flex rounded-full bg-red-100 px-2 text-xs font-semibold leading-5 text-red-800">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                        <a href="editar_usuario.php?id=<?php echo $usuario['id']; ?>" class="text-neutral-800 hover:text-neutral-900">Editar</a>
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