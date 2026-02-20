<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$id      = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$cliente = [];
$erro    = '';
$sucesso = '';

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();
    if (!$cliente) { header('Location: clientes.php'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome    = trim($_POST['nome']    ?? '');
    $tel     = trim($_POST['telefone'] ?? '');
    $email   = trim($_POST['email']   ?? '');
    $empresa = trim($_POST['empresa'] ?? '');
    $cnpj    = trim($_POST['cnpj']    ?? '');
    $obs     = trim($_POST['observacoes'] ?? '');

    if (!$nome) {
        $erro = 'O campo Nome é obrigatório.';
    } else {
        if ($editing) {
            $pdo->prepare('UPDATE clientes SET nome=?,telefone=?,email=?,empresa=?,cnpj=?,observacoes=? WHERE id=?')
                ->execute([$nome,$tel,$email,$empresa,$cnpj,$obs,$id]);
            $sucesso = 'Cliente atualizado com sucesso!';
            $cliente = array_merge($cliente, compact('nome','tel','email','empresa','cnpj','obs'));
        } else {
            $pdo->prepare('INSERT INTO clientes (nome,telefone,email,empresa,cnpj,observacoes) VALUES (?,?,?,?,?,?)')
                ->execute([$nome,$tel,$email,$empresa,$cnpj,$obs]);
            $newId = $pdo->lastInsertId();
            header("Location: cliente_form.php?id=$newId&criado=1");
            exit;
        }
    }
}

$pageTitle   = $editing ? 'Editar Cliente' : 'Novo Cliente';
$currentPage = 'clientes';
require_once __DIR__ . '/_nav.php';
?>

<div class="max-w-2xl mx-auto">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-gray-500 mb-5">
        <a href="clientes.php" class="hover:text-blue-600">Clientes</a>
        <span>›</span>
        <span class="text-gray-800"><?= $editing ? e($cliente['nome']) : 'Novo' ?></span>
    </div>

    <?php if ($erro): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-4 text-sm"><?= e($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso || isset($_GET['criado'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 mb-4 text-sm">
        <?= $sucesso ?: 'Cliente criado com sucesso!' ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-bold text-gray-800 mb-5"><?= $editing ? 'Editar dados do cliente' : 'Cadastrar novo cliente' ?></h2>
        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome <span class="text-red-500">*</span></label>
                    <input type="text" name="nome" required
                           value="<?= e($_POST['nome'] ?? $cliente['nome'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
                    <input type="text" name="telefone"
                           value="<?= e($_POST['telefone'] ?? $cliente['telefone'] ?? '') ?>"
                           placeholder="(77) 99999-9999"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                    <input type="email" name="email"
                           value="<?= e($_POST['email'] ?? $cliente['email'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empresa</label>
                    <input type="text" name="empresa"
                           value="<?= e($_POST['empresa'] ?? $cliente['empresa'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
                    <input type="text" name="cnpj"
                           value="<?= e($_POST['cnpj'] ?? $cliente['cnpj'] ?? '') ?>"
                           placeholder="00.000.000/0000-00"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                    <textarea name="observacoes" rows="3"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                              placeholder="Anotações sobre o cliente..."><?= e($_POST['observacoes'] ?? $cliente['observacoes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded-lg text-sm">
                    <?= $editing ? 'Salvar alterações' : 'Cadastrar cliente' ?>
                </button>
                <a href="clientes.php" class="text-sm text-gray-600 hover:text-gray-800">Cancelar</a>
            </div>
        </form>
    </div>

    <?php if ($editing): ?>
    <!-- Negociação vinculada -->
    <?php
    $neg = $pdo->prepare('SELECT * FROM negociacoes WHERE cliente_id = ? ORDER BY criado_em DESC');
    $neg->execute([$id]);
    $negs = $neg->fetchAll();
    ?>
    <div class="bg-white rounded-xl border border-gray-200 p-6 mt-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-bold text-gray-800">Negociações</h2>
            <a href="negociacao_detalhe.php?cliente_id=<?= $id ?>"
               class="text-xs text-blue-600 hover:underline">+ Nova negociação</a>
        </div>
        <?php if (empty($negs)): ?>
            <p class="text-sm text-gray-400">Nenhuma negociação cadastrada.</p>
        <?php else: ?>
            <div class="space-y-2">
            <?php foreach ($negs as $n): ?>
                <a href="negociacao_detalhe.php?id=<?= $n['id'] ?>"
                   class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:bg-gray-50">
                    <span class="text-sm text-gray-700">Negociação #<?= $n['id'] ?></span>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-gray-800"><?= formatMoney($n['valor']) ?></span>
                        <span class="badge bg-blue-100 text-blue-700"><?= e($n['etapa']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
