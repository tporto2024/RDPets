<?php
/**
 * whatsapp.php — Integração com WhatsApp Cloud API (Meta)
 *
 * Pré-requisitos:
 *  1. Preencher WA_PHONE_ID e WA_ACCESS_TOKEN em config.php
 *  2. Criar e aprovar o template "tarefa_atribuida" no Meta Business Manager
 *     com o corpo (exemplo):
 *
 *     Olá *{{1}}*, você recebeu uma nova tarefa no CRM:
 *
 *     📋 *{{2}}*
 *     🏷️ Tipo: {{3}}
 *     📅 Data: {{4}}
 *
 *  Os parâmetros são: 1=nome do usuário, 2=assunto, 3=tipo, 4=data/hora
 */

/**
 * Normaliza telefone para formato E.164 com código Brasil (55).
 * Aceita formatos: (77) 99152-6666 / 77991526666 / 5577991526666
 */
function waFormatPhone(string $telefone): string
{
    $tel = preg_replace('/\D/', '', $telefone);

    // Já tem DDI 55
    if (strlen($tel) === 13 && str_starts_with($tel, '55')) {
        return $tel;
    }
    // 11 dígitos: DDD + 9 + número
    if (strlen($tel) === 11) {
        return '55' . $tel;
    }
    // 10 dígitos: DDD + número sem o 9 — insere o 9
    if (strlen($tel) === 10) {
        return '55' . substr($tel, 0, 2) . '9' . substr($tel, 2);
    }

    return '55' . $tel; // fallback
}

/**
 * Envia notificação de nova tarefa via template do WhatsApp Cloud API.
 *
 * @param string $telefone   Número do responsável (campo usuarios.telefone)
 * @param string $nomeUsuario Nome do responsável
 * @param string $assunto    Assunto da tarefa
 * @param string $tipo       Tipo da tarefa (Ligar, Email, etc.)
 * @param string $quando     Datetime da tarefa (formato MySQL: Y-m-d H:i:s)
 * @return bool              true se enviou com sucesso
 */
function waSendTarefaAtribuida(
    string $telefone,
    string $nomeUsuario,
    string $assunto,
    string $tipo,
    string $quando
): bool {
    if (!WA_PHONE_ID || !WA_ACCESS_TOKEN || !$telefone) {
        return false;
    }

    $tel   = waFormatPhone($telefone);
    $data  = date('d/m/Y H:i', strtotime($quando));
    $url   = 'https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $tel,
        'type'              => 'template',
        'template'          => [
            'name'     => WA_TEMPLATE_NAME,
            'language' => ['code' => WA_TEMPLATE_LANG],
            'components' => [[
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $nomeUsuario],
                    ['type' => 'text', 'text' => $assunto],
                    ['type' => 'text', 'text' => $tipo],
                    ['type' => 'text', 'text' => $data],
                ],
            ]],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Busca o telefone do usuário pelo ID e dispara a notificação.
 * Retorna false silenciosamente se o usuário não tem telefone.
 */
function waNotificarResponsavel(
    PDO    $pdo,
    ?int   $responsavelId,
    string $assunto,
    string $tipo,
    string $quando
): void {
    if (!$responsavelId) return;

    $stmt = $pdo->prepare('SELECT nome, telefone FROM usuarios WHERE id = ?');
    $stmt->execute([$responsavelId]);
    $user = $stmt->fetch();

    if (!$user || !$user['telefone']) return;

    waSendTarefaAtribuida(
        $user['telefone'],
        $user['nome'],
        $assunto,
        $tipo,
        $quando
    );
}
