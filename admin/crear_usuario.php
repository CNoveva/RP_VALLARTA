<?php
require_once '../includes/header.php';
require_once '../includes/tipos_rp.php';
check_role(['admin']);

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST['nombre']);
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    // El tipo de RP solo aplica al rol RP
    $tipo_rp = ($rol === 'rp') ? trim($_POST['tipo_rp'] ?? '') : '';
    $tipo_rp = ($tipo_rp !== '') ? $tipo_rp : null;

    if (empty($nombre) || empty($usuario) || empty($password) || empty($rol)) {
        $error = 'Todos los campos son obligatorios.';
    } elseif ($rol === 'rp' && $tipo_rp === null) {
        $error = 'Selecciona el tipo de RP.';
    } else {
        // Hashear contraseña
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $sql = "INSERT INTO usuarios (nombre_completo, usuario, password, rol, tipo_rp) VALUES (:nombre, :usuario, :pass, :rol, :tipo_rp)";
            $stmt = $pdo->prepare($sql);

            $stmt->bindParam(':nombre', $nombre, PDO::PARAM_STR);
            $stmt->bindParam(':usuario', $usuario, PDO::PARAM_STR);
            $stmt->bindParam(':pass', $hashed_password, PDO::PARAM_STR);
            $stmt->bindParam(':rol', $rol, PDO::PARAM_STR);
            $stmt->bindParam(':tipo_rp', $tipo_rp);
            
            $stmt->execute();
            $success = "Usuario '$usuario' creado exitosamente. <a href='index.php' class='font-bold underline'>Volver al listado</a>";
            
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = 'Error: El nombre de usuario ya existe.';
            } else {
                $error = 'Error al crear el usuario: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="bg-neutral-600 p-8 rounded-lg shadow-md max-w-md mx-auto">
    <h2 class="text-2xl font-bold text-white mb-6">Crear Nuevo Usuario</h2>

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

    <?php if (empty($success)): // Ocultar formulario si fue exitoso ?>
    <form action="crear_usuario.php" method="POST">
        <div class="mb-4">
            <label for="nombre" class="block text-sm font-medium text-white">Nombre Completo</label>
            <input type="text" name="nombre" id="nombre" class="mt-1 block w-full rounded-md border-gray-300 bg-neutral-500 text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
        </div>
        <div class="mb-4">
            <label for="usuario" class="block text-sm font-medium text-white">Nombre de Usuario</label>
            <input type="text" name="usuario" id="usuario" class="mt-1 block w-full rounded-md border-gray-300 bg-neutral-500 text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
        </div>
        <div class="mb-4">
            <label for="password" class="block text-sm font-medium text-white">Contraseña</label>
            <input type="password" name="password" id="password" class="mt-1 block w-full rounded-md border-gray-300 bg-neutral-500 text-white shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" required>
        </div>
        <div class="mb-6">
            <label for="rol" class="block text-sm font-medium text-white">Rol</label>
            <select id="rol" name="rol" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base bg-neutral-500 text-white focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm" required>
                <option value="">Seleccione un rol</option>
                <option value="rp">RP</option>
                <option value="hostes">Hostes</option>
                <option value="caja">Caja</option>
                <option value="gerente">Gerente</option>
                <option value="coordinador">Coordinador (registra reservas a nombre de RPs)</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="mb-6 hidden" id="campo_tipo_rp">
            <label for="tipo_rp" class="block text-sm font-medium text-white">Tipo de RP</label>
            <select id="tipo_rp" name="tipo_rp" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base bg-neutral-500 text-white focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                <option value="">Seleccione un tipo</option>
                <?php foreach ($TIPOS_RP as $tipo): ?>
                    <option value="<?php echo htmlspecialchars($tipo); ?>"><?php echo htmlspecialchars($tipo); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-neutral-800 hover:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Crear Usuario
            </button>
        </div>
    </form>

    <script>
    // El tipo de RP solo aplica cuando el rol es RP
    document.getElementById('rol').addEventListener('change', function () {
        const esRp = this.value === 'rp';
        document.getElementById('campo_tipo_rp').classList.toggle('hidden', !esRp);
        document.getElementById('tipo_rp').required = esRp;
    });
    </script>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>