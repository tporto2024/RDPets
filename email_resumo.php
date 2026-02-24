<?php
/**
 * email_resumo.php — Resumo diário de tarefas por usuário
 *
 * Executar via cron (EC2):
 *   0 8 * * * /usr/bin/php /var/www/html/crm/email_resumo.php >> /var/log/crm_email.log 2>&1
 *
 * Pré-requisito: preencher GMAIL_USER e GMAIL_PASS em config.php
 * GMAIL_PASS = Senha de app Google (não é a senha normal da conta)
 * Gerar em: Conta Google → Segurança → Verificação em 2 etapas → Senhas de app
 */

require_once __DIR__ . '/config.php';

// ─── Mailer SMTP (Gmail SSL porta 465) ────────────────────────────────────────
function smtpSend(string $to, string $toName, string $subject, string $html): bool
{
    if (!GMAIL_USER || !GMAIL_PASS) {
        echo "[AVISO] GMAIL_USER ou GMAIL_PASS não configurado em config.php\n";
        return false;
    }

    $socket = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 10);
    if (!$socket) {
        echo "[ERRO] Conexão SMTP falhou: $errstr ($errno)\n";
        return false;
    }

    $read = function () use ($socket): string {
        $out = '';
        while ($line = fgets($socket, 512)) {
            $out .= $line;
            if ($line[3] === ' ') break;
        }
        return $out;
    };
    $send = fn($cmd) => fwrite($socket, $cmd . "\r\n");

    $read();                                   // 220 greeting
    $send('EHLO crm.rdpets.com.br');
    $read();                                   // 250 capabilities
    $send('AUTH LOGIN');
    $read();                                   // 334 VXNlcm5hbWU6
    $send(base64_encode(GMAIL_USER));
    $read();                                   // 334 UGFzc3dvcmQ6
    $send(base64_encode(GMAIL_PASS));
    $auth = $read();                           // 235 ou erro

    if (!str_starts_with(trim($auth), '235')) {
        fclose($socket);
        echo "[ERRO] Autenticação Gmail falhou. Verifique GMAIL_PASS.\n";
        return false;
    }

    $from     = GMAIL_USER;
    $fromName = MAIL_FROM_NAME;
    $send("MAIL FROM:<{$from}>");
    $read();
    $send("RCPT TO:<{$to}>");
    $read();
    $send('DATA');
    $read();                                   // 354 End data with .

    $subjEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>\r\n";
    $headers .= "Subject: {$subjEncoded}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= "\r\n";

    $body = $headers . chunk_split(base64_encode($html)) . "\r\n.";
    $send($body);
    $read();
    $send('QUIT');
    fclose($socket);

    return true;
}

// ─── Builder do HTML do e-mail ────────────────────────────────────────────────
function buildEmail(string $nome, array $atrasadas, array $hoje, array $proximas): string
{
    $data     = date('d/m/Y');
    $siteUrl  = MAIL_SITE_URL;
    $tipoIcon = [
        'Ligar' => '📞', 'Email' => '✉️', 'Reunião' => '📅',
        'WhatsApp' => '💬', 'Visita' => '🚗', 'Almoço' => '🍽️', 'Tarefa' => '✅',
    ];

    $renderTarefas = function (array $lista, string $bgClass, bool $showData = true) use ($tipoIcon, $siteUrl): string {
        if (empty($lista)) return '';
        $html = '';
        foreach ($lista as $t) {
            $icon     = $tipoIcon[$t['tipo']] ?? '✅';
            $empresa  = $t['empresa'] ? " &mdash; {$t['empresa']}" : '';
            $dataHora = date('d/m H:i', strtotime($t['quando']));
            $corData  = $showData && strtotime($t['quando']) < strtotime('today') ? '#dc2626' : '#374151';
            $html .= "
            <tr>
              <td style='padding:8px 0;'>
                <table width='100%' cellpadding='12' cellspacing='0'
                       style='background:{$bgClass};border-radius:8px;'>
                  <tr>
                    <td width='30' style='font-size:20px;vertical-align:top;padding-right:0;'>{$icon}</td>
                    <td style='vertical-align:top;'>
                      <p style='margin:0 0 2px;font-size:14px;font-weight:600;color:#111827;'>"
                . htmlspecialchars($t['assunto'], ENT_QUOTES, 'UTF-8') . "</p>
                      <p style='margin:0;font-size:12px;color:#6b7280;'>"
                . htmlspecialchars($t['cliente'] . $empresa, ENT_QUOTES, 'UTF-8') . "</p>
                      <p style='margin:4px 0 0;font-size:12px;font-weight:600;color:{$corData};'>{$dataHora}</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>";
        }
        return $html;
    };

    $secAtrasadas = '';
    if (!empty($atrasadas)) {
        $count = count($atrasadas);
        $secAtrasadas = "
        <tr><td style='padding-bottom:20px;'>
          <p style='margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;
                    letter-spacing:0.06em;color:#dc2626;border-bottom:2px solid #fca5a5;padding-bottom:8px;'>
            ⚠️ Atrasadas <span style='background:#dc2626;color:white;border-radius:999px;
            padding:1px 7px;font-size:11px;margin-left:4px;'>{$count}</span>
          </p>
          <table width='100%' cellpadding='0' cellspacing='0'>"
            . $renderTarefas($atrasadas, '#fef2f2') . "
          </table>
        </td></tr>";
    }

    $secHoje = '';
    if (!empty($hoje)) {
        $count = count($hoje);
        $secHoje = "
        <tr><td style='padding-bottom:20px;'>
          <p style='margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;
                    letter-spacing:0.06em;color:#2563eb;border-bottom:2px solid #93c5fd;padding-bottom:8px;'>
            📅 Hoje <span style='background:#2563eb;color:white;border-radius:999px;
            padding:1px 7px;font-size:11px;margin-left:4px;'>{$count}</span>
          </p>
          <table width='100%' cellpadding='0' cellspacing='0'>"
            . $renderTarefas($hoje, '#eff6ff', false) . "
          </table>
        </td></tr>";
    }

    $secProximas = '';
    if (!empty($proximas)) {
        $count = count($proximas);
        $secProximas = "
        <tr><td style='padding-bottom:20px;'>
          <p style='margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;
                    letter-spacing:0.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;padding-bottom:8px;'>
            🔜 Próximos 7 dias <span style='background:#6b7280;color:white;border-radius:999px;
            padding:1px 7px;font-size:11px;margin-left:4px;'>{$count}</span>
          </p>
          <table width='100%' cellpadding='0' cellspacing='0'>"
            . $renderTarefas($proximas, '#f9fafb') . "
          </table>
        </td></tr>";
    }

    $total = count($atrasadas) + count($hoje) + count($proximas);

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:20px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1e40af,#3b82f6);padding:28px 32px;">
            <p style="margin:0 0 4px;font-size:22px;font-weight:700;color:#ffffff;">📋 Resumo de Tarefas</p>
            <p style="margin:0;font-size:14px;color:rgba(255,255,255,0.85);">
              Olá, <strong>{$nome}</strong>! Você tem <strong>{$total} tarefa(s)</strong> pendente(s). Hoje é {$data}.
            </p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              {$secAtrasadas}
              {$secHoje}
              {$secProximas}
            </table>
          </td>
        </tr>

        <!-- CTA -->
        <tr>
          <td style="padding:0 32px 24px;text-align:center;">
            <a href="{$siteUrl}/tarefas.php"
               style="display:inline-block;background:#2563eb;color:#ffffff;font-size:14px;
                      font-weight:600;padding:12px 28px;border-radius:8px;text-decoration:none;">
              Abrir minhas tarefas →
            </a>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;padding:16px 32px;text-align:center;
                     border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;">
            Email enviado automaticamente pelo CRM &bull; Não responda este email
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ─── Main ─────────────────────────────────────────────────────────────────────
$hoje    = date('Y-m-d');
$em7     = date('Y-m-d', strtotime('+7 days'));

$usuarios = $pdo->query(
    "SELECT id, nome, email FROM usuarios WHERE email IS NOT NULL AND email != '' ORDER BY nome"
)->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Iniciando envio de resumo para " . count($usuarios) . " usuário(s).\n";

foreach ($usuarios as $usr) {
    $stmt = $pdo->prepare("
        SELECT t.assunto, t.tipo, t.quando, t.descricao,
               c.nome AS cliente, c.empresa
        FROM tarefas t
        JOIN negociacoes n ON n.id = t.negociacao_id
        JOIN clientes c    ON c.id = n.cliente_id
        WHERE t.responsavel_id = ? AND t.status = 'aberta'
        ORDER BY t.quando ASC
    ");
    $stmt->execute([$usr['id']]);
    $tarefas = $stmt->fetchAll();

    if (empty($tarefas)) {
        echo "  ↳ {$usr['nome']}: sem tarefas pendentes. Pulando.\n";
        continue;
    }

    $atrasadas = array_values(array_filter($tarefas, fn($t) => date('Y-m-d', strtotime($t['quando'])) < $hoje));
    $deHoje    = array_values(array_filter($tarefas, fn($t) => date('Y-m-d', strtotime($t['quando'])) === $hoje));
    $proximas  = array_values(array_filter($tarefas, function($t) use ($hoje, $em7) {
        $d = date('Y-m-d', strtotime($t['quando']));
        return $d > $hoje && $d <= $em7;
    }));

    $html     = buildEmail($usr['nome'], $atrasadas, $deHoje, $proximas);
    $subject  = '📋 Suas tarefas do dia — ' . date('d/m/Y');
    $enviado  = smtpSend($usr['email'], $usr['nome'], $subject, $html);

    echo "  ↳ {$usr['nome']} <{$usr['email']}>: " . ($enviado ? "✓ enviado" : "✗ falha") . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Concluído.\n";
