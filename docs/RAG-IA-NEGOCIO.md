# IA que aprende o negócio do cliente (RAG) — esboço

> Status: **proposta**, nada disso está implementado. Objetivo: o dono da conta "treina" a IA com FAQs, site, produtos, vendas e conversas, e ela (1) responde clientes com base nisso e (2) devolve **insights do negócio** numa tela.

## 1. Duas coisas diferentes (a decisão mais importante)

| Tipo de dado | Exemplos | Como a IA usa |
|---|---|---|
| **Texto / conhecimento** | FAQs, base de conhecimento, PDFs, site da empresa, descrições de produto, políticas | **RAG**: busca por similaridade (vetores) → trechos entram no prompt → resposta com citação |
| **Números / dados estruturados** | vendas, ticket médio, funil, conversão, renovações, MRR | **Nunca** por vetor nem "text-to-SQL" livre. O sistema calcula as métricas com código (já existem os relatórios) e expõe como **ferramentas fixas** (tool calling). A IA só interpreta e narra |

Isso evita números inventados e vazamento entre contas. O `ToolRegistry` e o `AgentResponder` atuais já suportam tool calling.

## 2. Arquitetura

```
 Fontes ──► Ingestão (fila) ──► Chunks + Embeddings ──► Vector store (por conta)
 (FAQ, KB, PDF,                                              │
  site, produtos)                                            ▼
                         Pergunta ──► Retriever (top-k + filtro da conta) ──► LLM ──► Resposta + citações
                                                              ▲
 Métricas (SQL fixo) ──► Ferramentas (receita, funil, churn…) ┘──► Relatório de Insights (blocos JSON → tela)
```

Componentes novos (todos atrás de interfaces para trocar o provedor):
- `VectorStore` (pgvector por padrão, Qdrant opcional) · `Embedder` · `Chunker` · `Ingestor` (jobs) · `Retriever` · `InsightBuilder`.
- O que já existe e é reaproveitado: provedores/modelos por agente (`config/ai.php`, protocolos openai/anthropic/gemini), `AgentResponder`, `ToolRegistry`, `KbArticle`, produtos, relatórios de funil/ganhos/renovações, filas, planos/limites, auditoria, `ContactPrivacy`.

## 3. Banco vetorial

| Opção | Quando | Prós | Contras |
|---|---|---|---|
| **pgvector** (recomendada) | padrão | um Postgres só para isso (`pgvector/pgvector:pg16`), HNSW, filtro por `user_id` na mesma query, backup simples, já usamos Postgres no Docker | escala média (milhões de chunks) — suficiente para o produto |
| Qdrant | muitos clientes / muito conteúdo | filtros e quantização muito bons, multi-tenant por coleção/payload | mais um serviço para operar |
| sqlite-vec | testes e dev | zero infra | só desenvolvimento |

Decisão: interface `VectorStore` com driver `pgvector` primeiro; novo serviço `rag-db` nos dois compose (produção e local, como foi feito com o Typebot). **Isolamento**: toda busca leva `user_id` obrigatório (a conta dona, não o membro da equipe) — um teste automático garante que uma conta nunca recupera chunk de outra.

## 4. Embeddings e modelos

- **Embeddings**: interface com provedores — OpenAI `text-embedding-3-small`, Google `text-embedding-004`/Gemini embeddings, Cohere/Voyage, ou **local via Ollama** (`bge-m3`, `multilingual-e5`) para quem exige que o dado não saia do servidor. Português precisa de modelo multilíngue. A Anthropic não oferece embeddings próprios, então o provedor de embeddings é independente do de chat. Guardar `embedding_model` e `dim` por chunk: trocar de modelo = reindexar.
- **Chat**: reaproveita o seletor atual de provedor/modelo. Proposta de **roteamento por tarefa**:
  - responder cliente com contexto (rápido, barato): modelo pequeno/médio (ex.: Haiku/Llama 3.1 8B/Gemini Flash);
  - relatório de insights (raciocínio mais longo, roda poucas vezes): modelo grande (Sonnet/Opus/GPT-4-classe/Llama 70B);
  - fallback automático quando o provedor falha.
- **Por tipo de negócio** — "perfis" prontos que o cliente escolhe no assistente de configuração; cada perfil define prompt de sistema, tom, quais fontes pedir, quais métricas importam e o nível de modelo:

| Perfil | Fontes sugeridas | Métricas/insights | Modelo de resposta / de insights |
|---|---|---|---|
| Varejo / e-commerce | catálogo, trocas e prazos, FAQ | produtos mais vendidos, ticket médio, recompra, carrinhos parados | pequeno / médio |
| Clínica / serviços com agenda | serviços, preços, preparo, políticas | agendamentos, no-show, retorno | pequeno (respostas curtas e seguras) / médio |
| Imobiliária | imóveis, regras de financiamento | funil por origem, tempo de ciclo, perdas por motivo | médio / grande |
| B2B / SaaS | docs, planos, contratos | funil, ciclo, MRR/ARR, churn, renovações | médio / grande |
| Educação | cursos, calendário, matrícula | leads por curso, conversão, evasão | pequeno / médio |
| Restaurante / delivery | cardápio, horários, taxas | itens campeões, horário de pico | pequeno / pequeno |

O perfil é só configuração (prompt + presets), então novos setores entram sem código.

## 5. Fontes e ingestão

1. **FAQ / base de conhecimento** (já existe `kb_articles`): indexação automática ao salvar. Primeiro passo mais barato.
2. **Upload** de PDF, DOCX, TXT, CSV: extração de texto no servidor, chunks de ~500–800 tokens com sobreposição, guardando título/página/URL para citar.
3. **Site da empresa**: o usuário informa a URL; rastreador (sitemap, mesmo domínio, limite de páginas, respeita `robots.txt`, **mesma validação anti-SSRF** do `OutboundWebhooks::urlAllowed`), reindexação agendada e por botão.
4. **Produtos** (`products`): um chunk por produto, atualizado quando o produto muda.
5. **Conversas** (opcional, desligado por padrão): pares pergunta/resposta bem avaliados viram sugestão de FAQ para o dono aprovar — nada de usar mensagens brutas de clientes sem consentimento (LGPD).
6. **Dados de venda**: não entram no vetor; viram ferramentas de métrica (seção 1).

Tudo roda em fila (`IngestSourceJob`), com status por fonte (pendente, indexando, pronto, erro), contagem de chunks e custo estimado em tokens.

## 6. Telas

- **Conhecimento** (`/knowledge`): lista de fontes com status, adicionar FAQ/arquivo/URL/produtos, reindexar, apagar, **"Testar pergunta"** mostrando a resposta com as fontes citadas e a pontuação de cada trecho. Painel "Perguntas sem resposta" (o que os clientes perguntaram e a IA não achou) → botão "criar FAQ".
- **Insights** (`/insights`): botão "Gerar análise" (ou agendada: semanal/mensal). A IA recebe os números das ferramentas e devolve um relatório. Saída:
  - **Recomendado: blocos JSON** (`kpi`, `chart`, `table`, `text`, `recommendation`) validados por esquema e **renderizados pelos nossos componentes** (gráficos com Chart.js). Bonito, consistente, sem risco.
  - **Se a pessoa quiser HTML livre**: sanitizar com lista de permissão (sem `script`, sem handlers, sem `javascript:`) e exibir em `<iframe sandbox>` com CSP restrita. Nunca injetar na página principal.
  - Cada afirmação numérica carrega a ferramenta e o período que a originou ("receita de outubro: R$ X — fonte: relatório de vendas"); histórico de relatórios salvo e comparável.
- Dentro do chat/agente: o agente de IA ganha "usar base de conhecimento" (liga/desliga) e mostra de onde veio a resposta para o atendente.

## 7. Modelo de dados (rascunho)

```
knowledge_sources   id, user_id, type(faq|file|url|products|kb), title, uri, status, error, chunks_count, tokens, last_indexed_at
knowledge_chunks    id, source_id, user_id, content, meta(json: página, url, título), embedding vector(N), embedding_model, token_count
insight_reports     id, user_id, period_from, period_to, profile, model, blocks(json), created_by, created_at
ai_usage            id, user_id, kind(embed|answer|insight), model, tokens_in, tokens_out, cost_estimate, created_at
```

Índice HNSW em `embedding` e índice em `user_id`; consulta sempre `WHERE user_id = ?`.

## 8. Segurança, privacidade e custo

- **Isolamento por conta** com teste automático; chunks e vetores entram na **exclusão LGPD** (`ContactPrivacy`) quando vierem de conversa, e na exportação de dados.
- **Prompt injection**: texto do site/PDF é *dado*, nunca instrução; o prompt de sistema diz isso, e o conteúdo recuperado não pode acionar ferramentas de escrita (enviar mensagem, apagar). Ferramentas de insight são somente leitura.
- **Chaves**: cada conta usa a própria chave de provedor (como hoje) ou a da plataforma com cota.
- **Custo**: limites por plano (nº de fontes/páginas/chunks, tokens de IA por mês), `ai_usage` para mostrar o consumo e bloquear com aviso; cache de embeddings por hash do conteúdo (reindexar só o que mudou); relatórios de insight agendados, não a cada clique.
- **Qualidade**: botão 👍/👎 em cada resposta; "não sei" obrigatório quando a pontuação do melhor trecho é baixa (a IA não inventa — encaminha para humano, reaproveitando o handoff).

## 9. Plano incremental

| Fase | Entrega | Valor |
|---|---|---|
| 1 | `VectorStore` + pgvector no compose, `Embedder`, indexar **FAQ/KB e upload**, "Testar pergunta" com citações, agente de IA usa o RAG | IA responde com base no negócio |
| 2 | Rastreador do **site** + **produtos** + reindexação agendada + "perguntas sem resposta → FAQ" | conhecimento fica completo e vivo |
| 3 | **Ferramentas de métrica** (receita, funil, ganhos/perdas, renovações) + tela **Insights** com blocos JSON e gráficos | o dono recebe análises |
| 4 | **Perfis por tipo de negócio** + roteamento de modelos + limites por plano + `ai_usage` | fácil de vender e controlar custo |
| 5 | Driver Qdrant, avaliação contínua (👍/👎), insights agendados por e-mail/WhatsApp, HTML sandbox opcional | escala e refinamento |

### Status da implementação

- **Fases 1 e 2: feitas** (fontes FAQ/texto/arquivo/KB/site/produtos, reindexação semanal, perguntas sem resposta, agente usa o RAG). Vetores ficam no banco da aplicação (`knowledge_chunks`, cosseno em PHP, limite `RAG_MAX_CHUNKS`); embeddings hospedados (OpenAI/Mistral) quando a conta tem chave, senão embedder interno.
- **Fase 3: feita** (tela *Insights*, métricas calculadas pelo código, blocos JSON validados, histórico).
- **Fase 4: feita** — perfis por tipo de negócio, roteamento de modelo (relatórios "deep" usam o modelo mais forte do provedor), limites por plano (`insights_per_month`, `knowledge_sources`) e log `ai_usage`.
- **Fase 5: feita** — 👍/👎 nas respostas, relatório agendado por e-mail e WhatsApp, e driver Qdrant opcional (`RAG_VECTOR_STORE=qdrant`) atrás da interface `VectorStore`. pgvector não foi implementado (Qdrant cobre o caso de escala sem exigir Postgres).
- Decisões 1 e 2 abaixo foram resolvidas: embeddings hospedados por padrão; blocos JSON (sem HTML livre).

## 10. Decisões em aberto (preciso da sua escolha)

1. Embeddings: usar um provedor hospedado por padrão (mais simples) ou local (Ollama) para privacidade? Sugestão: hospedado padrão, local opcional.
2. Insights em **blocos JSON** (seguro, recomendado) ou HTML livre em sandbox?
3. Limites por plano: quantas fontes/páginas/tokens em cada plano?
4. Conversas de clientes como fonte: oferecer (com consentimento e anonimização) ou deixar fora por enquanto?
5. O vetor fica num Postgres próprio (`rag-db`) ou reaproveitamos o Postgres do Evolution API? Sugestão: próprio, para não misturar com o serviço de terceiros.
