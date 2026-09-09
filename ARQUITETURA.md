# SINERGIA HOTRADAR — Arquitetura (E0 / E1 / E3)

## 1. Princípios

1. **Isolamento total do Achadinhos.** Repositório, banco, cron e deploy próprios.
   Nenhum `require` de código do Achadinhos, nenhuma tabela compartilhada, nenhuma
   credencial copiada.
2. **Modelo interno neutro.** O núcleo não conhece Mercado Livre nem Shopee. Cada
   marketplace é um *adapter* que produz `NormalizedProduct`. Adicionar Amazon /
   AliExpress no futuro = novo adapter, **sem tocar no banco**.
3. **Não inventar dados.** Campo indisponível = `NULL`, nunca `0`. O HOT SCORE trata
   ausência explicitamente (0 no bloco, ou neutro no caso da avaliação).
4. **Histórico imutável.** O estado atual do produto é atualizado; os sinais voláteis
   de cada coleta são preservados em `hr_product_snapshots` (base para TENDÊNCIA em E6).

## 2. Fluxo

```
   CollectorInterface                DiscoveryService                Banco (hr_*)
 ┌────────────────────┐   report   ┌─────────────────┐   upsert    ┌──────────────┐
 │ MercadoLivreCollector ─────────▶│ dedup + HOT SCORE ───────────▶│ hr_products   │ (estado atual)
 │  · GET /ofertas       │         │ + snapshot        ───────────▶│ hr_product_   │ (histórico)
 │  · OfertasJsonParser  │         │ + log da run      ───────────▶│   snapshots   │
 │ ShopeeCollector       │         └─────────────────┘             │ hr_collection_│
 │  (inativo: sem API)   │                                         │   runs        │
 └────────────────────┘                                            └──────────────┘
                                        Painel (public/index.php)  ◀── lê ───┘
                                        Dashboard · Curadoria · Ficha
```

- O **collector** só faz GET público e devolve `NormalizedProduct[]` + diagnóstico.
  Não grava nada.
- O **DiscoveryService** orquestra: dedup (`marketplace` + `marketplace_product_id`),
  cálculo do HOT SCORE, upsert do estado atual, escrita do snapshot, log da run.
- O **painel** só lê e muda status editorial.

## 3. Modelo de dados (`migrations/001_init.php`)

| Tabela | Papel |
|---|---|
| `hr_products` | **estado atual** normalizado (1 linha por produto de marketplace) |
| `hr_product_snapshots` | **histórico** — 1 linha por coleta que viu o produto (preço, desconto, vendas, nota, rank, hot_score, `raw` JSON) |
| `hr_collection_runs` | log de cada execução de coleta (páginas, cards, novos, erros, modo live/dry_run) |
| `hr_editorial_events` | trilha de mudanças de status editorial |
| `hr_settings` | override de config em JSON (ex.: pesos do HOT SCORE) |
| `hr_migrations` | controle de migrations |

### Campos comuns (`hr_products`) e o que cada marketplace preenche

| Campo | Mercado Livre (/ofertas) | Shopee (productOfferV2, quando ativo) |
|---|---|---|
| `marketplace_product_id` | MLB (catálogo) ou id do item | `itemId` |
| `shop_id` | — | `shopId` |
| `price_current` / `price_previous` / `discount_pct` | ✅ | ✅ |
| `sales_signal` (`muito_alto\|alto\|medio\|baixo`) | ✅ faixa textual normalizada | derivado de `sales` |
| `sales_exact` | **NULL** (ML não fornece) | ✅ `sales` |
| `rating` | ✅ | ✅ `ratingStar` |
| `rating_count` | **NULL** | **NULL** (não vem no offer) |
| `rank_position` | ✅ posição na página de ofertas | — |
| `has_video` | ✅ tag `has_published_clips` | **sempre false** (Shopee não expõe vídeo) |
| `commission_pct` / `commission_estimated` | — | ✅ |
| `campaign` | `promotion_type` (DEAL_OF_THE_DAY…) | período de campanha |
| `url_affiliate` | **NULL** nesta etapa (fase posterior) | `offerLink` (já vem pronto) |
| `data_quality` | `scrape_json` | `api` |
| `marketplace_extra` (JSON) | seller, loja oficial, ids extras, nicho | shop_name, faixas de preço, catids, período |

## 4. HOT SCORE — fonte única

Toda a matemática vive em **`config/hotscore.php`** (+ override opcional em
`hr_settings.hotscore`). Nada de pesos espalhados pelo código. `src/Score/HotScore.php`
só lê a config e produz um `ScoreBreakdown` **explicável** (cada bloco: pontos / máximo
/ justificativa / se o dado estava disponível). O breakdown é salvo em
`hr_products.hot_score_breakdown` e mostrado na ficha.

| Bloco | Máx | Fonte real |
|---|---:|---|
| Desconto | 30 | `discount_pct` |
| Vendas | 22 | `sales_signal` (ou `sales_exact` → signal na Shopee) |
| Avaliação | 14 | `rating` — **neutro 6 quando ausente** |
| Destaque | 10 | `rank_position` + bônus de `campaign` |
| Potencial visual | 14 | `has_video` (+9) + foto de qualidade (+5) |
| Aderência ao nicho | 10 | `NicheClassifier` (palavra-chave) |

Faixas: 🔥 85–100 · 🟠 70–84 · 🟡 50–69 · ⚪ <50.
Bloco "Tendência" fica para **v2** (precisa de ≥2 snapshots do mesmo produto).

## 5. Shopee — preparada e desligada

- `ShopeeCollector::isAvailable()` = `false` enquanto não houver `HR_SHOPEE_APP_ID` /
  `HR_SHOPEE_SECRET`. O painel mostra **"Shopee — aguardando credenciais da Open API"**.
- `ProductOfferV2Mapper` já mapeia todos os campos confirmados na auditoria
  (`itemId, shopId, price, priceMin/Max, priceDiscountRate, sales, ratingStar,
  commissionRate, commission, offerLink, productLink, imageUrl, shopName, campanha`).
- Ligar quando a conta for aprovada: preencher App ID + Secret no `.env`,
  `HR_SHOPEE_ENABLED=1`, e completar o POST assinado (SHA256) em `ShopeeCollector::collect()`
  — o restante (modelo, banco, painel, HOT SCORE) **não muda**.

## 6. Como adicionar um marketplace novo (ex.: Amazon)

1. `src/Collector/Amazon/AmazonCollector.php implements CollectorInterface`.
2. Um mapper que devolve `NormalizedProduct` (preenche o que a fonte tem, `NULL` no resto).
3. Registrar em `App::collectors()`.
4. Pronto. Sem migration, sem mudança no painel nem no HOT SCORE.

## 7. Link afiliado (fase posterior, fora de E0/E1/E3)

`url_affiliate` já existe no schema, vazio por ora. Quando for a hora:
não copiar cookie/CSRF do Achadinhos — consumir um endpoint interno do Achadinhos
(`POST /api/affiliate/ml-link`, ver auditoria §6). Na Shopee, `offerLink` preenche direto.

## 8. Não incluído nesta etapa (por decisão)

Instagram/Meta API, automação de comentários/Direct, WhatsApp, geração OpenAI,
download/recriação de vídeo, publicação automática, analytics de Instagram,
Link Builder ML automático, scraping de Shopee, cron, deploy em produção.
