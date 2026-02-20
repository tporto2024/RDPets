<?php
require_once __DIR__ . '/config.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function isMaster(): bool {
    return ($_SESSION['user_perfil'] ?? '') === 'master';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireMaster(): void {
    requireLogin();
    if (!isMaster()) {
        http_response_code(403);
        die('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
        <script src="https://cdn.tailwindcss.com"></script></head>
        <body class="bg-slate-100 min-h-screen flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow p-10 text-center max-w-sm">
            <div class="text-5xl mb-4">🔒</div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Acesso restrito</h1>
            <p class="text-gray-500 text-sm mb-6">Esta área é exclusiva para administradores master.</p>
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-6 py-2.5 rounded-lg inline-block">
                Voltar ao início
            </a>
        </div></body></html>');
    }
}

function getCurrentUser(): array {
    return [
        'id'     => $_SESSION['user_id']     ?? null,
        'nome'   => $_SESSION['user_nome']   ?? 'Usuário',
        'perfil' => $_SESSION['user_perfil'] ?? 'user',
    ];
}

function getClientIP(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}
