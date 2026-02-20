<?php
$pageTitle   = 'Negociações — Kanban';
$currentPage = 'negociacoes';
require_once __DIR__ . '/_nav.php';

// ── Etapas dinâmicas do banco ─────────────────────────────────────────────────
$etapasDB  = getEtapas();   // [{id, nome, cor, ordem, is_encerrada, is_ganho}]

// ── Negociações ───────────────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT n.id, n.etapa, n.valor, n.qualificacao, n.criado_em,
           c.nome AS cliente, c.empresa, c.id AS cliente_id,
           u.nome AS responsavel
    FROM negociacoes n
    JOIN clientes c ON c.id = n.cliente_id
    LEFT JOIN usuarios u ON u.id = n.responsavel_id
    ORDER BY n.criado_em DESC
");
$all = $stmt->fetchAll();

// Agrupar por etapa
$byEtapa = [];
foreach ($etapasDB as $et) {
    $byEtapa[$et['nome']] = [];
}
// Negociações em etapas que não existem mais ficam em coluna "Outras"
foreach ($all as $neg) {
    if (array_key_exists($neg['etapa'], $byEtapa)) {
        $byEtapa[$neg['etapa']][] = $neg;
    }
}

// Total de valor por etapa
$totais = [];
foreach ($byEtapa as $nome => $itens) {
    $totais[$nome] = array_sum(array_column($itens, 'valor'));
}

$qualCores = [
    'Quente'           => 'bg-red-100 text-red-700',
    'Muito Interessado'=> 'bg-orange-100 text-orange-700',
    'Morno'            => 'bg-yellow-100 text-yellow-700',
    'Sem interesse'    => 'bg-gray-100 text-gray-600',
];
?>

<div class="mb-5 flex items-center justify-between">
    <p class="text-sm text-gray-500">Arraste os cards entre as colunas para mover a etapa</p>
    <a href="negociacao_detalhe.php?novo=1"
       class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Nova Negociação
    </a>
</div>

<!-- Kanban Board -->
<div class="flex gap-4 overflow-x-auto pb-4" style="min-height: 70vh;">
    <?php foreach ($etapasDB as $etapa):
        $cor   = ETAPA_CORES[$etapa['cor']] ?? ETAPA_CORES['cinza'];
        $itens = $byEtapa[$etapa['nome']] ?? [];
        $total = $totais[$etapa['nome']] ?? 0;
    ?>
    <div class="flex-shrink-0 w-64 flex flex-col">
        <!-- Cabeçalho da coluna -->
        <div class="<?= $cor['header'] ?> rounded-t-xl px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full <?= $cor['dot'] ?>"></span>
                <span class="text-xs font-bold uppercase tracking-wide"><?= e($etapa['nome']) ?></span>
                <?php if ($etapa['is_ganho']): ?>
                <span title="Conta como ganho" class="text-xs">🏆</span>
                <?php elseif ($etapa['is_encerrada']): ?>
                <span title="Encerra negociação" class="text-xs">🔒</span>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <span class="text-xs font-medium opacity-70">
                    <?= count($itens) ?> · <?= formatMoney($total) ?>
                </span>
            </div>
        </div>

        <!-- Zona de drop -->
        <div class="kanban-col bg-gray-100 rounded-b-xl flex-1 p-2 space-y-2 min-h-48"
             data-etapa="<?= e($etapa['nome']) ?>">
            <?php foreach ($itens as $neg): ?>
            <div class="kanban-card bg-white rounded-lg border border-gray-200 p-3 shadow-sm cursor-grab hover:shadow-md transition-shadow"
                 data-id="<?= $neg['id'] ?>">
                <a href="negociacao_detalhe.php?id=<?= $neg['id'] ?>" class="block">
                    <p class="text-sm font-semibold text-gray-800 leading-tight"><?= e($neg['cliente']) ?></p>
                    <?php if ($neg['empresa']): ?>
                    <p class="text-xs text-gray-500 mt-0.5"><?= e($neg['empresa']) ?></p>
                    <?php endif; ?>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-sm font-bold text-gray-700"><?= formatMoney($neg['valor']) ?></span>
                        <?php if ($neg['qualificacao']): ?>
                        <span class="badge text-xs <?= $qualCores[$neg['qualificacao']] ?? 'bg-gray-100 text-gray-600' ?>">
                            <?= e($neg['qualificacao']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($neg['responsavel']): ?>
                    <p class="text-xs text-gray-400 mt-2">👤 <?= e($neg['responsavel']) ?></p>
                    <?php endif; ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('.kanban-col').forEach(col => {
    new Sortable(col, {
        group: 'kanban',
        animation: 150,
        ghostClass: 'opacity-40',
        onEnd(evt) {
            const negId = evt.item.dataset.id;
            const etapa = evt.to.dataset.etapa;
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'mover_etapa', negociacao_id: negId, etapa })
            });
        }
    });
});
</script>

<?php require_once '_footer.php'; ?>
