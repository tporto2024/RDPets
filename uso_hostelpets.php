<?php
ob_start();
$pageTitle   = 'Uso HostelPets';
$currentPage = 'uso_hostelpets';

require_once __DIR__ . '/auth.php';
requireLogin();

// ── Conexão com o banco petcare_saas (mesmo servidor) ───────────────────────
try {
    $pdoHP = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, 'petcare_saas', DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    require __DIR__ . '/_nav.php';
    echo '<main class="flex-1 overflow-y-auto p-8"><div class="bg-red-100 text-red-700 p-4 rounded-lg">Erro ao conectar ao banco petcare_saas: '.e($e->getMessage()).'</div></main></div></body></html>';
    exit;
}

// ── Filtros ─────────────────────────────────────────────────────────────────
$filtroData   = $_GET['data'] ?? '';
$filtroLoja   = $_GET['loja'] ?? '';
$filtroStatus = $_GET['status'] ?? 'producao'; // producao, paga, todas

// ── Buscar lojas (primeiro admin de cada tenant = dono da loja) ─────────────
$lojasRaw = $pdoHP->query("
    SELECT u.tenant_id, u.nome, u.email, u.id
    FROM usuarios u
    WHERE u.papel = 'admin'
    ORDER BY u.tenant_id, u.id ASC
")->fetchAll();

// Primeiro admin por tenant (dono original)
$lojas = [];
$lojaDonos = []; // tenant_id → [nome, email]
foreach ($lojasRaw as $lj) {
    if (!isset($lojaDonos[$lj['tenant_id']])) {
        $lojaDonos[$lj['tenant_id']] = $lj;
        $lojas[] = $lj;
    }
}

// Buscar nome da empresa e etapa da negociação no CRM pelo email do admin
$lojaEmpresas = []; // tenant_id → nome empresa
$lojaEtapas   = []; // tenant_id → etapa da negociação
$emailsAdmin = array_column($lojas, 'email');
if ($emailsAdmin) {
    $placeholders = implode(',', array_fill(0, count($emailsAdmin), '?'));
    // Buscar cliente + negociação mais recente
    $stEmp = $pdo->prepare("
        SELECT c.email, c.empresa, c.nome AS cliente_nome, n.etapa
        FROM clientes c
        LEFT JOIN negociacoes n ON n.cliente_id = c.id
        WHERE c.email IN ($placeholders) AND c.empresa IS NOT NULL AND c.empresa != ''
        ORDER BY c.email, n.criado_em DESC
    ");
    $stEmp->execute($emailsAdmin);
    $empresasPorEmail = [];
    $etapasPorEmail = [];
    foreach ($stEmp->fetchAll() as $emp) {
        if (!isset($empresasPorEmail[$emp['email']])) {
            $empresasPorEmail[$emp['email']] = $emp['empresa'];
        }
        if (!isset($etapasPorEmail[$emp['email']]) && $emp['etapa']) {
            $etapasPorEmail[$emp['email']] = $emp['etapa'];
        }
    }
    foreach ($lojas as $lj) {
        if (isset($empresasPorEmail[$lj['email']])) {
            $lojaEmpresas[$lj['tenant_id']] = $empresasPorEmail[$lj['email']];
        }
        if (isset($etapasPorEmail[$lj['email']])) {
            $lojaEtapas[$lj['tenant_id']] = $etapasPorEmail[$lj['email']];
        }
    }
}

// Identificar lojas de produção (tem cliente correspondente no CRM)
$tenantsProducao = array_keys($lojaEmpresas);

// Filtrar apenas lojas com status "Vendido" (pagas) no CRM
$tenantsPagos = [];
foreach ($lojaEtapas as $tid => $etapa) {
    if ($etapa === 'Vendido') {
        $tenantsPagos[] = $tid;
    }
}

// ── Query principal: primeiras 3 ações do dia por usuário ───────────────────
$where = [];
$params = [];

if ($filtroData) {
    $where[] = 'DATE(l.created_at) = ?';
    $params[] = $filtroData;
}
if ($filtroLoja) {
    $where[] = 'l.tenant_id = ?';
    $params[] = (int)$filtroLoja;
}

// Filtrar por tipo de loja
if ($filtroStatus === 'producao' && !empty($tenantsProducao)) {
    $tpPlaceholders = implode(',', array_fill(0, count($tenantsProducao), '?'));
    $where[] = "l.tenant_id IN ($tpPlaceholders)";
    $params = array_merge($params, $tenantsProducao);
} elseif ($filtroStatus === 'producao' && empty($tenantsProducao)) {
    $where[] = '1 = 0';
} elseif ($filtroStatus === 'paga' && !empty($tenantsPagos)) {
    $tpPlaceholders = implode(',', array_fill(0, count($tenantsPagos), '?'));
    $where[] = "l.tenant_id IN ($tpPlaceholders)";
    $params = array_merge($params, $tenantsPagos);
} elseif ($filtroStatus === 'paga' && empty($tenantsPagos)) {
    $where[] = '1 = 0';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
SELECT
    l.id,
    l.usuario_id,
    l.tenant_id,
    l.acao,
    l.descricao,
    l.created_at,
    u.nome AS usuario_nome,
    u.email AS usuario_email,
    u.tipo_negocio,
    u.trial_expires_at,
    DATE(l.created_at) AS dia,
    ROW_NUMBER() OVER (PARTITION BY l.usuario_id, DATE(l.created_at) ORDER BY l.created_at ASC) AS rn
FROM trial_activity_log l
JOIN usuarios u ON u.id = l.usuario_id
$whereSQL
ORDER BY l.created_at DESC
";

$stmt = $pdoHP->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

// Filtrar apenas as 3 primeiras de cada dia/usuario
$rows = array_filter($allRows, fn($r) => $r['rn'] <= 3);

// Agrupar por dia → tenant → usuario
$grouped = [];
foreach ($rows as $r) {
    $dia = $r['dia'];
    $tid = $r['tenant_id'];
    $uid = $r['usuario_id'];
    $grouped[$dia][$tid][$uid][] = $r;
}

// Mapear tenant_id → nome da loja (empresa do CRM ou nome do admin)
$lojaMap = [];
foreach ($lojas as $lj) {
    $tid = $lj['tenant_id'];
    $lojaMap[$tid] = $lojaEmpresas[$tid] ?? $lj['nome'];
}

// ── Métricas rápidas ────────────────────────────────────────────────────────
$totalLojasProducao = count($tenantsProducao);
$totalLojasPagas = count($tenantsPagos);
$totalLojas = count($lojaMap);
$diasComUso = count($grouped);
$totalAcoes = count($rows);
// Lojas ativas hoje
$hoje = date('Y-m-d');
$lojasHoje = isset($grouped[$hoje]) ? count($grouped[$hoje]) : 0;

// Cores por etapa
$etapaCores = [
    'Vendido'     => 'bg-green-100 text-green-700',
    'Testando'    => 'bg-yellow-100 text-yellow-700',
    'Em contato'  => 'bg-blue-100 text-blue-700',
    'Negociando'  => 'bg-purple-100 text-purple-700',
    'Importado'   => 'bg-gray-100 text-gray-600',
    'Sem Retorno' => 'bg-red-100 text-red-700',
    'Adiado'      => 'bg-orange-100 text-orange-700',
    'Perdido'     => 'bg-red-200 text-red-800',
];

// ── Ícones por ação ─────────────────────────────────────────────────────────
$acaoIcones = [
    'home'           => ['icon' => '🏠', 'label' => 'Dashboard',     'cor' => 'bg-blue-100 text-blue-700'],
    'cameras'        => ['icon' => '📷', 'label' => 'Câmeras',       'cor' => 'bg-purple-100 text-purple-700'],
    'board'          => ['icon' => '📋', 'label' => 'Quadro',        'cor' => 'bg-green-100 text-green-700'],
    'tutores'        => ['icon' => '👤', 'label' => 'Tutores',       'cor' => 'bg-orange-100 text-orange-700'],
    'financeiro'     => ['icon' => '💰', 'label' => 'Financeiro',    'cor' => 'bg-emerald-100 text-emerald-700'],
    'espacos'        => ['icon' => '🏢', 'label' => 'Espaços',       'cor' => 'bg-cyan-100 text-cyan-700'],
    'configuracoes'  => ['icon' => '⚙️', 'label' => 'Configurações', 'cor' => 'bg-gray-100 text-gray-700'],
];

require __DIR__ . '/_nav.php';
?>

<main class="flex-1 overflow-y-auto p-6 bg-gray-50">
    <div class="max-w-7xl mx-auto">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Uso Diário — HostelPets</h1>
                <p class="text-sm text-gray-500 mt-1">Primeiras 3 interações do dia por usuário nas lojas do app.hostelpets.com.br</p>
            </div>
        </div>

        <!-- Cards de métricas -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow-sm border p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Lojas Produção</div>
                <div class="text-2xl font-bold text-green-600 mt-1"><?= $totalLojasProducao ?></div>
                <div class="text-[10px] text-gray-400 mt-0.5"><?= $totalLojasPagas ?> paga(s) · <?= $totalLojas ?> total</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Ativas Hoje</div>
                <div class="text-2xl font-bold <?= $lojasHoje > 0 ? 'text-green-600' : 'text-red-500' ?> mt-1"><?= $lojasHoje ?></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Dias com Uso</div>
                <div class="text-2xl font-bold text-blue-600 mt-1"><?= $diasComUso ?></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border p-4">
                <div class="text-xs text-gray-500 uppercase tracking-wide">Interações (filtro)</div>
                <div class="text-2xl font-bold text-purple-600 mt-1"><?= $totalAcoes ?></div>
            </div>
        </div>

        <!-- Filtros -->
        <form method="GET" class="bg-white rounded-xl shadow-sm border p-4 mb-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Data</label>
                <input type="date" name="data" value="<?= e($filtroData) ?>"
                       class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                <select name="status" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none">
                    <option value="producao" <?= $filtroStatus === 'producao' ? 'selected' : '' ?>>Produção (clientes reais)</option>
                    <option value="paga" <?= $filtroStatus === 'paga' ? 'selected' : '' ?>>Somente Pagas (Vendido)</option>
                    <option value="todas" <?= $filtroStatus === 'todas' ? 'selected' : '' ?>>Todas (inclui teste)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Loja</label>
                <select name="loja" class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400 focus:outline-none">
                    <option value="">Todas</option>
                    <?php foreach ($lojas as $lj): ?>
                        <option value="<?= $lj['tenant_id'] ?>" <?= $filtroLoja == $lj['tenant_id'] ? 'selected' : '' ?>>
                            <?= e($lojaEmpresas[$lj['tenant_id']] ?? $lj['nome']) ?>
                            <?php if (isset($lojaEtapas[$lj['tenant_id']])): ?>
                                [<?= e($lojaEtapas[$lj['tenant_id']]) ?>]
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                Filtrar
            </button>
            <?php if ($filtroData || $filtroLoja || $filtroStatus !== 'producao'): ?>
                <a href="uso_hostelpets.php" class="text-sm text-gray-500 hover:text-red-500 transition">Limpar filtros</a>
            <?php endif; ?>
        </form>

        <!-- Resultados agrupados por dia -->
        <?php if (empty($grouped)): ?>
            <div class="bg-white rounded-xl shadow-sm border p-8 text-center text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Nenhum registro de uso encontrado.
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $dia => $tenants): ?>
                <div class="mb-6">
                    <!-- Cabeçalho do dia -->
                    <div class="flex items-center gap-3 mb-3">
                        <div class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold shadow">
                            <?= date('d/m/Y', strtotime($dia)) ?>
                            <?php if ($dia === $hoje): ?>
                                <span class="ml-1 bg-white/20 px-1.5 py-0.5 rounded text-xs">HOJE</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-gray-400"><?= date('l', strtotime($dia)) ?></span>
                        <span class="text-xs text-gray-400">— <?= count($tenants) ?> loja(s) ativa(s)</span>
                    </div>

                    <!-- Cards por loja/tenant -->
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        <?php foreach ($tenants as $tid => $usuarios): ?>
                            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                                <!-- Header da loja -->
                                <?php
                                    $etapaLoja = $lojaEtapas[$tid] ?? null;
                                    $etapaCorCard = $etapaCores[$etapaLoja] ?? 'bg-gray-100 text-gray-600';
                                ?>
                                <div class="bg-gradient-to-r from-slate-800 to-slate-700 px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center text-white text-xs font-bold">
                                            <?= strtoupper(mb_substr($lojaMap[$tid] ?? 'L', 0, 2)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-white text-sm font-semibold truncate"><?= e($lojaMap[$tid] ?? "Loja #$tid") ?></div>
                                            <div class="text-gray-400 text-xs"><?= e($lojaDonos[$tid]['email'] ?? '') ?></div>
                                        </div>
                                        <?php if ($etapaLoja): ?>
                                            <span class="<?= $etapaCorCard ?> px-2 py-0.5 rounded text-[10px] font-bold uppercase shrink-0"><?= e($etapaLoja) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Ações por usuário -->
                                <div class="p-3 space-y-3">
                                    <?php foreach ($usuarios as $uid => $acoes): ?>
                                        <div>
                                            <div class="flex items-center gap-2 mb-2">
                                                <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-xs font-bold text-gray-600">
                                                    <?= strtoupper(mb_substr($acoes[0]['usuario_nome'], 0, 1)) ?>
                                                </div>
                                                <span class="text-xs font-medium text-gray-700"><?= e($acoes[0]['usuario_nome']) ?></span>
                                                <span class="text-[10px] text-gray-400"><?= e($acoes[0]['usuario_email']) ?></span>
                                            </div>
                                            <div class="space-y-1 ml-8">
                                                <?php foreach ($acoes as $i => $acao):
                                                    $info = $acaoIcones[$acao['acao']] ?? ['icon' => '📌', 'label' => $acao['acao'], 'cor' => 'bg-gray-100 text-gray-600'];
                                                    $hora = date('H:i:s', strtotime($acao['created_at']));
                                                ?>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs font-mono text-gray-400 w-14 shrink-0"><?= $hora ?></span>
                                                        <span class="text-sm"><?= $info['icon'] ?></span>
                                                        <span class="<?= $info['cor'] ?> px-2 py-0.5 rounded text-xs font-medium"><?= e($info['label']) ?></span>
                                                        <?php if ($acao['descricao']): ?>
                                                            <span class="text-xs text-gray-400 truncate"><?= e($acao['descricao']) ?></span>
                                                        <?php endif; ?>
                                                        <span class="ml-auto text-[10px] text-gray-300">#<?= $i + 1 ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</main>

</div><!-- /flex wrapper -->
</body>
</html>
<?php ob_end_flush(); ?>
