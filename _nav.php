<?php
// Inclua este arquivo no topo de cada página autenticada.
// Variáveis esperadas: $pageTitle (string), $currentPage (string)
require_once __DIR__ . '/auth.php';
requireLogin();

// Garante que o perfil sempre está atualizado na sessão
if (empty($_SESSION['user_perfil']) && !empty($_SESSION['user_id'])) {
    $stmtPerfil = $pdo->prepare('SELECT perfil FROM usuarios WHERE id = ?');
    $stmtPerfil->execute([$_SESSION['user_id']]);
    $_SESSION['user_perfil'] = $stmtPerfil->fetchColumn() ?: 'user';
}

$user = getCurrentUser();
$page = $currentPage ?? '';

// Badges dinâmicos
$tarefasAtrasadas = (int)$pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta' AND quando < NOW()")->fetchColumn();
$tarefasAbertas   = (int)$pdo->query("SELECT COUNT(*) FROM tarefas WHERE status='aberta'")->fetchColumn();
$totalClientes    = (int)$pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
// Conta negociações em etapas abertas (não encerradas) de forma dinâmica
$_encSub = $pdo->query("SELECT nome FROM neg_etapas WHERE is_encerrada=1")->fetchAll(PDO::FETCH_COLUMN);
$_inEnc  = $_encSub ? "'" . implode("','", array_map('addslashes', $_encSub)) . "'" : "'__none__'";
$negsAbertas = (int)$pdo->query("SELECT COUNT(*) FROM negociacoes WHERE etapa NOT IN ($_inEnc)")->fetchColumn();
try { $totalLeadsNovos = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'novo'")->fetchColumn(); } catch (PDOException $e) { $totalLeadsNovos = 0; }
try { $totalTrialPendentes = (int)$pdo->query("SELECT COUNT(*) FROM trial_leads WHERE status = 'pendente'")->fetchColumn(); } catch (PDOException $e) { $totalTrialPendentes = 0; }
$notifNaoLidas = contarNotificacoesNaoLidas($_SESSION['user_id'] ?? 0);

$nav = [
    [
        'href'  => 'index.php',
        'label' => 'Dashboard',
        'key'   => 'dashboard',
        'badge' => null,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
    ],
    [
        'href'  => 'clientes.php',
        'label' => 'Clientes',
        'key'   => 'clientes',
        'badge' => $totalClientes,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
    ],
    [
        'href'  => 'leads.php',
        'label' => 'Leads',
        'key'   => 'leads',
        'badge' => $totalLeadsNovos ?: null,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>',
    ],
    [
        'href'  => 'negociacoes.php',
        'label' => 'Negociações',
        'key'   => 'negociacoes',
        'badge' => $negsAbertas,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
    ],
    [
        'href'  => 'tarefas.php',
        'label' => 'Tarefas',
        'key'   => 'tarefas',
        'badge' => $tarefasAbertas ?: null,
        'badge_alert' => $tarefasAtrasadas > 0,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
    ],
    [
        'href'  => 'trial_hostel.php',
        'label' => 'Trial Hostel',
        'key'   => 'trial_hostel',
        'badge' => $totalTrialPendentes ?: null,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
    ],
    [
        'href'  => 'uso_hostelpets.php',
        'label' => 'Uso HostelPets',
        'key'   => 'uso_hostelpets',
        'badge' => null,
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
    ],
];

// Iniciais do nome (até 2 letras)
$iniciais = implode('', array_map(fn($p) => strtoupper($p[0]),
    array_slice(explode(' ', trim($user['nome'])), 0, 2)));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'CRM') ?> — CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        :root { --sidebar-w: 260px; }

        /* Sidebar */
        #sidebar {
            width: var(--sidebar-w);
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            transition: width .25s ease, transform .25s ease;
        }
        #sidebar.collapsed { width: 68px; }

        /* Nav links */
        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            font-size: .8125rem; font-weight: 500;
            color: #94a3b8;
            transition: background .18s, color .18s, padding .25s;
            position: relative; white-space: nowrap; overflow: hidden;
        }
        .nav-link:hover  { background: rgba(255,255,255,.07); color: #e2e8f0; }
        .nav-link.active {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            box-shadow: 0 4px 14px rgba(37,99,235,.35);
        }
        .nav-link .nav-icon { flex-shrink: 0; width: 20px; height: 20px; }
        .nav-label { transition: opacity .2s, width .25s; }

        /* Collapsed state */
        #sidebar.collapsed .nav-label,
        #sidebar.collapsed .section-label,
        #sidebar.collapsed .user-name,
        #sidebar.collapsed .user-role,
        #sidebar.collapsed .logout-btn span { opacity: 0; width: 0; overflow: hidden; }
        #sidebar.collapsed .nav-link { padding: 10px 14px; justify-content: center; gap: 0; }
        #sidebar.collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }
        #sidebar.collapsed .badge-count { display: none; }

        /* Tooltip no modo collapsed */
        #sidebar.collapsed .nav-link::after {
            content: attr(data-tooltip);
            position: absolute; left: 100%; top: 50%; transform: translateY(-50%);
            background: #1e293b; color: #e2e8f0;
            font-size: .75rem; padding: 5px 10px; border-radius: 6px;
            white-space: nowrap; pointer-events: none;
            opacity: 0; transition: opacity .15s; margin-left: 10px;
            border: 1px solid rgba(255,255,255,.1);
            z-index: 50;
        }
        #sidebar.collapsed .nav-link:hover::after { opacity: 1; }

        /* Badge */
        .badge-count {
            margin-left: auto; font-size: .65rem; font-weight: 700;
            padding: 1px 7px; border-radius: 20px; flex-shrink: 0;
        }
        .badge-normal { background: rgba(255,255,255,.12); color: #94a3b8; }
        .badge-alert  { background: #ef4444; color: #fff; animation: pulse-badge 2s infinite; }
        @keyframes pulse-badge {
            0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
            50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
        }

        /* Divider label */
        .section-label {
            font-size: .625rem; font-weight: 700; letter-spacing: .1em;
            color: #475569; padding: 14px 16px 6px; text-transform: uppercase;
            white-space: nowrap; overflow: hidden;
            transition: opacity .2s;
        }

        /* Active indicator bar */
        .nav-link.active::before {
            content: '';
            position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; background: #93c5fd; border-radius: 0 3px 3px 0;
        }

        /* User area */
        .user-avatar {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 700; color: #fff;
            flex-shrink: 0; letter-spacing: .05em;
        }

        /* Toggle button */
        #toggle-sidebar {
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 8px; padding: 6px;
            color: #64748b; cursor: pointer;
            transition: background .18s, color .18s;
        }
        #toggle-sidebar:hover { background: rgba(255,255,255,.13); color: #e2e8f0; }

        /* General */
        .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        [x-cloak] { display: none; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex">

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<aside id="sidebar" class="flex flex-col flex-shrink-0 min-h-screen relative z-20">

    <!-- Logo + toggle -->
    <div class="flex items-center justify-between px-4 py-5 border-b border-white/5">
        <div class="flex items-center gap-3 overflow-hidden">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                 style="background:linear-gradient(135deg,#2563eb,#7c3aed)">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
            </div>
            <div class="logo-text overflow-hidden">
                <p class="text-white font-bold text-sm leading-tight">CRM</p>
                <p class="text-slate-400 text-xs">Gestão Comercial</p>
            </div>
        </div>
        <button id="toggle-sidebar" title="Recolher menu">
            <svg id="icon-collapse" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
            </svg>
            <svg id="icon-expand" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
            </svg>
        </button>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-3 overflow-y-auto overflow-x-hidden">
        <p class="section-label">Menu Principal</p>

        <?php foreach ($nav as $item):
            $isActive = $page === $item['key'];
            $hasAlert = !empty($item['badge_alert']);
            $badge    = $item['badge'] ?? null;
        ?>
        <a href="<?= $item['href'] ?>"
           data-tooltip="<?= e($item['label']) ?>"
           class="nav-link <?= $isActive ? 'active' : '' ?> mb-0.5">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?= $item['icon'] ?>
            </svg>
            <span class="nav-label flex-1"><?= e($item['label']) ?></span>
            <?php if ($badge !== null): ?>
            <span class="badge-count <?= $hasAlert ? 'badge-alert' : 'badge-normal' ?>">
                <?= $hasAlert ? $tarefasAtrasadas . '!' : $badge ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>

        <!-- Separador -->
        <div class="my-3 border-t border-white/5"></div>
        <p class="section-label">Ações rápidas</p>

        <a href="cliente_form.php"
           data-tooltip="Novo Cliente"
           class="nav-link mb-0.5">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
            </svg>
            <span class="nav-label">Novo Cliente</span>
        </a>

        <a href="importar.php"
           data-tooltip="Importar Planilha"
           class="nav-link mb-0.5 <?= $page === 'importar' ? 'active' : '' ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="nav-label">Importar Planilha</span>
        </a>

        <a href="negociacao_detalhe.php?novo=1"
           data-tooltip="Nova Negociação"
           class="nav-link mb-0.5">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M12 4v16m8-8H4"/>
            </svg>
            <span class="nav-label">Nova Negociação</span>
        </a>

        <?php if (isMaster()): ?>
        <!-- Separador -->
        <div class="my-3 border-t border-white/5"></div>
        <p class="section-label">Sistema</p>

        <a href="usuarios.php"
           data-tooltip="Usuários"
           class="nav-link mb-0.5 <?= $page === 'usuarios' ? 'active' : '' ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span class="nav-label">Usuários</span>
        </a>

        <a href="configuracoes.php"
           data-tooltip="Configurações"
           class="nav-link mb-0.5 <?= $page === 'configuracoes' ? 'active' : '' ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="nav-label">Configurações</span>
        </a>

        <a href="whatsapp_crm.php"
           data-tooltip="WhatsApp CRM"
           class="nav-link mb-0.5 <?= $page === 'whatsapp_crm' ? 'active' : '' ?>">
            <svg class="nav-icon" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
            <span class="nav-label">WhatsApp CRM</span>
        </a>

        <a href="instagram_config.php"
           data-tooltip="Instagram"
           class="nav-link mb-0.5 <?= $page === 'instagram' ? 'active' : '' ?>">
            <svg class="nav-icon" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
            </svg>
            <span class="nav-label">Instagram</span>
        </a>
        <?php endif; ?>
    </nav>

    <!-- User info -->
    <div class="border-t border-white/5 px-3 py-4">
        <div class="flex items-center gap-3 overflow-hidden">
            <div class="user-avatar"><?= e($iniciais) ?></div>
            <div class="flex-1 min-w-0 user-name overflow-hidden">
                <p class="text-slate-200 text-xs font-semibold truncate"><?= e($user['nome']) ?></p>
                <p class="text-slate-500 text-xs user-role">
                    <?= isMaster() ? '👑 Master' : '👤 Usuário' ?>
                </p>
            </div>
            <a href="logout.php"
               title="Sair"
               class="logout-btn flex items-center gap-1 text-slate-500 hover:text-red-400 transition-colors flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>
</aside>

<!-- ── Main content wrapper ──────────────────────────────────────────────── -->
<div class="flex-1 flex flex-col min-w-0">
    <!-- Top bar -->
    <header class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <h1 class="text-base font-bold text-gray-800"><?= e($pageTitle ?? 'CRM') ?></h1>
        </div>
        <div class="flex items-center gap-4">
            <?php if ($tarefasAtrasadas > 0): ?>
            <a href="tarefas.php?status=aberta" title="Tarefas atrasadas"
               class="flex items-center gap-1.5 text-red-500 hover:text-red-700 text-xs font-semibold">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <?= $tarefasAtrasadas ?> atrasada<?= $tarefasAtrasadas > 1 ? 's' : '' ?>
            </a>
            <?php endif; ?>

            <!-- Sino de notificações -->
            <div class="relative" id="notif-wrapper">
                <button id="notif-bell" class="relative p-1.5 text-gray-400 hover:text-gray-600 transition-colors rounded-lg hover:bg-gray-100" title="Notificações">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <?php if ($notifNaoLidas > 0): ?>
                    <span id="notif-badge" class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1 animate-pulse">
                        <?= $notifNaoLidas > 99 ? '99+' : $notifNaoLidas ?>
                    </span>
                    <?php else: ?>
                    <span id="notif-badge" class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1 hidden">0</span>
                    <?php endif; ?>
                </button>

                <!-- Dropdown de notificações -->
                <div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-xl border border-gray-200 z-50 overflow-hidden">
                    <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
                        <h3 class="text-xs font-bold text-gray-700">Notificações</h3>
                        <button id="notif-mark-all" class="text-[10px] text-blue-600 hover:text-blue-800 font-medium">
                            Marcar todas como lidas
                        </button>
                    </div>
                    <div id="notif-list" class="max-h-80 overflow-y-auto">
                        <div class="px-4 py-6 text-center text-xs text-gray-400">Carregando...</div>
                    </div>
                    <div class="px-4 py-2.5 border-t bg-gray-50 text-center">
                        <a href="instagram_leads.php" class="text-[11px] text-blue-600 hover:text-blue-800 font-medium">Ver todos os leads</a>
                    </div>
                </div>
            </div>

            <span class="text-xs text-gray-400 font-medium"><?= date('d \d\e F \d\e Y') ?></span>
        </div>
    </header>

    <!-- Page content -->
    <main class="flex-1 p-6">

<script>
(function(){
    const sidebar = document.getElementById('sidebar');
    const iconC   = document.getElementById('icon-collapse');
    const iconE   = document.getElementById('icon-expand');
    const key     = 'sidebar_collapsed';

    function apply(collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        iconC.classList.toggle('hidden', collapsed);
        iconE.classList.toggle('hidden', !collapsed);
    }

    apply(localStorage.getItem(key) === '1');

    document.getElementById('toggle-sidebar').addEventListener('click', () => {
        const now = sidebar.classList.contains('collapsed');
        localStorage.setItem(key, now ? '0' : '1');
        apply(!now);
    });
})();

// ── Notificações (sino) ──────────────────────────────────────────────────────
(function(){
    const bell     = document.getElementById('notif-bell');
    const dropdown = document.getElementById('notif-dropdown');
    const badge    = document.getElementById('notif-badge');
    const list     = document.getElementById('notif-list');
    const markAll  = document.getElementById('notif-mark-all');
    if (!bell) return;

    let isOpen = false;

    // Toggle dropdown
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen = !isOpen;
        dropdown.classList.toggle('hidden', !isOpen);
        if (isOpen) loadNotifs();
    });

    // Fechar ao clicar fora
    document.addEventListener('click', (e) => {
        if (!document.getElementById('notif-wrapper').contains(e.target)) {
            isOpen = false;
            dropdown.classList.add('hidden');
        }
    });

    // Carregar notificações
    function loadNotifs() {
        fetch('api_notificacoes.php?action=buscar&limit=15')
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                const notifs = data.notificacoes;
                if (notifs.length === 0) {
                    list.innerHTML = '<div class="px-4 py-6 text-center text-xs text-gray-400">Nenhuma notificação</div>';
                    return;
                }
                list.innerHTML = notifs.map(n => {
                    const isIG = n.tipo === 'instagram_lead';
                    const icon = isIG
                        ? '<svg class="w-4 h-4 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'
                        : '<svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
                    const bg = n.lida == 0 ? 'bg-blue-50' : '';
                    const link = n.link || '#';
                    return `<a href="${link}" class="flex gap-3 px-4 py-3 hover:bg-gray-50 border-b border-gray-100 ${bg} transition-colors" data-notif-id="${n.id}">
                        <div class="flex-shrink-0 mt-0.5">${icon}</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-700 truncate">${n.titulo}</p>
                            ${n.mensagem ? '<p class="text-[11px] text-gray-500 truncate">' + n.mensagem + '</p>' : ''}
                            <p class="text-[10px] text-gray-400 mt-0.5">${n.tempo}</p>
                        </div>
                    </a>`;
                }).join('');
            })
            .catch(() => {
                list.innerHTML = '<div class="px-4 py-6 text-center text-xs text-red-400">Erro ao carregar</div>';
            });
    }

    // Marcar todas como lidas
    markAll.addEventListener('click', () => {
        fetch('api_notificacoes.php?action=marcar_todas', {method:'POST'})
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    badge.classList.add('hidden');
                    badge.textContent = '0';
                    loadNotifs();
                }
            });
    });

    // Marcar individual como lida ao clicar
    list.addEventListener('click', (e) => {
        const link = e.target.closest('[data-notif-id]');
        if (link) {
            const id = link.dataset.notifId;
            fetch('api_notificacoes.php?action=marcar_lida&id=' + id, {method:'POST'});
        }
    });

    // Polling: atualizar contagem a cada 30 segundos
    function updateBadge() {
        fetch('api_notificacoes.php?action=contar')
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                const count = data.count;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            })
            .catch(() => {});
    }
    setInterval(updateBadge, 30000);
})();
</script>
