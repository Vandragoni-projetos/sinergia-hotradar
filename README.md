# SINERGIA HOTRADAR

Descoberta, qualificação e curadoria de produtos "quentes" em marketplaces para
conteúdo de afiliados. **Aplicação separada do Achadinhos** — banco próprio, cron
próprio, deploy próprio. Não lê nem escreve nada do Achadinhos.

Etapa atual: **E0 (fundação) + E1 (coletor Mercado Livre) + E3 (painel + HOT SCORE)**.
Não implementado nesta etapa: Instagram, WhatsApp, publicação, geração de copy,
Link Builder automático, Shopee ativo, deploy.

## Stack

- PHP 8.2 + Apache (produção, imagem Docker — paridade com o Achadinhos)
- Banco **próprio**: SQLite no dev local / MariaDB em produção (mesmo schema, via PDO)
- Sem framework, sem Composer (autoloader PSR-4 mínimo)

## Rodar localmente (Windows, sem instalar nada no sistema)

O PHP portátil fica em `.tools/php/` (gitignored). Para recriar:

```bash
curl -sL -o php.zip https://windows.php.net/downloads/releases/php-8.2.33-nts-Win32-vs16-x64.zip
# extrair em .tools/php/ ; baixar cacert.pem em .tools/php/ ; usar .tools/php/php.ini
```

```bash
PHP=".tools/php/php.exe -c .tools/php/php.ini"

cp .env.example .env
$PHP bin/hr.php migrate                     # cria o schema (SQLite em storage/)
$PHP bin/hr.php collect:ml --dry-run         # coleta ML sem gravar
$PHP bin/hr.php collect:ml --pages=3         # coleta real
$PHP bin/hr.php stats
$PHP bin/hr.php test                         # suíte de testes

$PHP -S 127.0.0.1:8090 -t public            # painel em http://127.0.0.1:8090
```

## CLI (`bin/hr.php`)

| Comando | O quê |
|---|---|
| `migrate` / `migrate:status` | schema |
| `collect:ml [--dry-run] [--pages=N] [--categories=MLB1574,MLB5726] [--save-raw]` | coleta Mercado Livre |
| `collect:shopee` | mostra "aguardando credenciais da Open API" |
| `score:recompute` | recalcula HOT SCORE de todos os produtos |
| `stats` | distribuição por faixa / status / marketplace |
| `test` | testes |

## Painel

- **Dashboard** — contadores, distribuição HOT SCORE, status dos marketplaces, últimas coletas, botão "Coletar agora".
- **Curadoria** — lista de cards com filtros (marketplace, faixa, nicho, vídeo, desconto, avaliação, status, data, busca); ações **Aprovar / Analisar / Descartar / Abrir produto**.
- **Ficha** — todos os dados + "Por que recebeu esse HOT SCORE?" (componente a componente) + histórico de coletas + trilha editorial.

## Documentação

- [`ARQUITETURA.md`](ARQUITETURA.md) — decisões, modelo de dados, extensibilidade, Shopee.
- Auditorias originais: entregues à parte (`SINERGIA-HOTRADAR-AUDITORIA*.md`).
