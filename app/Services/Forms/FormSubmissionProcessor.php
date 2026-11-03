<?php

namespace App\Services\Forms;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactFormSubmission;
use App\Models\Instance;
use App\Models\User;
use App\Services\AI\AiAutoReplyService;
use App\Services\AI\AiInboundMessageRecorder;
use App\Services\Billing\Entitlements;
use App\Services\Channels\ContactLinks;
use App\Services\Channels\InboundAiBridge;
use App\Services\Channels\ReplyWindow;
use App\Services\Email\EmailInbound;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Support\Facades\Log;

/**
 * What happens after a visitor sends a form: the person becomes a contact, the submission appears as a conversation in the CRM chat
 * and, when the owner wants it, the AI agent answers through WhatsApp (or through another channel the same person already uses).
 */
class FormSubmissionProcessor
{
    public function __construct(private ChatMessageRecorder $chats)
    {
    }

    public function process(ContactFormSubmission $s): void
    {
        $form = $s->form;
        $user = User::find($s->user_id);
        if (!$form || !$user) {
            $s->update(['status' => 'failed', 'note' => 'form or owner missing']);

            return;
        }

        $values = (array) $s->data;
        $name = ContactFormFields::mapped($form, $values, 'name');
        $phone = ContactFormFields::mapped($form, $values, 'phone');
        $email = ContactFormFields::mapped($form, $values, 'email');
        $text = $this->summary($form, $values);

        $notes = [];
        $contact = $phone ? $this->contactFor($user, $phone, $name, $email, $notes) : null;
        if (!$phone) {
            $notes[] = __('No phone number: stored only.');
        }

        $delivery = 'none';
        $chat = null;
        if ($contact) {
            [$delivery, $chat] = $this->deliver($form, $s, $user, $contact, $phone, $name, $email, $text, $notes);
        } elseif ($email) {
            // no phone: the lead's own address and the owner's mailbox are enough for a conversation
            if ($mail = $this->viaEmail($form, $s, $user, $name, $email, $text)) {
                [$delivery, $chat, $contact] = $mail;
                $notes = [];
            }
        }

        $s->update([
            'contact_id' => $contact?->id,
            'chat_id' => $chat?->id,
            'delivery' => $delivery,
            'ai_requested' => $form->ai_reply && $delivery !== 'none',
            'status' => 'processed',
            'note' => $notes ? mb_substr(implode(' ', $notes), 0, 255) : null,
        ]);
    }

    /** Text of the conversation: every answer with its label, so the attendant and the AI see what the person filled in. */
    public function summary($form, array $values): string
    {
        $lines = [];
        foreach ((array) $form->fields as $f) {
            $v = $values[$f['key']] ?? '';
            if ($v !== '') {
                $lines[] = $f['label'] . ': ' . $v;
            }
        }

        return '📝 ' . __('Form') . ' «' . $form->name . "»\n" . implode("\n", $lines);
    }

    private function contactFor(User $user, string $phone, ?string $name, ?string $email, array &$notes): ?Contact
    {
        $contact = $this->chats->findContact((int) $user->id, $phone);

        if (!$contact) {
            if (!app(Entitlements::class)->allows($user, 'contacts')) {
                $notes[] = __('Contact limit of the plan reached: stored only.');

                return null;
            }
            $contact = Contact::create([
                'user_id' => $user->id,
                'contact' => $phone,
                'name' => $name ?: '+' . $phone,
                'email' => $email,
                'status' => 'ativo',
            ]);

            return $contact;
        }

        $dirty = [];
        if ($name && (!$contact->name || $contact->name === '+' . $contact->contact)) {
            $dirty['name'] = $name;
        }
        if ($email && !$contact->email) {
            $dirty['email'] = $email;
        }
        if ($dirty) {
            $contact->update($dirty);
        }

        return $contact;
    }

    /** @return array{0:string,1:?Chat} channel used + chat where the conversation landed */
    private function deliver($form, ContactFormSubmission $s, User $user, Contact $contact, string $phone, ?string $name, ?string $email, string $text, array &$notes): array
    {
        $allowed = array_values((array) $form->reply_channels);
        $may = fn (string $ch) => !$allowed || in_array($ch, $allowed, true);

        // 1) WhatsApp: the person typed the number, so it is always reachable if the owner has a connected number
        if ($may('whatsapp') && ($instance = $this->whatsappInstance($form, $user))) {
            $jid = $phone . '@s.whatsapp.net';
            $data = [
                'key' => ['remoteJid' => $jid, 'id' => 'FORM-' . $s->id, 'fromMe' => false],
                'pushName' => $name,
                'messageTimestamp' => time(),
                'message' => ['conversation' => $text],
            ];
            $chat = null;
            try {
                $message = $this->chats->recordUpsert($instance->instance_name, $data);
                $chat = $message ? Chat::find($message->chat_id) : null;
            } catch (\Throwable $e) {
                Log::warning('Form submission not recorded in the chat: ' . $e->getMessage());
            }

            $this->askAi($form, $contact, fn () => $this->runWhatsappAi($instance, $data, $text));

            return ['whatsapp', $chat];
        }

        // 2) Another channel the same person already writes through (window still open, e.g. Instagram 24 h)
        foreach (ContactLinks::group($contact) as $member) {
            $channel = ContactLinks::channelOf($member);
            if ($channel === 'whatsapp' || !$may($channel)) {
                continue;
            }
            $chat = Chat::with('instance')->where('user_id', $user->id)->where('contact_id', $member->id)->orderByDesc('last_message_at')->first();
            if (!$chat || !$chat->instance || !$chat->instance->isConnected() || !ReplyWindow::isOpen($chat)) {
                continue;
            }
            $this->askAi($form, $member, fn () => InboundAiBridge::run($chat->instance, $member, (string) $member->contact, 'FORM-' . $s->id, $text, 'conversation'));

            return [$channel, $chat];
        }

        // 3) The lead left an e-mail address and the owner has a mailbox connected
        if ($email && ($mail = $this->viaEmail($form, $s, $user, $name, $email, $text))) {
            return [$mail[0], $mail[1]];
        }

        $notes[] = __('No channel available to answer: stored only.');

        return ['none', null];
    }

    /** @return array{0:string,1:Chat,2:Contact}|null conversation opened in the owner's mailbox (the AI answers by e-mail) */
    private function viaEmail($form, ContactFormSubmission $s, User $user, ?string $name, string $email, string $text): ?array
    {
        $allowed = array_values((array) $form->reply_channels);
        if ($allowed && !in_array('email', $allowed, true)) {
            return null;
        }
        $instance = Instance::where('user_id', $user->id)->where('channel', 'email')->where('status', 'connected')->orderBy('id')->first();
        if (!$instance) {
            return null;
        }

        $parsed = [
            'subject' => (string) ($form->title ?: $form->name), 'from_email' => strtolower($email), 'from_name' => $name, 'to' => null,
            'message_id' => 'form-' . $s->id . '@forms.local', 'in_reply_to' => null, 'references' => [], 'date' => now()->toRfc2822String(),
            'text' => $text, 'attachments' => [], 'auto' => false, 'bounce' => false,
        ];
        $message = app(EmailInbound::class)->handle($instance, $parsed, (bool) $form->ai_reply);
        if (!$message) {
            return null;
        }
        $chat = Chat::find($message->chat_id);
        $contact = $chat ? Contact::find($chat->contact_id) : null;

        return $contact ? ['email', $chat, $contact] : null;
    }

    private function whatsappInstance($form, User $user): ?Instance
    {
        $q = Instance::where('user_id', $user->id)->where('status', 'connected')->where(fn ($w) => $w->whereNull('channel')->orWhere('channel', 'whatsapp'));
        if ($form->instance_id && ($chosen = (clone $q)->where('id', $form->instance_id)->first())) {
            return $chosen;
        }

        return $q->orderBy('id')->first();
    }

    private function askAi($form, Contact $contact, callable $run): void
    {
        if (!$form->ai_reply || (int) $contact->ignore_me === 1 || in_array($contact->status, ['inativo', 'no-whatsapp'], true)) {
            return;
        }
        try {
            $run();
        } catch (\Throwable $e) {
            Log::warning('AI flow failed for a form submission: ' . $e->getMessage());
        }
    }

    private function runWhatsappAi(Instance $instance, array $data, string $text): void
    {
        app(AiInboundMessageRecorder::class)->record($instance->instance_name, $data, $text);
        app(AiAutoReplyService::class)->handleInbound($instance->instance_name, $data, $text);
    }
}
