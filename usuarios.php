<?php
$pageTitle   = 'Usuários';
$currentPage = 'usuarios';
require_once __DIR__ . '/_nav.php';
requireMaster();

$msg  = '';
$erro = '';
$action = $_POST['action'] ?? '';
$meuId  = (int)($_SESSION['user_id'] ?? 0);

// ── Criar usuário ─────────────────────────────────────────────────────────────
if ($action === 'criar') {
    $nome     = trim($_POST['nome']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $senha    = $_POST['senha']    ?? '';
    $perfil   = $_POST['perfil']   ?? 'user';
    $telefone = trim($_POST['telefone'] ?? '');

    if (!in_array($perfil, ['master', 'user'])) $perfil = 'user';

    if (!$nome || !$email || !$senha) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter no mínimo 6 caracteres.';
    } else {
        try {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, telefone) VALUES (?,?,?,?,?)")
                ->execute([$nome, $email, $hash, $perfil, $telefone ?: null]);
            $msg = "Usuário \"$nome\" criado com sucesso.";
        } catch (PDOException $ex) {
            $erro = str_contains($ex->getMessage(), 'Duplicate') ? 'Já existe um usuário com este e-mail.' : $ex->getMessage();
        }
    }
}

// ── Alterar perfil ────────────────────────────────────────────────────────────
if ($action === 'alterar_perfil') {
    $id     = (int)($_POST['id']     ?? 0);
    $perfil = $_POST['perfil'] ?? 'user';
    if (!in_array($perfil, ['master', 'user'])) $perfil = 'user';

    if ($id === $meuId) {
        $erro = 'Você não pode alterar seu próprio perfil.';
    } elseif ($id) {
        $pdo->prepare("UPDATE usuarios SET perfil=? WHERE id=?")->execute([$perfil, $id]);
        $msg = 'Perfil atualizado.';
    }
}

// ── Redefinir senha ───────────────────────────────────────────────────────────
if ($action === 'redefinir_senha') {
    $id    = (int)($_POST['id']    ?? 0);
    $senha = $_POST['nova_senha'] ?? '';

    if (strlen($senha) < 6) {
        $erro = 'A nova senha deve ter no mínimo 6 caracteres.';
    } elseif ($id) {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE usuarios SET senha_hash=? WHERE id=?")->execute([$hash, $id]);
        $msg = 'Senha redefinida com sucesso.';
    }
}

// ── Excluir usuário ───────────────────────────────────────────────────────────
if ($action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === $meuId) {
        $erro = 'Você não pode excluir sua própria conta.';
    } elseif ($id) {
        // Desvincula negociações e tarefas antes de excluir
        $pdo->prepare("UPDATE negociacoes SET responsavel_id=NULL WHERE responsavel_id=?")->execute([$id]);
        $pdo->prepare("UPDATE tarefas SET responsavel_id=NULL WHERE responsavel_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
        $msg = 'Usuário excluído.';
    }
}

// ── Carregar lista ────────────────────────────────────────────────────────────
$usuarios = $pdo->query("SELECT id, nome, email, perfil, telefone, criado_em FROM usuarios ORDER BY nome")->fetchAll();
?>

<!-- Flash -->
<?php if ($msg): ?>
<div id="flash-ok" class="mb-4 bg-green-50 border border-green-200 text-green-700 rounded-xl px-4 py-3 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <?= e($msg) ?>
</div>
<?php elseif ($erro): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
    </svg>
    <?= e($erro) ?>
</div>
<?php endif; ?>

<!-- Lista de usuários -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-gray-800">Usuários do Sistema</h2>
            <p class="text-xs text-gray-400 mt-0.5"><?= count($usuarios) ?> usuário(s) cadastrado(s)</p>
        </div>
    </div>

    <table class="w-full text-sm">
        <thead>
            <tr class="text-xs font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-100">
                <th class="px-6 py-3 text-left">Nome</th>
                <th class="px-6 py-3 text-left">E-mail</th>
                <th class="px-6 py-3 text-left">Telefone</th>
                <th class="px-6 py-3 text-center">Perfil</th>
                <th class="px-6 py-3 text-center">Cadastro</th>
                <th class="px-6 py-3 text-right">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $usr):
            $isMe = ($usr['id'] === $meuId);
        ?>
        <tr class="border-b border-gray-50 hover:bg-gray-50 group">
            <td class="px-6 py-3">
                <div class="flex items-center gap-3">
                    <?php
                    $iniciais = implode('', array_map(fn($p) => strtoupper($p[0]),
                        array_slice(explode(' ', trim($usr['nome'])), 0, 2)));
                    ?>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                         style="background:linear-gradient(135deg,<?= $usr['perfil']==='master' ? '#2563eb,#7c3aed' : '#0891b2,#0e7490' ?>)">
                        <?= e($iniciais) ?>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800"><?= e($usr['nome']) ?></p>
                        <?php if ($isMe): ?>
                        <p class="text-xs text-blue-500">Você</p>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="px-6 py-3 text-gray-500"><?= e($usr['email']) ?></td>
            <td class="px-6 py-3 text-gray-500"><?= e($usr['telefone'] ?? '—') ?></td>
            <td class="px-6 py-3 text-center">
                <?php if ($usr['perfil'] === 'master'): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">
                    👑 Master
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                    👤 Usuário
                </span>
                <?php endif; ?>
            </td>
            <td class="px-6 py-3 text-center text-xs text-gray-400">
                <?= date('d/m/Y', strtotime($usr['criado_em'])) ?>
            </td>
            <td class="px-6 py-3 text-right">
                <?php if (!$isMe): ?>
                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <!-- Alterar perfil -->
                    <button onclick="openModalPerfil(<?= $usr['id'] ?>, '<?= e($usr['nome']) ?>', '<?= $usr['perfil'] ?>')"
                            class="text-xs text-blue-600 hover:text-blue-800 font-medium px-2 py-1 rounded hover:bg-blue-50">
                        ✏️ Perfil
                    </button>
                    <!-- Redefinir senha -->
                    <button onclick="openModalSenha(<?= $usr['id'] ?>, '<?= e($usr['nome']) ?>')"
                            class="text-xs text-orange-600 hover:text-orange-800 font-medium px-2 py-1 rounded hover:bg-orange-50">
                        🔑 Senha
                    </button>
                    <!-- Excluir -->
                    <form method="POST" onsubmit="return confirm('Excluir o usuário \'<?= e($usr['nome']) ?>\'?')">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium px-2 py-1 rounded hover:bg-red-50">
                            🗑️ Excluir
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <span class="text-xs text-gray-300 opacity-0 group-hover:opacity-100">Sua conta</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Formulário: Criar novo usuário -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-sm font-bold text-gray-800">Criar Novo Usuário</h2>
        <p class="text-xs text-gray-400 mt-0.5">Apenas masters podem criar e gerenciar usuários</p>
    </div>
    <div class="px-6 py-5">
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <input type="hidden" name="action" value="criar">

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Nome completo *</label>
                <input type="text" name="nome" required placeholder="Ex: João Silva"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">E-mail *</label>
                <input type="email" name="email" required placeholder="joao@empresa.com"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Senha *</label>
                <input type="password" name="senha" required placeholder="Mínimo 6 caracteres"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Telefone</label>
                <input type="text" name="telefone" placeholder="Ex: (77) 99152-6666"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Perfil *</label>
                <select name="perfil"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="user">👤 Usuário — acesso padrão</option>
                    <option value="master">👑 Master — acesso total</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-6 py-2.5 rounded-lg flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Criar Usuário
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Alterar Perfil -->
<div id="modal-perfil" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-base font-bold text-gray-800">Alterar Perfil</h3>
            <button onclick="closeModal('modal-perfil')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="alterar_perfil">
            <input type="hidden" name="id" id="modal-perfil-id">

            <p class="text-sm text-gray-600">Usuário: <strong id="modal-perfil-nome"></strong></p>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-2">Novo perfil</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-200 cursor-pointer hover:border-blue-300 transition-colors has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                        <input type="radio" name="perfil" value="user" class="text-blue-600">
                        <div>
                            <p class="text-sm font-semibold text-gray-800">👤 Usuário</p>
                            <p class="text-xs text-gray-500">Acesso padrão — sem gestão de usuários e configurações</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-200 cursor-pointer hover:border-purple-300 transition-colors has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                        <input type="radio" name="perfil" value="master" class="text-purple-600">
                        <div>
                            <p class="text-sm font-semibold text-gray-800">👑 Master</p>
                            <p class="text-xs text-gray-500">Acesso total — gerencia usuários e configurações do sistema</p>
                        </div>
                    </label>
                </div>
            </div>

            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-2.5 text-sm">Salvar</button>
                <button type="button" onclick="closeModal('modal-perfil')" class="px-5 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Redefinir Senha -->
<div id="modal-senha" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-base font-bold text-gray-800">Redefinir Senha</h3>
            <button onclick="closeModal('modal-senha')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="redefinir_senha">
            <input type="hidden" name="id" id="modal-senha-id">

            <p class="text-sm text-gray-600">Usuário: <strong id="modal-senha-nome"></strong></p>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Nova senha *</label>
                <input type="password" name="nova_senha" required placeholder="Mínimo 6 caracteres"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="flex gap-3 pt-1">
                <button type="submit" class="flex-1 bg-orange-500 hover:bg-orange-600 text-white font-semibold rounded-lg py-2.5 text-sm">Redefinir</button>
                <button type="button" onclick="closeModal('modal-senha')" class="px-5 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalPerfil(id, nome, perfil) {
    document.getElementById('modal-perfil-id').value = id;
    document.getElementById('modal-perfil-nome').textContent = nome;
    document.querySelectorAll('[name="perfil"]').forEach(r => r.checked = (r.value === perfil));
    document.getElementById('modal-perfil').classList.remove('hidden');
}
function openModalSenha(id, nome) {
    document.getElementById('modal-senha-id').value = id;
    document.getElementById('modal-senha-nome').textContent = nome;
    document.getElementById('modal-senha').classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
document.querySelectorAll('#modal-perfil, #modal-senha').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
setTimeout(() => { const f = document.getElementById('flash-ok'); if (f) f.remove(); }, 4000);
</script>

<?php require_once '_footer.php'; ?>
