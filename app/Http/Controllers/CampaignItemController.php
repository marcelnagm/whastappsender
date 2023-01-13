<?php

namespace App\Http\Controllers;

use App\Models\CampaignItem;
use Illuminate\Http\Request;

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
        $campaignItems = CampaignItem::paginate();

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
        return view('campaign-item.create', compact('campaignItem'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        request()->validate(CampaignItem::$rules);

        $campaignItem = CampaignItem::create($request->all());

        return redirect()->route('campaign-items.index')
            ->with('success', 'CampaignItem created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $campaignItem = CampaignItem::find($id);

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

        return view('campaign-item.edit', compact('campaignItem'));
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
