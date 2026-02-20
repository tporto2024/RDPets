# CRM — Sistema de Gestão Comercial

Sistema PHP + MySQL para gestão de clientes, negociações e tarefas.

## Requisitos

- **XAMPP** (ou WAMP/Laragon) com PHP 8.0+ e MySQL/MariaDB
- Navegador moderno (Chrome, Edge, Firefox)

## Instalação (passo a passo)

### 1. Instalar o XAMPP
Baixe em: https://www.apachefriends.org/

### 2. Copiar os arquivos
Copie a pasta `crm/` para dentro de:
```
C:\xampp\htdocs\crm\        (Windows)
/Applications/XAMPP/htdocs/crm/  (Mac)
```

### 3. Importar o banco de dados
1. Inicie o Apache e o MySQL no painel do XAMPP
2. Acesse: http://localhost/phpmyadmin
3. Crie um banco chamado `crm`
4. Clique em "Importar" → selecione o arquivo `crm.sql` → Execute
5. Em seguida, importe também `melhorias.sql`

### 4. Configurar a conexão (se necessário)
Abra `config.php` e ajuste se sua instalação tiver senha no MySQL:
```php
define('DB_USER', 'root');
define('DB_PASS', '');   // coloque a senha aqui, se houver
```

### 5. Acessar o sistema
Abra no navegador: **http://localhost/crm/login.php**

### Credenciais padrão (do banco de dados importado)
| Usuário         | E-mail                      | Senha     |
|-----------------|-----------------------------|-----------|
| Thiago Porto    | tporto.thiago@gmail.com     | (a do banco original) |
| Patrick         | patrickpssp@gmail.com       | (a do banco original) |

> As senhas são as que você já usava no sistema original (hash bcrypt).
> Se não lembrar a senha, recadastre via phpMyAdmin com:
> `UPDATE usuarios SET senha_hash = '$2y$10$...' WHERE email = 'seu@email.com';`
> Gere um novo hash em: https://bcrypt-generator.com/

---

## Funcionalidades

| Módulo | Descrição |
|--------|-----------|
| **Dashboard** | Cards de resumo + gráficos de funil e valor por etapa |
| **Clientes** | Listagem com busca, cadastro, edição e exclusão |
| **Negociações (Kanban)** | Quadro com drag & drop entre etapas |
| **Detalhe da Negociação** | Formulário completo + tarefas + histórico de etapas |
| **Tarefas** | Lista com filtros por status, tipo e responsável |

## Estrutura de arquivos

```
crm/
├── config.php              — Configuração do banco de dados
├── auth.php                — Helpers de autenticação
├── login.php               — Tela de login
├── logout.php              — Logout
├── _nav.php                — Layout compartilhado (sidebar)
├── _footer.php             — Rodapé do layout
├── index.php               — Dashboard
├── clientes.php            — Lista de clientes
├── cliente_form.php        — Criar / editar cliente
├── negociacoes.php         — Kanban de negociações
├── negociacao_detalhe.php  — Detalhe + tarefas + histórico
├── tarefas.php             — Lista de tarefas
├── api.php                 — Endpoint AJAX (mover kanban, etc.)
├── melhorias.sql           — Melhorias no banco (execute após crm.sql)
└── README.md               — Este arquivo
```

## Suporte

Sistema desenvolvido com PHP 8+, Tailwind CSS, Chart.js e SortableJS.
