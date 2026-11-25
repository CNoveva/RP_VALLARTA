<?php
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
check_role(['gerente', 'admin']);

$error = '';
$success = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Cambio de tipo de RP (solo usuarios con rol RP)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_usuario = intval($_POST['id_usuario'] ?? 0);
    $tipo_rp = trim($_POST['tipo_rp'] ?? '');
    $nombre = trim($_POST['nombre'] ?? ''); // solo se envía para RPs sin usuario

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } elseif (!in_array($tipo_rp, $TIPOS_RP, true)) {
        $error = 'Selecciona un tipo de RP válido.';
    } elseif ($nombre !== '') {
        // Nombre y tipo (cualquier RP, con o sin usuario)
        $stmt = $pdo->prepare("UPDATE usuarios SET tipo_rp = :tipo, nombre_completo = :nombre WHERE id = :id AND rol = 'rp'");
        $stmt->execute(['tipo' => $tipo_rp, 'nombre' => $nombre, 'id' => $id_usuario]);
        $success = 'RP actualizado.';
    } else {
        $stmt = $pdo->prepare("UPDATE usuarios SET tipo_rp = :tipo WHERE id = :id AND rol = 'rp'");
        $stmt->execute(['tipo' => $tipo_rp, 'id' => $id_usuario]);
        $success = 'Tipo de RP actualizado.';
    }
}

$busqueda = trim($_GET['q'] ?? '');
$filtro_tipo = $_GET['tipo'] ?? 'todos';

$where = "WHERE u.rol = 'rp'";
$params = [];
if ($busqueda !== '') {
    $like = '%' . addcslashes($busqueda, '%_\\') . '%';
    $where .= " AND (u.nombre_completo LIKE :q_nombre OR u.usuario LIKE :q_usuario)";
    $params['q_nombre'] = $like;
    $params['q_usuario'] = $like;
}
if ($filtro_tipo === 'pendientes') {
    // Tipos anteriores (ej. "Personal Operativo") o sin tipo
    $lista = [];
    foreach ($TIPOS_RP as $i => $tipo) {
        $lista[] = ":t$i";
        $params["t$i"] = $tipo;
    }
    $where .= " AND (u.tipo_rp IS NULL OR u.tipo_rp NOT IN (" . implode(',', $lista) . "))";
} elseif ($filtro_tipo !== 'todos') {
    $where .= " AND u.tipo_rp = :tipo";
    $params['tipo'] = $filtro_tipo;
}

$stmt = $pdo->prepare("SELECT u.id, u.nombre_completo, u.usuario, u.tipo_rp, u.activo, u.sin_acceso,
               (SELECT COUNT(*) FROM reservas r WHERE r.id_rp = u.id) AS reservas
        FROM usuarios u
        $where
        ORDER BY u.activo DESC, u.nombre_completo ASC");
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendientes = 0;
foreach ($usuarios as $u) {
    if (empty($u['tipo_rp']) || !in_array($u['tipo_rp'], $TIPOS_RP)) $pendientes++;
}
$url_actual = 'usuarios.php?' . http_build_query(['q' => $busqueda, 'tipo' => $filtro_tipo]);
?>

<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">RPs</h1>
        <p class="mt-1 text-sm text-neutral-500">Corrige el nombre o el tipo de cada RP (aplica de inmediato en reportes, dashboard y tickets), enlaza un teléfono a los RPs sin usuario o elimínalos. Los RPs sin usuario se crean desde Nueva Reserva.</p>
    </div>

    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?></div><?php endif; ?>

    <form method="GET" action="usuarios.php" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row items-end gap-3 mb-6">
        <div class="w-full sm:flex-1">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Buscar</label>
            <input type="search" name="q" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Nombre o teléfono" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
        </div>
        <div class="w-full sm:w-auto">
            <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Tipo</label>
            <select name="tipo" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                <option value="todos">Todos</option>
                <option value="pendientes" <?php echo $filtro_tipo == 'pendientes' ? 'selected' : ''; ?>>Pendientes de reclasificar</option>
                <?php foreach ($TIPOS_RP as $tipo): ?>
                    <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $filtro_tipo == $tipo ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="btn-amber flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-semibold text-white">Filtrar</button>
            <a href="usuarios.php" class="btn-secondary flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">Limpiar</a>
        </div>
    </form>

    <?php if ($pendientes > 0 && $filtro_tipo !== 'pendientes'): ?>
        <p class="mb-3 text-xs text-amber-400"><?php echo $pendientes; ?> RP(s) con tipo anterior o sin tipo. <a href="usuarios.php?tipo=pendientes" class="underline">Ver solo pendientes</a></p>
    <?php endif; ?>

    <!-- Un formulario por fila, fuera de la tabla; los controles lo referencian con form="..." -->
    <?php foreach ($usuarios as $u): ?>
        <form id="form-<?php echo $u['id']; ?>" method="POST" action="<?php echo htmlspecialchars($url_actual); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="id_usuario" value="<?php echo $u['id']; ?>">
        </form>
    <?php endforeach; ?>

    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Nombre</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Teléfono</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Reservas</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Estado</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Tipo de RP</th>
                        <th class="py-3.5 pl-3 pr-5"><span class="sr-only">Guardar</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($usuarios)): ?>
                        <tr><td colspan="6" class="px-3 py-10 text-sm text-neutral-500 text-center">No se encontraron RPs.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($usuarios as $u): ?>
                        <?php $es_anterior = !empty($u['tipo_rp']) && !in_array($u['tipo_rp'], $TIPOS_RP); ?>
                        <?php $sin_usuario = !empty($u['sin_acceso']); ?>
                        <tr class="<?php echo ($u['activo'] || $sin_usuario) ? '' : 'opacity-50'; ?>">
                            <td class="whitespace-nowrap py-3 pl-5 pr-3 text-sm font-medium text-white">
                                <input type="text" name="nombre" form="form-<?php echo $u['id']; ?>" value="<?php echo htmlspecialchars($u['nombre_completo']); ?>" required class="input-premium rounded-lg py-1.5 px-2 text-sm text-white w-56" oninput="document.getElementById('guardar-<?php echo $u['id']; ?>').classList.remove('hidden')">
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm text-neutral-300 font-mono"><?php echo $sin_usuario ? '<span class="text-neutral-600">—</span>' : htmlspecialchars($u['usuario']); ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm text-neutral-300 hidden sm:table-cell"><?php echo $u['reservas']; ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm">
                                <?php if ($sin_usuario): ?>
                                    <span class="inline-flex rounded-lg px-2 py-0.5 text-xs font-semibold bg-blue-500/15 text-blue-400" title="Registrado por el gerente; no entra al sistema">Sin usuario</span>
                                <?php else: ?>
                                    <span class="inline-flex rounded-lg px-2 py-0.5 text-xs font-semibold <?php echo $u['activo'] ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'; ?>"><?php echo $u['activo'] ? 'Activo' : 'Inactivo'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm">
                                <select name="tipo_rp" form="form-<?php echo $u['id']; ?>" class="input-premium rounded-lg py-1.5 px-2 text-sm <?php echo ($es_anterior || empty($u['tipo_rp'])) ? 'text-amber-400 border-amber-500/40' : 'text-white'; ?>" onchange="document.getElementById('guardar-<?php echo $u['id']; ?>').classList.remove('hidden')">
                                    <?php if (empty($u['tipo_rp'])): ?>
                                        <option value="" selected>Sin tipo</option>
                                    <?php elseif ($es_anterior): ?>
                                        <option value="<?php echo htmlspecialchars($u['tipo_rp']); ?>" selected><?php echo htmlspecialchars($u['tipo_rp']); ?> (anterior)</option>
                                    <?php endif; ?>
                                    <?php foreach ($TIPOS_RP as $tipo): ?>
                                        <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $u['tipo_rp'] == $tipo ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="whitespace-nowrap py-3 pl-3 pr-5 text-right text-sm">
                                <div class="flex flex-col items-end space-y-1.5">
                                    <button type="submit" form="form-<?php echo $u['id']; ?>" id="guardar-<?php echo $u['id']; ?>" class="hidden text-amber-500 hover:text-amber-400 font-semibold">Guardar</button>
                                    <?php if ($sin_usuario): ?>
                                        <a href="dar_acceso.php?id=<?php echo $u['id']; ?>" class="text-blue-400 hover:text-blue-300 text-xs transition-colors duration-200" title="Enlazar un teléfono para que pueda entrar, o fusionar con su usuario registrado">Enlazar teléfono</a>
                                    <?php endif; ?>
                                    <a href="eliminar_rp.php?id=<?php echo $u['id']; ?>" class="text-red-500/60 hover:text-red-400 text-xs transition-colors duration-200">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
