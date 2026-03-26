<?php
ob_start();
$pageTitle   = 'Integração Instagram';
$currentPage = 'instagram';
require_once __DIR__ . '/_nav.php';
requireMaster();
logActivity('Instagram', 'Acessou configurações Instagram');

$msg = '';
$msgType = '';

// ── Salvar configurações ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar_config') {
        $keys = ['meta_app_id', 'meta_app_secret', 'meta_verify_token', 'meta_page_token', 'meta_page_id'];
        foreach ($keys as $k) {
            setMetaConfig($k, trim($_POST[$k] ?? ''));
        }
        $msg = 'Configurações salvas com sucesso!';
        $msgType = 'success';
        logActivity('Instagram', 'Salvou configurações Meta');
    }

    if ($action === 'testar_conexao') {
        $token = getMetaConfig('meta_page_token');
        $pageId = getMetaConfig('meta_page_id');
        if (!$token || !$pageId) {
            $msg = 'Preencha o Page Token e Page ID antes de testar.';
            $msgType = 'error';
        } else {
            $url = "https://graph.facebook.com/v19.0/{$pageId}?fields=name,instagram_business_account&access_token=" . urlencode($token);
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp) {
                $data = json_decode($resp, true);
                if (!empty($data['name'])) {
                    $pageName = $data['name'];
                    $igId = $data['instagram_business_account']['id'] ?? 'N/A';
                    $msg = "Conexão OK! Página: {$pageName} | Instagram Business ID: {$igId}";
                    $msgType = 'success';
                } elseif (!empty($data['error'])) {
                    $msg = 'Erro Meta API: ' . ($data['error']['message'] ?? 'desconhecido');
                    $msgType = 'error';
                }
            } else {
                $msg = 'Não foi possível conectar à API do Meta.';
                $msgType = 'error';
            }
        }
    }
}

// Carregar configurações atuais
$cfg = [];
$keys = ['meta_app_id', 'meta_app_secret', 'meta_verify_token', 'meta_page_token', 'meta_page_id'];
foreach ($keys as $k) {
    $cfg[$k] = getMetaConfig($k);
}

// Status da integração
$isConnected = !empty($cfg['meta_page_token']) && !empty($cfg['meta_page_id']);

// Estatísticas
try {
    $totalLeadsIG = (int)$pdo->query("SELECT COUNT(*) FROM instagram_leads")->fetchColumn();
    $leadsHoje    = (int)$pdo->query("SELECT COUNT(*) FROM instagram_leads WHERE DATE(criado_em) = CURDATE()")->fetchColumn();
    $leadsSemana  = (int)$pdo->query("SELECT COUNT(*) FROM instagram_leads WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
} catch (PDOException $e) {
    $totalLeadsIG = $leadsHoje = $leadsSemana = 0;
}

$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com')
    . dirname($_SERVER['SCRIPT_NAME']) . '/instagram_webhook.php';
?>

<!-- Mensagem de feedback -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium <?= $msgType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= e($msg) ?>
</div>
<?php endif; ?>

<!-- Status + Estatísticas -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <!-- Status -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $isConnected ? 'bg-green-100' : 'bg-gray-100' ?>">
                <svg class="w-5 h-5 <?= $isConnected ? 'text-green-600' : 'text-gray-400' ?>" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500 font-medium">Status</p>
                <p class="text-sm font-bold <?= $isConnected ? 'text-green-600' : 'text-gray-400' ?>">
                    <?= $isConnected ? 'Conectado' : 'Desconectado' ?>
                </p>
            </div>
        </div>
    </div>
    <!-- Total leads -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500 font-medium">Total Leads</p>
                <p class="text-lg font-bold text-gray-800"><?= $totalLeadsIG ?></p>
            </div>
        </div>
    </div>
    <!-- Leads hoje -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500 font-medium">Leads Hoje</p>
                <p class="text-lg font-bold text-gray-800"><?= $leadsHoje ?></p>
            </div>
        </div>
    </div>
    <!-- Leads semana -->
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
            </div>
            <div>
                <p class="text-xs text-gray-500 font-medium">Leads 7 dias</p>
                <p class="text-lg font-bold text-gray-800"><?= $leadsSemana ?></p>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- ── Configurações ──────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="text-sm font-bold text-gray-800">Configurações Meta / Instagram</h2>
            <form method="POST" class="inline">
                <input type="hidden" name="action" value="testar_conexao">
                <button type="submit" class="text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 px-3 py-1.5 rounded-lg font-medium transition-colors">
                    Testar Conexão
                </button>
            </form>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="salvar_config">

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">App ID</label>
                <input type="text" name="meta_app_id" value="<?= e($cfg['meta_app_id']) ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ex: 1234567890123456">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">App Secret</label>
                <input type="password" name="meta_app_secret" value="<?= e($cfg['meta_app_secret']) ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ex: abc123def456...">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Verify Token (webhook)</label>
                <input type="text" name="meta_verify_token" value="<?= e($cfg['meta_verify_token']) ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Um token qualquer que você escolher">
                <p class="text-xs text-gray-400 mt-1">Token usado para verificar o webhook. Escolha qualquer texto seguro.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Page Access Token</label>
                <textarea name="meta_page_token" rows="3"
                          class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-xs"
                          placeholder="Token de longa duração da página"><?= e($cfg['meta_page_token']) ?></textarea>
                <p class="text-xs text-gray-400 mt-1">Gere um token de longa duração no Meta Business Suite.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Page ID</label>
                <input type="text" name="meta_page_id" value="<?= e($cfg['meta_page_id']) ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="ID da página Facebook/Instagram">
            </div>

            <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold py-2.5 rounded-lg hover:opacity-90 transition-opacity text-sm">
                Salvar Configurações
            </button>
        </form>
    </div>

    <!-- ── Instruções de Setup ────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm border">
        <div class="px-6 py-4 border-b">
            <h2 class="text-sm font-bold text-gray-800">Como Configurar</h2>
        </div>
        <div class="p-6 space-y-4 text-sm text-gray-600">

            <div class="bg-blue-50 rounded-lg p-4 border border-blue-100">
                <p class="font-semibold text-blue-700 text-xs mb-1">URL do Webhook</p>
                <code class="text-xs bg-white px-2 py-1 rounded border block break-all"><?= e($webhookUrl) ?></code>
                <p class="text-xs text-blue-500 mt-1">Use esta URL no Meta for Developers &gt; Webhooks.</p>
            </div>

            <ol class="space-y-3 text-xs">
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">1</span>
                    <div>
                        <p class="font-semibold text-gray-700">Acesse o Meta for Developers</p>
                        <p class="text-gray-500">Vá em <strong>developers.facebook.com</strong> &gt; seu App &gt; Configurações &gt; Básico. Copie o <strong>App ID</strong> e <strong>App Secret</strong>.</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">2</span>
                    <div>
                        <p class="font-semibold text-gray-700">Gere o Page Access Token</p>
                        <p class="text-gray-500">Em <strong>Graph API Explorer</strong>, selecione sua página e gere um token com permissões: <code>pages_manage_metadata</code>, <code>leads_retrieval</code>, <code>instagram_manage_messages</code>, <code>pages_messaging</code>.</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">3</span>
                    <div>
                        <p class="font-semibold text-gray-700">Configure o Webhook</p>
                        <p class="text-gray-500">No app Meta &gt; <strong>Webhooks</strong> &gt; Adicionar assinatura para <strong>Page</strong>. Cole a URL do webhook acima e o <strong>Verify Token</strong> que você definiu.</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">4</span>
                    <div>
                        <p class="font-semibold text-gray-700">Assine os eventos</p>
                        <p class="text-gray-500">Marque os campos: <strong>leadgen</strong> (para Lead Ads) e <strong>messages</strong> (para Instagram Direct).</p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-xs">5</span>
                    <div>
                        <p class="font-semibold text-gray-700">Vincule a página</p>
                        <p class="text-gray-500">Em <strong>Configurações do App &gt; Avançado &gt; Páginas autorizadas</strong>, adicione sua página e assine os webhooks para ela.</p>
                    </div>
                </li>
            </ol>

            <div class="bg-amber-50 rounded-lg p-3 border border-amber-200">
                <p class="text-xs text-amber-700"><strong>Importante:</strong> O webhook precisa de HTTPS com certificado válido. Configure SSL no seu servidor AWS antes de ativar.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once '_footer.php'; ?>
