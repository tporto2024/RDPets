<?php
$pageTitle   = 'Negociações — Kanban';
$currentPage = 'negociacoes';
require_once __DIR__ . '/_nav.php';
logActivity('Negociações', 'Visualizou o Kanban');

// ── Etapas dinâmicas do banco ─────────────────────────────────────────────────
$etapasDB = getEtapas();

// ── Perfil do usuário logado ──────────────────────────────────────────────────
$isMaster = ($_SESSION['user_perfil'] ?? '') === 'master';

// ── Usuários para o filtro (apenas master vê todos) ───────────────────────────
$usuarios = $isMaster
    ? $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll()
    : [];

// ── Todos os usuários para o modal (sempre necessário) ───────────────────────
$todosUsuarios = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll();

// ── Negociações ───────────────────────────────────────────────────────────────
if ($isMaster) {
    $stmt = $pdo->query("
        SELECT n.id, n.etapa, n.valor, n.qualificacao, n.criado_em,
               n.responsavel_id,
               c.nome AS cliente, c.empresa, c.id AS cliente_id, c.telefone,
               u.nome AS responsavel
        FROM negociacoes n
        JOIN clientes c ON c.id = n.cliente_id
        LEFT JOIN usuarios u ON u.id = n.responsavel_id
        ORDER BY n.criado_em DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT n.id, n.etapa, n.valor, n.qualificacao, n.criado_em,
               n.responsavel_id,
               c.nome AS cliente, c.empresa, c.id AS cliente_id, c.telefone,
               u.nome AS responsavel
        FROM negociacoes n
        JOIN clientes c ON c.id = n.cliente_id
        LEFT JOIN usuarios u ON u.id = n.responsavel_id
        WHERE n.responsavel_id = :uid
        ORDER BY n.criado_em DESC
    ");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
}
$all = $stmt->fetchAll();

// ── Última tarefa aberta por negociação ───────────────────────────────────────
$ultTarefas = [];
$stmtT = $pdo->query("
    SELECT t.negociacao_id, t.id AS tarefa_id, t.assunto, t.quando, t.status, t.tipo
    FROM tarefas t
    INNER JOIN (
        SELECT negociacao_id, MAX(criado_em) AS mc
        FROM tarefas
        GROUP BY negociacao_id
    ) sub ON t.negociacao_id = sub.negociacao_id AND t.criado_em = sub.mc
");
foreach ($stmtT->fetchAll() as $row) {
    $ultTarefas[$row['negociacao_id']] = $row;
}

// ── Agrupar por etapa ─────────────────────────────────────────────────────────
$byEtapa = [];
foreach ($etapasDB as $et) {
    $byEtapa[$et['nome']] = [];
}
foreach ($all as $neg) {
    if (array_key_exists($neg['etapa'], $byEtapa)) {
        $byEtapa[$neg['etapa']][] = $neg;
    }
}

// ── Total de valor por etapa ──────────────────────────────────────────────────
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

$tipoIcone = [
    'Ligar'    => '📞',
    'Email'    => '✉️',
    'Reunião'  => '🤝',
    'Tarefa'   => '📋',
    'Almoço'   => '🍽️',
    'Visita'   => '📍',
    'WhatsApp' => '💬',
];

function iniciais(string $nome): string {
    $partes = explode(' ', trim($nome));
    $ini = strtoupper(mb_substr($partes[0], 0, 1));
    if (count($partes) > 1) $ini .= strtoupper(mb_substr(end($partes), 0, 1));
    return $ini;
}

$avatarCores = [
    'bg-blue-500','bg-purple-500','bg-green-500','bg-yellow-500',
    'bg-pink-500','bg-indigo-500','bg-red-500','bg-teal-500',
];
?>

<!-- Barra de filtros -->
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <?php if ($isMaster): ?>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Responsável:</span>
        <button type="button" data-uid="0"
                class="filtro-user active flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border transition-all
                       bg-gray-800 text-white border-gray-800">
            Todos
        </button>
        <?php foreach ($usuarios as $i => $usr):
            $cor = $avatarCores[$i % count($avatarCores)];
        ?>
        <button type="button" data-uid="<?= $usr['id'] ?>"
                class="filtro-user flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border border-gray-300 bg-white text-gray-700
                       hover:border-gray-400 transition-all">
            <span class="w-5 h-5 rounded-full <?= $cor ?> text-white flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                <?= iniciais($usr['nome']) ?>
            </span>
            <?= e($usr['nome']) ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="flex items-center gap-2">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Exibindo:</span>
        <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-blue-600 text-white border border-blue-600">
            Minhas negociações
        </span>
    </div>
    <?php endif; ?>

    <div class="flex items-center gap-3">
        <!-- Campo de busca -->
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" id="busca-kanban" placeholder="Buscar cliente, empresa, telefone..."
                   class="w-64 border border-gray-300 rounded-lg pl-9 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                   autocomplete="off">
            <button type="button" id="busca-limpar" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-300 hover:text-gray-500 hidden" title="Limpar busca">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <a href="negociacao_detalhe.php?novo=1"
           class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg flex items-center gap-2 flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nova Negociação
        </a>
    </div>
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
            <span class="col-counter text-xs font-medium opacity-70"
                  data-count="<?= count($itens) ?>"
                  data-total="<?= $total ?>">
                <?= count($itens) ?> · <?= formatMoney($total) ?>
            </span>
        </div>

        <!-- Zona de drop -->
        <div class="kanban-col bg-gray-100 rounded-b-xl flex-1 p-2 space-y-2 min-h-48"
             data-etapa="<?= e($etapa['nome']) ?>">
            <?php foreach ($itens as $neg):
                $dias       = (int)floor((time() - strtotime($neg['criado_em'])) / 86400);
                $telRaw     = preg_replace('/\D/', '', $neg['telefone'] ?? '');
                $waLink     = $telRaw ? 'https://wa.me/55' . $telRaw : null;
                $ultimaTarefa = $ultTarefas[$neg['id']] ?? null;
            ?>
            <div class="kanban-card bg-white rounded-lg border border-gray-200 p-3 shadow-sm cursor-grab hover:shadow-md transition-shadow"
                 data-id="<?= $neg['id'] ?>"
                 data-uid="<?= (int)($neg['responsavel_id'] ?? 0) ?>"
                 data-valor="<?= (float)$neg['valor'] ?>"
                 data-search="<?= e(mb_strtolower($neg['cliente'] . ' ' . ($neg['empresa'] ?? '') . ' ' . ($neg['telefone'] ?? '') . ' ' . ($neg['responsavel'] ?? '') . ' ' . ($neg['qualificacao'] ?? ''))) ?>">
                <a href="negociacao_detalhe.php?id=<?= $neg['id'] ?>" class="block">
                    <div class="flex items-start justify-between gap-1">
                        <p class="text-sm font-semibold text-gray-800 leading-tight"><?= e($neg['cliente']) ?></p>
                        <span class="flex-shrink-0 text-[10px] font-medium text-blue-500 bg-blue-50 rounded-full px-1.5 py-0.5 leading-none" title="Dias desde o cadastro">
                            <?= $dias ?>d
                        </span>
                    </div>
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

                <?php if ($waLink): ?>
                <a href="<?= $waLink ?>" target="_blank" rel="noopener"
                   class="flex items-center gap-1 mt-2 text-xs text-green-600 hover:text-green-700 font-medium"
                   onclick="event.stopPropagation()">
                    <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.122 1.532 5.855L.058 23.625a.75.75 0 00.916.916l5.77-1.474A11.952 11.952 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75a9.71 9.71 0 01-4.953-1.355l-.355-.211-3.676.939.955-3.596-.232-.371A9.712 9.712 0 012.25 12C2.25 6.615 6.615 2.25 12 2.25S21.75 6.615 21.75 12 17.385 21.75 12 21.75z"/>
                    </svg>
                    <?= e($neg['telefone']) ?>
                </a>
                <?php endif; ?>

                <!-- Última tarefa / Botão criar -->
                <div class="mt-2 pt-2 border-t border-gray-100">
                    <?php if ($ultimaTarefa): ?>
                    <a href="negociacao_detalhe.php?id=<?= $neg['id'] ?>" class="flex items-start gap-1.5 group" onclick="event.stopPropagation()">
                        <span class="text-[11px] mt-0.5 flex-shrink-0"><?= $tipoIcone[$ultimaTarefa['tipo']] ?? '📋' ?></span>
                        <div class="min-w-0">
                            <p class="text-[11px] text-gray-600 font-medium leading-tight truncate group-hover:text-blue-600">
                                <?= e($ultimaTarefa['assunto']) ?>
                            </p>
                            <p class="text-[10px] text-gray-400 mt-0.5">
                                <?= date('d/m/Y H:i', strtotime($ultimaTarefa['quando'])) ?>
                            </p>
                        </div>
                        <?php if ($ultimaTarefa['status'] === 'concluida'): ?>
                        <span class="flex-shrink-0 text-[9px] font-semibold bg-green-100 text-green-600 rounded px-1 py-0.5 ml-auto">✓</span>
                        <?php else: ?>
                        <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full bg-orange-400 mt-1 ml-auto flex-shrink-0"></span>
                        <?php endif; ?>
                    </a>
                    <?php else: ?>
                    <button type="button"
                            class="btn-criar-tarefa w-full flex items-center justify-center gap-1 py-1.5 rounded-md border border-dashed border-gray-300 text-[11px] text-gray-400 hover:border-blue-400 hover:text-blue-500 transition-colors"
                            data-neg-id="<?= $neg['id'] ?>"
                            data-neg-cliente="<?= e($neg['cliente']) ?>"
                            data-neg-empresa="<?= e($neg['empresa'] ?? '') ?>"
                            onclick="event.stopPropagation()">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Criar Tarefa
                    </button>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Modal Nova Tarefa (slide-in direita) ───────────────────────────────── -->
<div id="modal-tarefa" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" id="modal-overlay"></div>
    <div id="modal-panel"
         class="absolute right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl flex flex-col transition-transform duration-300 translate-x-full">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b bg-gray-50">
            <div>
                <h2 class="text-base font-bold text-gray-800">Nova Tarefa</h2>
                <p id="modal-cliente-nome" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button id="modal-close" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-200 text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto px-6 py-5">
            <form id="form-tarefa" class="space-y-4">
                <input type="hidden" id="input-neg-id" name="negociacao_id">

                <!-- Tipo -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                    <select name="tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="Tarefa">📋 Tarefa</option>
                        <option value="Ligar">📞 Ligar</option>
                        <option value="Email">✉️ Email</option>
                        <option value="Reunião">🤝 Reunião</option>
                        <option value="WhatsApp">💬 WhatsApp</option>
                        <option value="Almoço">🍽️ Almoço</option>
                        <option value="Visita">📍 Visita</option>
                    </select>
                </div>

                <!-- Assunto -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Assunto <span class="text-red-500">*</span></label>
                    <input type="text" name="assunto" id="input-assunto" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Ex: Ligar para fechar proposta">
                </div>

                <!-- Descrição -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
                    <textarea name="descricao" rows="3"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                              placeholder="Detalhes da tarefa..."></textarea>
                </div>

                <!-- Data e Hora -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Data e Hora <span class="text-red-500">*</span></label>
                    <input type="datetime-local" name="quando" id="input-quando" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <!-- Responsável -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Responsável</label>
                    <select name="responsavel_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($todosUsuarios as $usr): ?>
                        <option value="<?= $usr['id'] ?>"
                            <?= $usr['id'] == ($_SESSION['user_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($usr['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Prioridade -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prioridade</label>
                    <div class="flex gap-2">
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="prioridade" value="baixa" class="sr-only peer">
                            <div class="text-center py-2 rounded-lg border border-gray-300 text-xs font-medium text-gray-600
                                        peer-checked:border-green-500 peer-checked:bg-green-50 peer-checked:text-green-700 transition-colors">
                                Baixa
                            </div>
                        </label>
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="prioridade" value="media" checked class="sr-only peer">
                            <div class="text-center py-2 rounded-lg border border-gray-300 text-xs font-medium text-gray-600
                                        peer-checked:border-yellow-500 peer-checked:bg-yellow-50 peer-checked:text-yellow-700 transition-colors">
                                Média
                            </div>
                        </label>
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="prioridade" value="alta" class="sr-only peer">
                            <div class="text-center py-2 rounded-lg border border-gray-300 text-xs font-medium text-gray-600
                                        peer-checked:border-red-500 peer-checked:bg-red-50 peer-checked:text-red-700 transition-colors">
                                Alta
                            </div>
                        </label>
                    </div>
                </div>

            </form>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t bg-gray-50 flex gap-3">
            <button id="btn-salvar-tarefa"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Salvar Tarefa
            </button>
            <button id="modal-cancel"
                    class="flex-1 border border-gray-300 hover:bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-medium transition-colors">
                Cancelar
            </button>
        </div>
    </div>
</div>

<script>
// ── Busca no Kanban ─────────────────────────────────────────────────────────
(function() {
    const input   = document.getElementById('busca-kanban');
    const btnLimp = document.getElementById('busca-limpar');
    const allCards = document.querySelectorAll('.kanban-card');

    if (!input) return;

    function formatMoney(val) {
        return 'R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateCountersSearch() {
        document.querySelectorAll('.kanban-col').forEach(col => {
            let count = 0, total = 0;
            col.querySelectorAll('.kanban-card').forEach(card => {
                if (card.style.display !== 'none') {
                    count++;
                    total += parseFloat(card.dataset.valor) || 0;
                }
            });
            const counter = col.closest('.flex-shrink-0').querySelector('.col-counter');
            if (counter) counter.textContent = count + ' · ' + formatMoney(total);
        });
    }

    function aplicarBusca() {
        const termo = input.value.trim().toLowerCase();
        btnLimp.classList.toggle('hidden', !termo);

        allCards.forEach(card => {
            if (!termo) {
                card.style.display = '';
                return;
            }
            const searchText = card.dataset.search || '';
            card.style.display = searchText.includes(termo) ? '' : 'none';
        });
        updateCountersSearch();
    }

    input.addEventListener('input', aplicarBusca);
    btnLimp.addEventListener('click', () => {
        input.value = '';
        aplicarBusca();
        input.focus();
    });

    // Atalho Ctrl+K para focar na busca
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape' && document.activeElement === input) {
            input.value = '';
            aplicarBusca();
            input.blur();
        }
    });
})();

// ── Filtro por etapa vindo do Dashboard ──────────────────────────────────────
(function() {
    const params = new URLSearchParams(window.location.search);
    const etapaFiltro = params.get('etapa');
    if (etapaFiltro) {
        const cols = document.querySelectorAll('.kanban-col');
        let targetCol = null;
        cols.forEach(col => {
            if (col.dataset.etapa === etapaFiltro) {
                targetCol = col.closest('.flex-shrink-0');
                // Destaque com borda e glow
                targetCol.classList.add('ring-2', 'ring-blue-500', 'ring-offset-2', 'rounded-xl');
            } else {
                // Diminuir opacidade das outras colunas
                col.closest('.flex-shrink-0').classList.add('opacity-30');
            }
        });
        // Scroll até a coluna
        if (targetCol) {
            setTimeout(() => {
                targetCol.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }, 200);
        }
        // Botão para limpar filtro
        const kanbanContainer = document.querySelector('.flex.gap-4.overflow-x-auto');
        if (kanbanContainer) {
            const banner = document.createElement('div');
            banner.className = 'mb-3 flex items-center gap-3 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg px-4 py-2.5 text-sm';
            banner.innerHTML = '<span>🔍 Filtrando: <strong>' + etapaFiltro + '</strong></span>' +
                '<a href="negociacoes.php" class="ml-auto text-xs text-blue-600 hover:text-blue-800 font-medium px-3 py-1 rounded-lg hover:bg-blue-100">✕ Limpar filtro</a>';
            kanbanContainer.parentNode.insertBefore(banner, kanbanContainer);
        }
    }
})();

// ── Drag & Drop (Sortable) ────────────────────────────────────────────────────
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

// ── Filtro por usuário ────────────────────────────────────────────────────────
const btns  = document.querySelectorAll('.filtro-user');
const cards = document.querySelectorAll('.kanban-card');

if (btns.length > 0) {
    function formatMoney(val) {
        return 'R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateCounters(uid) {
        document.querySelectorAll('.kanban-col').forEach(col => {
            let count = 0, total = 0;
            col.querySelectorAll('.kanban-card').forEach(card => {
                const match = uid === '0' || card.dataset.uid === uid;
                if (match) { count++; total += parseFloat(card.dataset.valor) || 0; }
            });
            const counter = col.closest('.flex-shrink-0').querySelector('.col-counter');
            if (counter) counter.textContent = count + ' · ' + formatMoney(total);
        });
    }

    function applyFilter(uid) {
        btns.forEach(btn => {
            const active = btn.dataset.uid === uid;
            btn.classList.toggle('bg-gray-800',     active && uid === '0');
            btn.classList.toggle('text-white',       active);
            btn.classList.toggle('border-gray-800',  active && uid === '0');
            btn.classList.toggle('bg-blue-600',      active && uid !== '0');
            btn.classList.toggle('border-blue-600',  active && uid !== '0');
            btn.classList.toggle('bg-white',         !active);
            btn.classList.toggle('text-gray-700',    !active);
            btn.classList.toggle('border-gray-300',  !active);
        });
        cards.forEach(card => {
            card.style.display = (uid === '0' || card.dataset.uid === uid) ? '' : 'none';
        });
        updateCounters(uid);
    }

    btns.forEach(btn => btn.addEventListener('click', () => applyFilter(btn.dataset.uid)));
}

// ── Modal Nova Tarefa ─────────────────────────────────────────────────────────
const modalEl    = document.getElementById('modal-tarefa');
const modalPanel = document.getElementById('modal-panel');

function openModal(negId, cliente, empresa) {
    document.getElementById('input-neg-id').value = negId;
    document.getElementById('modal-cliente-nome').textContent = empresa ? cliente + ' · ' + empresa : cliente;
    // Data padrão: amanhã mesmo horário
    const d = new Date(); d.setDate(d.getDate() + 1);
    document.getElementById('input-quando').value = d.toISOString().slice(0,16);
    document.getElementById('input-assunto').value = '';
    modalEl.classList.remove('hidden');
    requestAnimationFrame(() => modalPanel.classList.remove('translate-x-full'));
}

function closeModal() {
    modalPanel.classList.add('translate-x-full');
    setTimeout(() => modalEl.classList.add('hidden'), 300);
}

document.getElementById('modal-close').addEventListener('click', closeModal);
document.getElementById('modal-cancel').addEventListener('click', closeModal);
document.getElementById('modal-overlay').addEventListener('click', closeModal);

document.querySelectorAll('.btn-criar-tarefa').forEach(btn => {
    btn.addEventListener('click', () => openModal(btn.dataset.negId, btn.dataset.negCliente, btn.dataset.negEmpresa));
});

document.getElementById('btn-salvar-tarefa').addEventListener('click', () => {
    const form   = document.getElementById('form-tarefa');
    const fd     = new FormData(form);
    const assunto = fd.get('assunto')?.trim();
    const quando  = fd.get('quando');

    if (!assunto) { document.getElementById('input-assunto').focus(); return; }
    if (!quando)  { document.getElementById('input-quando').focus(); return; }

    const btn = document.getElementById('btn-salvar-tarefa');
    btn.disabled = true;
    btn.textContent = 'Salvando...';

    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:         'criar_tarefa',
            negociacao_id:  fd.get('negociacao_id'),
            tipo:           fd.get('tipo'),
            assunto:        assunto,
            descricao:      fd.get('descricao') || '',
            responsavel_id: fd.get('responsavel_id'),
            quando:         quando,
            prioridade:     fd.get('prioridade') || 'media'
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            closeModal();
            setTimeout(() => location.reload(), 310);
        } else {
            alert(data.error || 'Erro ao salvar tarefa.');
            btn.disabled = false;
            btn.textContent = 'Salvar Tarefa';
        }
    })
    .catch(() => {
        alert('Erro de conexão.');
        btn.disabled = false;
        btn.textContent = 'Salvar Tarefa';
    });
});
</script>

<?php require_once '_footer.php'; ?>
