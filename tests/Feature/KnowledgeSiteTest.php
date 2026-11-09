<?php

namespace Tests\Feature;

use App\Jobs\CrawlKnowledgeSiteJob;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\User;
use App\Services\Rag\RagException;
use App\Services\Rag\Retriever;
use App\Services\Rag\SiteCrawler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class KnowledgeSiteTest extends TestCase
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

    private function page(string $title, string $body, array $links = []): \GuzzleHttp\Promise\PromiseInterface
    {
        $a = implode('', array_map(fn ($l) => '<a href="' . $l . '">x</a>', $links));

        return Http::response("<html><head><title>$title</title></head><body><p>$body</p>$a</body></html>", 200, ['Content-Type' => 'text/html']);
    }

    public function test_crawler_follows_same_host_links_and_obeys_robots(): void
    {
        $long = str_repeat('Texto da página com informações úteis. ', 5);
        Http::fake([
            '93.184.216.34/robots.txt' => Http::response("User-agent: *\nDisallow: /privado", 200),
            '93.184.216.34' => $this->page('Início', $long . 'Bem-vindo', ['/sobre', '/privado/x', 'http://93.184.216.35/fora', 'mailto:a@b.c']),
            '93.184.216.34/sobre' => $this->page('Sobre nós', $long . 'Entregamos em toda a cidade'),
            '*' => Http::response('nope', 404),
        ]);
        $r = app(SiteCrawler::class)->crawl('http://93.184.216.34/');
        $this->assertSame(2, $r['pages']);
        $this->assertStringContainsString('Entregamos em toda a cidade', $r['text']);
        $this->assertStringNotContainsString('privado', $r['text']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '93.184.216.35'));
    }

    public function test_crawler_refuses_private_addresses(): void
    {
        $this->expectException(RagException::class);
        app(SiteCrawler::class)->crawl('http://127.0.0.1/');
    }

    public function test_redirect_to_internal_address_is_not_followed(): void
    {
        Http::fake(['93.184.216.34/robots.txt' => Http::response('', 404), '93.184.216.34' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
        try {
            app(SiteCrawler::class)->crawl('http://93.184.216.34/');
            $this->fail('should have found nothing');
        } catch (RagException $e) {
            $this->assertStringContainsString('No readable text', $e->getMessage());
        }
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254'));
    }

    public function test_site_source_is_crawled_chunked_per_page_and_searchable(): void
    {
        $long = str_repeat('Atendemos clientes todos os dias com carinho. ', 4);
        Http::fake([
            '93.184.216.34/robots.txt' => Http::response('', 404),
            '93.184.216.34' => $this->page('Início', $long, ['/entrega']),
            '93.184.216.34/entrega' => $this->page('Política de entrega', $long . 'O frete é grátis acima de cinquenta reais.'),
        ]);
        $this->actingAs($this->owner)->post(route('ai.knowledge.site'), ['url' => 'http://93.184.216.34/'])->assertRedirect();
        $s = KnowledgeSource::first();
        $this->assertSame('ready', $s->status);
        $r = app(Retriever::class)->search($this->owner->id, 'o frete é grátis?');
        $this->assertStringContainsString('Política de entrega', $r['hits'][0]['content']);
        $this->assertStringContainsString('http://93.184.216.34/entrega', $r['hits'][0]['content']);
    }

    public function test_site_form_rejects_internal_addresses(): void
    {
        $this->actingAs($this->owner)->post(route('ai.knowledge.site'), ['url' => 'http://127.0.0.1/admin'])->assertSessionHasErrors('url');
        $this->actingAs($this->owner)->post(route('ai.knowledge.site'), ['url' => 'http://10.0.0.5:8080/'])->assertSessionHasErrors('url');
        $this->assertSame(0, KnowledgeSource::count());
    }

    public function test_products_are_imported_then_follow_changes(): void
    {
        $p = Product::create(['user_id' => $this->owner->id, 'name' => 'Camiseta Azul', 'sku' => 'CAM-1', 'unit' => 'un', 'price' => 59.9, 'active' => true]);
        $this->assertSame(0, KnowledgeSource::count());   // not imported yet
        $this->actingAs($this->owner)->post(route('ai.knowledge.import-products'))->assertRedirect();
        $this->assertStringContainsString('Camiseta Azul (CAM-1): R$ 59,90', KnowledgeSource::first()->content);

        $p->update(['price' => 64.9]);
        $this->assertStringContainsString('R$ 64,90', KnowledgeSource::first()->content);
        $r = app(Retriever::class)->search($this->owner->id, 'quanto custa a camiseta azul?');
        $this->assertStringContainsString('64,90', $r['hits'][0]['content']);
    }

    public function test_refresh_command_queues_sites(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        KnowledgeSource::create(['user_id' => $this->owner->id, 'type' => 'site', 'title' => 'x', 'uri' => 'http://93.184.216.34/', 'status' => 'ready']);
        $this->artisan('knowledge:refresh')->expectsOutput('1 website(s) queued.')->assertSuccessful();
        $this->assertSame('error', KnowledgeSource::first()->status);   // nothing readable in this fake web
    }
}
