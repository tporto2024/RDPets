<?php
/**
 * api_notificacoes.php — Endpoint AJAX para notificações
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$userId = $_SESSION['user_id'] ?? 0;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Contar não-lidas ───────────────────────────────────────────────────────────
if ($action === 'contar') {
    $count = contarNotificacoesNaoLidas($userId);
    echo json_encode(['ok' => true, 'count' => $count]);
    exit;
}

// ── Buscar notificações ────────────────────────────────────────────────────────
if ($action === 'buscar') {
    $limit = min((int)($_GET['limit'] ?? 15), 50);
    $stmt = $pdo->prepare(
        'SELECT id, tipo, titulo, mensagem, link, ref_id, lida, criado_em
         FROM notificacoes
         WHERE (usuario_id IS NULL OR usuario_id = ?)
         ORDER BY criado_em DESC
         LIMIT ?'
    );
    $stmt->execute([$userId, $limit]);
    $notifs = $stmt->fetchAll();

    foreach ($notifs as &$n) {
        $n['tempo'] = tempoRelativo($n['criado_em']);
    }
    unset($n);

    echo json_encode(['ok' => true, 'notificacoes' => $notifs]);
    exit;
}

// ── Marcar como lida ───────────────────────────────────────────────────────────
if ($action === 'marcar_lida') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID obrigatório']);
        exit;
    }
    $pdo->prepare(
        'UPDATE notificacoes SET lida = 1 WHERE id = ? AND (usuario_id IS NULL OR usuario_id = ?)'
    )->execute([$id, $userId]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Marcar todas como lidas ────────────────────────────────────────────────────
if ($action === 'marcar_todas') {
    $pdo->prepare(
        'UPDATE notificacoes SET lida = 1 WHERE lida = 0 AND (usuario_id IS NULL OR usuario_id = ?)'
    )->execute([$userId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação desconhecida']);
