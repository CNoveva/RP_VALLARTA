<?php
// Listas de RPs para los selectores del gerente.
// 'sin_usuario' = registrados por el gerente, no pueden entrar al sistema (sin_acceso = 1).
// 'con_usuario' = RPs con cuenta propia (activos e inactivos, para poder reasignar reservas viejas).
function lista_rps($pdo) {
    $stmt = $pdo->query("SELECT id, nombre_completo, tipo_rp, activo, sin_acceso FROM usuarios WHERE rol = 'rp' ORDER BY nombre_completo");
    $listas = ['sin_usuario' => [], 'con_usuario' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $listas[$u['sin_acceso'] ? 'sin_usuario' : 'con_usuario'][] = $u;
    }
    return $listas;
}

// Crea un RP sin usuario. Devuelve su id.
function crear_rp_sin_usuario($pdo, $nombre, $tipo_rp) {
    // "usuario" debe ser único pero nadie lo va a teclear; la contraseña es aleatoria y nunca se entrega
    $usuario = 'sin-usuario-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre_completo, usuario, password, rol, tipo_rp, activo, sin_acceso)
                           VALUES (:nombre, :usuario, :password, 'rp', :tipo_rp, 0, 1)");
    $stmt->execute([
        'nombre' => $nombre,
        'usuario' => $usuario,
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'tipo_rp' => $tipo_rp,
    ]);
    return intval($pdo->lastInsertId());
}

// Contraseña temporal sin 0/o ni 1/l/i, para poder dictarla sin confusiones
if (!function_exists('generar_password_temporal')) {
    function generar_password_temporal($largo = 8) {
        $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < $largo; $i++) {
            $password .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $password;
    }
}

// Opciones <optgroup> para un <select> de RP
function opciones_rps($listas, $seleccionado = null) {
    $html = '';
    foreach (['sin_usuario' => 'RPs sin usuario', 'con_usuario' => 'RPs con usuario'] as $clave => $titulo) {
        if (empty($listas[$clave])) continue;
        $html .= '<optgroup label="' . $titulo . '">';
        foreach ($listas[$clave] as $u) {
            $etiqueta = $u['nombre_completo'] . (!empty($u['tipo_rp']) ? ' (' . $u['tipo_rp'] . ')' : '') . (($clave == 'con_usuario' && !$u['activo']) ? ' · inactivo' : '');
            $html .= '<option value="' . $u['id'] . '"' . ($seleccionado == $u['id'] ? ' selected' : '') . '>' . htmlspecialchars($etiqueta) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}
