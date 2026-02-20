<?php
$pageTitle   = 'Clientes';
$currentPage = 'clientes';
require_once __DIR__ . '/_nav.php';

// ── Ações ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
    }
    header('Location: clientes.php?deleted=1');
    exit;
}

// ── Busca e filtros ───────────────────────────────────────────────────────────
$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = $q ? 'WHERE (c.nome LIKE ? OR c.empresa LIKE ? OR c.email LIKE ? OR c.telefone LIKE ?)' : '';
$params = $q ? ["%$q%", "%$q%", "%$q%", "%$q%"] : [];

$total = $pdo->prepare("SELECT COUNT(*) FROM clientes c $where");
$total->execute($params);
$total = (int)$total->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT c.*, n.etapa, n.valor
    FROM clientes c
    LEFT JOIN negociacoes n ON n.cliente_id = c.id
    $where
    ORDER BY c.criado_em DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// Cores das etapas – dinâmico pelo banco
$etapasDB  = getEtapas();
$etapaCoresDB = [];
foreach ($etapasDB as $et) {
    $cor = ETAPA_CORES[$et['cor']] ?? ETAPA_CORES['cinza'];
    $etapaCoresDB[$et['nome']] = $cor['header'];
}
?>

<!-- Toolbar -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <form method="GET" class="flex gap-2 flex-1 max-w-sm">
        <input type="text" name="q" value="<?= e($q) ?>"
               placeholder="Buscar por nome, empresa, e-mail..."
               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">
            Buscar
        </button>
        <?php if ($q): ?>
        <a href="clientes.php" class="border border-gray-300 text-gray-600 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">✕</a>
        <?php endif; ?>
    </form>

    <div class="flex items-center gap-2">
        <!-- Importar Planilha -->
        <a href="importar.php"
           class="border border-green-500 text-green-700 hover:bg-green-50 text-sm font-medium px-4 py-2 rounded-lg flex items-center gap-2 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Importar Planilha
        </a>

        <!-- Novo Cliente -->
        <a href="cliente_form.php"
           class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Cliente
        </a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 mb-4 text-sm">
    Cliente excluído com sucesso.
</div>
<?php endif; ?>

<?php if (isset($_GET['importado'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 mb-4 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <?= (int)$_GET['importado'] ?> cliente(s) importado(s) com sucesso!
</div>
<?php endif; ?>

<!-- Tabela -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 text-xs text-gray-500">
        <?= $total ?> cliente(s) encontrado(s)
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Nome</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Empresa</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Telefone</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">E-mail</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Negociação</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Cadastro</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($clientes as $c): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3 font-medium text-gray-800"><?= e($c['nome']) ?></td>
                    <td class="px-5 py-3 text-gray-600"><?= e($c['empresa'] ?? '-') ?></td>
                    <td class="px-5 py-3 text-gray-600"><?= e($c['telefone'] ?? '-') ?></td>
                    <td class="px-5 py-3 text-gray-600">
                        <?php if ($c['email']): ?>
                        <a href="mailto:<?= e($c['email']) ?>" class="hover:text-blue-600"><?= e($c['email']) ?></a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <?php if ($c['etapa']): ?>
                        <span class="badge <?= $etapaCoresDB[$c['etapa']] ?? 'bg-gray-100 text-gray-700' ?>">
                            <?= e($c['etapa']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">Sem negociação</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-gray-500 text-xs"><?= date('d/m/Y', strtotime($c['criado_em'])) ?></td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2 justify-end">
                            <a href="cliente_form.php?id=<?= $c['id'] ?>"
                               class="text-blue-600 hover:text-blue-800 text-xs font-medium">Editar</a>
                            <form method="POST" onsubmit="return confirm('Excluir este cliente?')">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($clientes)): ?>
                <tr><td colspan="7" class="px-5 py-10 text-center text-gray-400">Nenhum cliente encontrado</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($pages > 1): ?>
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <div class="flex gap-1">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="?p=<?= $i ?>&q=<?= urlencode($q) ?>"
               class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-100' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
