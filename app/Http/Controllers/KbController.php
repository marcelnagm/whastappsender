<?php

namespace App\Http\Controllers;

use App\Models\KbArticle;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Knowledge base: articles managed here, searched from the chat with "?term". */
class KbController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $articles = KbArticle::where('user_id', $request->user()->id)->when($q !== '', fn ($w) => $this->match($w, $q))->orderBy('title')->paginate(30)->withQueryString();

        return view('kb.index', compact('articles', 'q'));
    }

    /** JSON for the chat: the best active matches (all words must appear somewhere). */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $items = KbArticle::where('user_id', $request->user()->id)->where('active', true)->when($q !== '', fn ($w) => $this->match($w, $q))
            ->orderByDesc('uses')->orderBy('title')->limit(8)->get();

        return response()->json(['items' => $items->map(fn ($a) => ['id' => $a->id, 'shortcut' => '', 'title' => $a->title, 'text' => $a->body, 'kb' => true])->values()]);
    }

    public function used(Request $request, int $article)
    {
        KbArticle::where('user_id', $request->user()->id)->whereKey($article)->increment('uses');

        return response()->json(['ok' => true]);
    }

    public function store(Request $request)
    {
        KbArticle::create($this->data($request) + ['user_id' => $request->user()->id, 'created_by' => TeamContext::actor($request)->id]);

        return back()->with('success', __('Article saved.'));
    }

    public function update(Request $request, int $article)
    {
        KbArticle::where('user_id', $request->user()->id)->findOrFail($article)->update($this->data($request));

        return back()->with('success', __('Article saved.'));
    }

    public function destroy(Request $request, int $article)
    {
        KbArticle::where('user_id', $request->user()->id)->findOrFail($article)->delete();

        return back()->with('success', __('Article removed.'));
    }

    private function data(Request $request): array
    {
        $d = $request->validate(['title' => 'required|string|max:160', 'body' => 'required|string|max:4000', 'keywords' => 'nullable|string|max:255']);

        return $d + ['active' => $request->boolean('active')];
    }

    private function match($query, string $q)
    {
        foreach (array_slice(preg_split('/\s+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY), 0, 5) as $w) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
            $query->where(fn ($x) => $x->where('title', 'like', $like)->orWhere('body', 'like', $like)->orWhere('keywords', 'like', $like));
        }

        return $query;
    }
}
