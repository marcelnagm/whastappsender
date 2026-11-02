<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\RegisterRequest;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\WhatsappJob;
use Exception;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /**
     * Display register page.
     * 
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        return view('auth.register');
    }

    /**
     * Handle account registration request
     * 
     * @param RegisterRequest $request
     * 
     * @return \Illuminate\Http\Response
     */
    public function register(RegisterRequest $request)
    {

        $data = $request->validated();
        //  $user = User::create($data);
        $this->generateFollowUp($data);

        // auth()->login($user);

        return redirect('/')->with('success', __('Account created. Contact :phone to activate it.', ['phone' => '5595981110695']));
    }

    private function generateFollowUp($data)
    {
        try {
            // 1. Upsert contact (updateOrCreate avoids duplicate key errors)
            $contact = Contact::updateOrCreate(
                ['contact' => $data['ddi'].$data['phone']], // Lookup key (avoids unique index exceptions)
                [
                    'name'    => $data['name'] . ' Site',
                    'email'   => $data['email'],
                    'user_id' => 1 // Admin / system user id
                ]
            );

            // 2. Message chosen by the administrator (Admin > Signup welcome); WHATSAPP_MESSAGE_UP is the fallback
            $welcome = app(\App\Services\Settings\SignupWelcome::class);
            $campaignItemId = $welcome->itemId();
            if (!$campaignItemId) {
                Log::info('Signup welcome skipped: no message selected in Admin > Signup welcome.');
                return;
            }

            $item = CampaignItem::find($campaignItemId);
            if (!$item) {
                Log::error("Follow-up aborted: CampaignItem {$campaignItemId} not found.");
                return;
            }

            // 3. Persist job row
            $now = now();
            $job = new WhatsappJob([
                'user_id'          => $item->user_id,
                'campaign_id'      => $item->campaign_id,
                'campaign_item_id' => $item->id,
                'contact_id'       => $contact->id,
                'status'           => 'pendente',
                'endpoint'         => $item->getOperation(),
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            if ($job->save()) {
                // 4. Queue dispatch — keeps the HTTP request responsive
                \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('default');
            }
        } catch (\Exception $e) {
            // Silent fail: log only; user signup must not break on follow-up issues.
            Log::critical("Critical follow-up failure for {$data['email']}: " . $e->getMessage());
        }
    }
}
