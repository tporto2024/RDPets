<?php
$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
require_once __DIR__ . '/_nav.php';
logActivity('Dashboard', 'Visualizou o dashboard');

// ── Etapas dinâmicas ──────────────────────────────────────────────────────────
$etapasDB       = getEtapas();
$etapasEncerradas = array_column(array_filter($etapasDB, fn($e) => $e['is_encerrada']), 'nome');
$etapasGanho      = array_column(array_filter($etapasDB, fn($e) => $e['is_ganho']),     'nome');
$etapasAbertas    = array_column(array_filter($etapasDB, fn($e) => !$e['is_encerrada']), 'nome');

$inEncerradas = $etapasEncerradas ? "'" . implode("','", array_map('addslashes', $etapasEncerradas)) . "'" : "'__none__'";
$inGanho      = $etapasGanho      ? "'" . implode("','", array_map('addslashes', $etapasGanho))      . "'" : "'__none__'";
$inAbertas    = $etapasAbertas    ? "'" . implode("','", array_map('addslashes', $etapasAbertas))    . "'" : "'__none__'";

// ── Cards de resumo ───────────────────────────────────────────────────────────
$totalClientes    = $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
$totalNeg         = $pdo->query('SELECT COUNT(*) FROM negociacoes')->fetchColumn();
$valorPipeline    = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM negociacoes WHERE etapa IN ($inAbertas)")->fetchColumn();
$valorVendido     = $pdo->query("SELECT COALESCE(SUM(valor),0) FROM negociacoes WHERE etapa IN ($inGanho)")->fetchColumn();
$tarefasAbertas   = $pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta'")->fetchColumn();
$tarefasAtrasadas = $pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta' AND quando < NOW()")->fetchColumn();

// ── Funil por etapa (ordem do banco) ─────────────────────────────────────────
$funilMap = [];
foreach ($etapasDB as $et) {
    $funilMap[$et['nome']] = ['qtd' => 0, 'total' => 0, 'cor' => $et['cor']];
}
foreach ($pdo->query("SELECT etapa, COUNT(*) AS qtd, COALESCE(SUM(valor),0) AS total FROM negociacoes GROUP BY etapa") as $r) {
    if (isset($funilMap[$r['etapa']])) {
        $funilMap[$r['etapa']]['qtd']   = (int)$r['qtd'];
        $funilMap[$r['etapa']]['total'] = (float)$r['total'];
    }
}
$chartLabels = array_keys($funilMap);
$chartQtds   = array_column($funilMap, 'qtd');
$chartTotais = array_column($funilMap, 'total');
$chartCores  = array_map(fn($c) => match($c['cor']) {
    'vermelho' => '#ef4444', 'azul'    => '#3b82f6', 'amarelo' => '#eab308',
    'laranja'  => '#f97316', 'verde'   => '#22c55e', 'roxo'    => '#8b5cf6',
    'rosa'     => '#ec4899', 'indigo'  => '#6366f1', 'ciano'   => '#06b6d4',
    default    => '#9ca3af'
}, $funilMap);

// ── Tarefas próximas (7 dias) ─────────────────────────────────────────────────
$prox = $pdo->query("
    SELECT t.assunto, t.tipo, t.quando, c.nome AS cliente
    FROM tarefas t
    JOIN negociacoes n ON n.id = t.negociacao_id
    JOIN clientes c   ON c.id = n.cliente_id
    WHERE t.status = 'aberta'
      AND t.quando >= NOW()
      AND t.quando <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY t.quando
    LIMIT 6
")->fetchAll();

// ── Últimas negociações ───────────────────────────────────────────────────────
$ultNeg = $pdo->query("
    SELECT n.id, n.etapa, n.valor, n.qualificacao, c.nome AS cliente
    FROM negociacoes n
    JOIN clientes c ON c.id = n.cliente_id
    ORDER BY n.criado_em DESC
    LIMIT 5
")->fetchAll();

// ── Clientes sem atividade há mais de 30 dias ─────────────────────────────────
// Considera: última entrada no log de etapas OU última tarefa criada OU data de criação da negociação
$semAtividade = $pdo->query("
    SELECT
        c.id  AS cliente_id,
        c.nome AS cliente,
        c.empresa,
        n.id   AS neg_id,
        n.etapa,
        n.valor,
        GREATEST(
            COALESCE(MAX(nl.changed_at), n.criado_em),
            COALESCE(MAX(t.criado_em),   n.criado_em),
            n.criado_em
        ) AS ultima_atividade
    FROM negociacoes n
    JOIN clientes c      ON c.id = n.cliente_id
    LEFT JOIN neg_etapas e ON e.nome = n.etapa
    LEFT JOIN negociacoes_log nl ON nl.negociacao_id = n.id
    LEFT JOIN tarefas t         ON t.negociacao_id  = n.id
    WHERE COALESCE(e.is_encerrada, 0) = 0
    GROUP BY n.id, c.id, c.nome, c.empresa, n.etapa, n.valor, n.criado_em
    HAVING ultima_atividade < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY ultima_atividade ASC
    LIMIT 8
")->fetchAll();
?>

<!-- Cards de Resumo -->
<div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
<?php
$cards = [
    ['label'=>'Clientes',       'value'=>$totalClientes,              'color'=>'blue',   'icon'=>'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
    ['label'=>'Negociações',    'value'=>$totalNeg,                   'color'=>'indigo', 'icon'=>'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
    ['label'=>'Pipeline',       'value'=>formatMoney($valorPipeline), 'color'=>'cyan',   'icon'=>'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 13v-1m0-8v.01M12 4a8 8 0 100 16A8 8 0 0012 4z'],
    ['label'=>'Vendido',        'value'=>formatMoney($valorVendido),  'color'=>'green',  'icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['label'=>'Tarefas abertas','value'=>$tarefasAbertas . ($tarefasAtrasadas ? " (⚠ $tarefasAtrasadas)" : ''), 'color'=>'amber', 'icon'=>'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
];
$colorMap = [
    'blue'  => ['bg-blue-50',  'text-blue-600',  'text-blue-800'],
    'indigo'=> ['bg-indigo-50','text-indigo-600','text-indigo-800'],
    'cyan'  => ['bg-cyan-50',  'text-cyan-600',  'text-cyan-800'],
    'green' => ['bg-green-50', 'text-green-600', 'text-green-800'],
    'amber' => ['bg-amber-50', 'text-amber-600', 'text-amber-800'],
];
foreach ($cards as $c): [$bg,$ic,$txt] = $colorMap[$c['color']]; ?>
<div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
    <div class="<?= $bg ?> p-3 rounded-xl">
        <svg class="w-6 h-6 <?= $ic ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $c['icon'] ?>"/>
        </svg>
    </div>
    <div>
        <p class="text-xs text-gray-500 font-medium"><?= $c['label'] ?></p>
        <p class="text-lg font-bold <?= $txt ?>"><?= $c['value'] ?></p>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Gráficos -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-sm font-bold text-gray-700 mb-4">Funil de Negociações</h2>
        <canvas id="chartFunil" height="200"></canvas>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-sm font-bold text-gray-700 mb-4">Valor por Etapa (R$)</h2>
        <canvas id="chartValor" height="200"></canvas>
    </div>
</div>

<!-- Últimas negociações + próximas tarefas -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Últimas negociações -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-gray-700">Últimas Negociações</h2>
            <a href="negociacoes.php" class="text-xs text-blue-600 hover:underline">Ver todas →</a>
        </div>
        <div class="space-y-2">
        <?php foreach ($ultNeg as $n):
            $etDB = array_values(array_filter($etapasDB, fn($e) => $e['nome'] === $n['etapa']))[0] ?? null;
            $cor  = $etDB ? (ETAPA_CORES[$etDB['cor']] ?? ETAPA_CORES['cinza']) : ETAPA_CORES['cinza'];
        ?>
            <a href="negociacao_detalhe.php?id=<?= $n['id'] ?>"
               class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors">
                <div>
                    <p class="text-sm font-medium text-gray-800"><?= e($n['cliente']) ?></p>
                    <p class="text-xs text-gray-400"><?= e($n['qualificacao'] ?? '—') ?></p>
                </div>
                <div class="text-right">
                    <span class="badge text-xs <?= $cor['header'] ?>"><?= e($n['etapa']) ?></span>
                    <p class="text-xs text-gray-400 mt-1"><?= formatMoney($n['valor']) ?></p>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Próximas tarefas -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-gray-700">Próximas Tarefas (7 dias)</h2>
            <a href="tarefas.php" class="text-xs text-blue-600 hover:underline">Ver todas →</a>
        </div>
        <?php if (empty($prox)): ?>
            <p class="text-sm text-gray-400 text-center py-6">Nenhuma tarefa nos próximos 7 dias</p>
        <?php else: ?>
        <div class="space-y-2">
        <?php foreach ($prox as $t): ?>
            <div class="flex items-start gap-3 p-3 rounded-lg hover:bg-gray-50">
                <span class="text-lg mt-0.5"><?= match($t['tipo']) {
                    'Ligar'=>'📞','Email'=>'✉️','Reunião'=>'📅',
                    'WhatsApp'=>'💬','Visita'=>'🚗','Almoço'=>'🍽️',default=>'✅'
                } ?></span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate"><?= e($t['assunto']) ?></p>
                    <p class="text-xs text-gray-400"><?= e($t['cliente']) ?> · <?= date('d/m H:i', strtotime($t['quando'])) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Clientes sem atividade há +30 dias ════════════════════════════════════ -->
<div class="bg-white rounded-xl border border-orange-200 p-5">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-bold text-gray-800">Negociações sem atividade há +30 dias</h2>
                <p class="text-xs text-gray-400">Clientes em aberto que precisam de atenção</p>
            </div>
        </div>
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
            <?= count($semAtividade) > 0 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-400' ?>">
            <?= count($semAtividade) ?>
        </span>
    </div>

    <?php if (empty($semAtividade)): ?>
    <div class="text-center py-8">
        <div class="text-3xl mb-2">🎉</div>
        <p class="text-sm text-gray-500 font-medium">Tudo em dia!</p>
        <p class="text-xs text-gray-400">Nenhuma negociação parada há mais de 30 dias.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="pb-2 text-left">Cliente</th>
                    <th class="pb-2 text-left hidden sm:table-cell">Empresa</th>
                    <th class="pb-2 text-left">Etapa</th>
                    <th class="pb-2 text-right hidden sm:table-cell">Valor</th>
                    <th class="pb-2 text-right">Última atividade</th>
                    <th class="pb-2 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($semAtividade as $s):
                $diasSem  = (int)floor((time() - strtotime($s['ultima_atividade'])) / 86400);
                $etDB     = array_values(array_filter($etapasDB, fn($e) => $e['nome'] === $s['etapa']))[0] ?? null;
                $cor      = $etDB ? (ETAPA_CORES[$etDB['cor']] ?? ETAPA_CORES['cinza']) : ETAPA_CORES['cinza'];
                $urgencia = $diasSem >= 60 ? 'text-red-600 font-bold' : ($diasSem >= 45 ? 'text-orange-600 font-semibold' : 'text-yellow-600');
            ?>
            <tr class="hover:bg-orange-50 transition-colors group">
                <td class="py-3 pr-4">
                    <p class="font-semibold text-gray-800"><?= e($s['cliente']) ?></p>
                </td>
                <td class="py-3 pr-4 text-gray-500 hidden sm:table-cell">
                    <?= e($s['empresa'] ?: '—') ?>
                </td>
                <td class="py-3 pr-4">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $cor['header'] ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= $cor['dot'] ?>"></span>
                        <?= e($s['etapa']) ?>
                    </span>
                </td>
                <td class="py-3 pr-4 text-right text-gray-600 font-medium hidden sm:table-cell">
                    <?= formatMoney($s['valor']) ?>
                </td>
                <td class="py-3 pr-4 text-right">
                    <span class="<?= $urgencia ?> text-xs">
                        <?= $diasSem ?> dia<?= $diasSem !== 1 ? 's' : '' ?> atrás
                    </span>
                    <p class="text-xs text-gray-400"><?= date('d/m/Y', strtotime($s['ultima_atividade'])) ?></p>
                </td>
                <td class="py-3 text-right">
                    <a href="negociacao_detalhe.php?id=<?= $s['neg_id'] ?>"
                       class="opacity-0 group-hover:opacity-100 transition-opacity text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded hover:bg-blue-50">
                        Retomar →
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
const labels = <?= json_encode($chartLabels) ?>;
const qtds   = <?= json_encode($chartQtds) ?>;
const totais = <?= json_encode($chartTotais) ?>;
const cores  = <?= json_encode(array_values($chartCores)) ?>;
const totalGeral = qtds.reduce((a, b) => a + b, 0);

// Plugin: texto no centro do donut
const centerTextPlugin = {
    id: 'centerText',
    afterDraw(chart) {
        if (chart.config.type !== 'doughnut') return;
        const { ctx, chartArea: { left, right, top, bottom } } = chart;
        const cx = (left + right) / 2;
        const cy = (top + bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = 'bold 28px sans-serif';
        ctx.fillStyle = '#1f2937';
        ctx.fillText(totalGeral, cx, cy - 8);
        ctx.font = '12px sans-serif';
        ctx.fillStyle = '#6b7280';
        ctx.fillText('negociações', cx, cy + 14);
        ctx.restore();
    }
};

Chart.register(ChartDataLabels);

const chartFunil = new Chart(document.getElementById('chartFunil'), {
    type: 'doughnut',
    data: { labels, datasets: [{ data: qtds, backgroundColor: cores, borderWidth: 2, borderColor: '#fff' }] },
    plugins: [centerTextPlugin],
    options: {
        plugins: {
            legend: { position: 'right' },
            datalabels: {
                color: '#fff',
                font: { weight: 'bold', size: 12 },
                formatter(value) { return value > 0 ? value : ''; },
                textShadowColor: 'rgba(0,0,0,0.4)',
                textShadowBlur: 4
            }
        },
        cutout: '60%',
        onClick(evt, elements) {
            if (elements.length > 0) {
                const idx = elements[0].index;
                const etapa = labels[idx];
                window.location.href = 'negociacoes.php?etapa=' + encodeURIComponent(etapa);
            }
        },
        onHover(evt, elements) {
            evt.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
        }
    }
});

const chartValor = new Chart(document.getElementById('chartValor'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'R$', data: totais, backgroundColor: cores, borderRadius: 6 }] },
    options: {
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                color: '#374151',
                font: { weight: 'bold', size: 11 },
                formatter(value) {
                    if (value === 0) return '';
                    return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                }
            }
        },
        scales: {
            y: { beginAtZero: true }
        },
        onClick(evt, elements) {
            if (elements.length > 0) {
                const idx = elements[0].index;
                const etapa = labels[idx];
                window.location.href = 'negociacoes.php?etapa=' + encodeURIComponent(etapa);
            }
        },
        onHover(evt, elements) {
            evt.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
        }
    }
});
</script>

<?php require_once '_footer.php'; ?>
