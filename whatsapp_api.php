<?php
/**
 * WhatsApp Chrome Extension API
 * Receives contacts and messages from the Chrome extension.
 * Auth: X-API-Token header (stored in configuracoes_meta as 'whatsapp_ext_token')
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Auth by token ──────────────────────────────────────────────────────────────
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if (!$token) {
    // Try Authorization: Bearer <token>
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
    }
}

$storedToken = getMetaConfig('whatsapp_ext_token', '');
if (!$storedToken) {
    // Auto-generate token on first use
    $storedToken = bin2hex(random_bytes(32));
    setMetaConfig('whatsapp_ext_token', $storedToken);
}

if (!$token || !hash_equals($storedToken, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token invalido', 'ok' => false]);
    exit;
}

// ── Parse request ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!empty($body['action'])) $action = $body['action'];

// ── GET CONFIG (connection test) ───────────────────────────────────────────────
if ($action === 'get_config') {
    echo json_encode(['ok' => true, 'version' => '1.0', 'server' => 'CRM Hostelpets']);
    exit;
}

// ── SYNC MESSAGES (batch) ──────────────────────────────────────────────────────
if ($action === 'sync_messages') {
    $messages = $body['messages'] ?? [];
    if (empty($messages) || !is_array($messages)) {
        echo json_encode(['ok' => true, 'inserted' => 0, 'skipped' => 0]);
        exit;
    }

    $inserted = 0;
    $skipped  = 0;

    foreach ($messages as $msg) {
        $telefone  = trim($msg['telefone'] ?? '');
        $nome      = trim($msg['nome'] ?? '');
        $texto     = $msg['texto'] ?? '';
        $direcao   = in_array($msg['direcao'] ?? '', ['recebida', 'enviada']) ? $msg['direcao'] : 'recebida';
        $tipo      = trim($msg['tipo'] ?? 'text');
        $timestamp = $msg['timestamp'] ?? date('Y-m-d H:i:s');
        $hashMsg   = $msg['hash'] ?? '';

        if (!$telefone || !$hashMsg) {
            $skipped++;
            continue;
        }

        // Normalize phone (remove non-digits, ensure 55 prefix)
        $telClean = preg_replace('/\D/', '', $telefone);
        if (strlen($telClean) <= 11) $telClean = '55' . $telClean;

        try {
            // Upsert contact
            $pdo->prepare("
                INSERT INTO whatsapp_contatos (telefone, nome, push_name, ultima_mensagem, ultima_mensagem_em, total_mensagens)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    nome = COALESCE(VALUES(nome), nome),
                    push_name = COALESCE(VALUES(push_name), push_name),
                    ultima_mensagem = VALUES(ultima_mensagem),
                    ultima_mensagem_em = VALUES(ultima_mensagem_em),
                    total_mensagens = total_mensagens + 1
            ")->execute([$telClean, $nome ?: null, $nome ?: null, mb_substr($texto, 0, 200), $timestamp]);

            // Get contact ID
            $stC = $pdo->prepare("SELECT id FROM whatsapp_contatos WHERE telefone = ?");
            $stC->execute([$telClean]);
            $contatoId = (int)$stC->fetchColumn();

            if (!$contatoId) {
                $skipped++;
                continue;
            }

            // Insert message (skip if hash already exists)
            $stM = $pdo->prepare("
                INSERT IGNORE INTO whatsapp_mensagens (contato_id, direcao, texto, tipo, timestamp_wa, hash_msg)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stM->execute([$contatoId, $direcao, $texto, $tipo, $timestamp, $hashMsg]);

            if ($stM->rowCount() > 0) {
                $inserted++;
            } else {
                // Duplicate hash — decrement the total we just incremented
                $pdo->prepare("UPDATE whatsapp_contatos SET total_mensagens = GREATEST(0, total_mensagens - 1) WHERE id = ?")->execute([$contatoId]);
                $skipped++;
            }
        } catch (\PDOException $e) {
            error_log("WA_API sync_messages error: " . $e->getMessage());
            $skipped++;
        }
    }

    // Create notification if new contacts were created
    if ($inserted > 0) {
        try {
            criarNotificacao(
                'whatsapp_sync',
                "WhatsApp: $inserted nova" . ($inserted > 1 ? 's' : '') . " mensagem" . ($inserted > 1 ? 's' : ''),
                null,
                'whatsapp_crm.php'
            );
        } catch (\Throwable $e) {}
    }

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
    exit;
}

// ── SYNC CONTACT (single) ──────────────────────────────────────────────────────
if ($action === 'sync_contact') {
    $telefone = trim($body['telefone'] ?? '');
    $nome     = trim($body['nome'] ?? '');

    if (!$telefone) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'telefone obrigatorio']);
        exit;
    }

    $telClean = preg_replace('/\D/', '', $telefone);
    if (strlen($telClean) <= 11) $telClean = '55' . $telClean;

    $pdo->prepare("
        INSERT INTO whatsapp_contatos (telefone, nome, push_name)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            nome = COALESCE(VALUES(nome), nome),
            push_name = COALESCE(VALUES(push_name), push_name)
    ")->execute([$telClean, $nome ?: null, $nome ?: null]);

    $stC = $pdo->prepare("SELECT id, telefone, nome, status FROM whatsapp_contatos WHERE telefone = ?");
    $stC->execute([$telClean]);
    $contato = $stC->fetch();

    echo json_encode(['ok' => true, 'contato' => $contato]);
    exit;
}

// ── QUEUE MESSAGE (send via extension) ─────────────────────────────────────────
if ($action === 'queue_message') {
    $telefone = trim($body['telefone'] ?? '');
    $texto    = trim($body['texto'] ?? '');

    if (!$telefone || !$texto) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'telefone e texto obrigatorios']);
        exit;
    }

    $telClean = preg_replace('/\D/', '', $telefone);
    if (strlen($telClean) <= 11) $telClean = '55' . $telClean;

    $pdo->prepare("
        INSERT INTO whatsapp_outbox (telefone, texto, status, criado_em)
        VALUES (?, ?, 'pending', NOW())
    ")->execute([$telClean, $texto]);

    $msgId = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $msgId]);
    exit;
}

// ── GET PENDING MESSAGES (extension polls this) ────────────────────────────────
if ($action === 'get_pending') {
    $msgs = $pdo->query("
        SELECT id, telefone, texto FROM whatsapp_outbox
        WHERE status = 'pending'
        ORDER BY criado_em ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'messages' => $msgs]);
    exit;
}

// ── MARK MESSAGE SENT ──────────────────────────────────────────────────────────
if ($action === 'mark_sent') {
    $id     = (int)($body['id'] ?? $_GET['id'] ?? 0);
    $status = in_array($body['status'] ?? '', ['sent', 'failed']) ? $body['status'] : 'sent';

    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id obrigatorio']);
        exit;
    }

    $pdo->prepare("UPDATE whatsapp_outbox SET status = ?, enviado_em = NOW() WHERE id = ?")
        ->execute([$status, $id]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Unknown action ─────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acao desconhecida: ' . $action]);
