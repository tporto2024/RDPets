# CRM SocialPets / RD Pets - Backup de Memoria

> Documento de referencia para manutencao e desenvolvimento do sistema CRM.
> Atualizado em: 2026-02-24 (sessao 2)

---

## 1. Visao Geral do Sistema

- **Nome:** CRM SocialPets (RD Pets)
- **Versao:** 1.0
- **Stack:** PHP 8+ | MariaDB 10.4+ | Tailwind CSS (CDN) | Nginx (AWS) / Apache (XAMPP local)
- **Funcionalidades:** Gestao de clientes, negociacoes (Kanban), tarefas, importacao de planilhas, log de atividades

---

## 2. Ambientes

### Local (Desenvolvimento)
- **Servidor:** XAMPP (macOS)
- **Caminho:** `/Applications/XAMPP/xamppfiles/htdocs/crm/`
- **URL:** `http://localhost/crm/`
- **Banco:** MySQL local (`localhost`)
- **Credenciais BD:** `crmuser` / `CrmRD@2026` / database: `crm`

### AWS (Producao)
- **IP:** `18.190.119.64`
- **Porta:** `8080`
- **URL:** `http://18.190.119.64:8080/`
- **Servidor:** EC2 (Amazon Linux) + Nginx + PHP-FPM
- **Caminho dos arquivos:** `/var/www/html/crm/`
- **Chave SSH:** `~/.ssh/RD.pem` | Usuario: `ec2-user`
- **Banco:** MySQL local no EC2 (`localhost`) | `crmuser` / `CrmRD@2026`
- **Outro caminho Nginx (nao usado pelo CRM):** `/usr/share/nginx/html/` (config.php corrompido neste path - ignorar)

### Comando SSH
```bash
ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64
```

### Deploy manual (SCP)
```bash
scp -i ~/.ssh/RD.pem arquivo.php ec2-user@18.190.119.64:/tmp/
ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64 "sudo cp /tmp/arquivo.php /var/www/html/crm/ && sudo chown nginx:nginx /var/www/html/crm/arquivo.php"
```

---

## 3. Estrutura de Arquivos

```
crm/
├── config.php              # Conexao BD, helpers, constantes, email, WhatsApp, OAuth
├── auth.php                # Autenticacao: isLoggedIn(), isMaster(), requireLogin(), requireMaster()
├── login.php               # Tela de login
├── logout.php              # Logout
├── index.php               # Dashboard principal
├── _nav.php                # Menu lateral (sidebar)
├── _footer.php             # Rodape comum
├── clientes.php            # Lista de clientes (busca + tabela)
├── cliente_form.php        # Formulario criar/editar cliente
├── negociacoes.php         # Kanban de negociacoes (drag & drop)
├── negociacao_detalhe.php  # Detalhe/edicao de negociacao
├── tarefas.php             # Lista e gestao de tarefas
├── usuarios.php            # CRUD de usuarios (somente master)
├── configuracoes.php       # Configuracoes do sistema (etapas, tipos)
├── importar.php            # Importacao de planilha CSV/Excel
├── api.php                 # API interna (mover cards Kanban, etc.)
├── email_resumo.php        # Envio de resumo por email
├── google_callback.php     # Callback OAuth Google
├── whatsapp.php            # Integracao WhatsApp Cloud API
├── crm.sql                 # Schema completo do banco de dados
├── logo.png                # Logo do sistema
└── CLAUDE.md               # Este arquivo
```

---

## 4. Banco de Dados - Tabelas

| Tabela | Descricao |
|---|---|
| `usuarios` | Usuarios do sistema (id, nome, email, perfil, senha_hash, google_id, avatar_url, telefone) |
| `clientes` | Clientes cadastrados (id, nome, telefone, email, empresa, **tipo_negocio**, **origem** (Inbound/Outbound), cnpj, observacoes) |
| `neg_etapas` | Etapas do Kanban (nome, cor, ordem, is_encerrada, is_ganho) |
| `neg_tipos` | Tipos de negociacao |
| `negociacoes` | Negociacoes vinculadas a clientes (etapa, tipo, valor, responsavel, previsao) |
| `negociacoes_log` | Historico de mudancas de etapa |
| `tarefas` | Tarefas vinculadas a negociacoes (tipo, assunto, quando, status, prioridade) |
| `tarefas_log` | Historico de alteracoes em tarefas |
| `usuarios_log` | Log de atividades dos usuarios |

### Perfis de Usuario
- **master** — Acesso total (gerencia usuarios, configuracoes)
- **user** — Acesso padrao (sem gestao de usuarios/configuracoes)

---

## 5. Usuarios Cadastrados (AWS - Producao)

| ID | Nome | Email | Perfil |
|---|---|---|---|
| 1 | Thiago Porto | tporto.thiago@gmail.com | master |
| 2 | Patrick | patrickpssp@gmail.com | master |
| 3 | Luiz Preto123456 | luizpreto@gmail.com | user |
| 4 | Hayra Abade | hlhabade@gmail.com | master |
| 5+ | Rafaela | rgf.rafaelasocialpets@gmail.com | user |

---

## 6. Historico de Correcoes

### 2026-02-24 — Sessao de Manutencao

#### Bug 1: Coluna `tipo_negocio` inexistente no banco AWS
- **Erro:** `PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'tipo_negocio'`
- **Causa:** `cliente_form.php` referenciava a coluna `tipo_negocio` nos INSERT/UPDATE, mas a coluna nao existia na tabela `clientes` do banco AWS.
- **Correcao:**
  ```sql
  ALTER TABLE crm.clientes ADD COLUMN tipo_negocio varchar(100) DEFAULT NULL AFTER empresa;
  ```
- **Arquivos:** `crm.sql` atualizado com a coluna

#### Bug 2: Erro 500 por CNPJ duplicado (constraint UNIQUE)
- **Erro:** `PDOException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '' for key 'uniq_clientes_cnpj'`
- **Causa:** Quando o CNPJ nao era preenchido, o valor `''` (string vazia) era salvo, violando a constraint UNIQUE pois ja existiam outros clientes sem CNPJ.
- **Correcao em `cliente_form.php`:**
  - Campos vazios agora sao convertidos para `NULL` usando `?: null`
  - Adicionado `try/catch` com mensagem amigavel para CNPJ duplicado
  ```php
  $cnpj = trim($_POST['cnpj'] ?? '') ?: null;
  // ... try/catch com tratamento de PDOException
  ```

#### Bug 3: TypeError em `negociacao_detalhe.php`
- **Erro:** `TypeError: e(): Argument #1 ($s) must be of type string, null given` na linha 338
- **Causa:** Campo `changed_by` do log podia ser `NULL`
- **Correcao:**
  ```php
  <?= e($log['changed_by'] ?? 'sistema') ?>
  ```

#### Manutencao: Reset de senha
- Senha do usuario Thiago Porto (ID 1) resetada para `123456` no banco local
  ```bash
  /Applications/XAMPP/xamppfiles/bin/php -r "echo password_hash('123456', PASSWORD_DEFAULT);"
  ```

### 2026-02-24 — Sessao 2: Novas Funcionalidades

#### Feature 1: Campo Origem no cadastro de cliente
- Adicionado campo `origem` (ENUM: Inbound/Outbound) na tabela `clientes`
- Campo selecionavel no formulario `cliente_form.php`
- Exibido no card do cliente em `negociacao_detalhe.php` com icones (Inbound=📥, Outbound=📤)
- **SQL:** `ALTER TABLE clientes ADD COLUMN origem ENUM('Inbound','Outbound') DEFAULT NULL AFTER tipo_negocio;`

#### Feature 2: Tipo de Negocio como select (antes era texto livre)
- Substituido campo de texto por `<select>` que busca da tabela `neg_tipos` via `getTipos()`
- Dados legados normalizados ("Pet Shop" → "PetShop" para coincidir com `neg_tipos`)

#### Feature 3: Card do cliente na pagina de negociacao
- Card compacto no sidebar direito com: iniciais, nome, empresa, telefone (link tel:), email (link mailto:), tipo negocio, origem, CNPJ, observacoes
- Link de edicao do cliente (icone lapis)
- Query expandida no `negociacao_detalhe.php` para trazer todos os campos do cliente via JOIN

#### Feature 4: Nome do cliente fixo em negociacoes existentes
- Em negociacoes ja criadas, o nome do cliente aparece como texto estatico (nao editavel)
- Hidden input mantem o `cliente_id` original
- Select de cliente so aparece na criacao de nova negociacao

#### Feature 5: Vinculacao automatica tipo_id nas negociacoes
- Ao criar/atualizar negociacao, o sistema busca o `tipo_negocio` do cliente e resolve o `tipo_id` correspondente em `neg_tipos`
- A contagem na tela Configuracoes > Tipos de Negociacao se atualiza automaticamente
- Negociacoes existentes foram retroativamente vinculadas

#### Feature 6: Graficos do dashboard clicaveis
- Clicar em fatia do donut ou barra do grafico redireciona para `negociacoes.php?etapa=<etapa>`
- No Kanban, a coluna filtrada fica destacada (ring-2 azul), as outras ficam com opacidade reduzida
- Banner azul mostra o filtro ativo com botao "Limpar filtro"
- Cursor muda para pointer ao passar sobre areas clicaveis

#### Feature 7: Numeros nos graficos do dashboard
- Numero total (ex: 79) exibido no centro do donut com texto "negociacoes"
- Cada fatia do donut mostra seu numero (datalabels branco)
- Barras do grafico de valor mostram "R$ X" acima de cada barra
- Plugin `chartjs-plugin-datalabels@2.2.0` adicionado ao `_nav.php`

#### Feature 8: Campo de busca no Kanban
- Input de busca acima do Kanban (ao lado do botao "Nova Negociacao")
- Filtra cards em tempo real por: nome do cliente, empresa, telefone, responsavel, qualificacao
- Contadores das colunas se atualizam conforme filtro
- Atalho Ctrl+K (Cmd+K) para focar / Esc para limpar
- Botao X para limpar busca
- Dados de busca armazenados em `data-search` no HTML (mb_strtolower)

---

## 7. Logs e Diagnostico

### Localizacao dos logs no AWS
```
/var/log/php-fpm/www-error.log    # Erros PHP
/var/log/php-fpm/error.log        # Erros PHP-FPM
/var/log/nginx/error.log          # Erros Nginx
```

### Comandos uteis
```bash
# Ver ultimos erros PHP
ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64 "sudo tail -50 /var/log/php-fpm/www-error.log"

# Verificar colunas de uma tabela
ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64 "mysql -u crmuser -p'CrmRD@2026' -e 'SHOW COLUMNS FROM crm.clientes;'"

# Listar usuarios
ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64 "mysql -u crmuser -p'CrmRD@2026' -e 'SELECT id, nome, email, perfil FROM crm.usuarios;'"

# Resetar senha de um usuario (gerar hash local e atualizar)
/Applications/XAMPP/xamppfiles/bin/php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"
```

---

## 8. Etapas do Kanban (Padrao)

| Ordem | Nome | Cor | Encerrada | Ganho |
|---|---|---|---|---|
| 1 | Importado | cinza | Nao | Nao |
| 2 | Sem Retorno | vermelho | Nao | Nao |
| 3 | Em contato | azul | Nao | Nao |
| 4 | Testando | amarelo | Nao | Nao |
| 5 | Adiado | laranja | Sim | Nao |
| 6 | Vendido | verde | Sim | Sim |
| 7 | Perdido | vermelho | Sim | Nao |

---

## 9. Integracoes (Configuradas mas nao ativas)

- **Email SMTP:** Gmail (`contato@sismedic.com.br`)
- **WhatsApp Cloud API:** Template `tarefa_atribuida` (Phone ID e Token nao configurados)
- **Google OAuth 2.0:** Client ID/Secret nao configurados

---

## 10. Observacoes Importantes

- O arquivo `config.php` em `/usr/share/nginx/html/` no AWS esta **corrompido** (tag `<?php` duplicada). Esse path NAO e usado pelo CRM principal (que roda em `/var/www/html/crm/`). Pode ser ignorado.
- A constraint `UNIQUE` no CNPJ permite `NULL` (varios clientes sem CNPJ), mas nao permite string vazia duplicada `''`.
- Campos opcionais devem sempre ser convertidos para `NULL` quando vazios antes de salvar no banco.
- O sistema roda na porta `8080` no AWS (nao na 80).
