<?php

namespace App\Http\Controllers;

use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\Campaign;
use App\Models\WhatsappJob;
use App\WhastappService;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Storage;
use Image;
use Illuminate\Support\Facades\Artisan;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;



/**
 * Class CampaignItemController
 * @package App\Http\Controllers
 */
class CampaignItemController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        $campaignItems = CampaignItem::where('user_id', Auth::user()->id)->paginate();


        return view('campaign-item.index', compact('campaignItems'))
            ->with('i', (request()->input('page', 1) - 1) * $campaignItems->perPage());
    }


    public function index_campaign($campaign)
    {
        $campaignItems = CampaignItem::where('user_id', Auth::user()->id)
            ->where('campaign_id', $campaign)->paginate();

        return view('campaign-item.index', compact('campaignItems'))
            ->with('i', (request()->input('page', 1) - 1) * $campaignItems->perPage());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function create()
    {
        $campaignItem = new CampaignItem();
        $campaigns = Campaign::where('user_id', Auth::user()->id)->pluck('name', 'id')->toArray();
        return view('campaign-item.create', compact('campaignItem', 'campaigns'));
    }


    public function store(Request $request)
    {
        $request->validate(array_merge(CampaignItem::$rules, [
            'file_upload' => 'nullable|image|max:5120', // Máx 5MB
        ]));

        $data = $request->all();
        $data['user_id'] = Auth::id();

        // Define a URL inicial caso tenha vindo via campo image_url
        $data['image'] = $request->input('image_url');

        // 1. Cria primeiro para obter o ID
        $campaignItem = CampaignItem::create($data);

        // 2. Processa Upload se existir
        if ($request->hasFile('file_upload')) {
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();

            // Estrutura: ads/{id-campanha}/{id-item}.extensao
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";

            // Upload para o MinIO (disco 's3' ou o nome que você deu ao MinIO no filesystems.php)
            Storage::disk('s3')->put($path, file_get_contents($file));

            // Atualiza o registro com a URL do MinIO ou o path relativo
            $campaignItem->update(['image' => Storage::disk('s3')->url($path)]);
        }

        return redirect()->route('campaign-items.index')->with('success', 'Item criado com sucesso.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function send($id)
    {

        Artisan::queue('whatsapp:disparar');
        return redirect()->route('campaign-items.index')

            ->with('success', 'Enviado com sucesso para o disparo, aguarde no seu whatsapp');
    }
    public function show($id)
    {
        $campaignItem = CampaignItem::find($id);

        return view('campaign-item.show', compact('campaignItem'));
    }

    public function generateAll($id)
    {
        $campaignItem = CampaignItem::select('id', 'user_id')->findOrFail($id);

        // 1. Conte direto no banco. Muito mais rápido que pluck()->count()
        $totalContatos = Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me') // Regra: só gera para quem foi minerado
            ->count();

        if ($totalContatos === 0) {
            return redirect()->back()->with('error', 'Nenhum contato validado encontrado para este usuário.');
        }

        // 2. Despacha o comando para a fila (background)
        // Certifique-se de que o comando NÃO seja interativo (sem $this->confirm)
        Artisan::queue('whatsapp:gerar', ['item_id' => $id]);

        return redirect()->route('campaign-items.index')
            ->with('success', "Iniciada a geração de {$totalContatos} disparos em segundo plano.");
    }

    public function generate($id)
    {

        $campaignItem = CampaignItem::find($id);
        $job = new WhatsappJob();
        $job->endpoint = env('WHATSAPP_PROTOCOL', 'http') . '://' . env('WHATSAPP_URL', 'localhost') . ':' . env('WHATSAPP_PORT', '8080') . $campaignItem->getOperation();
        $job->payload = $campaignItem->generate(env('WHATSAPP_CONTACT_TEST'));
        $job->campaign_id = $campaignItem->campaign_id;
        $job->campaign_item_id = $campaignItem->id;
        $job->user_id = Auth::user()->id;
        $job->save();
        return view('campaign-item.show', compact('campaignItem'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $campaignItem = CampaignItem::find($id);
        $campaigns = Campaign::where('user_id', Auth::user()->id)->pluck('name', 'id')->toArray();

        return view('campaign-item.edit', compact('campaignItem', 'campaigns'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  CampaignItem $campaignItem
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, CampaignItem $campaignItem)
    {
        $request->validate(array_merge(CampaignItem::$rules, [
            'file_upload' => 'nullable|image|max:5120',
        ]));

        $data = $request->all();

        if ($request->hasFile('file_upload')) {
            // 1. Remover arquivo antigo do MinIO se existir
            if ($campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL)) {
                // Extrai o path do arquivo da URL salva (ou usa o padrão se você salvar o path)
                $oldPath = parse_url($campaignItem->image, PHP_URL_PATH);
                $oldPath = ltrim($oldPath, '/ads/'); // Ajuste dependendo de como o MinIO retorna a URL
                Storage::disk('s3')->delete("ads/{$campaignItem->campaign_id}/" . basename($campaignItem->image));
            }

            // 2. Novo Upload
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";

            Storage::disk('s3')->put($path, file_get_contents($file));
            $data['image'] = Storage::disk('s3')->url($path);
        } else {
            // Se não subiu arquivo, usa o que está no campo URL
            $data['image'] = $request->input('image_url') ?? $campaignItem->image;
        }

        $campaignItem->update($data);

        return redirect()->route('campaign-items.index')->with('success', 'Item atualizado com sucesso');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */

    public function destroy($id)
    {
        $campaignItem = CampaignItem::findOrFail($id);

        try {
            $campaignItem->delete();

            return redirect()->route('campaign-items.index')
                ->with('success', 'Item removido com sucesso.');
        } catch (\Exception $e) {
            return redirect()->route('campaign-items.index')
                ->with('error', 'Erro ao deletar: ' . $e->getMessage());
        }
    }
}
