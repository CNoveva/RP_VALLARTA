<?php
// Visitas sin reserva (hostess): solo cuentan para el aforo.
// No tienen cierre en caja, comisiones ni propinas.
require_once '../includes/header.php';
require_once '../includes/turno.php';
require_once '../includes/conceptos_visita.php';
check_role(['hostes']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$falta_migracion = false;

// Turno a consultar (por defecto el turno en curso)
$fecha = $_GET['fecha'] ?? turno_hoy();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = turno_hoy();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $accion = $_POST['accion'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'El formulario expiró. Intenta de nuevo.';
    } else {
        try {
            if ($accion === 'registrar') {
                $num_personas = intval($_POST['num_personas'] ?? 0);
                $concepto = trim($_POST['concepto'] ?? '');
                if ($num_personas < 1 || $num_personas > 500) {
                    $error = 'Indica un número de personas válido (1 a 500).';
                } elseif (!in_array($concepto, $CONCEPTOS_VISITA, true)) {
                    $error = 'Selecciona un concepto de visita.';
                } else {
                    $pdo->prepare("INSERT INTO visitas (id_hostes, num_personas, concepto, fecha_hora) VALUES (:hostes, :n, :concepto, NOW())")
                        ->execute(['hostes' => $_SESSION['id'], 'n' => $num_personas, 'concepto' => $concepto]);
                    $success = "Visita registrada: $num_personas persona(s) · " . htmlspecialchars($concepto) . '.';
                    $fecha = turno_hoy();
                }
            } elseif ($accion === 'eliminar') {
                // Solo se pueden borrar visitas del turno en curso (para corregir capturas erróneas)
                $id_visita = intval($_POST['id_visita'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM visitas WHERE id = :id AND fecha_hora >= :ini AND fecha_hora < :fin");
                $stmt->execute(['id' => $id_visita, 'ini' => turno_inicio(turno_hoy()), 'fin' => turno_fin(turno_hoy())]);
                $success = $stmt->rowCount() > 0 ? 'Visita eliminada.' : 'Solo se pueden eliminar visitas del turno en curso.';
            }
        } catch (PDOException $e) {
            $falta_migracion = true;
        }
    }
}

// Visitas del turno seleccionado
$visitas = [];
$totales = ['visitas' => 0, 'personas' => 0];
$por_concepto = [];
try {
    $stmt = $pdo->prepare("SELECT v.*, u.nombre_completo AS nombre_hostes
                           FROM visitas v LEFT JOIN usuarios u ON v.id_hostes = u.id
                           WHERE v.fecha_hora >= :ini AND v.fecha_hora < :fin
                           ORDER BY v.fecha_hora DESC");
    $stmt->execute(['ini' => turno_inicio($fecha), 'fin' => turno_fin($fecha)]);
    $visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($visitas as $v) {
        $totales['visitas']++;
        $totales['personas'] += intval($v['num_personas']);
        $por_concepto[$v['concepto']] = ($por_concepto[$v['concepto']] ?? 0) + intval($v['num_personas']);
    }

    // Personas que llegaron con reserva en el mismo turno, para ver el aforo completo
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(num_personas), 0) FROM reservas
                           WHERE estado IN ('iniciada', 'finalizada') AND fecha_reserva >= :ini AND fecha_reserva < :fin");
    $stmt->execute(['ini' => turno_inicio($fecha), 'fin' => turno_fin($fecha)]);
    $personas_reserva = intval($stmt->fetchColumn());
} catch (PDOException $e) {
    $falta_migracion = true;
    $personas_reserva = 0;
}

$es_turno_actual = ($fecha == turno_hoy());
$dias_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$etiqueta_fecha = $dias_semana[date('w', strtotime($fecha))] . ' ' . date('d/m/Y', strtotime($fecha));
?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-white">Visitas sin reserva</h1>
            <p class="mt-1 text-sm text-neutral-500">Clientes que llegan sin reserva. Solo cuentan para el aforo: sin cierre, comisión ni propina.</p>
        </div>
        <a href="index.php?tab=nuevas" class="mt-4 sm:mt-0 btn-secondary inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-medium text-neutral-300">Volver a reservas</a>
    </div>

    <?php if ($falta_migracion): ?>
        <div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm">Falta ejecutar <strong>ajustes_2026-10-27.sql</strong> en la base de datos para activar las visitas sin reserva.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert-error rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success rounded-xl px-4 py-3 mb-5 text-sm"><?php echo $success; ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Registro -->
        <form method="POST" action="visitas.php" class="glass-card rounded-2xl p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="accion" value="registrar">
            <h2 class="text-sm font-semibold text-white">Registrar visita</h2>
            <div>
                <label for="num_personas" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Personas</label>
                <input type="number" name="num_personas" id="num_personas" min="1" max="500" value="2" inputmode="numeric" class="input-premium rounded-xl w-full py-3 px-4 text-lg font-semibold text-white" required autofocus>
            </div>
            <div>
                <label for="concepto" class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Concepto</label>
                <select name="concepto" id="concepto" class="input-premium rounded-xl w-full py-3 px-4 text-sm text-white" required>
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($CONCEPTOS_VISITA as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-amber w-full py-3 px-4 rounded-xl text-sm font-semibold text-white" <?php echo $falta_migracion ? 'disabled' : ''; ?>>Registrar</button>
            <p class="text-xs text-neutral-600">Se registra con la hora actual en el turno en curso (<?php echo date('d/m/Y', strtotime(turno_hoy())); ?>).</p>
        </form>

        <!-- Resumen del turno -->
        <div class="lg:col-span-2 space-y-4">
            <form method="GET" action="visitas.php" class="glass-card rounded-xl p-4 flex flex-col sm:flex-row items-end gap-3">
                <div class="w-full sm:w-auto">
                    <label class="block text-xs font-semibold text-neutral-400 mb-1.5 uppercase tracking-wider">Turno (5 pm a 4:59 pm)</label>
                    <input type="date" name="fecha" value="<?php echo htmlspecialchars($fecha); ?>" class="input-premium rounded-xl w-full py-2 px-3 text-sm text-white">
                </div>
                <div class="flex gap-2 w-full sm:w-auto">
                    <button type="submit" class="btn-amber flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-semibold text-white">Ver</button>
                    <a href="visitas.php" class="btn-secondary flex-1 sm:flex-none py-2 px-5 rounded-xl text-sm font-medium text-neutral-300 text-center">Hoy</a>
                </div>
                <p class="sm:ml-auto text-sm text-neutral-400"><?php echo $etiqueta_fecha; ?><?php echo $es_turno_actual ? ' <span class="ml-1 rounded-md bg-amber-500/15 px-2 py-0.5 text-xs text-amber-400">Turno en curso</span>' : ''; ?></p>
            </form>

            <div class="grid grid-cols-3 gap-3">
                <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Sin reserva</p><p class="text-2xl font-bold text-white"><?php echo $totales['personas']; ?></p><p class="text-xs text-neutral-500"><?php echo $totales['visitas']; ?> visita(s)</p></div>
                <div class="glass-card rounded-xl px-4 py-3"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Con reserva</p><p class="text-2xl font-bold text-white"><?php echo $personas_reserva; ?></p><p class="text-xs text-neutral-500">llegaron</p></div>
                <div class="glass-card rounded-xl px-4 py-3 border-amber-500/30"><p class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Aforo total</p><p class="text-2xl font-bold text-amber-400"><?php echo $personas_reserva + $totales['personas']; ?></p><p class="text-xs text-neutral-500">personas</p></div>
            </div>

            <?php if (!empty($por_concepto)): ?>
                <div class="flex flex-wrap gap-2">
                    <?php arsort($por_concepto); foreach ($por_concepto as $concepto => $personas): ?>
                        <span class="inline-flex items-center rounded-lg bg-neutral-800/80 px-3 py-1.5 text-xs text-neutral-300"><?php echo htmlspecialchars($concepto); ?> <span class="ml-2 font-bold text-white"><?php echo $personas; ?></span></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lista -->
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full table-premium">
                <thead>
                    <tr class="border-b border-neutral-700/50">
                        <th class="py-3.5 pl-5 pr-3 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Hora</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Personas</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider">Concepto</th>
                        <th class="px-3 py-3.5 text-left text-xs font-semibold text-neutral-400 uppercase tracking-wider hidden sm:table-cell">Registró</th>
                        <th class="py-3.5 pl-3 pr-5"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-800/50">
                    <?php if (empty($visitas)): ?>
                        <tr><td colspan="5" class="px-3 py-10 text-sm text-neutral-500 text-center">No hay visitas sin reserva en este turno.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($visitas as $v): ?>
                        <tr>
                            <td class="whitespace-nowrap py-3 pl-5 pr-3 text-sm text-neutral-300"><?php echo date('d/m/Y h:i A', strtotime($v['fecha_hora'])); ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm font-semibold text-white"><?php echo $v['num_personas']; ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm"><span class="inline-flex rounded-lg bg-blue-500/15 px-2.5 py-1 text-xs font-semibold text-blue-400"><?php echo htmlspecialchars($v['concepto']); ?></span></td>
                            <td class="whitespace-nowrap px-3 py-3 text-sm text-neutral-400 hidden sm:table-cell"><?php echo htmlspecialchars($v['nombre_hostes'] ?? '-'); ?></td>
                            <td class="whitespace-nowrap py-3 pl-3 pr-5 text-right text-sm">
                                <?php if ($es_turno_actual): ?>
                                    <form method="POST" action="visitas.php" onsubmit="return confirm('¿Eliminar esta visita?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_visita" value="<?php echo $v['id']; ?>">
                                        <button type="submit" class="text-red-500/60 hover:text-red-400 text-xs transition-colors duration-200">Eliminar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
