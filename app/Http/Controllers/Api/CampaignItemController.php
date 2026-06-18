<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GenerateCampaignItemJobsJob;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\WhatsappJob;
use App\Notifications\CampaignItemGenerationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CampaignItemController extends ApiController
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = $this->scopedToUser(CampaignItem::query())->with(['campaign:id,name']);

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }

        return $this->paginated($query->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge(CampaignItem::$rules, [
            'campaign_id' => 'required|exists:campaign,id',
            'image_url' => 'nullable|url',
            'file_upload' => 'nullable|image|max:5120',
            'welcome_enabled' => 'nullable|boolean',
        ]));

        $campaign = Campaign::findOrFail($data['campaign_id']);
        $this->authorizeOwner($campaign);

        $itemData = [
            'name' => $data['name'],
            'text' => $data['text'],
            'campaign_id' => $data['campaign_id'],
            'user_id' => Auth::id(),
            'welcome_enabled' => $request->boolean('welcome_enabled'),
            'image' => $request->input('image_url'),
        ];

        $campaignItem = CampaignItem::create($itemData);

        if ($request->hasFile('file_upload')) {
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";
            Storage::disk('s3')->put($path, file_get_contents($file));
            $campaignItem->update(['image' => Storage::disk('s3')->url($path)]);
        }

        return $this->success($campaignItem->fresh(), 'Campaign item created.', 201);
    }

    public function show(CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);

        return $this->success($campaignItem->load('campaign:id,name'));
    }

    public function update(Request $request, CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);

        $data = $request->validate(array_merge(CampaignItem::$rules, [
            'image_url' => 'nullable|url',
            'file_upload' => 'nullable|image|max:5120',
            'welcome_enabled' => 'nullable|boolean',
        ]));

        $updateData = [
            'name' => $data['name'] ?? $campaignItem->name,
            'text' => $data['text'] ?? $campaignItem->text,
            'welcome_enabled' => $request->has('welcome_enabled')
                ? $request->boolean('welcome_enabled')
                : $campaignItem->welcome_enabled,
        ];

        if ($request->hasFile('file_upload')) {
            $file = $request->file('file_upload');
            $extension = $file->getClientOriginalExtension();
            $path = "ads/{$campaignItem->campaign_id}/{$campaignItem->id}.{$extension}";
            Storage::disk('s3')->put($path, file_get_contents($file));
            $updateData['image'] = Storage::disk('s3')->url($path);
        } elseif ($request->has('image_url')) {
            $updateData['image'] = $request->input('image_url');
        }

        $campaignItem->update($updateData);

        return $this->success($campaignItem->fresh(), 'Campaign item updated.');
    }

    public function destroy(CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);

        try {
            $campaignItem->delete();
            return $this->success(null, 'Campaign item deleted.');
        } catch (\Exception $e) {
            return $this->error('Delete error: ' . $e->getMessage(), 500);
        }
    }

    public function generateTest(CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);

        $testContactId = env('WHATSAPP_CONTACT_TEST');
        if (!$testContactId) {
            return $this->error('WHATSAPP_CONTACT_TEST is not configured.', 422);
        }

        $job = new WhatsappJob();
        $job->endpoint = env('WHATSAPP_PROTOCOL', 'http') . '://' . env('WHATSAPP_URL', 'localhost') . ':' . env('WHATSAPP_PORT', '8080') . $campaignItem->getOperation();
        $job->payload = $campaignItem->generate($testContactId);
        $job->campaign_id = $campaignItem->campaign_id;
        $job->campaign_item_id = $campaignItem->id;
        $job->user_id = Auth::id();
        $job->save();

        return $this->success($job, 'Test send job created.');
    }

    public function generateAll(CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);

        $totalContatos = Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me')
            ->count();

        if ($totalContatos === 0) {
            return $this->error('No validated contacts found.', 422);
        }

        GenerateCampaignItemJobsJob::dispatch($campaignItem->id)->onQueue('default');
        Auth::user()->notify(new CampaignItemGenerationStatus(
            $campaignItem->id,
            $campaignItem->name,
            'started'
        ));

        return $this->success([
            'campaign_item_id' => $campaignItem->id,
            'contacts_count' => $totalContatos,
        ], "Started generation of {$totalContatos} send job(s).");
    }
}
