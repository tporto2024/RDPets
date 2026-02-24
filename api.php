<?php
/**
 * api.php — Endpoint AJAX para ações do CRM
 * Aceita: Content-Type: application/json (POST)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whatsapp.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$u    = getCurrentUser();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── Mover etapa no Kanban ─────────────────────────────────────────────────────
if ($action === 'mover_etapa') {
    $negId = (int)($body['negociacao_id'] ?? 0);
    $novaEtapa = $body['etapa'] ?? '';
    $etapasValidas = ['Importado','Sem Retorno','Em contato','Testando','Adiado','Vendido'];

    if (!$negId || !in_array($novaEtapa, $etapasValidas)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros inválidos']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT etapa FROM negociacoes WHERE id = ?');
    $stmt->execute([$negId]);
    $etapaAtual = $stmt->fetchColumn();

    if (!$etapaAtual) {
        http_response_code(404);
        echo json_encode(['error' => 'Negociação não encontrada']);
        exit;
    }

    if ($etapaAtual !== $novaEtapa) {
        $pdo->prepare('UPDATE negociacoes SET etapa = ? WHERE id = ?')
            ->execute([$novaEtapa, $negId]);

        $pdo->prepare('INSERT INTO negociacoes_log (negociacao_id, de_etapa, para_etapa, changed_by, changed_ip) VALUES (?,?,?,?,?)')
            ->execute([$negId, $etapaAtual, $novaEtapa, $u['nome'], getClientIP()]);

        logActivity('Negociações', 'Moveu negociação de etapa', "Neg. #$negId: $etapaAtual → $novaEtapa");
    }

    echo json_encode(['ok' => true, 'etapa' => $novaEtapa]);
    exit;
}

// ── Toggle status de tarefa ───────────────────────────────────────────────────
if ($action === 'toggle_tarefa') {
    $tarefaId = (int)($body['tarefa_id'] ?? 0);
    $novoStatus = $body['status'] ?? '';

    if (!$tarefaId || !in_array($novoStatus, ['aberta','concluida'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros inválidos']);
        exit;
    }

    $acao = $novoStatus === 'concluida' ? 'concluida' : 'reaberta';
    $pdo->prepare('UPDATE tarefas SET status = ? WHERE id = ?')->execute([$novoStatus, $tarefaId]);
    $pdo->prepare('INSERT INTO tarefas_log (tarefa_id,acao,para_status,changed_by,changed_ip) VALUES (?,?,?,?,?)')
        ->execute([$tarefaId, $acao, $novoStatus, $u['nome'], getClientIP()]);
    logActivity('Tarefas', 'Tarefa ' . $acao, "Tarefa ID $tarefaId");

    echo json_encode(['ok' => true, 'status' => $novoStatus]);
    exit;
}

// ── Criar tarefa via Kanban ───────────────────────────────────────────────────
if ($action === 'criar_tarefa') {
    $negId     = (int)($body['negociacao_id'] ?? 0);
    $assunto   = trim($body['assunto'] ?? '');
    $tipo      = $body['tipo'] ?? 'Tarefa';
    $descricao = trim($body['descricao'] ?? '');
    $respId    = (int)($body['responsavel_id'] ?? 0) ?: null;
    $quando    = $body['quando'] ?? '';
    $prioridade= in_array($body['prioridade'] ?? '', ['baixa','media','alta']) ? $body['prioridade'] : 'media';
    $tiposValidos = ['Ligar','Email','Reunião','Tarefa','Almoço','Visita','WhatsApp'];

    if (!$negId || !$assunto || !$quando || !in_array($tipo, $tiposValidos)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros inválidos']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM negociacoes WHERE id = ?');
    $stmt->execute([$negId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Negociação não encontrada']);
        exit;
    }

    $pdo->prepare('INSERT INTO tarefas (negociacao_id, responsavel_id, tipo, assunto, descricao, quando, prioridade) VALUES (?,?,?,?,?,?,?)')
        ->execute([$negId, $respId, $tipo, $assunto, $descricao, $quando, $prioridade]);

    $tarefaId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO tarefas_log (tarefa_id, acao, para_status, changed_by, changed_ip, payload) VALUES (?,?,?,?,?,?)')
        ->execute([
            $tarefaId, 'criada', 'aberta', $u['nome'], getClientIP(),
            json_encode(['assunto' => $assunto, 'tipo' => $tipo, 'quando' => $quando])
        ]);
    logActivity('Tarefas', 'Criou tarefa', "$tipo: $assunto");

    waNotificarResponsavel($pdo, $respId, $assunto, $tipo, $quando);

    echo json_encode(['ok' => true, 'tarefa_id' => $tarefaId]);
    exit;
}

// ── Busca de clientes (autocomplete) ─────────────────────────────────────────
if ($action === 'buscar_clientes') {
    $q = trim($body['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    $stmt = $pdo->prepare('SELECT id, nome, empresa FROM clientes WHERE nome LIKE ? OR empresa LIKE ? LIMIT 10');
    $stmt->execute(["%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação desconhecida']);
