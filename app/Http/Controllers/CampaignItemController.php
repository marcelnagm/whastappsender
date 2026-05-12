<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCampaignItemJobsJob;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\Campaign;
use App\Models\WhatsappJob;
use App\Notifications\CampaignItemGenerationStatus;
use App\WhastappService;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Storage;
use Image;
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

        $campaignItems = CampaignItem::where('user_id', Auth::user()->id)->with(['user', 'campaign'])->paginate();


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
            'file_upload' => 'nullable|image|max:5120', // Max ~5MB
        ]));

        $data = $request->all();
        $data['user_id'] = Auth::id();
        $data['welcome_enabled'] = $request->boolean('welcome_enabled');

        // Default image field from URL when no upload
        $data['image'] = $request->input('image_url');

        // 1. Create row first to obtain id
        $campaignItem = CampaignItem::create($data);

        // 2. Optional file upload
        if ($request->hasFile('file_upload')) {
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();

            // Path pattern: ads/{campaign_id}/{item_id}.{ext}
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";

            // Upload to MinIO (disk name from config/filesystems.php, often 's3')
            Storage::disk('s3')->put($path, file_get_contents($file));

            // Persist public/minio URL (or relative path, depending on driver)
            $campaignItem->update(['image' => Storage::disk('s3')->url($path)]);
        }

        return redirect()->route('campaign-items.index')->with('success', 'Item created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function send($id)
    {

        return $this->generateAll($id);
    }

    public function show($id)
    {
        $campaignItem = CampaignItem::find($id);

        return view('campaign-item.show', compact('campaignItem'));
    }

    public function generateAll($id)
    {
        $campaignItem = CampaignItem::select('id', 'user_id', 'name')->findOrFail($id);

        // 1. Count eligible contacts (single query, faster than pluck()->count())
        $totalContatos = Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me') // Only mined / validated leads
            ->count();

        if ($totalContatos === 0) {
            return redirect()->back()->with('error', 'No validated contacts found for this user.');
        }

        // 2. Async job on default queue to materialize send rows
        GenerateCampaignItemJobsJob::dispatch((int) $id)->onQueue('default');
        Auth::user()->notify(new CampaignItemGenerationStatus(
            (int) $campaignItem->id,
            (string) $campaignItem->name,
            'started'
        ));

        return redirect()->route('campaign-items.index')
            ->with('success', "Started generation of {$totalContatos} send job(s) in the background.");
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
        $data['welcome_enabled'] = $request->boolean('welcome_enabled');

        if ($request->hasFile('file_upload')) {
            // 1. Remove previous MinIO object when replacing upload
            if ($campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL)) {
                // Derive object key from stored URL (adjust if your MinIO URL shape differs)
                $oldPath = parse_url($campaignItem->image, PHP_URL_PATH);
                $oldPath = ltrim($oldPath, '/ads/'); // Depends on how MinIO returns URLs
                Storage::disk('s3')->delete("ads/{$campaignItem->campaign_id}/" . basename($campaignItem->image));
            }

            // 2. New upload
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";

            Storage::disk('s3')->put($path, file_get_contents($file));
            $data['image'] = Storage::disk('s3')->url($path);
        } else {
            // No new file — keep URL field
            $data['image'] = $request->input('image_url') ?? $campaignItem->image;
        }

        $campaignItem->update($data);

        return redirect()->route('campaign-items.index')->with('success', 'Item updated successfully.');
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
                ->with('success', 'Item removed successfully.');
        } catch (\Exception $e) {
            return redirect()->route('campaign-items.index')
                ->with('error', 'Delete error: ' . $e->getMessage());
        }
    }
}
