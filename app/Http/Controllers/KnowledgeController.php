<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlKnowledgeSiteJob;
use App\Jobs\IngestKnowledgeSourceJob;
use App\Models\KnowledgeQuestion;
use App\Models\KnowledgeSource;
use App\Services\Rag\DatabaseVectorStore;
use App\Services\Rag\EmbedderFactory;
use App\Services\Rag\KnowledgeSync;
use App\Services\Rag\RagException;
use App\Services\Rag\Retriever;
use App\Services\Rag\TextExtractor;
use Illuminate\Http\Request;

/** What the AI knows about the business: FAQs, texts, files and knowledge-base articles. */
class KnowledgeController extends Controller
{
    public function index(Request $request, EmbedderFactory $factory, DatabaseVectorStore $store)
    {
        $uid = $request->user()->id;
        $embedder = $factory->for($uid);

        return view('ai.knowledge', [
            'sources' => KnowledgeSource::where('user_id', $uid)->orderByDesc('id')->get(),
            'questions' => KnowledgeQuestion::where('user_id', $uid)->orderByDesc('hits')->orderByDesc('updated_at')->limit(15)->get(),
            'embedder' => $embedder, 'chunks' => $store->count($uid, $embedder->id()), 'allChunks' => $store->count($uid),
            'max' => (int) config('rag.max_chunks'), 'extensions' => TextExtractor::EXTENSIONS,
            'kbCount' => \App\Models\KbArticle::where('user_id', $uid)->where('active', true)->count(),
            'productCount' => \App\Models\Product::where('user_id', $uid)->where('active', true)->count(),
            'prefill' => (string) $request->query('q', ''),
        ]);
    }

    public function storeFaq(Request $request)
    {
        $d = $request->validate(['question' => 'required|string|max:300', 'answer' => 'required|string|max:8000', 'dismiss' => 'nullable|integer']);
        $uid = $request->user()->id;
        $this->queue(KnowledgeSource::create(['user_id' => $uid, 'type' => 'faq', 'title' => $d['question'], 'content' => $d['question'] . "\n\n" . $d['answer'], 'status' => 'pending']));
        if (!empty($d['dismiss'])) {
            KnowledgeQuestion::where('user_id', $uid)->whereKey($d['dismiss'])->delete();
        }

        return redirect()->route('ai.knowledge.index')->with('success', __('FAQ added. It will be searchable in a moment.'));
    }

    public function storeText(Request $request)
    {
        $d = $request->validate(['title' => 'required|string|max:200', 'content' => 'required|string|max:' . (int) config('rag.max_source_chars')]);
        $this->queue(KnowledgeSource::create(['user_id' => $request->user()->id, 'type' => 'text', 'title' => $d['title'], 'content' => $d['content'], 'status' => 'pending']));

        return redirect()->route('ai.knowledge.index')->with('success', __('Text added. It will be searchable in a moment.'));
    }

    public function storeFile(Request $request, TextExtractor $extractor)
    {
        $request->validate(['file' => 'required|file|max:' . (int) config('rag.max_upload_kb') . '|mimes:' . implode(',', TextExtractor::EXTENSIONS) . ',md,csv,txt']);
        $file = $request->file('file');
        try {
            $text = $extractor->extract($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RagException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        $name = mb_substr($file->getClientOriginalName(), 0, 190);
        $this->queue(KnowledgeSource::create(['user_id' => $request->user()->id, 'type' => 'file', 'title' => $name, 'uri' => $name, 'content' => $text, 'status' => 'pending']));

        return redirect()->route('ai.knowledge.index')->with('success', __('File added. It will be searchable in a moment.'));
    }

    public function storeSite(Request $request)
    {
        $d = $request->validate(['url' => 'required|url|max:255']);
        if (!\App\Services\Rag\PublicUrl::allowed($d['url'])) {
            return back()->withErrors(['url' => __('This address cannot be read (only public websites are allowed).')])->withInput();
        }
        $uid = $request->user()->id;
        $host = parse_url($d['url'], PHP_URL_HOST);
        $this->queue(KnowledgeSource::create(['user_id' => $uid, 'type' => 'site', 'title' => $host, 'uri' => $d['url'], 'status' => 'pending']));

        return redirect()->route('ai.knowledge.index')->with('success', __('Website added. Reading its pages can take a few minutes.'));
    }

    public function importProducts(Request $request, KnowledgeSync $sync)
    {
        $sync->products($request->user()->id, true);

        return back()->with('success', __('Products sent to the AI. They will follow future changes.'));
    }

    public function importKb(Request $request, KnowledgeSync $sync)
    {
        $n = $sync->importAll($request->user()->id);

        return back()->with('success', __(':n article(s) sent to the AI. They will follow future changes.', ['n' => $n]));
    }

    public function reindex(Request $request, int $source)
    {
        $s = KnowledgeSource::where('user_id', $request->user()->id)->findOrFail($source);
        $this->queue($s);

        return back()->with('success', __('Re-indexing started.'));
    }

    public function reindexAll(Request $request)
    {
        KnowledgeSource::where('user_id', $request->user()->id)->each(fn ($s) => $this->queue($s));

        return back()->with('success', __('Re-indexing started.'));
    }

    public function destroy(Request $request, int $source)
    {
        KnowledgeSource::where('user_id', $request->user()->id)->findOrFail($source)->delete();

        return back()->with('success', __('Deleted.'));
    }

    public function dismissQuestion(Request $request, int $question)
    {
        KnowledgeQuestion::where('user_id', $request->user()->id)->whereKey($question)->delete();

        return back();
    }

    /** "Test a question": the passages the agent would receive, with their scores. */
    public function ask(Request $request, Retriever $retriever)
    {
        $d = $request->validate(['question' => 'required|string|max:500']);
        try {
            $r = $retriever->search($request->user()->id, $d['question'], 6);
        } catch (RagException $e) {
            return back()->withInput()->withErrors(['question' => $e->getMessage()]);
        }

        return back()->withInput()->with('ask', $r);
    }

    private function queue(KnowledgeSource $s): void
    {
        $s->forceFill(['status' => 'pending', 'error' => null])->save();
        // a website is read again from the web; everything else is indexed from the text already saved
        $s->type === 'site' ? CrawlKnowledgeSiteJob::dispatch($s->id) : IngestKnowledgeSourceJob::dispatch($s->id);
    }
}
