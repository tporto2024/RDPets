<?php
ob_start();
$pageTitle   = 'Trial Hostel';
$currentPage = 'trial_hostel';
require_once __DIR__ . '/auth.php';
requireLogin();

// ── Ensure trial_leads table exists ────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trial_leads (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            nome            VARCHAR(255) NOT NULL,
            email           VARCHAR(255) NOT NULL,
            telefone        VARCHAR(30)  NULL,
            tipo_negocio    VARCHAR(50)  NULL,
            origem_lead     VARCHAR(50)  NULL,
            codigo_parceiro VARCHAR(50)  NULL,
            status          ENUM('pendente','confirmado') NOT NULL DEFAULT 'pendente',
            confirmado_em   DATETIME NULL,
            observacoes      TEXT NULL,
            criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Table already exists — ok
}

// ── POST actions ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $user   = getCurrentUser();

    // Alterar status
    if ($action === 'status' && $id) {
        $novoStatus = $_POST['novo_status'] ?? '';
        if (in_array($novoStatus, ['pendente','confirmado'])) {
            $updates = 'status = ?';
            $params  = [$novoStatus];
            if ($novoStatus === 'confirmado') {
                $updates .= ', confirmado_em = NOW()';
            } else {
                $updates .= ', confirmado_em = NULL';
            }
            $params[] = $id;
            $pdo->prepare("UPDATE trial_leads SET $updates WHERE id = ?")->execute($params);
            logActivity('Trial Hostel', 'Alterou status', "Lead #$id -> $novoStatus");
        }
    }

    // Converter em cliente
    if ($action === 'converter' && $id) {
        $lead = $pdo->prepare('SELECT * FROM trial_leads WHERE id = ?');
        $lead->execute([$id]);
        $lead = $lead->fetch();

        if ($lead && $lead['status'] !== 'convertido') {
            $pdo->beginTransaction();
            try {
                $obs = trim(
                    'Origem: Trial Hostel (' . ($lead['origem_lead'] ?? '') . ')'
                    . ($lead['tipo_negocio'] ? "\nTipo: " . $lead['tipo_negocio'] : '')
                    . ($lead['codigo_parceiro'] ? "\nParceiro: " . $lead['codigo_parceiro'] : '')
                    . ($lead['observacoes'] ? "\nObs: " . $lead['observacoes'] : '')
                );
                $pdo->prepare(
                    'INSERT INTO clientes (nome, telefone, email, tipo_negocio, observacoes, origem)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $lead['nome'],
                    $lead['telefone'],
                    $lead['email'],
                    $lead['tipo_negocio'] ?: 'Hotel Pet',
                    $obs ?: null,
                    'Inbound'
                ]);
                $clienteId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE trial_leads SET status = ? WHERE id = ?')
                    ->execute(['confirmado', $id]);
                $pdo->commit();
                logActivity('Trial Hostel', 'Converteu trial em cliente', "Lead #$id -> Cliente #$clienteId");
                header("Location: trial_hostel.php?convertido=$clienteId");
                exit;
            } catch (\Exception $e) {
                $pdo->rollBack();
            }
        }
    }

    // Salvar observacoes
    if ($action === 'salvar_obs' && $id) {
        $obs = trim($_POST['observacoes'] ?? '');
        $pdo->prepare('UPDATE trial_leads SET observacoes = ? WHERE id = ?')->execute([$obs ?: null, $id]);
        logActivity('Trial Hostel', 'Salvou observacoes', "Lead #$id");
    }

    // Excluir
    if ($action === 'excluir' && $id && isMaster()) {
        $pdo->prepare('DELETE FROM trial_leads WHERE id = ?')->execute([$id]);
        logActivity('Trial Hostel', 'Excluiu trial lead', "Lead #$id");
    }

    // Redirect preservando filtros
    $qs = http_build_query(array_filter([
        'status'   => $_POST['_fs'] ?? '',
        'tipo'     => $_POST['_ft'] ?? '',
        'origem'   => $_POST['_fo'] ?? '',
        'parceiro' => $_POST['_fp'] ?? '',
        'busca'    => $_POST['_fb'] ?? '',
        'pg'       => $_POST['_pg'] ?? '',
    ]));
    header("Location: trial_hostel.php" . ($qs ? "?$qs" : ''));
    exit;
}

require_once __DIR__ . '/_nav.php';
logActivity('Trial Hostel', 'Visualizou trial leads');

// ── Filters ────────────────────────────────────────────────────────────────────
$filtroStatus   = $_GET['status']   ?? '';
$filtroTipo     = $_GET['tipo']     ?? '';
$filtroOrigem   = $_GET['origem']   ?? '';
$filtroParceiro = $_GET['parceiro'] ?? '';
$busca          = trim($_GET['busca'] ?? '');
$pagina         = max(1, (int)($_GET['pg'] ?? 1));
$porPagina      = 25;

$where  = [];
$params = [];

if ($filtroStatus && in_array($filtroStatus, ['pendente','confirmado','expirado','convertido','descartado'])) {
    $where[]  = 'tl.status = ?';
    $params[] = $filtroStatus;
}
if ($filtroTipo) {
    $where[]  = 'tl.tipo_negocio = ?';
    $params[] = $filtroTipo;
}
if ($filtroOrigem) {
    $where[]  = 'tl.origem_lead = ?';
    $params[] = $filtroOrigem;
}
if ($filtroParceiro) {
    $where[]  = 'tl.codigo_parceiro = ?';
    $params[] = $filtroParceiro;
}
if ($busca) {
    $where[]  = '(tl.nome LIKE ? OR tl.email LIKE ? OR tl.telefone LIKE ? OR tl.codigo_parceiro LIKE ?)';
    $like = "%$busca%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM trial_leads tl $whereSQL");
$stmtCount->execute($params);
$total   = (int)$stmtCount->fetchColumn();
$paginas = max(1, ceil($total / $porPagina));
$offset  = ($pagina - 1) * $porPagina;

// Fetch leads
$stmtLeads = $pdo->prepare("
    SELECT tl.*
    FROM trial_leads tl
    $whereSQL
    ORDER BY tl.criado_em DESC
    LIMIT $porPagina OFFSET $offset
");
$stmtLeads->execute($params);
$leads = $stmtLeads->fetchAll();

// Status counters
$contadores = [];
foreach (['pendente','confirmado','expirado','convertido','descartado'] as $s) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM trial_leads WHERE status = ?");
    $st->execute([$s]);
    $contadores[$s] = (int)$st->fetchColumn();
}
$totalGeral = array_sum($contadores);

// Distinct values for filters
$tipos = $pdo->query("SELECT DISTINCT tipo_negocio FROM trial_leads WHERE tipo_negocio IS NOT NULL AND tipo_negocio != '' ORDER BY tipo_negocio")->fetchAll(PDO::FETCH_COLUMN);
$origens = $pdo->query("SELECT DISTINCT origem_lead FROM trial_leads WHERE origem_lead IS NOT NULL AND origem_lead != '' ORDER BY origem_lead")->fetchAll(PDO::FETCH_COLUMN);
$parceiros = $pdo->query("SELECT DISTINCT codigo_parceiro FROM trial_leads WHERE codigo_parceiro IS NOT NULL AND codigo_parceiro != '' ORDER BY codigo_parceiro")->fetchAll(PDO::FETCH_COLUMN);

// Hidden fields for POST forms
$hiddenFields = '<input type="hidden" name="_fs" value="' . e($filtroStatus) . '">'
    . '<input type="hidden" name="_ft" value="' . e($filtroTipo) . '">'
    . '<input type="hidden" name="_fo" value="' . e($filtroOrigem) . '">'
    . '<input type="hidden" name="_fp" value="' . e($filtroParceiro) . '">'
    . '<input type="hidden" name="_fb" value="' . e($busca) . '">'
    . '<input type="hidden" name="_pg" value="' . e((string)$pagina) . '">';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-5">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-amber-500">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
        </div>
        <div>
            <h2 class="text-sm font-bold text-gray-800">Trial Hostel</h2>
            <p class="text-xs text-gray-400"><?= $totalGeral ?> leads total</p>
        </div>
    </div>

    <!-- Search + Filters -->
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Buscar nome, email, tel..."
               class="border rounded-lg px-3 py-1.5 text-sm w-44 focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
        <select name="status" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="">Status</option>
            <option value="pendente" <?= $filtroStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
            <option value="confirmado" <?= $filtroStatus === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
        </select>
        <select name="tipo" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="">Tipo</option>
            <?php foreach ($tipos as $t): ?>
            <option value="<?= e($t) ?>" <?= $filtroTipo === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="origem" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="">Origem</option>
            <?php foreach ($origens as $o): ?>
            <option value="<?= e($o) ?>" <?= $filtroOrigem === $o ? 'selected' : '' ?>><?= e($o) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($parceiros)): ?>
        <select name="parceiro" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="">Parceiro</option>
            <?php foreach ($parceiros as $pc): ?>
            <option value="<?= e($pc) ?>" <?= $filtroParceiro === $pc ? 'selected' : '' ?>><?= e($pc) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700">Filtrar</button>
        <?php if ($busca || $filtroStatus || $filtroTipo || $filtroOrigem || $filtroParceiro): ?>
        <a href="trial_hostel.php" class="text-xs text-gray-500 hover:text-gray-700">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Status badges -->
<div class="flex flex-wrap gap-2 mb-4">
    <div class="flex gap-1 text-xs">
        <a href="trial_hostel.php" class="inline-flex items-center gap-1 px-2 py-1 rounded-full font-semibold <?= !$filtroStatus ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            Todos <span class="font-bold"><?= $totalGeral ?></span>
        </a>
        <?php
        $statusBadges = [
            'pendente'   => ['Pendente',   'bg-yellow-500 text-white', 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100'],
            'confirmado' => ['Confirmado', 'bg-green-600 text-white',  'bg-green-50 text-green-700 hover:bg-green-100'],
            'expirado'   => ['Expirado',   'bg-red-600 text-white',    'bg-red-50 text-red-700 hover:bg-red-100'],
            'convertido' => ['Convertido', 'bg-blue-600 text-white',   'bg-blue-50 text-blue-700 hover:bg-blue-100'],
            'descartado' => ['Descartado', 'bg-gray-600 text-white',   'bg-gray-100 text-gray-600 hover:bg-gray-200'],
        ];
        foreach ($statusBadges as $sk => [$sl, $active, $inactive]):
            if (($contadores[$sk] ?? 0) === 0 && $filtroStatus !== $sk) continue;
        ?>
        <a href="trial_hostel.php?status=<?= $sk ?>" class="inline-flex items-center gap-1 px-2 py-1 rounded-full font-semibold <?= $filtroStatus === $sk ? $active : $inactive ?>">
            <?= $sl ?> <span class="font-bold"><?= $contadores[$sk] ?? 0 ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Alerts -->
<?php if (isset($_GET['convertido'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 rounded px-3 py-2 mb-3 text-sm flex items-center gap-2">
    Convertido em cliente!
    <a href="cliente_form.php?id=<?= (int)$_GET['convertido'] ?>" class="underline font-medium">Ver cliente &rarr;</a>
</div>
<?php endif; ?>

<!-- Table -->
<div class="bg-white rounded-xl border shadow-sm overflow-hidden">
<?php if (empty($leads)): ?>
    <div class="text-center py-12">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
        </svg>
        <p class="text-sm text-gray-500 font-medium">Nenhum trial encontrado</p>
        <p class="text-xs text-gray-400 mt-1">Os leads de trial aparecerao aqui automaticamente.</p>
    </div>
<?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide bg-gray-50 border-b">
                    <th class="px-3 py-2.5 text-left w-6"><input type="checkbox" id="checkAll" class="rounded"></th>
                    <th class="px-3 py-2.5 text-left">Nome</th>
                    <th class="px-3 py-2.5 text-left">Telefone</th>
                    <th class="px-3 py-2.5 text-center">Tipo</th>
                    <th class="px-3 py-2.5 text-center">Origem</th>
                    <th class="px-3 py-2.5 text-center">Parceiro</th>
                    <th class="px-3 py-2.5 text-center">Status</th>
                    <th class="px-3 py-2.5 text-left">Criado em</th>
                    <th class="px-3 py-2.5 text-right">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($leads as $l):
                $tel  = $l['telefone'] ?? '';
                $telC = preg_replace('/\D/', '', $tel);
                if ($telC && strlen($telC) <= 11) $telC = '55' . $telC;

                $tipoBadge = match($l['tipo_negocio'] ?? '') {
                    'hotel_pet'  => 'bg-blue-100 text-blue-700',
                    'creche'     => 'bg-green-100 text-green-700',
                    'petsitter'  => 'bg-purple-100 text-purple-700',
                    default      => 'bg-gray-100 text-gray-600',
                };
                $origemBadge = match($l['origem_lead'] ?? '') {
                    'google'    => 'bg-red-100 text-red-700',
                    'instagram' => 'bg-pink-100 text-pink-700',
                    'facebook'  => 'bg-blue-100 text-blue-700',
                    'indicacao' => 'bg-amber-100 text-amber-700',
                    'parceiro'  => 'bg-emerald-100 text-emerald-700',
                    default     => 'bg-gray-100 text-gray-600',
                };
            ?>
            <tr class="hover:bg-amber-50/50 transition-colors group" id="row-<?= $l['id'] ?>">
                <td class="px-3 py-2"><input type="checkbox" class="rounded row-check" value="<?= $l['id'] ?>"></td>
                <!-- Nome -->
                <td class="px-3 py-2">
                    <p class="font-semibold text-gray-800"><?= e($l['nome']) ?></p>
                    <p class="text-xs text-gray-400"><?= e($l['email']) ?></p>
                </td>
                <!-- Telefone -->
                <td class="px-3 py-2">
                    <?php if ($tel): ?>
                    <div class="flex items-center gap-1.5">
                        <span class="text-gray-700 text-xs"><?= e($tel) ?></span>
                        <a href="https://wa.me/<?= e($telC) ?>" target="_blank" title="WhatsApp"
                           class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-500 hover:bg-green-600 text-white">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        </a>
                    </div>
                    <?php else: ?>
                    <span class="text-gray-300">&mdash;</span>
                    <?php endif; ?>
                </td>
                <!-- Tipo -->
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $tipoBadge ?>">
                        <?= e($l['tipo_negocio'] ?? '-') ?>
                    </span>
                </td>
                <!-- Origem -->
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $origemBadge ?>">
                        <?= e($l['origem_lead'] ?? '-') ?>
                    </span>
                </td>
                <!-- Parceiro -->
                <td class="px-3 py-2 text-center">
                    <?php if (!empty($l['codigo_parceiro'])): ?>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">
                        <?= e($l['codigo_parceiro']) ?>
                    </span>
                    <?php else: ?>
                    <span class="text-gray-300">&mdash;</span>
                    <?php endif; ?>
                </td>
                <!-- Status -->
                <td class="px-3 py-2 text-center">
                    <?php
                    $stColors = [
                        'pendente'   => 'bg-yellow-100 text-yellow-700',
                        'confirmado' => 'bg-green-100 text-green-700',
                        'expirado'   => 'bg-red-100 text-red-700',
                        'convertido' => 'bg-blue-100 text-blue-700',
                        'descartado' => 'bg-gray-100 text-gray-500',
                    ];
                    $stLabel = ucfirst($l['status'] ?? 'pendente');
                    $stColor = $stColors[$l['status'] ?? 'pendente'] ?? 'bg-gray-100 text-gray-600';
                    ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold <?= $stColor ?>"><?= $stLabel ?></span>
                </td>
                <!-- Criado em -->
                <td class="px-3 py-2">
                    <p class="text-xs text-gray-600"><?= date('d/m/Y', strtotime($l['criado_em'])) ?></p>
                    <p class="text-[10px] text-gray-400"><?= date('H:i', strtotime($l['criado_em'])) ?></p>
                </td>
                <!-- Acoes -->
                <td class="px-3 py-2 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <form method="POST" class="inline" onsubmit="return confirm('Converter este trial em cliente?')">
                            <input type="hidden" name="action" value="converter">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <?= $hiddenFields ?>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium px-2 py-0.5 rounded text-xs" title="Converter em cliente">Conv.</button>
                        </form>
                        <?php if (isMaster()): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Excluir este trial lead?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <?= $hiddenFields ?>
                            <button type="submit" class="text-red-400 hover:text-red-600 font-bold" title="Excluir">&times;</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <!-- Observacao row -->
            <tr class="bg-gray-50/50">
                <td></td>
                <td colspan="8" class="px-3 pb-2 pt-0">
                    <form method="POST" class="flex items-center gap-2">
                        <input type="hidden" name="action" value="salvar_obs">
                        <input type="hidden" name="id" value="<?= $l['id'] ?>">
                        <?= $hiddenFields ?>
                        <input type="text" name="observacoes" value="<?= e($l['observacoes'] ?? '') ?>"
                               placeholder="Observacao..."
                               class="flex-1 border border-gray-200 rounded px-2 py-1 text-xs focus:ring-1 focus:ring-amber-400 focus:outline-none bg-white">
                        <button type="submit" class="text-blue-600 hover:text-blue-800 text-xs font-semibold hidden obs-save">Salvar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($paginas > 1): ?>
    <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            Mostrando <?= $offset + 1 ?> - <?= min($offset + $porPagina, $total) ?> de <?= $total ?>
        </p>
        <div class="flex gap-1">
            <?php for ($p = 1; $p <= $paginas; $p++):
                $pgQs = http_build_query(array_filter([
                    'status' => $filtroStatus, 'tipo' => $filtroTipo, 'origem' => $filtroOrigem,
                    'parceiro' => $filtroParceiro, 'busca' => $busca, 'pg' => $p > 1 ? $p : null
                ]));
            ?>
            <a href="trial_hostel.php<?= $pgQs ? "?$pgQs" : '' ?>"
               class="px-2.5 py-1 rounded text-xs font-medium <?= $p === $pagina ? 'bg-amber-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-100' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
// Check all
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
// Show save button on obs change
document.querySelectorAll('input[name="observacoes"]').forEach(inp => {
    const orig = inp.value;
    inp.addEventListener('input', function() {
        const btn = this.closest('form').querySelector('.obs-save');
        btn?.classList.toggle('hidden', this.value === orig);
    });
});
</script>

<?php require_once '_footer.php'; ?>
