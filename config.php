<?php
// ─── Configurações do Banco de Dados ────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'crm');
define('DB_USER',    'crmuser');
define('DB_PASS',    'CrmRD@2026');
define('DB_CHARSET', 'utf8mb4');

// ─── Conexão PDO ─────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<h2 style="font-family:sans-serif;color:red">Erro de conexão com o banco: ' . htmlspecialchars($e->getMessage()) . '</h2>
         <p style="font-family:sans-serif">Verifique as configurações em <strong>config.php</strong></p>');
}

// ─── Sessão ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Helpers globais ─────────────────────────────────────────────────────────
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function formatMoney(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function formatDate(string $d): string {
    if (!$d) return '-';
    return date('d/m/Y H:i', strtotime($d));
}

// ─── Paleta de cores para etapas do Kanban ───────────────────────────────────
const ETAPA_CORES = [
    'cinza'    => ['header' => 'bg-gray-200 text-gray-700',    'dot' => 'bg-gray-400',   'label' => 'Cinza'],
    'vermelho' => ['header' => 'bg-red-100 text-red-700',      'dot' => 'bg-red-400',    'label' => 'Vermelho'],
    'azul'     => ['header' => 'bg-blue-100 text-blue-700',    'dot' => 'bg-blue-500',   'label' => 'Azul'],
    'amarelo'  => ['header' => 'bg-yellow-100 text-yellow-700','dot' => 'bg-yellow-400', 'label' => 'Amarelo'],
    'laranja'  => ['header' => 'bg-orange-100 text-orange-700','dot' => 'bg-orange-400', 'label' => 'Laranja'],
    'verde'    => ['header' => 'bg-green-100 text-green-700',  'dot' => 'bg-green-500',  'label' => 'Verde'],
    'roxo'     => ['header' => 'bg-purple-100 text-purple-700','dot' => 'bg-purple-500', 'label' => 'Roxo'],
    'rosa'     => ['header' => 'bg-pink-100 text-pink-700',    'dot' => 'bg-pink-400',   'label' => 'Rosa'],
    'indigo'   => ['header' => 'bg-indigo-100 text-indigo-700','dot' => 'bg-indigo-500', 'label' => 'Índigo'],
    'ciano'    => ['header' => 'bg-cyan-100 text-cyan-700',    'dot' => 'bg-cyan-500',   'label' => 'Ciano'],
];

function getEtapas(): array {
    global $pdo;
    return $pdo->query("SELECT * FROM neg_etapas ORDER BY ordem ASC")->fetchAll();
}

function getTipos(): array {
    global $pdo;
    return $pdo->query("SELECT * FROM neg_tipos ORDER BY nome ASC")->fetchAll();
}

// ─── Log de atividades dos usuários ──────────────────────────────────────────
function logActivity(string $pagina, string $acao, string $detalhes = ''): void {
    global $pdo;
    $userId   = $_SESSION['user_id']   ?? null;
    $userName = $_SESSION['user_nome'] ?? '';
    if (!$userId) return;
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $pdo->prepare(
            "INSERT INTO usuarios_log (usuario_id, usuario_nome, pagina, acao, detalhes, ip)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$userId, $userName, $pagina, $acao, $detalhes ?: null, $ip]);
    } catch (PDOException $e) {
        // Falha silenciosa — log não deve quebrar a aplicação
    }
}

// ─── Email (Gmail SMTP) ───────────────────────────────────────────────────────
define('GMAIL_USER',       'contato@sismedic.com.br');
define('GMAIL_PASS',       'gfksjksrmpjzskxz');
define('MAIL_FROM_NAME',   'CRM RD Pets');
define('MAIL_SITE_URL',    'http://18.190.119.64/crm');

// ─── WhatsApp Cloud API (Meta) ───────────────────────────────────────────────
define('WA_PHONE_ID',      '');          // Phone Number ID — Meta for Developers
define('WA_ACCESS_TOKEN',  '');          // Token permanente do System User
define('WA_TEMPLATE_NAME', 'tarefa_atribuida'); // Nome do template aprovado no Meta
define('WA_TEMPLATE_LANG', 'pt_BR');

// ─── Google OAuth 2.0 ────────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     'SEU_CLIENT_ID_AQUI');
define('GOOGLE_CLIENT_SECRET', 'SEU_CLIENT_SECRET_AQUI');
define('GOOGLE_REDIRECT_URI',  'http://localhost/crm/google_callback.php');

// ─── Meta / Instagram Integration ────────────────────────────────────────────
// Configurações persistidas na tabela configuracoes_meta (gerenciadas via instagram_config.php)
function getMetaConfig(string $key, string $default = ''): string {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT valor FROM configuracoes_meta WHERE chave = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== '' ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setMetaConfig(string $key, string $value): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO configuracoes_meta (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    )->execute([$key, $value]);
}

// ─── Notificações ────────────────────────────────────────────────────────────
function criarNotificacao(string $tipo, string $titulo, ?string $mensagem = null, ?string $link = null, ?int $refId = null, ?int $usuarioId = null): void {
    global $pdo;
    try {
        $pdo->prepare(
            'INSERT INTO notificacoes (tipo, titulo, mensagem, link, ref_id, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$tipo, $titulo, $mensagem, $link, $refId, $usuarioId]);
    } catch (PDOException $e) {
        // Falha silenciosa
    }
}

function contarNotificacoesNaoLidas(?int $usuarioId = null): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notificacoes WHERE lida = 0 AND (usuario_id IS NULL OR usuario_id = ?)'
        );
        $stmt->execute([$usuarioId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function tempoRelativo(string $data): string {
    $diff = time() - strtotime($data);
    if ($diff < 60)   return 'agora';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    return date('d/m', strtotime($data));
}
