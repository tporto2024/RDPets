<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whatsapp.php';
requireLogin();
$u = getCurrentUser();

$etapas = ['Importado','Sem Retorno','Em contato','Testando','Adiado','Vendido'];
$qualificacoes = ['Quente','Muito Interessado','Morno','Sem interesse'];
$tiposTarefa   = ['Ligar','Email','Reunião','Tarefa','Almoço','Visita','WhatsApp'];

$negId = (int)($_GET['id'] ?? 0);
$novo  = isset($_GET['novo']) || isset($_GET['cliente_id']);
$clienteIdPre = (int)($_GET['cliente_id'] ?? 0);

$erro    = '';
$sucesso = '';

// ── Salvar negociação (nova ou editar) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'salvar_neg') {
    $cliId = (int)$_POST['cliente_id'];
    $etapa = $_POST['etapa'];
    $qual  = $_POST['qualificacao'];
    $valor = (float)str_replace(['.',',' ],['','.'], $_POST['valor']);
    $prev  = $_POST['previsao'] ?: null;
    $resp  = $_POST['responsavel_id'] ?: null;
    $ind   = trim($_POST['indicacao'] ?? '');
    $notas = trim($_POST['notas'] ?? '');

    if (!$cliId || !in_array($etapa, $etapas)) {
        $erro = 'Preencha os campos obrigatórios.';
    } else {
        // ── Resolver tipo_id automaticamente pelo tipo_negocio do cliente ──
        $tipoId = null;
        $stmtTipo = $pdo->prepare('SELECT c.tipo_negocio FROM clientes c WHERE c.id = ?');
        $stmtTipo->execute([$cliId]);
        $tipoNegocioCliente = $stmtTipo->fetchColumn();
        if ($tipoNegocioCliente) {
            $stmtTipoId = $pdo->prepare('SELECT id FROM neg_tipos WHERE nome = ?');
            $stmtTipoId->execute([$tipoNegocioCliente]);
            $tipoId = $stmtTipoId->fetchColumn() ?: null;
        }

        if ($negId) {
            // buscar etapa atual para log
            $etapaAnterior = $pdo->prepare('SELECT etapa FROM negociacoes WHERE id=?');
            $etapaAnterior->execute([$negId]);
            $etapaAnterior = $etapaAnterior->fetchColumn();

            $pdo->prepare('UPDATE negociacoes SET cliente_id=?,etapa=?,qualificacao=?,valor=?,previsao_fechamento=?,responsavel_id=?,indicacao=?,notas=?,tipo_id=? WHERE id=?')
                ->execute([$cliId,$etapa,$qual,$valor,$prev,$resp,$ind,$notas,$tipoId,$negId]);

            if ($etapaAnterior !== $etapa) {
                $pdo->prepare('INSERT INTO negociacoes_log (negociacao_id,de_etapa,para_etapa,changed_by,changed_ip) VALUES (?,?,?,?,?)')
                    ->execute([$negId,$etapaAnterior,$etapa,$u['nome'],getClientIP()]);
            }
            $sucesso = 'Negociação atualizada!';
        } else {
            $pdo->prepare('INSERT INTO negociacoes (cliente_id,etapa,qualificacao,valor,previsao_fechamento,responsavel_id,indicacao,notas,tipo_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$cliId,$etapa,$qual,$valor,$prev,$resp,$ind,$notas,$tipoId]);
            $negId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO negociacoes_log (negociacao_id,de_etapa,para_etapa,changed_by,changed_ip) VALUES (?,?,?,?,?)')
                ->execute([$negId,null,$etapa,$u['nome'],getClientIP()]);

            // Atualizar lead associado para "Em Negociação"
            $pdo->prepare("UPDATE leads SET status = 'em_negociacao' WHERE cliente_id = ? AND status = 'convertido'")
                ->execute([$cliId]);

            header("Location: negociacao_detalhe.php?id=$negId&criado=1");
            exit;
        }
    }
}

// ── Salvar tarefa ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'salvar_tarefa') {
    $tipo    = $_POST['tipo'];
    $assunto = trim($_POST['assunto']);
    $desc    = trim($_POST['descricao'] ?? '');
    $quando  = $_POST['quando'];
    $resp    = $_POST['responsavel_id'] ?: null;

    if ($assunto && $quando && $negId) {
        $pdo->prepare('INSERT INTO tarefas (negociacao_id,responsavel_id,tipo,assunto,descricao,quando) VALUES (?,?,?,?,?,?)')
            ->execute([$negId,$resp,$tipo,$assunto,$desc,$quando]);
        $tarefaId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO tarefas_log (tarefa_id,acao,para_status,changed_by,changed_ip,payload) VALUES (?,?,?,?,?,?)')
            ->execute([$tarefaId,'criada','aberta',$u['nome'],getClientIP(),
                json_encode(['assunto'=>$assunto,'tipo'=>$tipo,'quando'=>$quando])]);
        waNotificarResponsavel($pdo, $resp ? (int)$resp : null, $assunto, $tipo, $quando);
        header("Location: negociacao_detalhe.php?id=$negId&ok=tarefa");
        exit;
    }
}

// ── Concluir/reabrir tarefa ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle_tarefa') {
    $tid      = (int)$_POST['tarefa_id'];
    $newSt    = $_POST['novo_status'];
    $acao     = $newSt === 'concluida' ? 'concluida' : 'reaberta';
    $pdo->prepare('UPDATE tarefas SET status=? WHERE id=?')->execute([$newSt,$tid]);
    $pdo->prepare('INSERT INTO tarefas_log (tarefa_id,acao,para_status,changed_by,changed_ip) VALUES (?,?,?,?,?)')
        ->execute([$tid,$acao,$newSt,$u['nome'],getClientIP()]);
    header("Location: negociacao_detalhe.php?id=$negId");
    exit;
}

// ── Carregar dados ────────────────────────────────────────────────────────────
$neg = null;
if ($negId) {
    $stmt = $pdo->prepare('SELECT n.*, c.nome AS cliente_nome, c.empresa, c.telefone AS cliente_telefone, c.email AS cliente_email, c.cnpj AS cliente_cnpj, c.tipo_negocio AS cliente_tipo_negocio, c.origem AS cliente_origem, c.observacoes AS cliente_obs FROM negociacoes n JOIN clientes c ON c.id=n.cliente_id WHERE n.id=?');
    $stmt->execute([$negId]);
    $neg = $stmt->fetch();
    if (!$neg) { header('Location: negociacoes.php'); exit; }
}

$clientes  = $pdo->query('SELECT id,nome,empresa FROM clientes ORDER BY nome')->fetchAll();
$usuarios  = $pdo->query('SELECT id,nome FROM usuarios ORDER BY nome')->fetchAll();

$tarefas   = $negId ? $pdo->prepare('SELECT t.*,u.nome AS resp_nome FROM tarefas t LEFT JOIN usuarios u ON u.id=t.responsavel_id WHERE t.negociacao_id=? ORDER BY t.quando') : null;
if ($tarefas) { $tarefas->execute([$negId]); $tarefas = $tarefas->fetchAll(); }

$logs = $negId ? $pdo->prepare('SELECT * FROM negociacoes_log WHERE negociacao_id=? ORDER BY changed_at DESC LIMIT 20') : null;
if ($logs) { $logs->execute([$negId]); $logs = $logs->fetchAll(); }

$pageTitle   = $negId ? 'Negociação #' . $negId : 'Nova Negociação';
$currentPage = 'negociacoes';
require_once __DIR__ . '/_nav.php';

$tipoIcon = ['Ligar'=>'📞','Email'=>'✉️','Reunião'=>'📅','WhatsApp'=>'💬','Visita'=>'🚗','Almoço'=>'🍽️','Tarefa'=>'✅'];
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center gap-2 text-sm text-gray-500 mb-5">
        <a href="negociacoes.php" class="hover:text-blue-600">Negociações</a>
        <span>›</span>
        <span class="text-gray-800"><?= $negId ? '#' . $negId . ' — ' . e($neg['cliente_nome']) : 'Nova' ?></span>
    </div>

    <?php if ($erro): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-4 text-sm"><?= e($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso || isset($_GET['criado'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 mb-4 text-sm">
        <?= $sucesso ?: 'Negociação criada com sucesso!' ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Coluna esquerda -->
        <div class="lg:col-span-2 space-y-5">
            <!-- Formulário da negociação -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Dados da Negociação</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="_action" value="salvar_neg">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cliente <span class="text-red-500">*</span></label>
                        <?php if ($negId && $neg): ?>
                            <input type="hidden" name="cliente_id" value="<?= $neg['cliente_id'] ?>">
                            <div class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-800 font-medium">
                                <?= e($neg['cliente_nome']) ?><?= $neg['empresa'] ? ' — ' . e($neg['empresa']) : '' ?>
                            </div>
                        <?php else: ?>
                            <select name="cliente_id" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Selecione o cliente...</option>
                                <?php foreach ($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    <?= ($clienteIdPre == $c['id']) ? 'selected' : '' ?>>
                                    <?= e($c['nome']) ?><?= $c['empresa'] ? ' — ' . e($c['empresa']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Etapa</label>
                            <select name="etapa"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($etapas as $et): ?>
                                <option value="<?= $et ?>" <?= ($neg['etapa'] ?? 'Importado') === $et ? 'selected' : '' ?>><?= $et ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Qualificação</label>
                            <select name="qualificacao"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">—</option>
                                <?php foreach ($qualificacoes as $q): ?>
                                <option value="<?= $q ?>" <?= ($neg['qualificacao'] ?? '') === $q ? 'selected' : '' ?>><?= $q ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Valor (R$)</label>
                            <input type="text" name="valor"
                                   value="<?= number_format((float)($neg['valor'] ?? 0), 2, ',', '.') ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Previsão de fechamento</label>
                            <input type="datetime-local" name="previsao"
                                   value="<?= $neg['previsao_fechamento'] ? date('Y-m-d\TH:i', strtotime($neg['previsao_fechamento'])) : '' ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Responsável</label>
                            <select name="responsavel_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">—</option>
                                <?php foreach ($usuarios as $usr): ?>
                                <option value="<?= $usr['id'] ?>" <?= ($neg['responsavel_id'] ?? '') == $usr['id'] ? 'selected' : '' ?>>
                                    <?= e($usr['nome']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Indicação</label>
                            <input type="text" name="indicacao"
                                   value="<?= e($neg['indicacao'] ?? '') ?>"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
                        <textarea name="notas" rows="3"
                                  placeholder="Observações sobre a negociação..."
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= e($neg['notas'] ?? '') ?></textarea>
                    </div>
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-lg text-sm">
                        <?= $negId ? 'Salvar alterações' : 'Criar negociação' ?>
                    </button>
                </form>
            </div>

            <?php if ($negId): ?>
            <!-- Tarefas -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Tarefas / Follow-up</h2>

                <?php if (isset($_GET['ok'])): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-2 mb-4 text-sm">Tarefa salva!</div>
                <?php endif; ?>

                <!-- Lista de tarefas -->
                <?php if (!empty($tarefas)): ?>
                <div class="space-y-2 mb-5">
                <?php foreach ($tarefas as $t):
                    $atrasada = $t['status'] === 'aberta' && strtotime($t['quando']) < time();
                ?>
                    <div class="flex items-start gap-3 p-3 rounded-lg border <?= $t['status']==='concluida' ? 'border-gray-100 bg-gray-50 opacity-60' : ($atrasada ? 'border-red-200 bg-red-50' : 'border-gray-200') ?>">
                        <form method="POST" class="mt-0.5">
                            <input type="hidden" name="_action" value="toggle_tarefa">
                            <input type="hidden" name="tarefa_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="novo_status" value="<?= $t['status']==='concluida' ? 'aberta' : 'concluida' ?>">
                            <button type="submit" title="<?= $t['status']==='concluida' ? 'Reabrir' : 'Concluir' ?>"
                                    class="w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors
                                    <?= $t['status']==='concluida' ? 'bg-green-500 border-green-500 text-white' : 'border-gray-400 hover:border-green-500' ?>">
                                <?php if ($t['status']==='concluida'): ?>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                </svg>
                                <?php endif; ?>
                            </button>
                        </form>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span><?= $tipoIcon[$t['tipo']] ?? '✅' ?></span>
                                <span class="text-sm font-medium <?= $t['status']==='concluida' ? 'line-through text-gray-400' : 'text-gray-800' ?>">
                                    <?= e($t['assunto']) ?>
                                </span>
                            </div>
                            <?php if ($t['descricao']): ?>
                            <p class="text-xs text-gray-500 mt-0.5"><?= e($t['descricao']) ?></p>
                            <?php endif; ?>
                            <p class="text-xs <?= $atrasada ? 'text-red-500 font-medium' : 'text-gray-400' ?> mt-1">
                                <?= date('d/m/Y H:i', strtotime($t['quando'])) ?>
                                <?php if ($t['resp_nome']): ?> · <?= e($t['resp_nome']) ?><?php endif; ?>
                                <?php if ($atrasada): ?> ⚠ Atrasada<?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Nova tarefa -->
                <details class="border border-dashed border-gray-300 rounded-lg">
                    <summary class="px-4 py-3 text-sm text-blue-600 cursor-pointer font-medium hover:bg-blue-50 rounded-lg">
                        + Adicionar tarefa
                    </summary>
                    <form method="POST" class="p-4 space-y-3 border-t border-gray-200">
                        <input type="hidden" name="_action" value="salvar_tarefa">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Tipo</label>
                                <select name="tipo"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <?php foreach ($tiposTarefa as $t): ?>
                                    <option><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Quando</label>
                                <input type="datetime-local" name="quando" required
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Assunto</label>
                            <input type="text" name="assunto" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Descrição</label>
                            <textarea name="descricao" rows="2"
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Responsável</label>
                            <select name="responsavel_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">—</option>
                                <?php foreach ($usuarios as $usr): ?>
                                <option value="<?= $usr['id'] ?>"><?= e($usr['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg">
                            Salvar tarefa
                        </button>
                    </form>
                </details>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar direita -->
        <div class="space-y-5">

            <?php if ($negId && $neg): ?>
            <!-- Card do Cliente -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <?php
                        $iniciais = implode('', array_map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)),
                            array_slice(explode(' ', trim($neg['cliente_nome'])), 0, 2)));
                        ?>
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                             style="background:linear-gradient(135deg,#2563eb,#7c3aed)">
                            <?= e($iniciais) ?>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-gray-800"><?= e($neg['cliente_nome']) ?></h2>
                            <?php if ($neg['empresa']): ?>
                            <p class="text-xs text-gray-500"><?= e($neg['empresa']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="cliente_form.php?id=<?= $neg['cliente_id'] ?>"
                       class="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded-lg hover:bg-blue-50">
                        ✏️
                    </a>
                </div>
                <div class="space-y-2">
                    <?php if ($neg['cliente_telefone']): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-4 text-center">📞</span>
                        <a href="tel:<?= e($neg['cliente_telefone']) ?>" class="text-blue-600 hover:underline font-medium">
                            <?= e($neg['cliente_telefone']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($neg['cliente_email']): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-4 text-center">✉️</span>
                        <a href="mailto:<?= e($neg['cliente_email']) ?>" class="text-blue-600 hover:underline font-medium truncate">
                            <?= e($neg['cliente_email']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($neg['cliente_tipo_negocio']): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-4 text-center">🏢</span>
                        <span class="text-gray-700 font-medium"><?= e($neg['cliente_tipo_negocio']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($neg['cliente_origem']): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-4 text-center"><?= $neg['cliente_origem'] === 'Inbound' ? '📥' : '📤' ?></span>
                        <span class="font-medium <?= $neg['cliente_origem'] === 'Inbound' ? 'text-green-700' : 'text-blue-700' ?>">
                            <?= e($neg['cliente_origem']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($neg['cliente_cnpj']): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-4 text-center">📋</span>
                        <span class="text-gray-700 font-medium"><?= e($neg['cliente_cnpj']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($neg['cliente_obs']): ?>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-0.5">Obs:</p>
                        <p class="text-xs text-gray-600"><?= e($neg['cliente_obs']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($negId && !empty($logs)): ?>
            <!-- Histórico de etapas -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-sm font-bold text-gray-800 mb-4">Histórico de Etapas</h2>
            <div class="space-y-3">
                <?php foreach ($logs as $log): ?>
                <div class="flex items-start gap-3">
                    <div class="w-2 h-2 rounded-full bg-blue-400 mt-1.5 flex-shrink-0"></div>
                    <div>
                        <p class="text-xs text-gray-700">
                            <?= $log['de_etapa'] ? '<span class="text-gray-400">' . e($log['de_etapa']) . '</span> → ' : '' ?>
                            <strong><?= e($log['para_etapa']) ?></strong>
                        </p>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?= e($log['changed_by'] ?? 'sistema') ?> · <?= date('d/m/Y H:i', strtotime($log['changed_at'])) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
            <?php endif; ?>

        </div><!-- /sidebar -->
    </div><!-- /grid -->
</div><!-- /container -->

<?php require_once '_footer.php'; ?>
