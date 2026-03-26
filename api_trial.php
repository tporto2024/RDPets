<?php
/**
 * api_trial.php — Webhook para receber registros de Trial do Hostelpets
 *
 * Endpoint: POST /crm/api_trial.php
 *
 * Payload JSON esperado:
 * {
 *   "nome":            "Nome do cliente",
 *   "email":           "email@exemplo.com",
 *   "telefone":        "11999999999",       // opcional
 *   "tipo_negocio":    "hotel_pet",          // opcional (hotel_pet, creche, petsitter, etc.)
 *   "origem_lead":     "google",             // opcional (google, instagram, facebook, indicacao, parceiro)
 *   "codigo_parceiro": "PARCEIRO123",        // opcional
 *   "observacoes":     "Texto livre",        // opcional
 *   "token":           "TOKEN_AQUI"          // obrigatorio - autenticacao
 * }
 *
 * Respostas:
 *   201 - Lead criado com sucesso
 *   200 - Lead ja existia (atualizado se necessario)
 *   400 - Dados invalidos
 *   401 - Token invalido
 *   405 - Metodo nao permitido
 *   429 - Rate limit
 *   500 - Erro interno
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Token de autenticacao ──────────────────────────────────────────────────────
// Token compartilhado entre Hostelpets e CRM
define('TRIAL_API_TOKEN', 'hptCRM2026!tok3n');

// ── DB connection ──────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=crm;charset=utf8mb4',
        'crmuser',
        'CrmRD@2026',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// ── Parse input ────────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalido ou vazio']);
    exit;
}

// ── Autenticacao ───────────────────────────────────────────────────────────────
$token = $input['token']
    ?? str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '')
    ?? '';

if ($token !== TRIAL_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Token invalido']);
    exit;
}

// ── Rate limit: max 30 por IP por hora ─────────────────────────────────────────
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['HTTP_X_REAL_IP']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

// Cria tabela de rate limit se nao existir
$pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limit (
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, endpoint, criado_em)
) ENGINE=InnoDB");

$stmt = $pdo->prepare("SELECT COUNT(*) FROM api_rate_limit WHERE ip = ? AND endpoint = 'trial' AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$ip]);
if ($stmt->fetchColumn() >= 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit: max 30 requests/hora']);
    exit;
}

// Registra chamada
$pdo->prepare("INSERT INTO api_rate_limit (ip, endpoint) VALUES (?, 'trial')")->execute([$ip]);

// Limpa registros antigos (mais de 2h)
$pdo->exec("DELETE FROM api_rate_limit WHERE criado_em < DATE_SUB(NOW(), INTERVAL 2 HOUR)");

// ── Validacao ──────────────────────────────────────────────────────────────────
$nome  = trim($input['nome'] ?? '');
$email = trim($input['email'] ?? '');

if (!$nome || !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Campos obrigatorios: nome, email']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email invalido']);
    exit;
}

// Sanitizar dados
$telefone        = trim($input['telefone'] ?? '') ?: null;
$tipo_negocio    = substr(trim($input['tipo_negocio'] ?? ''), 0, 50) ?: null;
$origem_lead     = substr(trim($input['origem_lead'] ?? ''), 0, 50) ?: null;
$codigo_parceiro = substr(trim($input['codigo_parceiro'] ?? ''), 0, 100) ?: null;
$observacoes     = trim($input['observacoes'] ?? '') ?: null;

// Normalizar telefone (remover tudo exceto numeros)
if ($telefone) {
    $telefone = preg_replace('/\D/', '', $telefone);
    if (strlen($telefone) > 30) $telefone = substr($telefone, 0, 30);
}

// ── Verificar se ja existe ─────────────────────────────────────────────────────
$existing = $pdo->prepare('SELECT id, status FROM trial_leads WHERE email = ?');
$existing->execute([$email]);
$existingLead = $existing->fetch();

if ($existingLead) {
    // Lead ja existe - atualizar campos se ainda estiver pendente
    if ($existingLead['status'] === 'pendente') {
        $pdo->prepare(
            'UPDATE trial_leads SET nome = ?, telefone = COALESCE(?, telefone),
             tipo_negocio = COALESCE(?, tipo_negocio), origem_lead = COALESCE(?, origem_lead),
             codigo_parceiro = COALESCE(?, codigo_parceiro)
             WHERE id = ?'
        )->execute([$nome, $telefone, $tipo_negocio, $origem_lead, $codigo_parceiro, $existingLead['id']]);
    }

    echo json_encode([
        'success' => true,
        'action'  => 'existing',
        'id'      => (int)$existingLead['id'],
        'status'  => $existingLead['status'],
        'message' => 'Lead ja cadastrado'
    ]);
    exit;
}

// ── Inserir novo trial lead ────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'INSERT INTO trial_leads (nome, email, telefone, tipo_negocio, origem_lead, codigo_parceiro, observacoes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $nome,
        $email,
        $telefone,
        $tipo_negocio,
        $origem_lead,
        $codigo_parceiro,
        $observacoes,
        'pendente'
    ]);
    $newId = (int)$pdo->lastInsertId();

    // ── Criar notificacao ──────────────────────────────────────────────────────
    try {
        $nomeShort = mb_strlen($nome) > 30 ? mb_substr($nome, 0, 30) . '...' : $nome;
        $origemText = $origem_lead ? " (via $origem_lead)" : '';
        $parceiroText = $codigo_parceiro ? " | Parceiro: $codigo_parceiro" : '';

        $pdo->prepare(
            "INSERT INTO notificacoes (tipo, titulo, mensagem, link, ref_id)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            'trial',
            "🆕 Novo Trial — $nomeShort",
            "Novo registro de trial: $nome ($email)$origemText$parceiroText",
            'trial_hostel.php',
            $newId
        ]);
    } catch (\Exception $e) {
        // Notificacao falhou, mas o lead foi salvo - nao impedir resposta
        error_log("api_trial: Falha ao criar notificacao para lead #$newId: " . $e->getMessage());
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'action'  => 'created',
        'id'      => $newId,
        'message' => 'Trial lead criado com sucesso'
    ]);

} catch (PDOException $e) {
    error_log("api_trial: Erro ao inserir lead: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar lead', 'detail' => $e->getMessage()]);
}
