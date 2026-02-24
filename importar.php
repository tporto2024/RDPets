<?php
$pageTitle   = 'Importar Clientes';
$currentPage = 'importar';
require_once __DIR__ . '/_nav.php';

$etapasDB      = getEtapas();
$etapasNomes   = array_column($etapasDB, 'nome');
$etapaPadrao   = $etapasNomes[0] ?? 'Importado';
$qualificacoes = ['Quente', 'Muito Interessado', 'Morno', 'Sem interesse'];
$usuarios      = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome")->fetchAll();

$msg       = '';
$erro      = '';
$preview   = [];
$errosLinha = [];
$step      = 'upload'; // upload | preview | done

// ── Download do template CSV ──────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_importacao.csv"');
    echo "\xEF\xBB\xBF"; // BOM para Excel reconhecer UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['nome','email','telefone','empresa','cnpj','valor','etapa','qualificacao','indicacao','responsavel_email'], ';');
    fputcsv($out, ['João Silva','joao@email.com','11999990000','Empresa X','00.000.000/0001-00','1500.00', $etapaPadrao,'Quente','Google',''], ';');
    fputcsv($out, ['Maria Souza','maria@email.com','21988880000','Empresa Y','','3200.50', $etapasNomes[1] ?? $etapaPadrao,'Morno','Indicação',''], ';');
    fclose($out);
    exit;
}

// ── Confirmar importação ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'confirmar') {
    $rows        = json_decode($_POST['rows_json'] ?? '[]', true);
    $respPadrao  = (int)($_POST['responsavel_padrao'] ?? 0) ?: null;
    $importados  = 0;
    $ignorados   = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            if (!empty($row['_erro'])) { $ignorados++; continue; }

            $nome    = trim($row['nome'] ?? '');
            $email   = trim($row['email'] ?? '');
            $tel     = trim($row['telefone'] ?? '');
            $empresa = trim($row['empresa'] ?? '');
            $cnpj    = trim($row['cnpj'] ?? '');
            $valor   = (float)str_replace(',', '.', $row['valor'] ?? '0');
            $etapa   = in_array($row['etapa'], $etapasNomes) ? $row['etapa'] : $etapaPadrao;
            $qual    = in_array($row['qualificacao'], $qualificacoes) ? $row['qualificacao'] : null;
            $ind     = trim($row['indicacao'] ?? '');
            $respEmail = trim($row['responsavel_email'] ?? '');

            if (!$nome) { $ignorados++; continue; }

            // Responsável por e-mail (opcional)
            $respId = $respPadrao;
            if ($respEmail) {
                $s = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                $s->execute([$respEmail]);
                $respId = $s->fetchColumn() ?: $respPadrao;
            }

            // Criar ou reutilizar cliente (chave: email se existir, senão nome+empresa)
            $clienteId = null;
            if ($email) {
                $s = $pdo->prepare("SELECT id FROM clientes WHERE email = ? LIMIT 1");
                $s->execute([$email]);
                $clienteId = $s->fetchColumn() ?: null;
            }
            if (!$clienteId) {
                $s = $pdo->prepare("SELECT id FROM clientes WHERE nome = ? AND empresa = ? LIMIT 1");
                $s->execute([$nome, $empresa]);
                $clienteId = $s->fetchColumn() ?: null;
            }
            if (!$clienteId) {
                $pdo->prepare("INSERT INTO clientes (nome, email, telefone, empresa, cnpj) VALUES (?,?,?,?,?)")
                    ->execute([$nome, $email ?: null, $tel ?: null, $empresa ?: null, $cnpj ?: null]);
                $clienteId = (int)$pdo->lastInsertId();
            }

            // Criar negociação
            $pdo->prepare("INSERT INTO negociacoes (cliente_id, etapa, qualificacao, valor, indicacao, responsavel_id) VALUES (?,?,?,?,?,?)")
                ->execute([$clienteId, $etapa, $qual, $valor, $ind ?: null, $respId]);

            $negId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO negociacoes_log (negociacao_id, de_etapa, para_etapa, changed_by, changed_ip) VALUES (?,?,?,?,?)")
                ->execute([$negId, null, $etapa, $u['nome'], getClientIP()]);

            $importados++;
        }
        $pdo->commit();
        $msg  = "$importados negociação(ões) importada(s) com sucesso." . ($ignorados ? " $ignorados linha(s) ignorada(s)." : '');
        $step = 'done';
    } catch (Exception $ex) {
        $pdo->rollBack();
        $erro = 'Erro durante a importação: ' . $ex->getMessage();
        $step = 'upload';
    }
}

// ── Upload e parse do CSV ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'upload') {
    $file = $_FILES['arquivo'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $erro = 'Erro no upload do arquivo. Tente novamente.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $erro = 'Formato inválido. Envie um arquivo .CSV (exportado do Excel).';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            // Remove BOM se existir
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            // Detectar separador (vírgula ou ponto-e-vírgula)
            $primLinha = fgets($handle);
            rewind($handle);
            if ($bom !== "\xEF\xBB\xBF") { /* já rewind */ }
            else { fread($handle, 3); } // pular BOM novamente
            $sep = (substr_count($primLinha, ';') >= substr_count($primLinha, ',')) ? ';' : ',';

            $header = null;
            $lnum   = 0;
            while (($row = fgetcsv($handle, 2000, $sep)) !== false) {
                $lnum++;
                if (!$header) {
                    $header = array_map(fn($h) => strtolower(trim($h)), $row);
                    continue;
                }
                if (count(array_filter($row)) === 0) continue; // linha vazia

                $data = [];
                foreach ($header as $i => $col) {
                    $data[$col] = trim($row[$i] ?? '');
                }

                // Validação básica
                $erroLinha = '';
                if (empty($data['nome'])) {
                    $erroLinha = 'Nome obrigatório';
                } elseif (!empty($data['etapa']) && !in_array($data['etapa'], $etapasNomes)) {
                    $erroLinha = 'Etapa "' . $data['etapa'] . '" não existe — será usada "' . $etapaPadrao . '"';
                    $data['etapa'] = $etapaPadrao;
                }

                $data['_linha'] = $lnum;
                $data['_erro']  = $erroLinha;
                $preview[] = $data;
            }
            fclose($handle);

            if (empty($preview)) {
                $erro = 'O arquivo está vazio ou não tem dados além do cabeçalho.';
            } else {
                $step = 'preview';
            }
        }
    }
}
?>

<?php if ($msg): ?>
<div class="mb-4 bg-green-50 border border-green-200 text-green-700 rounded-xl px-4 py-3 text-sm flex items-center gap-2">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <?= e($msg) ?>
    <a href="negociacoes.php" class="ml-auto text-green-700 underline font-semibold">Ver Kanban →</a>
</div>
<?php elseif ($erro): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm">
    <?= e($erro) ?>
</div>
<?php endif; ?>

<?php if ($step === 'upload' || $step === 'done'): ?>
<!-- ══ STEP 1: UPLOAD ══════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

    <!-- Card upload -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-sm font-bold text-gray-800 mb-1">Importar planilha</h2>
        <p class="text-xs text-gray-400 mb-5">Envie um arquivo <strong>CSV</strong> exportado do Excel ou Google Sheets.</p>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="_step" value="upload">

            <!-- Drop zone -->
            <label id="drop-zone"
                   class="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-xl p-10 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-all">
                <svg class="w-10 h-10 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="text-sm font-semibold text-gray-600" id="drop-label">Clique para selecionar ou arraste o arquivo aqui</p>
                <p class="text-xs text-gray-400 mt-1">Formatos aceitos: .CSV</p>
                <input type="file" name="arquivo" id="arquivo" accept=".csv,.txt" class="hidden"
                       onchange="document.getElementById('drop-label').textContent = this.files[0]?.name || 'Nenhum arquivo'">
            </label>

            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Responsável padrão (opcional)</label>
                <select name="responsavel_padrao"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">— Sem responsável —</option>
                    <?php foreach ($usuarios as $usr): ?>
                    <option value="<?= $usr['id'] ?>"><?= e($usr['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">Será aplicado a todos os registros que não tiverem "responsavel_email" preenchido.</p>
            </div>

            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-3 text-sm flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Carregar e pré-visualizar
            </button>
        </form>
    </div>

    <!-- Card instruções -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-bold text-gray-800 mb-3">📥 Template</h3>
            <p class="text-xs text-gray-500 mb-3">Baixe o modelo pronto com as colunas corretas e exemplos.</p>
            <a href="?template=1"
               class="w-full flex items-center justify-center gap-2 border border-green-500 text-green-700 hover:bg-green-50 font-semibold rounded-lg py-2.5 text-sm transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Baixar template CSV
            </a>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-bold text-gray-800 mb-3">📋 Colunas suportadas</h3>
            <div class="space-y-1.5">
                <?php
                $cols = [
                    ['nome',              'Texto', true],
                    ['email',             'Texto', false],
                    ['telefone',          'Texto', false],
                    ['empresa',           'Texto', false],
                    ['cnpj',              'Texto', false],
                    ['valor',             'Número (ex: 1500.00)', false],
                    ['etapa',             implode(', ', $etapasNomes), false],
                    ['qualificacao',      implode(', ', $qualificacoes), false],
                    ['indicacao',         'Texto livre', false],
                    ['responsavel_email', 'E-mail do usuário', false],
                ];
                foreach ($cols as [$col, $desc, $req]): ?>
                <div class="flex items-start gap-2">
                    <code class="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded font-mono flex-shrink-0"><?= $col ?></code>
                    <?php if ($req): ?><span class="text-red-500 text-xs flex-shrink-0">*</span><?php endif; ?>
                    <span class="text-xs text-gray-400"><?= e($desc) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-red-500 mt-3">* Obrigatório</p>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <p class="text-xs text-amber-700 font-semibold mb-1">💡 Dica Excel</p>
            <p class="text-xs text-amber-600">No Excel: <strong>Arquivo → Salvar como → CSV UTF-8 (delimitado por vírgula)</strong></p>
        </div>
    </div>
</div>

<?php elseif ($step === 'preview'): ?>
<!-- ══ STEP 2: PREVIEW ═════════════════════════════════════════════════════ -->

<?php
$totalOk   = count(array_filter($preview, fn($r) => !$r['_erro']));
$totalErro = count(array_filter($preview, fn($r) =>  $r['_erro']));
?>

<div class="flex items-center gap-3 mb-4">
    <button onclick="history.back()" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
        ← Voltar
    </button>
    <h2 class="text-sm font-bold text-gray-800">Pré-visualização —
        <span class="text-green-600"><?= $totalOk ?> prontos</span>
        <?php if ($totalErro): ?>, <span class="text-amber-600"><?= $totalErro ?> com aviso</span><?php endif; ?>
    </h2>
</div>

<form method="POST">
    <input type="hidden" name="_step" value="confirmar">
    <input type="hidden" name="rows_json" value="<?= htmlspecialchars(json_encode($preview), ENT_QUOTES) ?>">

    <div class="mb-4 flex items-center gap-4">
        <div class="flex items-center gap-2">
            <label class="text-xs font-semibold text-gray-600">Responsável padrão:</label>
            <select name="responsavel_padrao"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">— Sem responsável —</option>
                <?php foreach ($usuarios as $usr): ?>
                <option value="<?= $usr['id'] ?>"><?= e($usr['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit"
                class="ml-auto bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-2 rounded-lg text-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Confirmar e importar <?= $totalOk ?> registro(s)
        </button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-500 uppercase tracking-wide border-b border-gray-100 bg-gray-50">
                        <th class="px-3 py-2 text-left w-8">#</th>
                        <th class="px-3 py-2 text-left">Nome</th>
                        <th class="px-3 py-2 text-left">E-mail</th>
                        <th class="px-3 py-2 text-left">Empresa</th>
                        <th class="px-3 py-2 text-left">Etapa</th>
                        <th class="px-3 py-2 text-left">Qualificação</th>
                        <th class="px-3 py-2 text-right">Valor</th>
                        <th class="px-3 py-2 text-left">Situação</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $row): ?>
                <tr class="border-b border-gray-50 <?= $row['_erro'] ? 'bg-amber-50' : 'hover:bg-gray-50' ?>">
                    <td class="px-3 py-2 text-gray-400"><?= $row['_linha'] ?></td>
                    <td class="px-3 py-2 font-semibold text-gray-800"><?= e($row['nome'] ?? '') ?></td>
                    <td class="px-3 py-2 text-gray-500"><?= e($row['email'] ?? '—') ?></td>
                    <td class="px-3 py-2 text-gray-500"><?= e($row['empresa'] ?? '—') ?></td>
                    <td class="px-3 py-2">
                        <?php
                        $etNome = $row['etapa'] ?? $etapaPadrao;
                        if (!in_array($etNome, $etapasNomes)) $etNome = $etapaPadrao;
                        $etDB = array_values(array_filter($etapasDB, fn($e) => $e['nome'] === $etNome))[0] ?? null;
                        $cor  = $etDB ? (ETAPA_CORES[$etDB['cor']] ?? ETAPA_CORES['cinza']) : ETAPA_CORES['cinza'];
                        ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $cor['header'] ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?= $cor['dot'] ?>"></span>
                            <?= e($etNome) ?>
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-500"><?= e($row['qualificacao'] ?? '—') ?></td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-700">
                        <?= !empty($row['valor']) ? 'R$ ' . number_format((float)str_replace(',','.',$row['valor']),2,',','.') : '—' ?>
                    </td>
                    <td class="px-3 py-2">
                        <?php if ($row['_erro']): ?>
                        <span class="text-amber-600 font-medium">⚠ <?= e($row['_erro']) ?></span>
                        <?php else: ?>
                        <span class="text-green-600 font-medium">✓ OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<?php endif; ?>

<script>
// Drag & drop na drop zone
const dz = document.getElementById('drop-zone');
if (dz) {
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('border-blue-500','bg-blue-50'); }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('border-blue-500','bg-blue-50'); }));
    dz.addEventListener('drop', e => {
        const f = e.dataTransfer.files[0];
        if (f) {
            document.getElementById('arquivo').files = e.dataTransfer.files;
            document.getElementById('drop-label').textContent = f.name;
        }
    });
}
</script>

<?php require_once '_footer.php'; ?>
