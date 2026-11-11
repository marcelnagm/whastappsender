<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Instance;
use App\Models\User;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use App\Services\Channels\ContactLinks;
use App\Services\Email\EmailClient;
use App\Services\Email\EmailPoller;
use App\Services\Email\Mailbox;
use App\Services\Email\QuotedReply;
use App\Services\Forms\ContactFormFields;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/**
 * Stands in for the IMAP server (the library itself talks to a real one): holds raw messages by uid and hands them over
 * parsed by the real webklex/php-imap library through Mailbox::map.
 */
class FakeImap
{
    public int $opened = 0;
    public bool $loginOk = true;

    /** @param array<int,string> $mailbox uid => raw message */
    public function __construct(public array $mailbox = [], public int $validity = 7)
    {
    }

    public function connect(): object
    {
        $this->opened++;
        if (!$this->loginOk) {
            throw new \App\Services\Email\EmailException('IMAP: login refused');
        }
        $server = $this;

        return new class($server) {
            public function __construct(private FakeImap $s)
            {
            }

            public function status(): array
            {
                return ['uidvalidity' => $this->s->validity, 'uidnext' => (empty($this->s->mailbox) ? 0 : max(array_keys($this->s->mailbox))) + 1];
            }

            public function newerThan(int $last, int $max): array
            {
                $out = [];
                foreach ($this->s->mailbox as $uid => $raw) {
                    if ($uid > $last && count($out) < $max) {
                        $out[$uid] = Mailbox::map(\Webklex\PHPIMAP\Message::fromString($raw));
                    }
                }

                return $out;
            }

            public function close(): void
            {
            }
        };
    }
}

class FakeSmtp implements TransportInterface
{
    /** @var Email[] */
    public array $sent = [];
    public ?string $fail = null;

    public function send(RawMessage $message, Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail) {
            throw new \RuntimeException($this->fail);
        }
        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'fake://smtp';
    }
}

class EmailChannelTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private FakeSmtp $smtp;
    private FakeImap $imap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        config(['app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false, 'email.allow_private_hosts' => false,
            'forms.default_country_code' => '55', 'email.ai_max_replies_per_sender_per_day' => 5]);
        $this->user = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto'])->fresh();

        $this->smtp = new FakeSmtp();
        $this->imap = new FakeImap();
        EmailClient::$transportFactory = fn () => $this->smtp;
        Mailbox::$factory = fn () => $this->imap->connect();
    }

    protected function tearDown(): void
    {
        EmailClient::$transportFactory = null;
        Mailbox::$factory = null;
        parent::tearDown();
    }

    private function creds(array $o = []): array
    {
        return $o + ['email' => 'Loja@Exemplo.com', 'from_name' => 'Loja', 'smtp_host' => 'smtp.exemplo.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_username' => 'loja@exemplo.com', 'smtp_password' => 'S3gredo!', 'imap_host' => 'imap.exemplo.com', 'imap_port' => 993, 'imap_encryption' => 'ssl'];
    }

    private function box(array $o = []): Instance
    {
        $this->imap->mailbox = [1 => $this->mail('antigo', 'Velho', 'ana@x.com')]; // exists before connecting
        $i = app(ChannelAccounts::class)->connectEmail($this->user, $this->creds($o));

        return $i->fresh();
    }

    private function mail(string $id, string $subject, string $from, string $body = 'Olá, queria um orçamento', array $headers = []): string
    {
        $h = ['From' => "Ana Souza <$from>", 'To' => 'loja@exemplo.com', 'Subject' => $subject, 'Message-ID' => "<$id@x.com>", 'Date' => date('r'),
            'Content-Type' => 'text/plain; charset=UTF-8'] + $headers;

        return implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($h), $h)) . "\r\n\r\n" . $body;
    }

    private function agent(): void
    {
        AiProfile::create(['user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'x']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Olá Ana! Segue o orçamento.']]], 'usage' => []])]);
    }

    // ---- connecting ------------------------------------------------------------------------------------------------

    public function test_connecting_checks_smtp_and_imap_and_stores_credentials_encrypted(): void
    {
        $i = $this->box();

        $this->assertSame('email', $i->channel);
        $this->assertSame('connected', $i->status);
        $this->assertSame('loja@exemplo.com', $i->email_address); // lower-cased
        $this->assertSame('S3gredo!', $i->smtp_password);
        $this->assertStringNotContainsString('S3gredo!', (string) DB::table('instances')->where('id', $i->id)->value('smtp_password'));
        $this->assertSame(1, (int) $i->email_last_uid); // the old mail is not imported
        $this->assertArrayNotHasKey('smtp_password', $i->toArray());
        $this->assertSame(1, $this->imap->opened);
    }

    public function test_wrong_credentials_are_refused_and_nothing_is_saved(): void
    {
        $this->smtp->fail = null;
        EmailClient::$transportFactory = function () {
            throw new \App\Services\Email\EmailException('SMTP: 535 login refused');
        };
        try {
            $this->box();
            $this->fail('should refuse');
        } catch (ChannelException $e) {
            $this->assertStringContainsString('535', $e->getMessage());
        }
        $this->assertSame(0, Instance::count());

        EmailClient::$transportFactory = fn () => $this->smtp;
        $this->imap->loginOk = false;
        Mailbox::$factory = fn () => $this->imap->connect();
        $this->expectException(ChannelException::class);
        $this->expectExceptionMessage('login refused');
        $this->box();
    }

    public function test_the_same_address_cannot_be_connected_twice(): void
    {
        $this->box();
        $this->expectException(ChannelException::class);
        app(ChannelAccounts::class)->connectEmail($this->user, $this->creds());
    }

    public function test_private_network_servers_and_odd_ports_are_blocked(): void
    {
        EmailClient::$transportFactory = null;
        foreach ([['smtp_host' => '127.0.0.1', 'smtp_port' => 587], ['smtp_host' => '10.0.0.5', 'smtp_port' => 587], ['smtp_host' => '169.254.169.254', 'smtp_port' => 25], ['smtp_host' => 'smtp.exemplo.com', 'smtp_port' => 6379]] as $bad) {
            try {
                app(ChannelAccounts::class)->connectEmail($this->user, $this->creds($bad));
                $this->fail('should block ' . $bad['smtp_host'] . ':' . $bad['smtp_port']);
            } catch (ChannelException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, Instance::count());
    }

    public function test_screen_connects_lists_and_removes_a_mailbox(): void
    {
        $this->actingAs($this->user)->get(route('email.index'))->assertOk();
        $this->actingAs($this->user)->post(route('email.store'), $this->creds())->assertRedirect(route('email.index'));
        $i = Instance::first();
        $this->actingAs($this->user)->get(route('email.index'))->assertOk()->assertSee('loja@exemplo.com')->assertDontSee('S3gredo!');

        $this->actingAs($this->user)->post(route('email.store'), ['email' => 'nao-e-email'])->assertSessionHasErrors(['email', 'smtp_host']);
        $this->actingAs($this->user)->post(route('email.refresh', $i))->assertSessionHas('success');

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->delete(route('email.destroy', $i))->assertNotFound();
        $this->actingAs($this->user)->delete(route('email.destroy', $i))->assertRedirect(route('email.index'));
        $this->assertSame(0, Instance::count());
    }

    public function test_members_without_the_channels_permission_cannot_open_the_screen(): void
    {
        $m = User::create(['name' => 'ag', 'email' => 'ag@t.dev', 'username' => 'ag@t.dev', 'password' => 'x']);
        $m->forceFill(['parent_user_id' => $this->user->id, 'team_role' => 'agent'])->save();
        $this->actingAs($m->fresh(), 'web')->get(route('email.index'))->assertForbidden();
    }

    // ---- receiving -------------------------------------------------------------------------------------------------

    public function test_new_mail_becomes_contact_chat_and_message_and_is_not_imported_twice(): void
    {
        $i = $this->box();
        $poller = app(EmailPoller::class);
        $this->assertSame(0, $poller->poll($i->fresh()));

        $this->imap->mailbox[2] = $this->mail('m2', 'Orçamento', 'Ana@X.com', "Olá, queria um orçamento\r\n\r\nOn Mon, Bob wrote:\r\n> citado antigo");
        $this->assertSame(1, $poller->poll($i->fresh()));
        $this->assertSame(0, $poller->poll($i->fresh()));

        $c = Contact::where('contact', 'em:ana@x.com')->first();
        $this->assertNotNull($c);
        $this->assertSame('Ana Souza', $c->name);
        $this->assertSame('email', $c->status); // out of WhatsApp campaigns

        $chat = Chat::first();
        $this->assertSame('em:ana@x.com', $chat->remote_jid);
        $this->assertSame($c->id, (int) $chat->contact_id);
        $this->assertSame('Orçamento', $chat->email_subject);
        $m = ChatMessage::first();
        $this->assertFalse((bool) $m->from_me);
        $this->assertStringContainsString('Orçamento', $m->body);
        $this->assertStringContainsString('queria um orçamento', $m->body);
        $this->assertStringNotContainsString('citado antigo', $m->body);
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(2, (int) $i->fresh()->email_last_uid);
    }

    public function test_a_failing_mailbox_is_marked_and_recovers_with_renew(): void
    {
        $i = $this->box();
        $this->imap->loginOk = false;
        Mailbox::$factory = fn () => $this->imap->connect();
        app(EmailPoller::class)->poll($i->fresh());
        $this->assertSame('disconnected', $i->fresh()->status);
        $this->assertStringContainsString('login refused', (string) $i->fresh()->email_error);

        $this->imap->loginOk = true;
        app(ChannelAccounts::class)->renew($i->fresh());
        $this->assertSame('connected', $i->fresh()->status);
        $this->assertNull($i->fresh()->email_error);
    }

    public function test_ai_answers_by_email_in_the_same_thread(): void
    {
        $this->agent();
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('m2', 'Orçamento', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());

        $this->assertCount(1, $this->smtp->sent);
        $sent = $this->smtp->sent[0];
        $this->assertSame('Re: Orçamento', $sent->getSubject());
        $this->assertSame('ana@x.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('loja@exemplo.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('Olá Ana! Segue o orçamento.', trim($sent->getTextBody()));
        $this->assertStringContainsString('m2@x.com', $sent->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('auto-generated', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());

        $this->assertSame('sent', AiMessage::where('role', 'assistant')->first()->status);
        $out = ChatMessage::where('from_me', true)->first();
        $this->assertSame('Olá Ana! Segue o orçamento.', $out->body);
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_automatic_mail_is_shown_but_never_answered(): void
    {
        $this->agent();
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('n1', 'Promoção', 'ofertas@loja.com', 'compre', ['List-Unsubscribe' => '<mailto:x@y.com>']);
        $this->imap->mailbox[3] = $this->mail('n2', 'Aviso', 'no-reply@banco.com', 'senha');
        $this->imap->mailbox[4] = $this->mail('n3', 'Fora', 'bob@x.com', 'estou fora', ['Auto-Submitted' => 'auto-replied']);
        $this->imap->mailbox[5] = $this->mail('n4', 'Falha', 'MAILER-DAEMON@x.com', 'bounce');
        $this->assertSame(3, app(EmailPoller::class)->poll($i->fresh())); // the bounce is not a conversation

        $this->assertSame(3, Chat::count());
        $this->assertCount(0, $this->smtp->sent);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai'));
    }

    public function test_the_ai_stops_answering_a_sender_that_floods(): void
    {
        $this->agent();
        config(['email.ai_max_replies_per_sender_per_day' => 2]);
        $i = $this->box();
        foreach ([2, 3, 4, 5] as $n) {
            $this->imap->mailbox[$n] = $this->mail("f$n", 'Oi', 'loop@x.com', "mensagem $n");
        }
        $this->assertSame(4, app(EmailPoller::class)->poll($i->fresh()));
        $this->assertCount(2, $this->smtp->sent);
        $this->assertSame(4, ChatMessage::where('from_me', false)->count());
    }

    public function test_stop_keyword_opts_out(): void
    {
        $this->agent();
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('s1', 'Pare', 'ana@x.com', '#sair');
        app(EmailPoller::class)->poll($i->fresh());
        $this->assertSame(1, (int) Contact::where('contact', 'em:ana@x.com')->value('ignore_me'));
        // the AI stays silent; the only mail is the confirmation that the opt-out worked
        $this->assertCount(1, $this->smtp->sent);
        $this->assertStringContainsString('will not send you more messages', $this->smtp->sent[0]->toString());
    }

    // ---- unifying contacts -------------------------------------------------------------------------------------------

    public function test_mail_from_a_known_whatsapp_contact_is_linked_to_the_same_person(): void
    {
        $wa = Contact::create(['user_id' => $this->user->id, 'contact' => '5595981110000', 'name' => 'Ana', 'email' => 'ana@x.com', 'status' => 'ativo']);
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('l1', 'Oi', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());

        $mail = Contact::where('contact', 'em:ana@x.com')->first();
        $this->assertSame($wa->id, (int) $mail->linked_contact_id);
        $this->assertCount(2, ContactLinks::group($wa));
        $this->assertSame('email', ContactLinks::channelOf($mail));
    }

    public function test_saving_an_email_on_a_whatsapp_contact_links_an_existing_mail_conversation(): void
    {
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('l2', 'Oi', 'bia@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $mail = Contact::where('contact', 'em:bia@x.com')->first();
        $this->assertNull($mail->linked_contact_id);

        $wa = Contact::create(['user_id' => $this->user->id, 'contact' => '5595982220000', 'name' => 'Bia', 'status' => 'ativo']);
        $wa->update(['email' => 'BIA@x.com']);
        $this->assertSame($wa->id, (int) $mail->fresh()->linked_contact_id);
    }

    public function test_an_address_shared_by_two_whatsapp_contacts_is_never_guessed(): void
    {
        Contact::create(['user_id' => $this->user->id, 'contact' => '5595981110001', 'name' => 'A', 'email' => 'casal@x.com', 'status' => 'ativo']);
        Contact::create(['user_id' => $this->user->id, 'contact' => '5595981110002', 'name' => 'B', 'email' => 'casal@x.com', 'status' => 'ativo']);
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('l3', 'Oi', 'casal@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $this->assertNull(Contact::where('contact', 'em:casal@x.com')->value('linked_contact_id'));
    }

    // ---- sending from the chat -------------------------------------------------------------------------------------------

    public function test_attendant_reply_in_the_chat_goes_out_by_email_in_the_thread(): void
    {
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('c1', 'Pedido 10', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'Já verifico seu pedido'])->assertStatus(201);

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame('Re: Pedido 10', $this->smtp->sent[0]->getSubject());
        $this->assertStringContainsString('c1@x.com', $this->smtp->sent[0]->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertNull($this->smtp->sent[0]->getHeaders()->get('Auto-Submitted')); // a person wrote it
        $m = ChatMessage::where('from_me', true)->first();
        $this->assertSame('sent', $m->fresh()->status);
        $this->assertNotEmpty($m->fresh()->message_id);
    }

    public function test_smtp_failure_marks_the_chat_message_as_error(): void
    {
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('c2', 'Oi', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $this->smtp->fail = 'connection refused for S3gredo!';

        $this->actingAs($this->user)->postJson(route('chat.api.send', Chat::first()), ['text' => 'Olá'])->assertStatus(201);
        $m = ChatMessage::where('from_me', true)->first()->fresh();
        $this->assertSame('error', $m->status);
        $this->assertStringNotContainsString('S3gredo!', (string) $m->error);
    }

    // ---- forms ---------------------------------------------------------------------------------------------------------

    public function test_form_lead_with_only_an_email_opens_a_mail_conversation_and_the_ai_answers(): void
    {
        $this->agent();
        $this->box();
        $form = ContactForm::create(['user_id' => $this->user->id, 'name' => 'Site', 'title' => 'Fale conosco',
            'fields' => ContactFormFields::normalize([
                ['label' => 'Nome', 'map' => 'name', 'required' => 1], ['label' => 'Email', 'map' => 'email', 'required' => 1], ['label' => 'Mensagem', 'map' => 'message'],
            ])]);

        $this->postJson('/api/public/forms/' . $form->public_key, ['name' => 'Caio', 'email' => 'caio@x.com', 'message' => 'Quero saber preços'])->assertOk();

        $s = ContactFormSubmission::first();
        $this->assertSame('email', $s->delivery);
        $this->assertTrue($s->ai_requested);
        $this->assertSame('processed', $s->status);
        $this->assertSame('em:caio@x.com', Contact::find($s->contact_id)->contact);
        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame('caio@x.com', $this->smtp->sent[0]->getTo()[0]->getAddress());
        $this->assertSame('Re: Fale conosco', $this->smtp->sent[0]->getSubject());
        $this->assertStringContainsString('Quero saber preços', ChatMessage::where('from_me', false)->first()->body);
    }

    public function test_form_with_ai_off_only_records_the_mail_conversation(): void
    {
        $this->agent();
        $this->box();
        $form = ContactForm::create(['user_id' => $this->user->id, 'name' => 'Site', 'ai_reply' => false,
            'fields' => ContactFormFields::normalize([['label' => 'Email', 'map' => 'email', 'required' => 1]])]);
        $this->postJson('/api/public/forms/' . $form->public_key, ['email' => 'dani@x.com'])->assertOk();
        $this->assertSame('email', ContactFormSubmission::first()->delivery);
        $this->assertCount(0, $this->smtp->sent);
        $this->assertSame(1, Chat::count());
    }

    // ---- API / tools -----------------------------------------------------------------------------------------------------

    public function test_rest_and_tool_connect_a_mailbox_and_list_it(): void
    {
        $admin = User::create(['name' => 'ad', 'email' => 'ad@t.dev', 'username' => 'ad', 'password' => 'x', 'role' => 'admin'])->fresh();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/channels/email', ['email' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/channels/email', $this->creds())->assertCreated()->assertJsonPath('data.channel', 'email')->assertJsonPath('data.account', 'loja@exemplo.com');

        $r = $this->getJson('/api/v1/channels?channel=email')->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertStringNotContainsString('S3gredo!', $r->getContent());

        $names = array_column(array_column(\App\Services\Api\ToolRegistry::definitions(true), 'function'), 'name');
        $this->assertContains('connect_email_account', $names);
    }

    public function test_email_shows_up_in_the_channel_statistics(): void
    {
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('st1', 'Oi', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $s = app(\App\Services\Stats\ChannelStats::class)->summary($this->user, 30);
        $this->assertContains('email', array_keys($s['channels'] ?? $s));
    }

    // ---- MIME ------------------------------------------------------------------------------------------------------------

    public function test_library_message_is_mapped_with_encodings_and_attachments(): void
    {
        $raw = "From: =?UTF-8?B?Sm9zw6k=?= <jose@x.com>\r\nSubject: =?ISO-8859-1?Q?Or=E7amento_urgente?=\r\nMessage-ID: <abc@x.com>\r\nReferences: <a@x.com> <b@x.com>\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BB\"\r\n\r\n--BB\r\nContent-Type: multipart/alternative; boundary=\"AA\"\r\n\r\n"
            . "--AA\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nQuero o or=E7amento =E0 vista\r\n"
            . "--AA\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>html</p>\r\n--AA--\r\n"
            . "--BB\r\nContent-Type: application/pdf; name=\"proposta.pdf\"\r\nContent-Disposition: attachment; filename=\"proposta.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode('%PDF-1.4 fake')) . "\r\n--BB--\r\n";

        $m = Mailbox::map(\Webklex\PHPIMAP\Message::fromString($raw));
        $this->assertSame('José', $m['from_name']);
        $this->assertSame('jose@x.com', $m['from_email']);
        $this->assertSame('Orçamento urgente', $m['subject']);
        $this->assertSame('Quero o orçamento à vista', $m['text']);
        $this->assertSame('abc@x.com', $m['message_id']);
        $this->assertSame(['a@x.com', 'b@x.com'], $m['references']);
        $this->assertCount(1, $m['attachments']);
        $this->assertSame('proposta.pdf', $m['attachments'][0]['name']);
        $this->assertSame('%PDF-1.4 fake', $m['attachments'][0]['data']);
        $this->assertFalse($m['auto']);
    }

    public function test_html_only_message_falls_back_to_html_and_trims_quotes(): void
    {
        $m = Mailbox::map(\Webklex\PHPIMAP\Message::fromString("From: a@x.com\r\nSubject: x\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<style>p{}</style><p>Olá<br>mundo</p>"));
        $this->assertSame("Olá\nmundo", $m['text']);

        $this->assertSame('Obrigado!', QuotedReply::trim("Obrigado!\n\nEm seg., 2 de nov. de 2026 10:00, Loja <l@x.com> escreveu:\n> oi"));
        $this->assertSame('> só citação', QuotedReply::trim("> só citação")); // never returns empty
    }

    public function test_mail_to_the_mailbox_itself_is_ignored_and_attachments_are_stored(): void
    {
        \Illuminate\Support\Facades\Storage::fake();
        $i = $this->box();
        $this->imap->mailbox[2] = $this->mail('self', 'Cópia', 'loja@exemplo.com');
        $att = "From: Ana <ana@x.com>\r\nSubject: Foto\r\nMessage-ID: <p1@x.com>\r\nContent-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain\r\n\r\nveja\r\n--B\r\nContent-Type: image/png; name=\"a.png\"\r\nContent-Disposition: attachment; filename=\"a.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' . "\r\n--B--\r\n";
        $this->imap->mailbox[3] = $att;
        $this->assertSame(1, app(EmailPoller::class)->poll($i->fresh()));

        $img = ChatMessage::where('type', 'image')->first();
        $this->assertNotNull($img);
        $this->assertSame('a.png', $img->file_name);
        \Illuminate\Support\Facades\Storage::assertExists($img->media_path);
    }

    public function test_shared_mailbox_assigns_new_conversations_and_signs_what_it_sends(): void
    {
        $i = $this->box();
        $agent = User::create(['name' => 'Bia', 'email' => 'b@t.dev', 'username' => 'bia', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->user->id, 'team_role' => 'agent', 'active' => true])->save();
        $stranger = User::create(['name' => 'Zed', 'email' => 'z@t.dev', 'username' => 'zed', 'password' => 'x'])->fresh();

        $this->actingAs($this->user)->put(route('email.settings', $i), ['default_assignee' => $stranger->id, 'email_signature' => 'x'])->assertSessionHasErrors('email');
        $this->actingAs($this->user)->put(route('email.settings', $i), ['default_assignee' => $agent->id, 'email_signature' => "Equipe Vendas\n(95) 3000-0000"])->assertRedirect();
        $this->assertSame($agent->id, (int) $i->fresh()->default_assignee);

        $this->imap->mailbox[2] = $this->mail('s1', 'Dúvida', 'ana@x.com');
        app(EmailPoller::class)->poll($i->fresh());
        $this->assertSame($agent->id, (int) Chat::first()->assigned_to);

        $this->actingAs($this->user)->postJson(route('chat.api.send', Chat::first()), ['text' => 'Olá!'])->assertStatus(201);
        $this->assertStringContainsString("-- \nEquipe Vendas", $this->smtp->sent[0]->getTextBody());

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->put(route('email.settings', $i), ['email_signature' => 'hack'])->assertStatus(404);
    }
}
