<?php
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
check_role(['admin']);

$error = '';
$success = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    header('Location: index.php');
    exit;
}

// Lógica de actualización
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST['nombre']);
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    $activo = isset($_POST['activo']) ? 1 : 0;
    // El tipo de RP solo aplica al rol RP
    $tipo_rp = ($rol === 'rp') ? trim($_POST['tipo_rp'] ?? '') : '';
    $tipo_rp = ($tipo_rp !== '') ? $tipo_rp : null;

    try {
        if (!empty($password)) {
            // Si se provee una nueva contraseña, hashearla.
            // Un RP "sin usuario" (registrado por el gerente) obtiene acceso al recibir contraseña y quedar activo.
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $dar_acceso = $activo ? ", sin_acceso = 0" : "";
            $sql = "UPDATE usuarios SET nombre_completo = :nombre, usuario = :usuario, password = :pass, rol = :rol, tipo_rp = :tipo_rp, activo = :activo$dar_acceso WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':pass', $hashed_password);
        } else {
            // Si no, actualizar todo menos la contraseña
            $sql = "UPDATE usuarios SET nombre_completo = :nombre, usuario = :usuario, rol = :rol, tipo_rp = :tipo_rp, activo = :activo WHERE id = :id";
            $stmt = $pdo->prepare($sql);
        }

        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':usuario', $usuario);
        $stmt->bindParam(':rol', $rol);
        $stmt->bindParam(':tipo_rp', $tipo_rp);
        $stmt->bindParam(':activo', $activo, PDO::PARAM_INT);
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        
        $stmt->execute();
        $success = "Usuario actualizado exitosamente. <a href='index.php' class='font-bold underline'>Volver al listado</a>";
        
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            $error = 'Error: El nombre de usuario ya existe.';
        } else {
            $error = 'Error al actualizar el usuario: ' . $e->getMessage();
        }
    }
}

// Cargar datos del usuario
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    header('Location: index.php');
    exit;
}
?>

<div class="bg-neutral-600 p-8 rounded-lg shadow-md max-w-md mx-auto">
    <h2 class="text-2xl text-white font-bold mb-6">Editar Usuario</h2>

    <?php if (!empty($usuario['sin_acceso'])): ?>
        <div class="bg-blue-100 border border-blue-400 text-blue-800 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
            <strong>RP sin usuario</strong> (registrado por el gerente; no entra al sistema). Para darle acceso: escribe su teléfono en "Nombre de Usuario", ponle una contraseña y marca "Usuario Activo". Conservará todas sus reservas.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $success; ?></span>
        </div>
    <?php endif; ?>

    <form action="editar_usuario.php?id=<?php echo $user_id; ?>" method="POST">
        <div class="mb-4">
            <label for="nombre" class="block text-sm font-medium text-white">Nombre Completo</label>
            <input type="text" name="nombre" id="nombre" value="<?php echo htmlspecialchars($usuario['nombre_completo']); ?>" class="mt-1 block w-full bg-neutral-500 text-white rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
        </div>
        <div class="mb-4">
            <label for="usuario" class="block text-sm font-medium text-white">Nombre de Usuario</label>
            <input type="text" name="usuario" id="usuario" value="<?php echo htmlspecialchars($usuario['usuario']); ?>" class="mt-1 block w-full bg-neutral-500 text-white rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
        </div>
        <div class="mb-4">
            <label for="password" class="block text-sm font-medium text-white">Nueva Contraseña</label>
            <input type="password" name="password" id="password" class="mt-1 block w-full bg-neutral-500 text-white rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="Dejar en blanco para no cambiar">
        </div>
        <div class="mb-4">
            <label for="rol" class="block text-sm font-medium text-white">Rol</label>
            <select id="rol" name="rol" class="mt-1 block w-full rounded-md border-gray-300 bg-neutral-500 text-white py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm" required>
                <option value="rp" <?php echo $usuario['rol'] == 'rp' ? 'selected' : ''; ?>>RP</option>
                <option value="hostes" <?php echo $usuario['rol'] == 'hostes' ? 'selected' : ''; ?>>Hostes</option>
                <option value="caja" <?php echo $usuario['rol'] == 'caja' ? 'selected' : ''; ?>>Caja</option>
                <option value="gerente" <?php echo $usuario['rol'] == 'gerente' ? 'selected' : ''; ?>>Gerente</option>
                <option value="coordinador" <?php echo $usuario['rol'] == 'coordinador' ? 'selected' : ''; ?>>Coordinador</option>
                <option value="admin" <?php echo $usuario['rol'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
            </select>
        </div>
        <div class="mb-4" id="campo_tipo_rp">
            <label for="tipo_rp" class="block text-sm font-medium text-white">Tipo de RP</label>
            <select id="tipo_rp" name="tipo_rp" class="mt-1 block w-full rounded-md border-gray-300 bg-neutral-500 text-white py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                <option value="">Sin tipo</option>
                <?php if (!empty($usuario['tipo_rp']) && !in_array($usuario['tipo_rp'], $TIPOS_RP)): // tipo anterior, ej. "Personal Operativo" ?>
                    <option value="<?php echo htmlspecialchars($usuario['tipo_rp']); ?>" selected><?php echo htmlspecialchars($usuario['tipo_rp']); ?> (anterior, reclasificar)</option>
                <?php endif; ?>
                <?php foreach ($TIPOS_RP as $tipo): ?>
                    <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $usuario['tipo_rp'] == $tipo ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="relative flex items-start mb-6">
            <div class="flex h-5 items-center">
                <input id="activo" name="activo" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-white focus:ring-indigo-500" <?php echo $usuario['activo'] ? 'checked' : ''; ?>>
            </div>
            <div class="ml-3 text-sm">
                <label for="activo" class="font-medium text-white">Usuario Activo</label>
            </div>
        </div>
        <div>
            <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Actualizar Usuario
            </button>
        </div>
    </form>
</div>

<script>
// El tipo de RP solo aplica cuando el rol es RP
function mostrarTipoRp() {
    document.getElementById('campo_tipo_rp').classList.toggle('hidden', document.getElementById('rol').value !== 'rp');
}
document.getElementById('rol').addEventListener('change', mostrarTipoRp);
mostrarTipoRp();
</script>

<?php require_once '../includes/footer.php'; ?>