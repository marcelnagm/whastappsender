<?php

namespace Tests\Feature;

use App\Models\AiProfile;
use App\Models\KbArticle;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeQuestion;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\AI\AgentResponder;
use App\Services\Rag\Chunker;
use App\Services\Rag\Ingestor;
use App\Services\Rag\KnowledgeContext;
use App\Services\Rag\Retriever;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class KnowledgeRagTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function teach(User $u, string $title, string $text, string $type = 'faq'): KnowledgeSource
    {
        $s = KnowledgeSource::create(['user_id' => $u->id, 'type' => $type, 'title' => $title, 'content' => $text, 'status' => 'pending']);

        return app(Ingestor::class)->ingest($s);
    }

    private function profile(array $o = []): AiProfile
    {
        return AiProfile::create($o + ['user_id' => $this->owner->id, 'name' => 'Agente', 'enabled' => true, 'provider' => 'groq', 'model' => 'llama-3.3-70b-versatile', 'api_key' => 'gsk_test', 'temperature' => 0.7, 'max_tokens' => 256]);
    }

    public function test_chunker_respects_size_and_keeps_everything(): void
    {
        $text = implode("\n\n", array_map(fn ($i) => "Parágrafo $i. " . str_repeat('palavra ', 60), range(1, 8)));
        $chunks = (new Chunker())->split($text, 600, 60);
        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(700, mb_strlen($c));
        }
        $this->assertStringContainsString('Parágrafo 8', end($chunks));
        $this->assertSame([], (new Chunker())->split("  \n "));
    }

    public function test_basic_search_finds_the_right_passage_and_ignores_unrelated_questions(): void
    {
        $this->teach($this->owner, 'Horário', "Funcionamos de segunda a sexta, das 9h às 18h, e aos sábados até meio-dia.");
        $this->teach($this->owner, 'Trocas', "A troca de produtos pode ser feita em até 30 dias com a nota fiscal.");
        $r = app(Retriever::class)->search($this->owner->id, 'Qual o horário de funcionamento?');
        $this->assertSame('Horário', $r['hits'][0]['source']);

        $none = app(Retriever::class)->search($this->owner->id, 'xyzzy quantum banana');
        $this->assertSame([], $none['hits']);
    }

    public function test_search_never_crosses_accounts(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->teach($other, 'Segredo', 'A senha do cofre da outra empresa é laranja azul.');
        $r = app(Retriever::class)->search($this->owner->id, 'qual a senha do cofre?');
        $this->assertSame([], $r['hits']);
        $this->assertNull($r['best']);
    }

    public function test_hosted_embeddings_are_used_when_the_account_has_an_openai_key(): void
    {
        Http::fake(['api.openai.com/v1/embeddings' => function ($req) {
            $data = [];
            foreach ((array) $req['input'] as $i => $t) {
                $data[] = ['index' => $i, 'embedding' => str_contains(strtolower($t), 'preço') || str_contains(strtolower($t), 'valor') ? [1.0, 0.0, 0.0] : [0.0, 1.0, 0.0]];
            }

            return Http::response(['data' => $data]);
        }]);
        $this->profile(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-secret']);
        $this->teach($this->owner, 'Preços', 'O valor do plano é 99 reais.');
        $this->teach($this->owner, 'Endereço', 'Ficamos na Rua A.');

        $r = app(Retriever::class)->search($this->owner->id, 'quanto custa o preço?');
        $this->assertSame('openai:text-embedding-3-small', $r['embedder']);
        $this->assertSame('Preços', $r['hits'][0]['source']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer sk-secret'));
    }

    public function test_a_failing_provider_marks_the_source_with_a_clean_error(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key sk-secret']], 401)]);
        $this->profile(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-secret']);
        $s = $this->teach($this->owner, 'X', 'conteúdo qualquer');
        $this->assertSame('error', $s->status);
        $this->assertStringNotContainsString('sk-secret', $s->error);
    }

    public function test_changing_embedder_hides_old_vectors_until_reindexed(): void
    {
        $this->teach($this->owner, 'Horário', 'Funcionamos de segunda a sexta das 9h às 18h.');
        $this->assertSame('basic', KnowledgeChunk::first()->embedder);
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [1, 0]]]])]);
        $this->profile(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-x']);
        $this->assertSame([], app(Retriever::class)->search($this->owner->id, 'horário de funcionamento')['hits']);
        $this->actingAs($this->owner)->get(route('ai.knowledge.index'))->assertOk()->assertSee('need re-indexing');
    }

    public function test_context_instructs_the_agent_and_records_unanswered_questions(): void
    {
        $p = $this->profile(['use_knowledge' => true]);
        $this->teach($this->owner, 'Horário', 'Funcionamos de segunda a sexta das 9h às 18h.');
        $ctx = app(KnowledgeContext::class);

        $hit = $ctx->forHistory($p, [['role' => 'user', 'content' => 'Qual o horário de funcionamento?']]);
        $this->assertStringContainsString('REFERENCE MATERIAL', $hit);
        $this->assertStringContainsString('segunda a sexta', $hit);

        $miss = $ctx->forHistory($p, [['role' => 'user', 'content' => 'Vocês vendem foguetes?']]);
        $this->assertStringContainsString('Do not invent facts', $miss);
        $ctx->forHistory($p, [['role' => 'user', 'content' => 'Vocês vendem foguetes?']]);
        $this->assertSame(2, KnowledgeQuestion::first()->hits);
    }

    public function test_agent_prompt_carries_the_knowledge_only_when_enabled(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []])]);
        $this->teach($this->owner, 'Horário', 'Funcionamos de segunda a sexta das 9h às 18h.');
        $history = [['role' => 'user', 'content' => 'qual o horário de funcionamento?']];

        $off = $this->profile();
        app(AgentResponder::class)->generateReply($off, $history);
        Http::assertSent(fn ($r) => !str_contains(json_encode($r['messages']), 'segunda a sexta'));

        $on = $this->profile(['use_knowledge' => true]);
        app(AgentResponder::class)->generateReply($on, $history);
        Http::assertSent(fn ($r) => str_contains(json_encode($r['messages'], JSON_UNESCAPED_UNICODE), 'segunda a sexta'));
    }

    public function test_kb_articles_follow_only_after_the_import(): void
    {
        $a = KbArticle::create(['user_id' => $this->owner->id, 'title' => 'Garantia', 'body' => 'A garantia dura 12 meses.', 'active' => true]);
        $this->assertSame(0, KnowledgeSource::count());   // not imported yet: nothing is sent to the AI

        $this->actingAs($this->owner)->post(route('ai.knowledge.import-kb'))->assertRedirect();
        $this->assertSame('ready', KnowledgeSource::first()->status);

        $a->update(['body' => 'A garantia dura 24 meses.']);
        $this->assertStringContainsString('24 meses', KnowledgeSource::first()->content);
        $a->update(['active' => false]);
        $this->assertSame(0, KnowledgeSource::count());
    }

    public function test_pages_upload_and_account_scoping(): void
    {
        $this->actingAs($this->owner)->post(route('ai.knowledge.faq'), ['question' => 'Aceitam pix?', 'answer' => 'Sim, aceitamos pix e cartão.'])->assertRedirect();
        $file = UploadedFile::fake()->createWithContent('politica.txt', "Política de devolução: aceitamos devoluções em até 7 dias após o recebimento.");
        $this->actingAs($this->owner)->post(route('ai.knowledge.file'), ['file' => $file])->assertRedirect();
        $this->assertSame(2, KnowledgeSource::where('status', 'ready')->count());

        $this->actingAs($this->owner)->post(route('ai.knowledge.ask'), ['question' => 'aceitam pix?'])->assertRedirect()->assertSessionHas('ask');
        $this->actingAs($this->owner)->get(route('ai.knowledge.index'))->assertOk()->assertSee('Aceitam pix?')->assertSee('politica.txt');

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $id = KnowledgeSource::value('id');
        $this->actingAs($other)->delete(route('ai.knowledge.destroy', $id))->assertStatus(404);
        $this->actingAs($other)->post(route('ai.knowledge.reindex', $id))->assertStatus(404);
        $this->actingAs($this->owner)->delete(route('ai.knowledge.destroy', $id))->assertRedirect();
        $this->assertSame(0, KnowledgeChunk::where('source_id', $id)->count());   // chunks go with the source
    }

    public function test_unanswered_question_becomes_a_faq_and_leaves_the_list(): void
    {
        $q = KnowledgeQuestion::create(['user_id' => $this->owner->id, 'question' => 'Fazem entrega?', 'hits' => 3]);
        $this->actingAs($this->owner)->get(route('ai.knowledge.index', ['q' => 'Fazem entrega?', 'd' => $q->id]))->assertOk()->assertSee('value="Fazem entrega?"', false);
        $this->actingAs($this->owner)->post(route('ai.knowledge.faq'), ['question' => 'Fazem entrega?', 'answer' => 'Sim, em toda a cidade.', 'dismiss' => $q->id]);
        $this->assertSame(0, KnowledgeQuestion::count());
        $this->assertSame('Sim, em toda a cidade.', trim(explode("\n\n", KnowledgeSource::first()->content)[1]));
    }
}
