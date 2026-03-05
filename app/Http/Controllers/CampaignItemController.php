<?php

namespace App\Http\Controllers;

use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\Campaign;
use App\Models\WhatsappJob;
use App\WhastappService;
use Illuminate\Http\Request;
use Auth;
use Storage;
use Image;
use Illuminate\Support\Facades\Artisan;


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
        $campaignItem = new CampaignItem();
        request()->validate(CampaignItem::$rules);


        $data = $request->all();
        $data['user_id'] = Auth::user()->id;

        $campaignItem = CampaignItem::create($data);

        return redirect()->route('campaign-items.index')
            ->with('success', 'CampaignItem created successfully.');
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

        $campaignItem = CampaignItem::find($id);

        $cont = Contact::where('user_id', $campaignItem->user_id)->pluck('contact')->toArray();
        foreach ($cont as $c) {
            $job = new WhatsappJob();
            $job->endpoint = env('WHATSAPP_PROTOCOL', 'http') . '://' . env('WHATSAPP_URL', 'localhost') . ':' . env('WHATSAPP_PORT', '8080') . $campaignItem->getOperation();
            $job->payload = $campaignItem->generate($c);
            $job->campaign_id = $campaignItem->campaign_id;
            $job->campaign_item_id = $campaignItem->id;
            $job->user_id =  $campaignItem->user_id;
            $job->save();
        }
        return redirect()->route('campaign-items.index')
            ->with('success', 'Generated ' . (count($cont)) . " jobs");
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
        request()->validate(CampaignItem::$rules);


        $campaignItem->update($request->all());

        return redirect()->route('campaign-items.index')
            ->with('success', 'CampaignItem updated successfully');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy($id)
    {
        $campaignItem = CampaignItem::find($id)->delete();

        return redirect()->route('campaign-items.index')
            ->with('success', 'CampaignItem deleted successfully');
    }
}
