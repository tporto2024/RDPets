<?php
$pageTitle   = 'Tarefas';
$currentPage = 'tarefas';
require_once __DIR__ . '/_nav.php';
$u = getCurrentUser();

// ── Toggle status ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle') {
    $tid   = (int)$_POST['tarefa_id'];
    $newSt = $_POST['novo_status'];
    $acao  = $newSt === 'concluida' ? 'concluida' : 'reaberta';
    $pdo->prepare('UPDATE tarefas SET status=? WHERE id=?')->execute([$newSt,$tid]);
    $pdo->prepare('INSERT INTO tarefas_log (tarefa_id,acao,para_status,changed_by,changed_ip) VALUES (?,?,?,?,?)')
        ->execute([$tid,$acao,$newSt,$u['nome'],getClientIP()]);
    header('Location: tarefas.php?' . http_build_query(array_filter($_GET)));
    exit;
}

// ── Filtros ───────────────────────────────────────────────────────────────────
$filtroStatus = $_GET['status'] ?? 'aberta';
$filtroTipo   = $_GET['tipo']   ?? '';
$filtroUser   = (int)($_GET['user'] ?? 0);

$where  = ['1=1'];
$params = [];

if (in_array($filtroStatus, ['aberta','concluida'])) {
    $where[]  = 't.status = ?';
    $params[] = $filtroStatus;
}
if ($filtroTipo) {
    $where[]  = 't.tipo = ?';
    $params[] = $filtroTipo;
}
if ($filtroUser) {
    $where[]  = 't.responsavel_id = ?';
    $params[] = $filtroUser;
}

$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT t.*, c.nome AS cliente, c.empresa, n.id AS neg_id, u.nome AS resp_nome
    FROM tarefas t
    JOIN negociacoes n ON n.id = t.negociacao_id
    JOIN clientes c    ON c.id = n.cliente_id
    LEFT JOIN usuarios u ON u.id = t.responsavel_id
    WHERE $whereSQL
    ORDER BY t.quando ASC
");
$stmt->execute($params);
$tarefas = $stmt->fetchAll();

$usuarios  = $pdo->query('SELECT id,nome FROM usuarios ORDER BY nome')->fetchAll();
$tipos     = ['Ligar','Email','Reunião','Tarefa','Almoço','Visita','WhatsApp'];
$tipoIcon  = ['Ligar'=>'📞','Email'=>'✉️','Reunião'=>'📅','WhatsApp'=>'💬','Visita'=>'🚗','Almoço'=>'🍽️','Tarefa'=>'✅'];

// contagens para badges
$totalAberta    = $pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta'")->fetchColumn();
$totalAtrasadas = $pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta' AND quando < NOW()")->fetchColumn();
?>

<!-- Filtros -->
<div class="flex flex-wrap items-center gap-3 mb-5">
    <!-- Status tabs -->
    <div class="flex rounded-lg border border-gray-200 overflow-hidden bg-white text-sm">
        <?php foreach (['aberta'=>"Abertas ($totalAberta)",'concluida'=>'Concluídas',''=>'Todas'] as $v=>$l): ?>
        <a href="?status=<?= $v ?>&tipo=<?= urlencode($filtroTipo) ?>&user=<?= $filtroUser ?>"
           class="px-4 py-2 <?= $filtroStatus===$v ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-50' ?>">
            <?= $l ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Tipo -->
    <select onchange="location='?status=<?= urlencode($filtroStatus) ?>&user=<?= $filtroUser ?>&tipo='+this.value"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none">
        <option value="">Todos os tipos</option>
        <?php foreach ($tipos as $t): ?>
        <option value="<?= $t ?>" <?= $filtroTipo===$t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Responsável -->
    <select onchange="location='?status=<?= urlencode($filtroStatus) ?>&tipo=<?= urlencode($filtroTipo) ?>&user='+this.value"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none">
        <option value="0">Todos os responsáveis</option>
        <?php foreach ($usuarios as $usr): ?>
        <option value="<?= $usr['id'] ?>" <?= $filtroUser==$usr['id'] ? 'selected' : '' ?>><?= e($usr['nome']) ?></option>
        <?php endforeach; ?>
    </select>

    <?php if ($totalAtrasadas > 0 && $filtroStatus !== 'concluida'): ?>
    <span class="badge bg-red-100 text-red-700">⚠ <?= $totalAtrasadas ?> atrasada(s)</span>
    <?php endif; ?>
</div>

<!-- Lista de tarefas -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <?php if (empty($tarefas)): ?>
    <div class="text-center py-16 text-gray-400">
        <p class="text-4xl mb-3">✅</p>
        <p class="text-sm">Nenhuma tarefa encontrada</p>
    </div>
    <?php else: ?>

    <?php
    // Agrupar por data
    $grupos = [];
    foreach ($tarefas as $t) {
        $d = date('Y-m-d', strtotime($t['quando']));
        $grupos[$d][] = $t;
    }
    ?>

    <?php foreach ($grupos as $data => $items):
        $ts    = strtotime($data);
        $hoje  = date('Y-m-d');
        $amh   = date('Y-m-d', strtotime('+1 day'));
        $label = match(true) {
            $data <  $hoje => '⚠ ' . date('d/m/Y', $ts) . ' — Atrasadas',
            $data === $hoje => '📅 Hoje — ' . date('d/m/Y', $ts),
            $data === $amh  => '🔜 Amanhã — ' . date('d/m/Y', $ts),
            default         => date('d/m/Y', $ts),
        };
        $atrasado = $data < $hoje;
    ?>
    <div class="px-5 py-2 bg-gray-50 border-b border-gray-100 text-xs font-semibold <?= $atrasado ? 'text-red-600' : 'text-gray-500' ?>">
        <?= $label ?>
    </div>

    <?php foreach ($items as $t): ?>
    <div class="flex items-center gap-4 px-5 py-3 border-b border-gray-100 hover:bg-gray-50 transition-colors
                <?= $t['status']==='concluida' ? 'opacity-60' : '' ?>">
        <!-- Checkbox -->
        <form method="POST">
            <input type="hidden" name="_action" value="toggle">
            <input type="hidden" name="tarefa_id" value="<?= $t['id'] ?>">
            <input type="hidden" name="novo_status" value="<?= $t['status']==='concluida' ? 'aberta' : 'concluida' ?>">
            <button type="submit"
                    class="w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors
                    <?= $t['status']==='concluida' ? 'bg-green-500 border-green-500 text-white' : 'border-gray-300 hover:border-green-500' ?>">
                <?php if ($t['status']==='concluida'): ?>
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                </svg>
                <?php endif; ?>
            </button>
        </form>

        <!-- Ícone -->
        <span class="text-lg flex-shrink-0"><?= $tipoIcon[$t['tipo']] ?? '✅' ?></span>

        <!-- Conteúdo -->
        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800 <?= $t['status']==='concluida' ? 'line-through text-gray-400' : '' ?>">
                <?= e($t['assunto']) ?>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">
                <a href="negociacao_detalhe.php?id=<?= $t['neg_id'] ?>" class="hover:text-blue-600">
                    <?= e($t['cliente']) ?><?= $t['empresa'] ? ' — ' . e($t['empresa']) : '' ?>
                </a>
            </p>
            <?php if ($t['descricao']): ?>
            <p class="text-xs text-gray-400 mt-0.5"><?= e($t['descricao']) ?></p>
            <?php endif; ?>
        </div>

        <!-- Hora + responsável -->
        <div class="text-right flex-shrink-0">
            <p class="text-xs font-medium <?= ($atrasado && $t['status']==='aberta') ? 'text-red-500' : 'text-gray-500' ?>">
                <?= date('H:i', strtotime($t['quando'])) ?>
            </p>
            <?php if ($t['resp_nome']): ?>
            <p class="text-xs text-gray-400 mt-0.5"><?= e($t['resp_nome']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
