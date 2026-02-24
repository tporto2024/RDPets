<?php
$pageTitle   = 'Configurações';
$currentPage = 'configuracoes';
require_once __DIR__ . '/_nav.php';
requireMaster();

$msg   = '';
$erro  = '';
$action = $_POST['action'] ?? '';

// ══════════════════════════════════════════════════════
//  TIPOS DE NEGOCIAÇÃO
// ══════════════════════════════════════════════════════

if ($action === 'add_tipo') {
    $nome = trim($_POST['nome'] ?? '');
    $desc = trim($_POST['descricao'] ?? '');
    if ($nome === '') { $erro = 'Informe o nome do tipo.'; }
    else {
        try {
            $pdo->prepare("INSERT INTO neg_tipos (nome, descricao) VALUES (?,?)")->execute([$nome, $desc ?: null]);
            $msg = "Tipo \"$nome\" criado com sucesso.";
            logActivity('Configurações', 'Criou tipo de negociação', $nome);
        } catch (PDOException $ex) {
            $erro = str_contains($ex->getMessage(), 'Duplicate') ? "Já existe um tipo com esse nome." : $ex->getMessage();
        }
    }
}

if ($action === 'edit_tipo') {
    $id   = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $desc = trim($_POST['descricao'] ?? '');
    if ($id && $nome) {
        try {
            $pdo->prepare("UPDATE neg_tipos SET nome=?, descricao=? WHERE id=?")->execute([$nome, $desc ?: null, $id]);
            $msg = "Tipo atualizado.";
            logActivity('Configurações', 'Editou tipo de negociação', $nome);
        } catch (PDOException $ex) {
            $erro = str_contains($ex->getMessage(), 'Duplicate') ? "Já existe um tipo com esse nome." : $ex->getMessage();
        }
    }
}

if ($action === 'delete_tipo') {
    $id = (int)($_POST['id'] ?? 0);
    $qtd = (int)$pdo->prepare("SELECT COUNT(*) FROM negociacoes WHERE tipo_id=?")->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM negociacoes WHERE tipo_id=$id")->fetchColumn() : 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM negociacoes WHERE tipo_id=?");
    $stmt->execute([$id]);
    $qtd = (int)$stmt->fetchColumn();
    if ($qtd > 0) {
        $erro = "Não é possível excluir: $qtd negociação(ões) usam este tipo.";
    } else {
        $nomeStmt = $pdo->prepare("SELECT nome FROM neg_tipos WHERE id=?");
        $nomeStmt->execute([$id]);
        $nomeTipo = $nomeStmt->fetchColumn() ?: "ID $id";
        $pdo->prepare("DELETE FROM neg_tipos WHERE id=?")->execute([$id]);
        $msg = "Tipo excluído.";
        logActivity('Configurações', 'Excluiu tipo de negociação', $nomeTipo);
    }
}

// ══════════════════════════════════════════════════════
//  ETAPAS DO KANBAN
// ══════════════════════════════════════════════════════

if ($action === 'add_etapa') {
    $nome  = trim($_POST['nome'] ?? '');
    $cor   = $_POST['cor'] ?? 'cinza';
    $enc   = isset($_POST['is_encerrada']) ? 1 : 0;
    $ganho = isset($_POST['is_ganho'])     ? 1 : 0;
    if (!array_key_exists($cor, ETAPA_CORES)) $cor = 'cinza';
    if ($nome === '') { $erro = 'Informe o nome da etapa.'; }
    else {
        try {
            $maxOrdem = (int)$pdo->query("SELECT COALESCE(MAX(ordem),0) FROM neg_etapas")->fetchColumn();
            $pdo->prepare("INSERT INTO neg_etapas (nome,cor,ordem,is_encerrada,is_ganho) VALUES (?,?,?,?,?)")
                ->execute([$nome, $cor, $maxOrdem + 1, $enc, $ganho]);
            $msg = "Etapa \"$nome\" criada.";
            logActivity('Configurações', 'Criou etapa do Kanban', $nome);
        } catch (PDOException $ex) {
            $erro = str_contains($ex->getMessage(), 'Duplicate') ? "Já existe uma etapa com esse nome." : $ex->getMessage();
        }
    }
}

if ($action === 'edit_etapa') {
    $id    = (int)($_POST['id'] ?? 0);
    $nome  = trim($_POST['nome'] ?? '');
    $cor   = $_POST['cor'] ?? 'cinza';
    $enc   = isset($_POST['is_encerrada']) ? 1 : 0;
    $ganho = isset($_POST['is_ganho'])     ? 1 : 0;
    if (!array_key_exists($cor, ETAPA_CORES)) $cor = 'cinza';
    if ($id && $nome) {
        try {
            $pdo->prepare("UPDATE neg_etapas SET nome=?,cor=?,is_encerrada=?,is_ganho=? WHERE id=?")
                ->execute([$nome, $cor, $enc, $ganho, $id]);
            $msg = "Etapa atualizada.";
            logActivity('Configurações', 'Editou etapa do Kanban', $nome);
        } catch (PDOException $ex) {
            $erro = str_contains($ex->getMessage(), 'Duplicate') ? "Já existe uma etapa com esse nome." : $ex->getMessage();
        }
    }
}

if ($action === 'delete_etapa') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM negociacoes n JOIN neg_etapas e ON e.nome=n.etapa WHERE e.id=?");
    $stmt->execute([$id]);
    $qtd = (int)$stmt->fetchColumn();
    if ($qtd > 0) {
        $erro = "Não é possível excluir: $qtd negociação(ões) estão nessa etapa.";
    } else {
        $nomeStmt = $pdo->prepare("SELECT nome FROM neg_etapas WHERE id=?");
        $nomeStmt->execute([$id]);
        $nomeEtapa = $nomeStmt->fetchColumn() ?: "ID $id";
        $pdo->prepare("DELETE FROM neg_etapas WHERE id=?")->execute([$id]);
        $msg = "Etapa excluída.";
        logActivity('Configurações', 'Excluiu etapa do Kanban', $nomeEtapa);
    }
}

logActivity('Configurações', 'Visualizou configurações');

// ── Carregar dados ────────────────────────────────────────────────────────────
$tipos  = getTipos();
$etapas = getEtapas();

// ── Log de atividades ─────────────────────────────────────────────────────────
$logData    = [];
$logTotal   = 0;
$logPage    = max(1, (int)($_GET['lp'] ?? 1));
$logPerPage = 50;
$logOffset  = ($logPage - 1) * $logPerPage;
$logUsuario = (int)($_GET['lu'] ?? 0);
$logData_de = $_GET['ld'] ?? '';
$logData_ate= $_GET['la'] ?? '';
$logPagina  = trim($_GET['lpg'] ?? '');

$logWhere  = ['1=1'];
$logParams = [];
if ($logUsuario) { $logWhere[] = 'usuario_id = ?'; $logParams[] = $logUsuario; }
if ($logData_de)  { $logWhere[] = 'DATE(criado_em) >= ?'; $logParams[] = $logData_de; }
if ($logData_ate) { $logWhere[] = 'DATE(criado_em) <= ?'; $logParams[] = $logData_ate; }
if ($logPagina)   { $logWhere[] = 'pagina = ?'; $logParams[] = $logPagina; }

$logWhereStr = implode(' AND ', $logWhere);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios_log WHERE $logWhereStr");
$countStmt->execute($logParams);
$logTotal = (int)$countStmt->fetchColumn();
$logPages = max(1, (int)ceil($logTotal / $logPerPage));

$logStmt = $pdo->prepare(
    "SELECT * FROM usuarios_log WHERE $logWhereStr ORDER BY criado_em DESC LIMIT $logPerPage OFFSET $logOffset"
);
$logStmt->execute($logParams);
$logData = $logStmt->fetchAll();

$todosUsuarios = $pdo->query("SELECT DISTINCT usuario_id, usuario_nome FROM usuarios_log ORDER BY usuario_nome")->fetchAll();
$todasPaginas  = $pdo->query("SELECT DISTINCT pagina FROM usuarios_log ORDER BY pagina")->fetchAll(PDO::FETCH_COLUMN);

// Qtd de negociações por tipo
$qtdPorTipo = [];
foreach ($pdo->query("SELECT tipo_id, COUNT(*) AS qtd FROM negociacoes GROUP BY tipo_id") as $r) {
    $qtdPorTipo[$r['tipo_id']] = $r['qtd'];
}
// Qtd de negociações por etapa
$qtdPorEtapa = [];
foreach ($pdo->query("SELECT e.id, COUNT(n.id) AS qtd FROM neg_etapas e LEFT JOIN negociacoes n ON n.etapa=e.nome GROUP BY e.id") as $r) {
    $qtdPorEtapa[$r['id']] = $r['qtd'];
}

$abaAtiva = $_GET['aba'] ?? 'tipos';
?>

<!-- Flash messages -->
<?php if ($msg): ?>
<div id="flash-ok" class="mb-4 bg-green-50 border border-green-200 text-green-700 rounded-xl px-4 py-3 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <?= e($msg) ?>
</div>
<?php elseif ($erro): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
    </svg>
    <?= e($erro) ?>
</div>
<?php endif; ?>

<!-- Abas -->
<div class="flex gap-1 bg-gray-100 rounded-xl p-1 mb-6 w-fit">
    <button onclick="setAba('tipos')" id="tab-tipos"
            class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold transition-all">
        🏷️ Tipos de Negociação
    </button>
    <button onclick="setAba('etapas')" id="tab-etapas"
            class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold transition-all">
        📋 Etapas do Kanban
    </button>
    <button onclick="setAba('log')" id="tab-log"
            class="tab-btn px-5 py-2 rounded-lg text-sm font-semibold transition-all">
        📊 Log de Atividades
    </button>
</div>

<!-- ══════════════════════════════════════════════════════ -->
<!--  ABA: TIPOS                                            -->
<!-- ══════════════════════════════════════════════════════ -->
<div id="painel-tipos">
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-bold text-gray-800">Tipos de Negociação</h2>
                <p class="text-xs text-gray-400 mt-0.5">Classifique suas negociações por tipo (ex: Venda Direta, Revenda, Parceria)</p>
            </div>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="px-6 py-3 text-left">Nome</th>
                    <th class="px-6 py-3 text-left">Descrição</th>
                    <th class="px-6 py-3 text-center">Negociações</th>
                    <th class="px-6 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody id="tbody-tipos">
            <?php if (empty($tipos)): ?>
                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">Nenhum tipo cadastrado ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($tipos as $t): $qtd = $qtdPorTipo[$t['id']] ?? 0; ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50 group" id="tipo-row-<?= $t['id'] ?>">
                <!-- Modo visualização -->
                <td class="px-6 py-3 font-medium text-gray-800 view-mode"><?= e($t['nome']) ?></td>
                <td class="px-6 py-3 text-gray-500 view-mode"><?= e($t['descricao'] ?? '—') ?></td>
                <td class="px-6 py-3 text-center view-mode">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
                        <?= $qtd > 0 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500' ?>">
                        <?= $qtd ?>
                    </span>
                </td>
                <td class="px-6 py-3 text-right view-mode">
                    <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onclick="editTipo(<?= $t['id'] ?>, '<?= addslashes(e($t['nome'])) ?>', '<?= addslashes(e($t['descricao'] ?? '')) ?>')"
                                class="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded hover:bg-blue-50">✏️ Editar</button>
                        <?php if ($qtd === 0): ?>
                        <form method="POST" onsubmit="return confirm('Excluir este tipo?')">
                            <input type="hidden" name="action" value="delete_tipo">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded hover:bg-red-50">🗑️ Excluir</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-300 px-2 py-1">🔒 Em uso</span>
                        <?php endif; ?>
                    </div>
                </td>
                <!-- Modo edição (hidden) -->
                <td colspan="4" class="px-6 py-3 edit-mode hidden">
                    <form method="POST" class="flex items-center gap-3">
                        <input type="hidden" name="action" value="edit_tipo">
                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <input type="text" name="nome" required placeholder="Nome"
                               class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-44"
                               id="edit-tipo-nome-<?= $t['id'] ?>">
                        <input type="text" name="descricao" placeholder="Descrição (opcional)"
                               class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 flex-1"
                               id="edit-tipo-desc-<?= $t['id'] ?>">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-4 py-1.5 rounded-lg">Salvar</button>
                        <button type="button" onclick="cancelEditTipo(<?= $t['id'] ?>)" class="text-xs text-gray-500 hover:text-gray-700 px-2 py-1.5">Cancelar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Formulário de adição -->
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">Adicionar novo tipo</p>
            <form method="POST" class="flex items-end gap-3">
                <input type="hidden" name="action" value="add_tipo">
                <div class="flex-1">
                    <label class="block text-xs text-gray-600 mb-1">Nome *</label>
                    <input type="text" name="nome" required placeholder="Ex: Venda Direta"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-gray-600 mb-1">Descrição</label>
                    <input type="text" name="descricao" placeholder="Opcional"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Adicionar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════ -->
<!--  ABA: ETAPAS                                          -->
<!-- ══════════════════════════════════════════════════════ -->
<div id="painel-etapas" class="hidden">
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-sm font-bold text-gray-800">Etapas do Kanban</h2>
            <p class="text-xs text-gray-400 mt-0.5">Arraste para reordenar. As colunas do Kanban seguem esta ordem.</p>
        </div>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="px-4 py-3 text-center w-10">☰</th>
                    <th class="px-4 py-3 text-left">Nome</th>
                    <th class="px-4 py-3 text-center">Cor</th>
                    <th class="px-4 py-3 text-center">Encerra Neg.</th>
                    <th class="px-4 py-3 text-center">Conta como Ganho</th>
                    <th class="px-4 py-3 text-center">Negociações</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody id="sortable-etapas">
            <?php foreach ($etapas as $et):
                $cor  = ETAPA_CORES[$et['cor']] ?? ETAPA_CORES['cinza'];
                $qtd  = $qtdPorEtapa[$et['id']] ?? 0;
            ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50 group" data-id="<?= $et['id'] ?>">
                <td class="px-4 py-3 text-center text-gray-300 cursor-grab drag-handle select-none">⠿</td>
                <td class="px-4 py-3 font-semibold text-gray-800"><?= e($et['nome']) ?></td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium <?= $cor['header'] ?>">
                        <span class="w-2 h-2 rounded-full <?= $cor['dot'] ?>"></span>
                        <?= e(ETAPA_CORES[$et['cor']]['label'] ?? $et['cor']) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <?= $et['is_encerrada'] ? '<span class="text-orange-600 font-semibold text-xs">✓ Sim</span>' : '<span class="text-gray-300 text-xs">—</span>' ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?= $et['is_ganho'] ? '<span class="text-green-600 font-semibold text-xs">✓ Sim</span>' : '<span class="text-gray-300 text-xs">—</span>' ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
                        <?= $qtd > 0 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500' ?>">
                        <?= $qtd ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onclick="openEditEtapa(<?= htmlspecialchars(json_encode($et), ENT_QUOTES) ?>)"
                                class="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded hover:bg-blue-50">✏️ Editar</button>
                        <?php if ($qtd === 0): ?>
                        <form method="POST" onsubmit="return confirm('Excluir a etapa \'<?= e($et['nome']) ?>\'?')">
                            <input type="hidden" name="action" value="delete_etapa">
                            <input type="hidden" name="id" value="<?= $et['id'] ?>">
                            <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded hover:bg-red-50">🗑️ Excluir</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-300 px-2 py-1">🔒 Em uso</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Formulário adição de etapa -->
        <div class="px-6 py-5 bg-gray-50 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-500 mb-4 uppercase tracking-wide">Adicionar nova etapa</p>
            <form method="POST" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="action" value="add_etapa">

                <div class="w-52">
                    <label class="block text-xs text-gray-600 mb-1">Nome *</label>
                    <input type="text" name="nome" required placeholder="Ex: Em análise"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-2">Cor *</label>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (ETAPA_CORES as $key => $c): ?>
                        <label class="cursor-pointer" title="<?= e($c['label']) ?>">
                            <input type="radio" name="cor" value="<?= $key ?>" class="sr-only cor-radio"
                                   <?= $key === 'cinza' ? 'checked' : '' ?>>
                            <span class="cor-swatch inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border-2 border-transparent transition-all <?= $c['header'] ?>">
                                <span class="w-2 h-2 rounded-full <?= $c['dot'] ?>"></span>
                                <?= e($c['label']) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_encerrada" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-xs text-gray-600">Encerra a negociação</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_ganho" class="w-4 h-4 text-blue-600 rounded">
                        <span class="text-xs text-gray-600">Conta como ganho (vendido)</span>
                    </label>
                </div>

                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Adicionar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════ -->
<!--  ABA: LOG DE ATIVIDADES                                -->
<!-- ══════════════════════════════════════════════════════ -->
<div id="painel-log" class="hidden">

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-200 px-6 py-4 mb-4">
        <input type="hidden" name="aba" value="log">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Usuário</label>
                <select name="lu" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todos</option>
                    <?php foreach ($todosUsuarios as $tu): ?>
                    <option value="<?= $tu['usuario_id'] ?>" <?= $logUsuario == $tu['usuario_id'] ? 'selected' : '' ?>>
                        <?= e($tu['usuario_nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Página</label>
                <select name="lpg" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todas</option>
                    <?php foreach ($todasPaginas as $tp): ?>
                    <option value="<?= e($tp) ?>" <?= $logPagina === $tp ? 'selected' : '' ?>><?= e($tp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">De</label>
                <input type="date" name="ld" value="<?= e($logData_de) ?>"
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Até</label>
                <input type="date" name="la" value="<?= e($logData_ate) ?>"
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
                </svg>
                Filtrar
            </button>
            <?php if ($logUsuario || $logData_de || $logData_ate || $logPagina): ?>
            <a href="?aba=log" class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2">Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Tabela -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-bold text-gray-800">Log de Atividades</h2>
                <p class="text-xs text-gray-400 mt-0.5"><?= number_format($logTotal) ?> registro(s) encontrado(s)</p>
            </div>
        </div>

        <?php if (empty($logData)): ?>
        <div class="px-6 py-12 text-center text-gray-400 text-sm">
            Nenhuma atividade registrada ainda.
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="px-4 py-3 text-left">Data/Hora</th>
                    <th class="px-4 py-3 text-left">Usuário</th>
                    <th class="px-4 py-3 text-left">Página</th>
                    <th class="px-4 py-3 text-left">Ação</th>
                    <th class="px-4 py-3 text-left">Detalhes</th>
                    <th class="px-4 py-3 text-left">IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logData as $log): ?>
            <tr class="border-b border-gray-50 hover:bg-gray-50">
                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap text-xs">
                    <?= date('d/m/Y H:i:s', strtotime($log['criado_em'])) ?>
                </td>
                <td class="px-4 py-2.5 font-medium text-gray-800 whitespace-nowrap">
                    <?= e($log['usuario_nome']) ?>
                </td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        <?= e($log['pagina']) ?>
                    </span>
                </td>
                <td class="px-4 py-2.5 text-gray-700"><?= e($log['acao']) ?></td>
                <td class="px-4 py-2.5 text-gray-500 max-w-xs truncate" title="<?= e($log['detalhes'] ?? '') ?>">
                    <?= e($log['detalhes'] ?? '—') ?>
                </td>
                <td class="px-4 py-2.5 text-gray-400 text-xs font-mono whitespace-nowrap">
                    <?= e($log['ip'] ?? '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Paginação -->
        <?php if ($logPages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
            <span>Página <?= $logPage ?> de <?= $logPages ?></span>
            <div class="flex gap-2">
                <?php
                $logQueryBase = array_filter([
                    'aba' => 'log', 'lu' => $logUsuario ?: null, 'lpg' => $logPagina ?: null,
                    'ld' => $logData_de ?: null, 'la' => $logData_ate ?: null
                ]);
                ?>
                <?php if ($logPage > 1): ?>
                <a href="?<?= http_build_query(array_merge($logQueryBase, ['lp' => $logPage - 1])) ?>"
                   class="px-3 py-1 border border-gray-200 rounded-lg hover:bg-gray-50">← Anterior</a>
                <?php endif; ?>
                <?php if ($logPage < $logPages): ?>
                <a href="?<?= http_build_query(array_merge($logQueryBase, ['lp' => $logPage + 1])) ?>"
                   class="px-3 py-1 border border-gray-200 rounded-lg hover:bg-gray-50">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════ -->
<!--  MODAL: Editar Etapa                                   -->
<!-- ══════════════════════════════════════════════════════ -->
<div id="modal-etapa" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-base font-bold text-gray-800">Editar Etapa</h3>
            <button onclick="closeModalEtapa()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_etapa">
            <input type="hidden" name="id" id="modal-etapa-id">

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Nome *</label>
                <input type="text" name="nome" id="modal-etapa-nome" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-2">Cor *</label>
                <div class="flex flex-wrap gap-2" id="modal-cor-picker">
                    <?php foreach (ETAPA_CORES as $key => $c): ?>
                    <label class="cursor-pointer" title="<?= e($c['label']) ?>">
                        <input type="radio" name="cor" value="<?= $key ?>" class="sr-only modal-cor-radio">
                        <span class="modal-cor-swatch inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border-2 border-transparent transition-all <?= $c['header'] ?>">
                            <span class="w-2 h-2 rounded-full <?= $c['dot'] ?>"></span>
                            <?= e($c['label']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex gap-6">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_encerrada" id="modal-encerrada" class="w-4 h-4 text-blue-600 rounded">
                    <span class="text-sm text-gray-700">Encerra a negociação</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_ganho" id="modal-ganho" class="w-4 h-4 text-blue-600 rounded">
                    <span class="text-sm text-gray-700">Conta como ganho</span>
                </label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-2.5 text-sm">Salvar alterações</button>
                <button type="button" onclick="closeModalEtapa()" class="px-5 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Abas ─────────────────────────────────────────────────────────────────────
const ABA_INICIAL = '<?= e($abaAtiva) ?>';

function setAba(aba) {
    ['tipos', 'etapas', 'log'].forEach(p => {
        document.getElementById('painel-' + p).classList.toggle('hidden', aba !== p);
    });
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('bg-white', 'shadow-sm', 'text-gray-800');
        b.classList.add('text-gray-500');
    });
    const active = document.getElementById('tab-' + aba);
    active.classList.add('bg-white', 'shadow-sm', 'text-gray-800');
    active.classList.remove('text-gray-500');
    history.replaceState(null,'','?aba='+aba);
}
setAba(ABA_INICIAL);

// ── Edição inline de Tipos ────────────────────────────────────────────────────
function editTipo(id, nome, desc) {
    const row = document.getElementById('tipo-row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.classList.add('hidden'));
    row.querySelector('.edit-mode').classList.remove('hidden');
    document.getElementById('edit-tipo-nome-' + id).value = nome;
    document.getElementById('edit-tipo-desc-' + id).value = desc;
}
function cancelEditTipo(id) {
    const row = document.getElementById('tipo-row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.classList.remove('hidden'));
    row.querySelector('.edit-mode').classList.add('hidden');
}

// ── Cor swatches (formulário adição) ─────────────────────────────────────────
document.querySelectorAll('.cor-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.cor-swatch').forEach(s => s.classList.remove('border-gray-700', 'ring-2', 'ring-offset-1', 'ring-gray-400'));
        if (radio.checked) radio.nextElementSibling.classList.add('border-gray-700', 'ring-2', 'ring-offset-1', 'ring-gray-400');
    });
});
// Marcar inicial
document.querySelector('.cor-radio:checked')?.dispatchEvent(new Event('change'));

// ── Modal editar Etapa ────────────────────────────────────────────────────────
function openEditEtapa(et) {
    document.getElementById('modal-etapa-id').value   = et.id;
    document.getElementById('modal-etapa-nome').value = et.nome;
    document.getElementById('modal-encerrada').checked = et.is_encerrada == 1;
    document.getElementById('modal-ganho').checked     = et.is_ganho == 1;

    document.querySelectorAll('.modal-cor-radio').forEach(r => {
        r.checked = (r.value === et.cor);
        r.nextElementSibling.classList.remove('border-gray-700','ring-2','ring-offset-1','ring-gray-400');
        if (r.checked) r.nextElementSibling.classList.add('border-gray-700','ring-2','ring-offset-1','ring-gray-400');
    });
    document.querySelectorAll('.modal-cor-radio').forEach(r => {
        r.addEventListener('change', () => {
            document.querySelectorAll('.modal-cor-swatch').forEach(s => s.classList.remove('border-gray-700','ring-2','ring-offset-1','ring-gray-400'));
            if (r.checked) r.nextElementSibling.classList.add('border-gray-700','ring-2','ring-offset-1','ring-gray-400');
        });
    });

    document.getElementById('modal-etapa').classList.remove('hidden');
}
function closeModalEtapa() {
    document.getElementById('modal-etapa').classList.add('hidden');
}
document.getElementById('modal-etapa').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModalEtapa();
});

// ── Reordenar etapas com SortableJS ──────────────────────────────────────────
const tbody = document.getElementById('sortable-etapas');
if (tbody && typeof Sortable !== 'undefined') {
    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'opacity-40',
        onEnd() {
            const ids = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({action:'reorder_etapas', ids})
            });
        }
    });
}

// Auto-esconder flash
setTimeout(() => { const f = document.getElementById('flash-ok'); if (f) f.remove(); }, 4000);
</script>

<?php require_once '_footer.php'; ?>
