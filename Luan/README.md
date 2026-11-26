# SICE — Sistema Inteligente de Controle de Estoque (PHP + MySQL)

Versão reformulada da aplicação **SICE** (originalmente em Next.js + Payload CMS) para **PHP 8.1+**, **MySQL 8+ / MariaDB**, **HTML5**, **CSS3** e **JavaScript puro**, preservando fielmente toda a interface original e as regras de negócio.

## Estrutura do Projeto

```text
├── index.php                 # Ponto de entrada e shell HTML
├── config/
│   ├── database.php          # Conexão PDO com MySQL
│   └── auth.php              # Sessões PHP, permissões e carregamento de estado
├── api/
│   ├── auth.php              # Login e logout (POST)
│   ├── actions.php           # Endpoints de CRUD e movimentações com transações SQL
│   ├── state.php             # Retorna estado atualizado em JSON
│   └── receipt.php           # Download autenticado de comprovantes de compra
├── assets/
│   ├── css/styles.css        # Folha de estilos original preservada
│   ├── js/app.js             # Interface interativa, CMV, QR Code e etiquetas
│   └── img/                  # Imagens e favicon
├── database/
│   └── schema.sql            # Criação do banco, tabelas, chaves estrangeiras e seed
└── uploads/
    └── receipts/             # Pasta protegida para upload de comprovantes (PDF/imagens)
```

## Como Instalar e Executar

### 1. Importar o banco de dados MySQL
No **phpMyAdmin**, **MySQL Workbench** ou terminal MySQL, importe o arquivo `database/schema.sql`:

```bash
mysql -u root -p < database/schema.sql
```

Isso criará o banco `sice_db`, todas as 6 tabelas relacionais (`users`, `inventory_products`, `inventory_movements`, `inventory_labels`, `purchase_orders`, `purchase_receipts`) e dados iniciais de exemplo.

### 2. Configurar a conexão com o MySQL
Edite `config/database.php` caso seu usuário/senha ou host do MySQL sejam diferentes do padrão (`127.0.0.1`, porta `3306`, banco `sice_db`, usuário `root`, senha vazia), ou defina as variáveis de ambiente:
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

### 3. Subir a aplicação em PHP
- **XAMPP / Laragon / WAMP**: coloque esta pasta dentro de `htdocs/sice` (ou `www/sice`) e acesse `http://localhost/sice/`.
- **Servidor embutido do PHP**:
  ```bash
  php -S localhost:8000
  ```
  Acesse `http://localhost:8000/`.

## Credenciais de Teste (Seed)

A senha padrão para todos os perfis criados pelo `database/schema.sql` é: **`password`**

| Perfil | E-mail | Permissões |
|---|---|---|
| **Proprietário** | `admin@alltoalmar.com.br` | Acesso total (estoque, compras, CMV, projeção sazonal, equipe) |
| **Almoxarife** | `almoxarife@alltoalmar.com.br` | Estoque, entradas/baixas, compras, relatórios, etiquetas |
| **Equipe operacional** | `cozinha@alltoalmar.com.br` | Consulta de estoque, registro de baixas (Cozinha/Bar), etiquetas e leitor QR |
