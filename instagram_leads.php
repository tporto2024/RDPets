<?php
ob_start();
$pageTitle   = 'Leads Instagram';
$currentPage = 'instagram';
require_once __DIR__ . '/auth.php';
requireLogin();

// ── Ações POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $user   = getCurrentUser();

    // Alterar status
    if ($action === 'status' && $id) {
        $novoStatus = $_POST['novo_status'] ?? '';
        $validos = ['novo','contatado','convertido','descartado'];
        if (in_array($novoStatus, $validos)) {
            $updates = ['status' => $novoStatus];
            if ($novoStatus === 'contatado') {
                $updates['contatado_em']  = date('Y-m-d H:i:s');
                $updates['contatado_por'] = $user['nome'];
            }
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($updates)));
            $pdo->prepare("UPDATE instagram_leads SET $sets WHERE id = ?")->execute([...array_values($updates), $id]);
            logActivity('Instagram Leads', 'Alterou status', "Lead #$id → $novoStatus");
        }
    }

    // Converter em cliente
    if ($action === 'converter' && $id) {
        $lead = $pdo->prepare('SELECT * FROM instagram_leads WHERE id = ?');
        $lead->execute([$id]);
        $lead = $lead->fetch();

        if ($lead && $lead['status'] !== 'convertido') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO clientes (nome, telefone, email, empresa, tipo_negocio, observacoes, origem)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $lead['nome'] ?: ($lead['ig_username'] ? '@' . $lead['ig_username'] : 'Lead IG #' . $lead['id']),
                    $lead['telefone'],
                    $lead['email'],
                    $lead['empresa'],
                    $lead['ad_name'] ?: 'Instagram',
                    'Origem: Instagram (' . $lead['fonte'] . ')' . ($lead['mensagem'] ? "\nMensagem: " . $lead['mensagem'] : ''),
                    'Inbound'
                ]);
                $clienteId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    'UPDATE instagram_leads SET status = ?, cliente_id = ?, convertido_em = NOW(), convertido_por = ? WHERE id = ?'
                )->execute(['convertido', $clienteId, $user['nome'], $id]);

                $pdo->commit();
                logActivity('Instagram Leads', 'Converteu lead em cliente', "Lead #$id → Cliente #$clienteId");
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
    }

    // Excluir
    if ($action === 'excluir' && $id && isMaster()) {
        $pdo->prepare('DELETE FROM instagram_leads WHERE id = ?')->execute([$id]);
        logActivity('Instagram Leads', 'Excluiu lead', "Lead #$id");
    }

    // Redirect preservando filtros
    $qs = http_build_query(array_filter([
        'status' => $_POST['_fs'] ?? '',
        'fonte'  => $_POST['_ff'] ?? '',
        'busca'  => $_POST['_fb'] ?? '',
        'pg'     => $_POST['_pg'] ?? '',
    ]));
    header("Location: instagram_leads.php" . ($qs ? "?$qs" : ''));
    exit;
}

require_once __DIR__ . '/_nav.php';
logActivity('Instagram Leads', 'Visualizou leads Instagram');

// ── Filtros ─────────────────────────────────────────────────────────────────────
$filtroStatus = $_GET['status'] ?? '';
$filtroFonte  = $_GET['fonte']  ?? '';
$busca        = trim($_GET['busca'] ?? '');
$pagina       = max(1, (int)($_GET['pg'] ?? 1));
$porPagina    = 25;

$where  = [];
$params = [];

if ($filtroStatus && in_array($filtroStatus, ['novo','contatado','convertido','descartado'])) {
    $where[]  = 'il.status = ?';
    $params[] = $filtroStatus;
}
if ($filtroFonte && in_array($filtroFonte, ['lead_ad','direct_message'])) {
    $where[]  = 'il.fonte = ?';
    $params[] = $filtroFonte;
}
if ($busca) {
    $where[]  = '(il.nome LIKE ? OR il.email LIKE ? OR il.telefone LIKE ? OR il.ig_username LIKE ? OR il.ad_name LIKE ? OR il.mensagem LIKE ?)';
    $like = "%$busca%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Contagem total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM instagram_leads il $whereSQL");
$stmtCount->execute($params);
$total   = (int)$stmtCount->fetchColumn();
$paginas = max(1, ceil($total / $porPagina));
$offset  = ($pagina - 1) * $porPagina;

// Buscar leads
$stmtLeads = $pdo->prepare("
    SELECT il.*, c.nome AS cliente_nome
    FROM instagram_leads il
    LEFT JOIN clientes c ON c.id = il.cliente_id
    $whereSQL
    ORDER BY il.criado_em DESC
    LIMIT $porPagina OFFSET $offset
");
$stmtLeads->execute($params);
$leads = $stmtLeads->fetchAll();

// Contadores por status
$contadores = [];
foreach (['novo','contatado','convertido','descartado'] as $s) {
    $contadores[$s] = (int)$pdo->query("SELECT COUNT(*) FROM instagram_leads WHERE status = '$s'")->fetchColumn();
}
$totalGeral = array_sum($contadores);

// Hidden fields para preservar filtros nos forms POST
$hiddenFields = '<input type="hidden" name="_fs" value="' . e($filtroStatus) . '">'
    . '<input type="hidden" name="_ff" value="' . e($filtroFonte) . '">'
    . '<input type="hidden" name="_fb" value="' . e($busca) . '">'
    . '<input type="hidden" name="_pg" value="' . e((string)$pagina) . '">';

$qsBase = http_build_query(array_filter([
    'status' => $filtroStatus,
    'fonte'  => $filtroFonte,
    'busca'  => $busca,
]));
?>

<!-- Cabeçalho com filtros -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-5">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#833ab4,#fd1d1d,#fcb045)">
            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
            </svg>
        </div>
        <div>
            <h2 class="text-sm font-bold text-gray-800">Leads do Instagram</h2>
            <p class="text-xs text-gray-400"><?= $totalGeral ?> leads total</p>
        </div>
    </div>

    <!-- Busca -->
    <form method="GET" class="flex items-center gap-2">
        <?php if ($filtroStatus): ?><input type="hidden" name="status" value="<?= e($filtroStatus) ?>"><?php endif; ?>
        <?php if ($filtroFonte): ?><input type="hidden" name="fonte" value="<?= e($filtroFonte) ?>"><?php endif; ?>
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Buscar leads..."
               class="border rounded-lg px-3 py-1.5 text-sm w-48 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
        <button type="submit" class="bg-purple-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-purple-700 transition-colors">Buscar</button>
        <?php if ($busca || $filtroStatus || $filtroFonte): ?>
        <a href="instagram_leads.php" class="text-xs text-gray-500 hover:text-gray-700">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Badges de status -->
<div class="flex flex-wrap gap-2 mb-4">
    <a href="instagram_leads.php<?= $filtroFonte ? '?fonte=' . e($filtroFonte) : '' ?>"
       class="badge text-xs <?= !$filtroStatus ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?> cursor-pointer transition-colors">
        Todos <span class="ml-1 opacity-70"><?= $totalGeral ?></span>
    </a>
    <?php
    $statusLabels = ['novo' => ['Novos', 'bg-blue-100 text-blue-700', 'bg-blue-600 text-white'],
                     'contatado' => ['Contatados', 'bg-yellow-100 text-yellow-700', 'bg-yellow-500 text-white'],
                     'convertido' => ['Convertidos', 'bg-green-100 text-green-700', 'bg-green-600 text-white'],
                     'descartado' => ['Descartados', 'bg-gray-100 text-gray-500', 'bg-gray-600 text-white']];
    foreach ($statusLabels as $sk => [$sl, $sinactive, $sactive]):
        $qs = http_build_query(array_filter(['status' => $sk, 'fonte' => $filtroFonte, 'busca' => $busca]));
    ?>
    <a href="instagram_leads.php?<?= $qs ?>"
       class="badge text-xs <?= $filtroStatus === $sk ? $sactive : $sinactive ?> cursor-pointer transition-colors">
        <?= $sl ?> <span class="ml-1 opacity-70"><?= $contadores[$sk] ?></span>
    </a>
    <?php endforeach; ?>

    <span class="mx-2 border-l border-gray-200"></span>

    <!-- Filtro por fonte -->
    <?php
    $fonteLabels = ['lead_ad' => 'Lead Ads', 'direct_message' => 'Direct'];
    foreach ($fonteLabels as $fk => $fl):
        $qs = http_build_query(array_filter(['status' => $filtroStatus, 'fonte' => $fk, 'busca' => $busca]));
    ?>
    <a href="instagram_leads.php?<?= $qs ?>"
       class="badge text-xs <?= $filtroFonte === $fk ? 'bg-pink-600 text-white' : 'bg-pink-50 text-pink-600 hover:bg-pink-100' ?> cursor-pointer transition-colors">
        <?= $fl ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Tabela de leads -->
<div class="bg-white rounded-xl border shadow-sm overflow-hidden">
    <?php if (empty($leads)): ?>
    <div class="text-center py-12">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
        </svg>
        <p class="text-sm text-gray-500 font-medium">Nenhum lead encontrado</p>
        <p class="text-xs text-gray-400 mt-1">Os leads do Instagram aparecerão aqui automaticamente.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50 border-b">
                    <th class="px-4 py-3 text-left">Nome</th>
                    <th class="px-4 py-3 text-left">Fonte</th>
                    <th class="px-4 py-3 text-left hidden sm:table-cell">Contato</th>
                    <th class="px-4 py-3 text-left hidden md:table-cell">Campanha / Mensagem</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-right">Data</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($leads as $l):
                $statusColors = [
                    'novo'       => 'bg-blue-100 text-blue-700',
                    'contatado'  => 'bg-yellow-100 text-yellow-700',
                    'convertido' => 'bg-green-100 text-green-700',
                    'descartado' => 'bg-gray-100 text-gray-500',
                ];
            ?>
            <tr class="hover:bg-purple-50/50 transition-colors group">
                <td class="px-4 py-3">
                    <div>
                        <p class="font-semibold text-gray-800 text-sm"><?= e($l['nome'] ?? $l['ig_username'] ?? 'Sem nome') ?></p>
                        <?php if ($l['ig_username']): ?>
                        <p class="text-xs text-pink-500">@<?= e($l['ig_username']) ?></p>
                        <?php endif; ?>
                        <?php if ($l['empresa']): ?>
                        <p class="text-xs text-gray-400"><?= e($l['empresa']) ?></p>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-4 py-3">
                    <?php if ($l['fonte'] === 'lead_ad'): ?>
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 font-medium">Lead Ad</span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-pink-50 text-pink-600 font-medium">Direct</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden sm:table-cell">
                    <?php if ($l['telefone']): ?>
                        <a href="tel:<?= e($l['telefone']) ?>" class="text-xs text-blue-600 hover:underline block"><?= e($l['telefone']) ?></a>
                    <?php endif; ?>
                    <?php if ($l['email']): ?>
                        <a href="mailto:<?= e($l['email']) ?>" class="text-xs text-blue-600 hover:underline block truncate max-w-[180px]"><?= e($l['email']) ?></a>
                    <?php endif; ?>
                    <?php if (!$l['telefone'] && !$l['email']): ?>
                        <span class="text-xs text-gray-400">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                    <?php if ($l['ad_name']): ?>
                        <p class="text-xs text-gray-600 truncate max-w-[200px]" title="<?= e($l['ad_name']) ?>"><?= e($l['ad_name']) ?></p>
                    <?php elseif ($l['mensagem']): ?>
                        <p class="text-xs text-gray-500 italic truncate max-w-[200px]" title="<?= e($l['mensagem']) ?>">"<?= e(mb_substr($l['mensagem'], 0, 60)) ?><?= mb_strlen($l['mensagem']) > 60 ? '...' : '' ?>"</p>
                    <?php else: ?>
                        <span class="text-xs text-gray-400">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <?php if ($l['status'] === 'convertido' && $l['cliente_nome']): ?>
                        <span class="badge text-xs bg-green-100 text-green-700">Convertido</span>
                        <p class="text-[10px] text-green-600 mt-0.5">&rarr; <?= e($l['cliente_nome']) ?></p>
                    <?php else: ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <?= $hiddenFields ?>
                            <select name="novo_status" onchange="this.form.submit()"
                                    class="text-xs border rounded px-1.5 py-0.5 <?= $statusColors[$l['status']] ?? '' ?>">
                                <?php foreach (['novo','contatado','convertido','descartado'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $l['status'] === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <?php if ($l['contatado_por']): ?>
                        <p class="text-[10px] text-gray-400 mt-0.5"><?= e($l['contatado_por']) ?> · <?= $l['contatado_em'] ? date('d/m H:i', strtotime($l['contatado_em'])) : '' ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right">
                    <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($l['criado_em'])) ?></p>
                    <p class="text-[10px] text-gray-400"><?= date('H:i', strtotime($l['criado_em'])) ?></p>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <?php if ($l['status'] !== 'convertido'): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Converter este lead em cliente?')">
                            <input type="hidden" name="action" value="converter">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <?= $hiddenFields ?>
                            <button type="submit" class="p-1.5 rounded-lg text-green-600 hover:bg-green-50 transition-colors" title="Converter em cliente">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                </svg>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (isMaster()): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Excluir este lead?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <?= $hiddenFields ?>
                            <button type="submit" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 transition-colors" title="Excluir">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($paginas > 1): ?>
    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            Mostrando <?= $offset + 1 ?> - <?= min($offset + $porPagina, $total) ?> de <?= $total ?>
        </p>
        <div class="flex gap-1">
            <?php for ($p = 1; $p <= $paginas; $p++):
                $pgQs = http_build_query(array_filter(['status' => $filtroStatus, 'fonte' => $filtroFonte, 'busca' => $busca, 'pg' => $p > 1 ? $p : null]));
            ?>
            <a href="instagram_leads.php<?= $pgQs ? "?$pgQs" : '' ?>"
               class="px-2.5 py-1 rounded text-xs font-medium <?= $p === $pagina ? 'bg-purple-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-100' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
