<?php
/**
 * instagram_webhook.php — Endpoint público para webhooks do Meta (Instagram/Facebook)
 *
 * GET  → Verificação do webhook (hub.challenge)
 * POST → Receber eventos: leadgen (Lead Ads) e messages (Instagram Direct)
 *
 * NÃO requer autenticação — é chamado pelo Meta
 */
require_once __DIR__ . '/config.php';

$logFile = __DIR__ . '/webhook_log.txt';

function webhookLog(string $msg): void {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

// ── GET: Verificação do webhook ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '';

    $verifyToken = getMetaConfig('meta_verify_token');

    if ($mode === 'subscribe' && $token === $verifyToken && $verifyToken !== '') {
        webhookLog("Webhook verificado com sucesso");
        http_response_code(200);
        echo $challenge;
    } else {
        webhookLog("Falha na verificação: mode=$mode token=$token");
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── POST: Receber eventos ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    webhookLog("POST recebido: " . substr($raw, 0, 2000));

    if (!$data || !isset($data['entry'])) {
        http_response_code(200); // Meta espera 200 mesmo em caso de dados inválidos
        echo 'OK';
        exit;
    }

    $pageToken = getMetaConfig('meta_page_token');

    foreach ($data['entry'] as $entry) {
        $pageId = $entry['id'] ?? '';

        // ── Lead Ads (leadgen) ───────────────────────────────────────────────
        if (!empty($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                if (($change['field'] ?? '') === 'leadgen') {
                    $leadgenId = $change['value']['leadgen_id'] ?? null;
                    $formId    = $change['value']['form_id']    ?? null;
                    $adId      = $change['value']['ad_id']      ?? null;

                    if ($leadgenId && $pageToken) {
                        processLeadAd($leadgenId, $formId, $adId, $pageId, $pageToken);
                    } else {
                        webhookLog("Leadgen sem ID ou sem token: leadgen_id=$leadgenId");
                    }
                }
            }
        }

        // ── Instagram Direct Messages (messaging) ───────────────────────────
        if (!empty($entry['messaging'])) {
            foreach ($entry['messaging'] as $msgEvent) {
                if (!empty($msgEvent['message']) && empty($msgEvent['message']['is_echo'])) {
                    processDirectMessage($msgEvent, $pageId);
                }
            }
        }
    }

    http_response_code(200);
    echo 'EVENT_RECEIVED';
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
exit;

// ═══════════════════════════════════════════════════════════════════════════════
// Funções de processamento
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Busca dados do lead via Graph API e insere no banco
 */
function processLeadAd(string $leadgenId, ?string $formId, ?string $adId, string $pageId, string $pageToken): void {
    global $pdo;

    // Verificar duplicata
    $stmt = $pdo->prepare('SELECT id FROM instagram_leads WHERE meta_leadgen_id = ?');
    $stmt->execute([$leadgenId]);
    if ($stmt->fetchColumn()) {
        webhookLog("Lead duplicado ignorado: $leadgenId");
        return;
    }

    // Buscar dados completos do lead via Graph API
    $url = "https://graph.facebook.com/v19.0/{$leadgenId}?access_token=" . urlencode($pageToken);
    $response = @file_get_contents($url);

    if ($response === false) {
        webhookLog("Erro ao buscar lead $leadgenId via Graph API");
        return;
    }

    $leadData = json_decode($response, true);
    webhookLog("Lead Ad dados: " . substr($response, 0, 1500));

    // Extrair campos do formulário
    $fields = [];
    if (!empty($leadData['field_data'])) {
        foreach ($leadData['field_data'] as $field) {
            $name  = strtolower($field['name'] ?? '');
            $value = implode(', ', $field['values'] ?? []);
            $fields[$name] = $value;
        }
    }

    $nome     = $fields['full_name'] ?? $fields['nome'] ?? $fields['name'] ?? null;
    $email    = $fields['email'] ?? null;
    $telefone = $fields['phone_number'] ?? $fields['telefone'] ?? $fields['phone'] ?? null;
    $cidade   = $fields['city'] ?? $fields['cidade'] ?? null;
    $estado   = $fields['state'] ?? $fields['estado'] ?? null;
    $empresa  = $fields['company_name'] ?? $fields['empresa'] ?? null;

    // Nome do anúncio (buscar se tiver ad_id)
    $adName = null;
    if ($adId && $pageToken) {
        $adUrl = "https://graph.facebook.com/v19.0/{$adId}?fields=name&access_token=" . urlencode($pageToken);
        $adResp = @file_get_contents($adUrl);
        if ($adResp) {
            $adData = json_decode($adResp, true);
            $adName = $adData['name'] ?? null;
        }
    }

    // Campos extras (todos que não foram mapeados)
    $mapped = ['full_name','nome','name','email','phone_number','telefone','phone','city','cidade','state','estado','company_name','empresa'];
    $extras = array_diff_key($fields, array_flip($mapped));

    $pdo->prepare(
        'INSERT INTO instagram_leads (fonte, form_id, ad_id, ad_name, page_id, nome, email, telefone, cidade, estado, empresa, dados_extra, meta_leadgen_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'lead_ad', $formId, $adId, $adName, $pageId,
        $nome, $email, $telefone, $cidade, $estado, $empresa,
        $extras ? json_encode($extras) : null,
        $leadgenId
    ]);

    $newId = (int)$pdo->lastInsertId();
    webhookLog("Lead Ad inserido: ID=$newId nome=$nome email=$email tel=$telefone");

    // Criar notificação
    $titulo = 'Novo lead Instagram' . ($nome ? ": $nome" : '');
    criarNotificacao('instagram_lead', $titulo, $adName ? "Campanha: $adName" : null, "instagram_leads.php?id=$newId", $newId);
}

/**
 * Processa mensagem do Instagram Direct
 */
function processDirectMessage(array $msgEvent, string $pageId): void {
    global $pdo;

    $senderId = $msgEvent['sender']['id'] ?? '';
    $msgText  = $msgEvent['message']['text'] ?? '';
    $msgId    = $msgEvent['message']['mid'] ?? '';

    if (!$senderId || !$msgText) return;

    // Deduplicação por message ID
    $stmt = $pdo->prepare('SELECT id FROM instagram_leads WHERE meta_leadgen_id = ?');
    $stmt->execute(["dm_$msgId"]);
    if ($stmt->fetchColumn()) {
        webhookLog("DM duplicada ignorada: $msgId");
        return;
    }

    // Buscar perfil do usuário (pode falhar se não tiver permissão)
    $username = null;
    $pageToken = getMetaConfig('meta_page_token');
    if ($pageToken) {
        $profileUrl = "https://graph.facebook.com/v19.0/{$senderId}?fields=name,username&access_token=" . urlencode($pageToken);
        $profileResp = @file_get_contents($profileUrl);
        if ($profileResp) {
            $profile = json_decode($profileResp, true);
            $username = $profile['username'] ?? $profile['name'] ?? null;
        }
    }

    $pdo->prepare(
        'INSERT INTO instagram_leads (fonte, page_id, nome, mensagem, ig_user_id, ig_username, meta_leadgen_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'direct_message', $pageId,
        $username, $msgText, $senderId, $username,
        "dm_$msgId"
    ]);

    $newId = (int)$pdo->lastInsertId();
    webhookLog("DM inserida: ID=$newId sender=$senderId username=$username");

    $titulo = 'Nova mensagem Instagram' . ($username ? " de @$username" : '');
    criarNotificacao('instagram_lead', $titulo, mb_substr($msgText, 0, 100), "instagram_leads.php?id=$newId", $newId);
}
