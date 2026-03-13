<?php
ob_start();
$pageTitle   = 'Leads';
$currentPage = 'leads';

// ── Ações POST (antes do _nav.php para permitir header redirects) ────────
require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $tab    = $_POST['_tab'] ?? 'hotel';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $n = $pdo->prepare('SELECT empresa FROM leads WHERE id = ?'); $n->execute([$id]);
            $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
            logActivity('Leads', 'Excluiu lead', $n->fetchColumn() ?: "ID $id");
        }
        header("Location: leads.php?tab=$tab&deleted=1"); exit;
    }

    if ($action === 'status') {
        $id   = (int)($_POST['id'] ?? 0);
        $novo = $_POST['novo_status'] ?? '';
        if ($id && in_array($novo, ['novo','contatado','descartado'])) {
            if ($novo === 'contatado') {
                $pdo->prepare('UPDATE leads SET status = ?, contatado_em = NOW(), contatado_por = ? WHERE id = ? AND status NOT IN (?,?)')->execute([$novo, $_SESSION['user_nome'] ?? '', $id, 'convertido', 'em_negociacao']);
            } else {
                $pdo->prepare('UPDATE leads SET status = ?, contatado_em = NULL, contatado_por = NULL WHERE id = ? AND status NOT IN (?,?)')->execute([$novo, $id, 'convertido', 'em_negociacao']);
            }
            logActivity('Leads', 'Alterou status', "Lead #$id → $novo");
        }
        $qs = http_build_query(array_filter(['tab'=>$tab,'q'=>$_POST['_q']??'','status'=>$_POST['_fs']??'','segmento'=>$_POST['_fg']??'','p'=>$_POST['_p']??'']));
        header("Location: leads.php?$qs"); exit;
    }

    // ── Reverter status (apenas master) ──────────────────────────────────────
    if ($action === 'reverter_status' && isMaster()) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE leads SET status = ?, contatado_em = NULL, contatado_por = NULL, convertido_em = NULL, convertido_por = NULL, cliente_id = NULL WHERE id = ?')->execute(['novo', $id]);
            logActivity('Leads', 'Reverteu status', "Lead #$id → novo (master)");
        }
        $qs = http_build_query(array_filter(['tab'=>$tab,'q'=>$_POST['_q']??'','status'=>$_POST['_fs']??'','segmento'=>$_POST['_fg']??'','p'=>$_POST['_p']??'']));
        header("Location: leads.php?$qs"); exit;
    }

    if ($action === 'converter') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stLead = $pdo->prepare('SELECT * FROM leads WHERE id = ? AND status != ?'); $stLead->execute([$id, 'convertido']);
            $lead = $stLead->fetch();
            if ($lead) {
                $pdo->beginTransaction();
                try {
                    $obs = trim(($lead['observacoes']??'').($lead['website']?"\nSite: ".$lead['website']:'').($lead['cidade']?"\nCidade: ".$lead['cidade']:'').($lead['email']?"\nEmail: ".$lead['email']:''));
                    $pdo->prepare('INSERT INTO clientes (nome,empresa,telefone,email,tipo_negocio,observacoes) VALUES (?,?,?,?,?,?)')->execute([$lead['empresa'],$lead['empresa'],$lead['telefone'],$lead['email'],$lead['segmento'],$obs?:null]);
                    $clienteId = (int)$pdo->lastInsertId();
                    $pdo->prepare('UPDATE leads SET status=?,cliente_id=?,convertido_em=NOW(),convertido_por=? WHERE id=?')->execute(['convertido',$clienteId,$_SESSION['user_nome']??'',$id]);
                    $pdo->commit();
                    logActivity('Leads', 'Converteu lead', $lead['empresa']." → Cliente #$clienteId");
                    header("Location: leads.php?tab=$tab&convertido=$clienteId"); exit;
                } catch (\Exception $ex) { $pdo->rollBack(); $erroConversao = 'Erro: '.$ex->getMessage(); }
            }
        }
    }

    if ($action === 'salvar_telefone') {
        $id = (int)($_POST['id']??0); $tel = trim($_POST['telefone']??'');
        if ($id) { $pdo->prepare('UPDATE leads SET telefone=? WHERE id=?')->execute([$tel?:null,$id]); }
        $qs = http_build_query(array_filter(['tab'=>$tab,'q'=>$_POST['_q']??'','status'=>$_POST['_fs']??'','segmento'=>$_POST['_fg']??'','p'=>$_POST['_p']??'']));
        header("Location: leads.php?$qs"); exit;
    }

    if ($action === 'salvar_email') {
        $id = (int)($_POST['id']??0); $email = trim($_POST['email']??'');
        if ($id) { $pdo->prepare('UPDATE leads SET email=? WHERE id=?')->execute([$email?:null,$id]); }
        $qs = http_build_query(array_filter(['tab'=>$tab,'q'=>$_POST['_q']??'','status'=>$_POST['_fs']??'','segmento'=>$_POST['_fg']??'','p'=>$_POST['_p']??'']));
        header("Location: leads.php?$qs"); exit;
    }
}

// ── Inclui layout (navbar/sidebar) apenas no GET ────────────────────────────
require_once __DIR__ . '/_nav.php';

logActivity('Leads', 'Visualizou lista de leads', $_GET['q'] ?? '');

// ── Aba ativa ────────────────────────────────────────────────────────────────
$tabAtiva = $_GET['tab'] ?? 'hotel';
if (!in_array($tabAtiva, ['hotel','petshop'])) $tabAtiva = 'hotel';
$tabCounts = $pdo->query("SELECT categoria, COUNT(*) AS cnt FROM leads GROUP BY categoria")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Filtros ──────────────────────────────────────────────────────────────────
$q = trim($_GET['q']??''); $fStatus = $_GET['status']??''; $fSegmento = trim($_GET['segmento']??''); $fEstado = trim($_GET['estado']??'');
$curPage = max(1,(int)($_GET['p']??1)); $perPage = 25; $offset = ($curPage-1)*$perPage;

$where = ['l.categoria = ?']; $params = [$tabAtiva];
if ($q) { $where[] = '(l.empresa LIKE ? OR l.cidade LIKE ? OR l.observacoes LIKE ? OR l.telefone LIKE ? OR l.email LIKE ?)'; for($i=0;$i<5;$i++) $params[] = "%$q%"; }
if ($fStatus && in_array($fStatus, ['novo','contatado','convertido','em_negociacao','descartado'])) { $where[] = 'l.status = ?'; $params[] = $fStatus; }
if ($fSegmento) { $where[] = 'l.segmento = ?'; $params[] = $fSegmento; }
if ($fEstado) { $where[] = 'l.estado = ?'; $params[] = $fEstado; }
$whereSQL = 'WHERE '.implode(' AND ', $where);

$stT = $pdo->prepare("SELECT COUNT(*) FROM leads l $whereSQL"); $stT->execute($params); $total = (int)$stT->fetchColumn(); $pages = max(1,(int)ceil($total/$perPage));
$stD = $pdo->prepare("SELECT l.*, c.nome AS cliente_nome FROM leads l LEFT JOIN clientes c ON c.id=l.cliente_id $whereSQL ORDER BY l.id ASC LIMIT $perPage OFFSET $offset"); $stD->execute($params); $leads = $stD->fetchAll();
$stS = $pdo->prepare("SELECT status, COUNT(*) FROM leads WHERE categoria=? GROUP BY status"); $stS->execute([$tabAtiva]); $statsRaw = $stS->fetchAll(PDO::FETCH_KEY_PAIR);
$stats = ['novo'=>(int)($statsRaw['novo']??0),'contatado'=>(int)($statsRaw['contatado']??0),'convertido'=>(int)($statsRaw['convertido']??0),'em_negociacao'=>(int)($statsRaw['em_negociacao']??0),'descartado'=>(int)($statsRaw['descartado']??0)];
$stSeg = $pdo->prepare("SELECT DISTINCT segmento FROM leads WHERE segmento IS NOT NULL AND categoria=? ORDER BY segmento"); $stSeg->execute([$tabAtiva]); $segmentos = $stSeg->fetchAll(PDO::FETCH_COLUMN);
$stEst = $pdo->prepare("SELECT DISTINCT estado FROM leads WHERE estado IS NOT NULL AND estado != '' AND categoria=? ORDER BY estado"); $stEst->execute([$tabAtiva]); $estados = $stEst->fetchAll(PDO::FETCH_COLUMN);

$statusBadges = ['novo'=>'bg-blue-100 text-blue-700','contatado'=>'bg-yellow-100 text-yellow-700','convertido'=>'bg-green-100 text-green-700','em_negociacao'=>'bg-purple-100 text-purple-700','descartado'=>'bg-gray-100 text-gray-500'];
$statusLabels = ['novo'=>'Novo','contatado'=>'Contatado','convertido'=>'Convertido','em_negociacao'=>'Em Negociação','descartado'=>'Descartado'];
?>

<!-- Abas -->
<div class="flex border-b border-gray-200 mb-2">
    <a href="leads.php?tab=hotel" class="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold border-b-2 transition <?= $tabAtiva==='hotel' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        🏨 Hotel <span class="bg-blue-100 text-blue-700 rounded-full px-1.5 py-0.5 text-[10px] font-bold"><?= (int)($tabCounts['hotel']??0) ?></span>
    </a>
    <a href="leads.php?tab=petshop" class="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold border-b-2 transition <?= $tabAtiva==='petshop' ? 'border-orange-500 text-orange-700' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        🛒 Petshops <span class="bg-orange-100 text-orange-700 rounded-full px-1.5 py-0.5 text-[10px] font-bold"><?= (int)($tabCounts['petshop']??0) ?></span>
    </a>
</div>

<!-- Toolbar + Stats inline -->
<div class="flex flex-wrap items-center gap-1.5 mb-2">
    <form method="GET" class="flex flex-wrap gap-1 flex-1 items-center">
        <input type="hidden" name="tab" value="<?= e($tabAtiva) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar..." class="border border-gray-300 rounded px-2 py-1 text-[11px] focus:outline-none focus:ring-1 focus:ring-blue-500 w-32">
        <select name="status" class="border border-gray-300 rounded px-1.5 py-1 text-[11px]">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $sv => $sl): ?><option value="<?= $sv ?>" <?= $fStatus===$sv?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?>
        </select>
        <select name="segmento" class="border border-gray-300 rounded px-1.5 py-1 text-[11px]">
            <option value="">Segmento</option>
            <?php foreach ($segmentos as $seg): ?><option value="<?= e($seg) ?>" <?= $fSegmento===$seg?'selected':'' ?>><?= e($seg) ?></option><?php endforeach; ?>
        </select>
        <select name="estado" class="border border-gray-300 rounded px-1.5 py-1 text-[11px]">
            <option value="">Estado</option>
            <?php foreach ($estados as $est): ?><option value="<?= e($est) ?>" <?= $fEstado===$est?'selected':'' ?>><?= e($est) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="bg-blue-600 text-white px-2.5 py-1 rounded text-[11px] hover:bg-blue-700">Filtrar</button>
        <?php if ($q||$fStatus||$fSegmento||$fEstado): ?><a href="leads.php?tab=<?= e($tabAtiva) ?>" class="text-gray-400 hover:text-gray-600 text-[11px]">✕</a><?php endif; ?>
    </form>
    <div class="flex gap-1 text-[10px]">
        <?php foreach (['novo'=>['🆕','blue'],'contatado'=>['📞','yellow'],'convertido'=>['✅','green'],'em_negociacao'=>['🤝','purple'],'descartado'=>['❌','gray']] as $sk=>[$se,$sc]): ?>
        <a href="leads.php?tab=<?= e($tabAtiva) ?>&status=<?= $sk ?>" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full font-semibold <?= $fStatus===$sk?"ring-1 ring-{$sc}-400":'' ?> bg-<?= $sc ?>-50 text-<?= $sc ?>-700" title="<?= $statusLabels[$sk] ?>">
            <?= $se ?><span class="font-bold"><?= $stats[$sk] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Alertas -->
<?php if (isset($_GET['deleted'])): ?><div class="bg-green-50 border border-green-200 text-green-700 rounded px-2 py-1 mb-1.5 text-[11px]">Lead excluído.</div><?php endif; ?>
<?php if (isset($_GET['convertido'])): ?><div class="bg-green-50 border border-green-200 text-green-700 rounded px-2 py-1 mb-1.5 text-[11px] flex items-center gap-2">✅ Convertido! <a href="cliente_form.php?id=<?= (int)$_GET['convertido'] ?>" class="underline font-medium">Ver cliente →</a></div><?php endif; ?>
<?php if (!empty($erroConversao)): ?><div class="bg-red-50 border border-red-200 text-red-700 rounded px-2 py-1 mb-1.5 text-[11px]"><?= e($erroConversao) ?></div><?php endif; ?>

<!-- Tabela -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
<table class="w-full" style="font-size:11px">
<thead>
<tr class="bg-gray-50 border-b border-gray-200" style="line-height:1.2">
    <th class="text-left px-2 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Empresa</th>
    <th class="text-left px-1.5 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Segmento</th>
    <th class="text-left px-1.5 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Cidade</th>
    <th class="text-left px-1.5 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Telefone</th>
    <th class="text-center px-1 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Links</th>
    <th class="text-center px-1.5 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Status</th>
    <th class="text-center px-1 py-1 font-semibold text-gray-500 uppercase" style="font-size:9px">Ações</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-50">
<?php foreach ($leads as $l):
    $isCon = $l['status']==='convertido'; $isNeg = $l['status']==='em_negociacao'; $isCtd = $l['status']==='contatado'; $isAdv = $isCon||$isNeg;
    $tel = $l['telefone']??''; $telC = preg_replace('/\D/','',$tel);
    if ($telC && strlen($telC)<=11) $telC = '55'.$telC;
    $email = $l['email']??'';
    $redes = json_decode($l['redes_sociais']??'{}',true)?:[];
    $hasLinks = $email || $l['website'] || $redes;
?>
<tr class="hover:bg-gray-50 <?= $isNeg?'bg-purple-50/30':($isCon?'bg-green-50/30':'') ?>" style="line-height:1.3">
    <!-- Empresa -->
    <td class="px-2 py-0.5">
        <span class="font-medium text-gray-800" title="<?= e($l['observacoes']??'') ?>"><?= e($l['empresa']) ?></span>
    </td>
    <!-- Segmento -->
    <td class="px-1.5 py-0.5"><span class="px-1 py-px rounded-full font-medium bg-indigo-50 text-indigo-700" style="font-size:10px"><?= e($l['segmento']??'-') ?></span></td>
    <!-- Cidade -->
    <td class="px-1.5 py-0.5 text-gray-600"><?= e($l['cidade']??'-') ?><?php if(!empty($l['estado'])): ?> <span class="text-gray-400 font-bold"><?= e($l['estado']) ?></span><?php endif; ?></td>
    <!-- Telefone -->
    <td class="px-1.5 py-0.5">
        <?php if ($tel): ?>
        <div class="flex items-center gap-1">
            <span class="text-gray-700" style="font-size:10px"><?= e($tel) ?></span>
            <a href="https://wa.me/<?= e($telC) ?>" target="_blank" title="WhatsApp" class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-green-500 hover:bg-green-600 text-white"><svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></a>
        </div>
        <?php else: ?>
        <form method="POST" class="flex items-center gap-0.5">
            <input type="hidden" name="_action" value="salvar_telefone"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>"><input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_fs" value="<?= e($fStatus) ?>"><input type="hidden" name="_fg" value="<?= e($fSegmento) ?>"><input type="hidden" name="_p" value="<?= $curPage ?>">
            <input type="text" name="telefone" placeholder="(11) 9..." class="border border-gray-200 rounded px-1 py-px w-20 focus:ring-1 focus:ring-blue-400 focus:outline-none" style="font-size:10px">
            <button type="submit" class="text-blue-600 font-bold" style="font-size:10px">✓</button>
        </form>
        <?php endif; ?>
    </td>
    <!-- Links (icons only: email + site + redes) -->
    <td class="px-1 py-0.5">
        <div class="flex items-center justify-center gap-1 flex-wrap">
            <?php if ($email): ?>
            <a href="mailto:<?= e($email) ?>" title="<?= e($email) ?>" class="text-gray-400 hover:text-blue-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg></a>
            <?php endif; ?>
            <?php if ($l['website']): ?>
            <a href="<?= e($l['website']) ?>" target="_blank" title="<?= e($l['website']) ?>" class="text-gray-400 hover:text-blue-600"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg></a>
            <?php endif; ?>
            <?php if (!empty($redes['instagram'])): ?>
            <a href="<?= e($redes['instagram']) ?>" target="_blank" title="Instagram" class="text-pink-500 hover:text-pink-700"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg></a>
            <?php endif; ?>
            <?php if (!empty($redes['facebook'])): ?>
            <a href="<?= e($redes['facebook']) ?>" target="_blank" title="Facebook" class="text-blue-600 hover:text-blue-800"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385h-3.047v-3.47h3.047v-2.642c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953h-1.514c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385c5.738-.9 10.126-5.864 10.126-11.854z"/></svg></a>
            <?php endif; ?>
            <?php if (!empty($redes['tiktok'])): ?>
            <a href="<?= e($redes['tiktok']) ?>" target="_blank" title="TikTok" class="text-gray-800 hover:text-black"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg></a>
            <?php endif; ?>
            <?php if (!empty($redes['youtube'])): ?>
            <a href="<?= e($redes['youtube']) ?>" target="_blank" title="YouTube" class="text-red-600 hover:text-red-800"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg></a>
            <?php endif; ?>
            <?php if (!empty($redes['linkedin'])): ?>
            <a href="<?= e($redes['linkedin']) ?>" target="_blank" title="LinkedIn" class="text-blue-700 hover:text-blue-900"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667h-3.554v-11.452h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zm-15.11-13.019c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019h-3.564v-11.452h3.564v11.452zm15.106-20.452h-20.454c-.979 0-1.771.774-1.771 1.729v20.542c0 .956.792 1.729 1.771 1.729h20.451c.978 0 1.778-.773 1.778-1.729v-20.542c0-.955-.8-1.729-1.778-1.729z"/></svg></a>
            <?php endif; ?>
            <?php if (!$hasLinks): ?><span class="text-gray-300 text-[10px]">—</span><?php endif; ?>
        </div>
    </td>
    <!-- Status -->
    <td class="px-1.5 py-0.5 text-center">
        <?php if ($isNeg): ?>
        <div class="inline-flex flex-col items-center">
            <div class="inline-flex items-center gap-0.5">
                <span class="px-1.5 py-px rounded-full font-semibold bg-purple-100 text-purple-700" style="font-size:10px">🤝 Neg.</span>
                <?php if (isMaster()): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Reverter para Novo?')"><input type="hidden" name="_action" value="reverter_status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>"><input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_fs" value="<?= e($fStatus) ?>"><input type="hidden" name="_fg" value="<?= e($fSegmento) ?>"><input type="hidden" name="_p" value="<?= $curPage ?>"><button type="submit" title="Reverter para Novo" class="text-gray-300 hover:text-red-500 transition" style="font-size:10px">↩</button></form>
                <?php endif; ?>
            </div>
            <?php if(!empty($l['convertido_por'])): ?><span class="text-gray-400" style="font-size:8px">por <?= e($l['convertido_por']) ?></span><?php endif; ?>
        </div>
        <?php elseif ($isCon): ?>
        <div class="inline-flex flex-col items-center">
            <div class="inline-flex items-center gap-0.5">
                <span class="px-1.5 py-px rounded-full font-semibold bg-green-100 text-green-700" style="font-size:10px">✅ Conv.</span>
                <?php if (isMaster()): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Reverter para Novo?\nO cliente criado NÃO será excluído.')"><input type="hidden" name="_action" value="reverter_status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>"><input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_fs" value="<?= e($fStatus) ?>"><input type="hidden" name="_fg" value="<?= e($fSegmento) ?>"><input type="hidden" name="_p" value="<?= $curPage ?>"><button type="submit" title="Reverter para Novo" class="text-gray-300 hover:text-red-500 transition" style="font-size:10px">↩</button></form>
                <?php endif; ?>
            </div>
            <?php if(!empty($l['convertido_por'])): ?><span class="text-gray-400" style="font-size:8px">por <?= e($l['convertido_por']) ?></span><?php endif; ?>
        </div>
        <?php elseif ($isCtd): ?>
        <div class="inline-flex flex-col items-center">
            <div class="inline-flex items-center gap-0.5">
                <span class="px-1.5 py-px rounded-full font-semibold bg-yellow-100 text-yellow-700" style="font-size:10px">📞 Contatado</span>
                <?php if (isMaster()): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Reverter para Novo?')"><input type="hidden" name="_action" value="reverter_status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>"><input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_fs" value="<?= e($fStatus) ?>"><input type="hidden" name="_fg" value="<?= e($fSegmento) ?>"><input type="hidden" name="_p" value="<?= $curPage ?>"><button type="submit" title="Reverter para Novo" class="text-gray-300 hover:text-red-500 transition" style="font-size:10px">↩</button></form>
                <?php endif; ?>
            </div>
            <?php if(!empty($l['contatado_por'])): ?><span class="text-gray-400" style="font-size:8px">por <?= e($l['contatado_por']) ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <form method="POST" class="inline-flex">
            <input type="hidden" name="_action" value="status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>"><input type="hidden" name="_q" value="<?= e($q) ?>"><input type="hidden" name="_fs" value="<?= e($fStatus) ?>"><input type="hidden" name="_fg" value="<?= e($fSegmento) ?>"><input type="hidden" name="_p" value="<?= $curPage ?>">
            <select name="novo_status" onchange="this.form.submit()" class="border border-gray-200 rounded-full px-1.5 py-px font-semibold cursor-pointer focus:outline-none <?= $statusBadges[$l['status']]??'' ?>" style="font-size:10px">
                <?php foreach (['novo','contatado','descartado'] as $s): ?><option value="<?= $s ?>" <?= $l['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </td>
    <!-- Ações -->
    <td class="px-1 py-0.5 text-center">
        <div class="flex items-center gap-0.5 justify-center">
            <?php if ($isAdv): ?>
                <?php if ($l['cliente_id']): ?>
                <a href="cliente_form.php?id=<?= $l['cliente_id'] ?>" class="text-blue-600 hover:text-blue-800 font-medium" style="font-size:10px">Cli</a>
                <?php if ($isNeg): $stN=$pdo->prepare('SELECT id FROM negociacoes WHERE cliente_id=? ORDER BY id DESC LIMIT 1');$stN->execute([$l['cliente_id']]);$nid=$stN->fetchColumn(); if($nid): ?>
                <a href="negociacao_detalhe.php?id=<?= $nid ?>" class="text-purple-600 hover:text-purple-800 font-medium" style="font-size:10px">Neg</a>
                <?php endif; endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" onsubmit="return confirm('Converter em cliente?\n\n<?= e(addslashes($l['empresa'])) ?>')">
                    <input type="hidden" name="_action" value="converter"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>">
                    <button class="bg-green-600 hover:bg-green-700 text-white font-medium px-1.5 py-px rounded" style="font-size:10px">Conv.</button>
                </form>
                <form method="POST" onsubmit="return confirm('Excluir?')">
                    <input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="_tab" value="<?= e($tabAtiva) ?>">
                    <button class="text-red-400 hover:text-red-600" style="font-size:10px">✕</button>
                </form>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($leads)): ?><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400 text-sm">Nenhum lead encontrado</td></tr><?php endif; ?>
</tbody>
</table>
<?php if ($pages > 1): ?>
<div class="px-3 py-1.5 border-t border-gray-100 flex items-center justify-between text-gray-500" style="font-size:11px">
    <span>Pág. <?= $curPage ?>/<?= $pages ?> (<?= $total ?>)</span>
    <div class="flex gap-0.5"><?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?tab=<?= urlencode($tabAtiva) ?>&p=<?= $i ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fStatus) ?>&segmento=<?= urlencode($fSegmento) ?>" class="px-1.5 py-px rounded <?= $i===$curPage?'bg-blue-600 text-white':'hover:bg-gray-100' ?>"><?= $i ?></a>
    <?php endfor; ?></div>
</div>
<?php endif; ?>
</div>

<?php require_once '_footer.php'; ?>
