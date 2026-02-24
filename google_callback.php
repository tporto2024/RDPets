<?php
/**
 * Google OAuth 2.0 Callback Handler
 * Fluxo: login.php → ?action=init → Google → este arquivo com ?code=XXX&state=YYY
 */
require_once __DIR__ . '/auth.php';   // inclui config.php + sessão

// ── Helpers HTTP (sem Composer) ───────────────────────────────────────────────

function googlePost(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'curl_error', 'error_description' => $err];
    }
    return json_decode($body, true) ?? ['error' => 'json_decode_fail'];
}

function googleGet(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => 'curl_error', 'error_description' => $err];
    }
    return json_decode($body, true) ?? ['error' => 'json_decode_fail'];
}

function oauthError(string $msg): never
{
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
    <title>Erro — Login Google</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{background:linear-gradient(135deg,#1e3a5f,#2563eb)}</style>
    </head>
    <body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-10 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-2xl mb-4">
            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
        </div>
        <h2 class="text-lg font-bold text-gray-800 mb-2">Falha no login com Google</h2>
        <p class="text-sm text-gray-500 mb-6">' . htmlspecialchars($msg, ENT_QUOTES) . '</p>
        <a href="login.php"
           class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg px-6 py-2.5 text-sm transition-colors">
            Voltar ao login
        </a>
    </div></body></html>';
    exit;
}

// ── ETAPA 1: Iniciar o fluxo OAuth ───────────────────────────────────────────
if (($_GET['action'] ?? '') === 'init') {

    // Verificar se as credenciais foram configuradas
    if (GOOGLE_CLIENT_ID === 'SEU_CLIENT_ID_AQUI') {
        oauthError('As credenciais do Google OAuth ainda não foram configuradas. Preencha GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET em config.php.');
    }

    // Gerar state aleatório para proteção CSRF
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'state'         => $state,
        'prompt'        => 'select_account',  // Sempre exibe seletor de conta
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── ETAPA 2: Callback do Google ───────────────────────────────────────────────

// Erro retornado pelo Google
if (!empty($_GET['error'])) {
    oauthError('O Google recusou a autorização: ' . htmlspecialchars($_GET['error']));
}

// Verificar parâmetros obrigatórios
if (empty($_GET['code']) || empty($_GET['state'])) {
    oauthError('Parâmetros inválidos no retorno do Google.');
}

// Validar state (proteção CSRF)
if (!isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    unset($_SESSION['oauth_state']);
    oauthError('Token de segurança inválido. Tente novamente.');
}
unset($_SESSION['oauth_state']);

// ── Trocar code por access_token ──────────────────────────────────────────────
$tokenData = googlePost('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (isset($tokenData['error'])) {
    oauthError('Não foi possível obter o token de acesso: ' . ($tokenData['error_description'] ?? $tokenData['error']));
}

$accessToken = $tokenData['access_token'] ?? null;
if (!$accessToken) {
    oauthError('Token de acesso não recebido do Google.');
}

// ── Buscar dados do usuário no Google ─────────────────────────────────────────
$gUser = googleGet('https://www.googleapis.com/oauth2/v2/userinfo', $accessToken);

if (isset($gUser['error']) || empty($gUser['email'])) {
    oauthError('Não foi possível obter os dados do usuário no Google.');
}

$googleId  = $gUser['id']             ?? null;
$email     = strtolower(trim($gUser['email']));
$nome      = $gUser['name']           ?? $email;
$avatarUrl = $gUser['picture']        ?? null;

// ── Verificar / criar usuário no banco ────────────────────────────────────────

// 1. Buscar pelo google_id
$stmt = $pdo->prepare('SELECT id, nome FROM usuarios WHERE google_id = ? LIMIT 1');
$stmt->execute([$googleId]);
$usuario = $stmt->fetch();

if (!$usuario) {
    // 2. Buscar pelo e-mail (conta existente sem google_id)
    $stmt = $pdo->prepare('SELECT id, nome FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        // Vincular google_id ao usuário existente
        $pdo->prepare('UPDATE usuarios SET google_id = ?, avatar_url = ? WHERE id = ?')
            ->execute([$googleId, $avatarUrl, $usuario['id']]);
    } else {
        // 3. Criar novo usuário automaticamente
        $stmt = $pdo->prepare('
            INSERT INTO usuarios (nome, email, google_id, avatar_url, senha_hash)
            VALUES (?, ?, ?, ?, NULL)
        ');
        $stmt->execute([$nome, $email, $googleId, $avatarUrl]);
        $novoId  = $pdo->lastInsertId();
        $usuario = ['id' => $novoId, 'nome' => $nome];
    }
}

// ── Iniciar sessão ────────────────────────────────────────────────────────────
$_SESSION['user_id']   = $usuario['id'];
$_SESSION['user_nome'] = $usuario['nome'];

header('Location: index.php');
exit;
