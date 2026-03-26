<?php
ob_start();
$pageTitle   = 'WhatsApp CRM';
$currentPage = 'whatsapp_crm';
require_once __DIR__ . '/config.php';

// ── AJAX actions (same-file, JSON response) ─────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];
    $user   = $_SESSION['user_nome'] ?? 'Sistema';

    // Alterar status
    if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id         = (int)($_POST['id'] ?? 0);
        $novoStatus = $_POST['novo_status'] ?? '';
        $validos    = ['novo','contatado','convertido','descartado'];

        if (!$id || !in_array($novoStatus, $validos)) {
            echo json_encode(['ok' => false, 'msg' => 'Dados inválidos']);
            exit;
        }

        $updates = ['status' => $novoStatus];
        if ($novoStatus === 'contatado') {
            $updates['contatado_em']  = date('Y-m-d H:i:s');
            $updates['contatado_por'] = $user;
        }
        if ($novoStatus === 'convertido') {
            $updates['convertido_em']  = date('Y-m-d H:i:s');
            $updates['convertido_por'] = $user;
        }

        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($updates)));
        $pdo->prepare("UPDATE whatsapp_contatos SET $sets WHERE id = ?")->execute([...array_values($updates), $id]);
        logActivity('WhatsApp CRM', 'Alterou status', "Contato #$id → $novoStatus");

        echo json_encode(['ok' => true]);
        exit;
    }

    // Carregar mensagens de um contato
    if ($action === 'mensagens') {
        $contatoId = (int)($_GET['id'] ?? 0);
        if (!$contatoId) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
            exit;
        }

        $contato = $pdo->prepare('SELECT * FROM whatsapp_contatos WHERE id = ?');
        $contato->execute([$contatoId]);
        $contato = $contato->fetch();

        if (!$contato) {
            echo json_encode(['ok' => false, 'msg' => 'Contato não encontrado']);
            exit;
        }

        $msgs = $pdo->prepare('SELECT * FROM whatsapp_mensagens WHERE contato_id = ? ORDER BY timestamp_wa ASC');
        $msgs->execute([$contatoId]);
        $mensagens = $msgs->fetchAll();

        echo json_encode([
            'ok'        => true,
            'contato'   => $contato,
            'mensagens' => $mensagens,
        ]);
        exit;
    }

    // Converter em cliente
    if ($action === 'converter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
            exit;
        }

        $contato = $pdo->prepare('SELECT * FROM whatsapp_contatos WHERE id = ?');
        $contato->execute([$id]);
        $contato = $contato->fetch();

        if (!$contato || $contato['status'] === 'convertido') {
            echo json_encode(['ok' => false, 'msg' => 'Contato não encontrado ou já convertido']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO clientes (nome, telefone, observacoes, origem)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                $contato['nome'] ?: $contato['push_name'] ?: 'WhatsApp ' . $contato['telefone'],
                $contato['telefone'],
                $contato['observacoes'] ? 'WhatsApp: ' . $contato['observacoes'] : 'Origem: WhatsApp',
                'Inbound'
            ]);
            $clienteId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE whatsapp_contatos SET status = ?, cliente_id = ?, convertido_em = NOW(), convertido_por = ? WHERE id = ?'
            )->execute(['convertido', $clienteId, $user, $id]);

            $pdo->commit();
            logActivity('WhatsApp CRM', 'Converteu contato em cliente', "Contato #$id → Cliente #$clienteId");
            echo json_encode(['ok' => true, 'cliente_id' => $clienteId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Erro ao converter: ' . $e->getMessage()]);
        }
        exit;
    }

    // Enviar mensagem (queue para extensão)
    if ($action === 'enviar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $contatoId = (int)($_POST['contato_id'] ?? 0);
        $texto     = trim($_POST['texto'] ?? '');

        if (!$contatoId || !$texto) {
            echo json_encode(['ok' => false, 'msg' => 'Dados inválidos']);
            exit;
        }

        $contato = $pdo->prepare('SELECT telefone, nome FROM whatsapp_contatos WHERE id = ?');
        $contato->execute([$contatoId]);
        $contato = $contato->fetch();

        if (!$contato) {
            echo json_encode(['ok' => false, 'msg' => 'Contato não encontrado']);
            exit;
        }

        // Queue message in outbox
        $pdo->prepare("INSERT INTO whatsapp_outbox (telefone, texto, status, criado_em) VALUES (?, ?, 'pending', NOW())")
            ->execute([$contato['telefone'], $texto]);

        logActivity('WhatsApp CRM', 'Enviou mensagem', "Para {$contato['nome']} ({$contato['telefone']})");

        echo json_encode(['ok' => true, 'msg' => 'Mensagem na fila de envio']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
    exit;
}

// ── Page render ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/_nav.php';
logActivity('WhatsApp CRM', 'Visualizou contatos WhatsApp');

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtroStatus = $_GET['status'] ?? '';
$busca        = trim($_GET['busca'] ?? '');
$pagina       = max(1, (int)($_GET['pg'] ?? 1));
$porPagina    = 25;

$where  = [];
$params = [];

if ($filtroStatus && in_array($filtroStatus, ['novo','contatado','convertido','descartado'])) {
    $where[]  = 'wc.status = ?';
    $params[] = $filtroStatus;
}
if ($busca) {
    $where[]  = '(wc.nome LIKE ? OR wc.push_name LIKE ? OR wc.telefone LIKE ? OR wc.ultima_mensagem LIKE ?)';
    $like = "%$busca%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Contagem total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_contatos wc $whereSQL");
$stmtCount->execute($params);
$total   = (int)$stmtCount->fetchColumn();
$paginas = max(1, ceil($total / $porPagina));
$offset  = ($pagina - 1) * $porPagina;

// Buscar contatos
$stmtContatos = $pdo->prepare("
    SELECT wc.*, c.nome AS cliente_nome
    FROM whatsapp_contatos wc
    LEFT JOIN clientes c ON c.id = wc.cliente_id
    $whereSQL
    ORDER BY wc.ultima_mensagem_em DESC, wc.criado_em DESC
    LIMIT $porPagina OFFSET $offset
");
$stmtContatos->execute($params);
$contatos = $stmtContatos->fetchAll();

// Contadores por status
$contadores = [];
foreach (['novo','contatado','convertido','descartado'] as $s) {
    $contadores[$s] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_contatos WHERE status = '$s'")->fetchColumn();
}
$totalGeral = array_sum($contadores);

// Token da extensão
$extToken = getMetaConfig('whatsapp_ext_token', '');
?>

<!-- Cabeçalho com filtros -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-5">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-green-500">
            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
        </div>
        <div>
            <h2 class="text-sm font-bold text-gray-800">Contatos WhatsApp</h2>
            <p class="text-xs text-gray-400"><?= $totalGeral ?> contatos sincronizados</p>
        </div>
    </div>

    <!-- Busca -->
    <form method="GET" class="flex items-center gap-2">
        <?php if ($filtroStatus): ?><input type="hidden" name="status" value="<?= e($filtroStatus) ?>"><?php endif; ?>
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Buscar por nome ou telefone..."
               class="border rounded-lg px-3 py-1.5 text-sm w-56 focus:ring-2 focus:ring-green-500 focus:border-green-500">
        <button type="submit" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-green-700 transition-colors">Buscar</button>
        <?php if ($busca || $filtroStatus): ?>
        <a href="whatsapp_crm.php" class="text-xs text-gray-500 hover:text-gray-700">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Badges de status -->
<div class="flex flex-wrap gap-2 mb-4">
    <a href="whatsapp_crm.php"
       class="badge text-xs <?= !$filtroStatus ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?> cursor-pointer transition-colors">
        Todos <span class="ml-1 opacity-70"><?= $totalGeral ?></span>
    </a>
    <?php
    $statusLabels = [
        'novo'       => ['Novos',       'bg-blue-100 text-blue-700',     'bg-blue-600 text-white'],
        'contatado'  => ['Contatados',  'bg-yellow-100 text-yellow-700', 'bg-yellow-500 text-white'],
        'convertido' => ['Convertidos', 'bg-green-100 text-green-700',   'bg-green-600 text-white'],
        'descartado' => ['Descartados', 'bg-gray-100 text-gray-500',     'bg-gray-600 text-white'],
    ];
    foreach ($statusLabels as $sk => [$sl, $sinactive, $sactive]):
        $qs = http_build_query(array_filter(['status' => $sk, 'busca' => $busca]));
    ?>
    <a href="whatsapp_crm.php?<?= $qs ?>"
       class="badge text-xs <?= $filtroStatus === $sk ? $sactive : $sinactive ?> cursor-pointer transition-colors">
        <?= $sl ?> <span class="ml-1 opacity-70"><?= $contadores[$sk] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Layout: tabela + painel de chat -->
<div class="flex gap-4" id="wa-layout">
    <!-- Tabela de contatos -->
    <div class="flex-1 min-w-0">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
            <?php if (empty($contatos)): ?>
            <div class="text-center py-12">
                <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                <p class="text-sm text-gray-500 font-medium">Nenhum contato encontrado</p>
                <p class="text-xs text-gray-400 mt-1">Os contatos do WhatsApp aparecerão aqui quando sincronizados pela extensão.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50 border-b">
                            <th class="px-4 py-3 text-left">Nome</th>
                            <th class="px-4 py-3 text-left">Telefone</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left hidden md:table-cell">Última Mensagem</th>
                            <th class="px-4 py-3 text-center hidden sm:table-cell">Msgs</th>
                            <th class="px-4 py-3 text-right">Data</th>
                            <th class="px-4 py-3 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($contatos as $c):
                        $statusColors = [
                            'novo'       => 'bg-blue-100 text-blue-700',
                            'contatado'  => 'bg-yellow-100 text-yellow-700',
                            'convertido' => 'bg-green-100 text-green-700',
                            'descartado' => 'bg-gray-100 text-gray-500',
                        ];
                    ?>
                    <tr class="hover:bg-green-50/50 transition-colors group cursor-pointer"
                        onclick="openChat(<?= $c['id'] ?>)" data-contato-id="<?= $c['id'] ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <?php if ($c['foto_url']): ?>
                                <img src="<?= e($c['foto_url']) ?>" class="w-8 h-8 rounded-full object-cover flex-shrink-0" alt="">
                                <?php else: ?>
                                <div class="w-8 h-8 rounded-full bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0 text-xs font-bold">
                                    <?= strtoupper(mb_substr($c['nome'] ?: $c['push_name'] ?: '?', 0, 1)) ?>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <p class="font-semibold text-gray-800 text-sm"><?= e($c['nome'] ?: $c['push_name'] ?: 'Sem nome') ?></p>
                                    <?php if ($c['push_name'] && $c['nome'] && $c['push_name'] !== $c['nome']): ?>
                                    <p class="text-[10px] text-gray-400"><?= e($c['push_name']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs text-gray-600 font-mono"><?= e($c['telefone']) ?></span>
                        </td>
                        <td class="px-4 py-3" onclick="event.stopPropagation()">
                            <?php if ($c['status'] === 'convertido' && $c['cliente_nome']): ?>
                                <span class="badge text-xs bg-green-100 text-green-700">Convertido</span>
                                <p class="text-[10px] text-green-600 mt-0.5">&rarr; <?= e($c['cliente_nome']) ?></p>
                            <?php else: ?>
                                <select onchange="changeStatus(<?= $c['id'] ?>, this.value)"
                                        class="text-xs border rounded px-1.5 py-0.5 cursor-pointer <?= $statusColors[$c['status']] ?? '' ?>">
                                    <?php foreach (['novo','contatado','convertido','descartado'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $c['status'] === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <?php if ($c['contatado_por']): ?>
                                <p class="text-[10px] text-gray-400 mt-0.5"><?= e($c['contatado_por']) ?> · <?= $c['contatado_em'] ? date('d/m H:i', strtotime($c['contatado_em'])) : '' ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <?php if ($c['ultima_mensagem']): ?>
                            <p class="text-xs text-gray-500 truncate max-w-[200px]" title="<?= e($c['ultima_mensagem']) ?>">
                                <?= e(mb_substr($c['ultima_mensagem'], 0, 60)) ?><?= mb_strlen($c['ultima_mensagem'] ?? '') > 60 ? '...' : '' ?>
                            </p>
                            <?php else: ?>
                            <span class="text-xs text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-xs font-semibold text-gray-600">
                                <?= (int)$c['total_mensagens'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <?php if ($c['ultima_mensagem_em']): ?>
                            <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($c['ultima_mensagem_em'])) ?></p>
                            <p class="text-[10px] text-gray-400"><?= date('H:i', strtotime($c['ultima_mensagem_em'])) ?></p>
                            <?php else: ?>
                            <p class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($c['criado_em'])) ?></p>
                            <p class="text-[10px] text-gray-400"><?= date('H:i', strtotime($c['criado_em'])) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="openChat(<?= $c['id'] ?>)" class="p-1.5 rounded-lg text-green-600 hover:bg-green-50 transition-colors" title="Ver conversa">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                    </svg>
                                </button>
                                <?php if ($c['status'] !== 'convertido'): ?>
                                <button onclick="convertContact(<?= $c['id'] ?>)" class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Converter em cliente">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                    </svg>
                                </button>
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
                        $pgQs = http_build_query(array_filter(['status' => $filtroStatus, 'busca' => $busca, 'pg' => $p > 1 ? $p : null]));
                    ?>
                    <a href="whatsapp_crm.php<?= $pgQs ? "?$pgQs" : '' ?>"
                       class="px-2.5 py-1 rounded text-xs font-medium <?= $p === $pagina ? 'bg-green-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-100' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Painel de Chat (lateral) -->
    <div id="chat-panel" class="hidden w-96 flex-shrink-0">
        <div class="bg-white rounded-xl border shadow-sm overflow-hidden flex flex-col" style="height: calc(100vh - 200px); position: sticky; top: 100px;">
            <!-- Chat header -->
            <div id="chat-header" class="px-4 py-3 bg-green-600 text-white flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <div id="chat-avatar" class="w-9 h-9 rounded-full bg-green-700 flex items-center justify-center text-sm font-bold flex-shrink-0">?</div>
                    <div class="min-w-0">
                        <p id="chat-name" class="font-semibold text-sm truncate">Selecione um contato</p>
                        <p id="chat-phone" class="text-xs text-green-100 truncate"></p>
                    </div>
                </div>
                <button onclick="closeChat()" class="p-1.5 rounded-lg hover:bg-green-700 transition-colors flex-shrink-0" title="Fechar">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Chat messages area -->
            <div id="chat-messages" class="flex-1 overflow-y-auto px-3 py-4 space-y-2" style="background: #e5ddd5 url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22><rect width=%2260%22 height=%2260%22 fill=%22%23e5ddd5%22/><circle cx=%2230%22 cy=%2230%22 r=%221%22 fill=%22%23d5ccc5%22 opacity=%220.3%22/></svg>');">
                <div class="text-center text-xs text-gray-500 py-8">
                    Clique em um contato para ver a conversa
                </div>
            </div>

            <!-- Chat reply input -->
            <div id="chat-reply" class="hidden border-t bg-white flex-shrink-0">
                <form id="reply-form" class="flex items-end gap-2 px-3 py-2">
                    <textarea id="reply-text" rows="1" placeholder="Digite uma mensagem..."
                              class="flex-1 border rounded-xl px-3 py-2 text-sm resize-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                              style="max-height: 80px;"
                              onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); sendReply();}"></textarea>
                    <button type="button" onclick="sendReply()"
                            class="bg-green-600 text-white p-2.5 rounded-full hover:bg-green-700 transition-colors flex-shrink-0" title="Enviar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </form>
                <p class="text-[10px] text-gray-400 text-center pb-1">Envia via extensão Chrome (WhatsApp Web precisa estar aberto)</p>
            </div>
        </div>
    </div>
</div>

<!-- Token da extensão Chrome -->
<?php if ($extToken): ?>
<div class="mt-6 bg-white rounded-xl border shadow-sm p-4">
    <div class="flex items-center gap-3 mb-2">
        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
        <div>
            <p class="text-sm font-semibold text-gray-700">Token da Extensão Chrome</p>
            <p class="text-xs text-gray-400">Use este token para configurar a extensão WhatsApp CRM no Chrome.</p>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <input type="text" id="ext-token" value="<?= e($extToken) ?>" readonly
               class="flex-1 font-mono text-xs bg-gray-50 border rounded-lg px-3 py-2 text-gray-600 select-all">
        <button onclick="copyToken()" id="btn-copy-token"
                class="bg-green-600 text-white px-3 py-2 rounded-lg text-xs hover:bg-green-700 transition-colors flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            Copiar
        </button>
    </div>
</div>
<?php endif; ?>

<script>
// ── AJAX helpers ─────────────────────────────────────────────────────────────
function changeStatus(id, novoStatus) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('novo_status', novoStatus);
    fetch('whatsapp_crm.php?ajax=status', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                // Refresh page to update counters
                location.reload();
            } else {
                alert(data.msg || 'Erro ao alterar status');
            }
        })
        .catch(() => alert('Erro de conexão'));
}

function convertContact(id) {
    if (!confirm('Converter este contato em cliente?')) return;
    const fd = new FormData();
    fd.append('id', id);
    fetch('whatsapp_crm.php?ajax=converter', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                alert(data.msg || 'Erro ao converter');
            }
        })
        .catch(() => alert('Erro de conexão'));
}

// ── Chat panel ───────────────────────────────────────────────────────────────
let activeContactId = null;

function openChat(id) {
    activeContactId = id;
    const panel = document.getElementById('chat-panel');
    const msgs  = document.getElementById('chat-messages');
    const footer = document.getElementById('chat-footer');

    panel.classList.remove('hidden');

    // Highlight active row
    document.querySelectorAll('[data-contato-id]').forEach(r => r.classList.remove('bg-green-50'));
    const row = document.querySelector(`[data-contato-id="${id}"]`);
    if (row) row.classList.add('bg-green-50');

    // Loading state
    msgs.innerHTML = '<div class="text-center py-8"><div class="inline-block w-6 h-6 border-2 border-green-300 border-t-green-600 rounded-full animate-spin"></div></div>';

    fetch(`whatsapp_crm.php?ajax=mensagens&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                msgs.innerHTML = `<div class="text-center text-xs text-red-500 py-8">${data.msg || 'Erro'}</div>`;
                return;
            }

            const c = data.contato;
            const initial = (c.nome || c.push_name || '?').charAt(0).toUpperCase();

            // Update header
            document.getElementById('chat-name').textContent = c.nome || c.push_name || 'Sem nome';
            document.getElementById('chat-phone').textContent = c.telefone;

            if (c.foto_url) {
                document.getElementById('chat-avatar').innerHTML = `<img src="${escHtml(c.foto_url)}" class="w-9 h-9 rounded-full object-cover" alt="">`;
            } else {
                document.getElementById('chat-avatar').textContent = initial;
            }

            // Render messages
            if (data.mensagens.length === 0) {
                msgs.innerHTML = '<div class="text-center text-xs text-gray-500 py-8">Nenhuma mensagem sincronizada</div>';
                footer.classList.add('hidden');
                return;
            }

            let html = '';
            let lastDate = '';
            data.mensagens.forEach(m => {
                const dt = m.timestamp_wa.substring(0, 10);
                if (dt !== lastDate) {
                    lastDate = dt;
                    const [y, mo, d] = dt.split('-');
                    html += `<div class="text-center my-3"><span class="inline-block bg-white/80 text-[10px] text-gray-500 px-3 py-1 rounded-full shadow-sm">${d}/${mo}/${y}</span></div>`;
                }

                const isEnviada = m.direcao === 'enviada';
                const align  = isEnviada ? 'justify-end' : 'justify-start';
                const bgCol  = isEnviada ? 'bg-green-100' : 'bg-white';
                const corner = isEnviada ? 'rounded-tr-none' : 'rounded-tl-none';
                const time   = m.timestamp_wa.substring(11, 16);
                const texto  = m.texto ? escHtml(m.texto) : `<span class="italic text-gray-400">[${escHtml(m.tipo)}]</span>`;

                html += `<div class="flex ${align}">
                    <div class="${bgCol} ${corner} rounded-xl px-3 py-1.5 max-w-[80%] shadow-sm">
                        <p class="text-sm text-gray-800 whitespace-pre-wrap break-words">${texto}</p>
                        <p class="text-[10px] text-gray-400 text-right mt-0.5">${time}</p>
                    </div>
                </div>`;
            });

            msgs.innerHTML = html;
            document.getElementById('chat-reply').classList.remove('hidden');

            // Scroll to bottom
            msgs.scrollTop = msgs.scrollHeight;
        })
        .catch(() => {
            msgs.innerHTML = '<div class="text-center text-xs text-red-500 py-8">Erro ao carregar mensagens</div>';
        });
}

function closeChat() {
    activeContactId = null;
    document.getElementById('chat-panel').classList.add('hidden');
    document.getElementById('chat-reply').classList.add('hidden');
    document.querySelectorAll('[data-contato-id]').forEach(r => r.classList.remove('bg-green-50'));
}

function sendReply() {
    const texto = document.getElementById('reply-text').value.trim();
    if (!texto || !activeContactId) return;

    const btn = document.querySelector('#reply-form button');
    btn.disabled = true;
    btn.classList.add('opacity-50');

    const fd = new FormData();
    fd.append('contato_id', activeContactId);
    fd.append('texto', texto);

    fetch('whatsapp_crm.php?ajax=enviar', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                // Add message to chat visually
                const msgs = document.getElementById('chat-messages');
                const now = new Date();
                const time = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0');
                msgs.innerHTML += `<div class="flex justify-end">
                    <div class="bg-green-100 rounded-xl rounded-tr-none px-3 py-1.5 max-w-[80%] shadow-sm">
                        <p class="text-sm text-gray-800 whitespace-pre-wrap break-words">${escHtml(texto)}</p>
                        <p class="text-[10px] text-gray-400 text-right mt-0.5">${time} ⏳</p>
                    </div>
                </div>`;
                msgs.scrollTop = msgs.scrollHeight;
                document.getElementById('reply-text').value = '';
            } else {
                alert(data.msg || 'Erro ao enviar');
            }
        })
        .catch(() => alert('Erro de conexão'))
        .finally(() => {
            btn.disabled = false;
            btn.classList.remove('opacity-50');
        });
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Copy token ───────────────────────────────────────────────────────────────
function copyToken() {
    const input = document.getElementById('ext-token');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = document.getElementById('btn-copy-token');
        const orig = btn.innerHTML;
        btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copiado!';
        btn.classList.replace('bg-green-600', 'bg-gray-600');
        setTimeout(() => {
            btn.innerHTML = orig;
            btn.classList.replace('bg-gray-600', 'bg-green-600');
        }, 2000);
    });
}
</script>

<?php require_once '_footer.php'; ?>
