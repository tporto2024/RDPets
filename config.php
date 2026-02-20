<?php
// ─── Configurações do Banco de Dados ────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'crm');
define('DB_USER',    'root');
define('DB_PASS',    '');          // Altere se seu MySQL tiver senha
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

// ─── Google OAuth 2.0 ────────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     'SEU_CLIENT_ID_AQUI');
define('GOOGLE_CLIENT_SECRET', 'SEU_CLIENT_SECRET_AQUI');
define('GOOGLE_REDIRECT_URI',  'http://localhost/crm/google_callback.php');
