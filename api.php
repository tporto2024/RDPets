<?php
/**
 * api.php — Endpoint AJAX para ações do CRM
 * Aceita: Content-Type: application/json (POST)
 */
require_once __DIR__ . '/auth.php';

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

    echo json_encode(['ok' => true, 'status' => $novoStatus]);
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
