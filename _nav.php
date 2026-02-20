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
</script>
