<?php

namespace Tests\Feature;

use App\Models\CampaignItem;
use App\Models\Contact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class CampaignMediaTest extends TestCase
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
    }

    private function item(?string $image, string $name = 'Catálogo 2026', string $text = 'Olá!'): CampaignItem
    {
        $item = new CampaignItem(['name' => $name, 'text' => $text, 'image' => $image]);

        return $item;
    }

    private function contact(): Contact
    {
        $c = new Contact();
        $c->forceFill(['contact' => '5595999999999'])->save();

        return $c;
    }

    public function test_kind_and_endpoint_follow_the_extension_ignoring_the_query_string(): void
    {
        $cases = [
            'https://s3.test/ads/1/5.JPG?x=1' => ['image', '/message/sendMedia/'],
            'https://s3.test/ads/1/5.mp4' => ['video', '/message/sendMedia/'],
            'https://s3.test/ads/1/5.pdf' => ['document', '/message/sendMedia/'],
            'https://s3.test/ads/1/5.docx' => ['document', '/message/sendMedia/'],
            'https://s3.test/ads/1/5.ogg' => ['audio', '/message/sendWhatsAppAudio/'],
            'https://s3.test/ads/1/5.mp3' => ['audio', '/message/sendWhatsAppAudio/'],
            'https://s3.test/page' => ['text', '/message/sendText/'],
            'https://s3.test/file.exe' => ['text', '/message/sendText/'],
        ];

        foreach ($cases as $url => [$kind, $endpoint]) {
            $item = $this->item($url);
            $this->assertSame($kind, $item->imageType(), $url);
            $this->assertSame($endpoint, $item->getOperation(), $url);
        }

        $this->assertSame('text', $this->item(null)->imageType());
        $this->assertSame('text', $this->item('not a url.pdf')->imageType());
    }

    public function test_image_payload_has_the_real_mimetype_and_file_name(): void
    {
        $p = $this->item('https://s3.test/ads/1/5.png')->generate($this->contact()->id);

        $this->assertSame('image', $p['mediatype']);
        $this->assertSame('image/png', $p['mimetype']);
        $this->assertSame('5.png', $p['fileName']);
        $this->assertSame('Olá!', $p['caption']);
        $this->assertSame('https://s3.test/ads/1/5.png', $p['media']);
        $this->assertSame('5595999999999', $p['number']);
    }

    public function test_pdf_is_sent_as_a_document_named_after_the_item(): void
    {
        $p = $this->item('https://s3.test/ads/1/5.pdf')->generate($this->contact()->id);

        $this->assertSame('document', $p['mediatype']);
        $this->assertSame('application/pdf', $p['mimetype']);
        $this->assertSame('catalogo-2026.pdf', $p['fileName']);
        $this->assertSame('Olá!', $p['caption']);
    }

    public function test_video_payload(): void
    {
        $p = $this->item('https://s3.test/ads/1/5.mov')->generate($this->contact()->id);

        $this->assertSame(['video', 'video/quicktime', '5.mov'], [$p['mediatype'], $p['mimetype'], $p['fileName']]);
    }

    public function test_audio_is_a_voice_note_without_caption(): void
    {
        $p = $this->item('https://s3.test/ads/1/5.opus')->generate($this->contact()->id);

        $this->assertSame(['number' => '5595999999999', 'audio' => 'https://s3.test/ads/1/5.opus', 'encoding' => true], $p);
    }

    public function test_item_without_recognised_media_is_sent_as_plain_text(): void
    {
        $id = $this->contact()->id;

        $this->assertSame(['number' => '5595999999999', 'text' => 'Olá!'], $this->item(null)->generate($id));
        $this->assertSame(['number' => '5595999999999', 'text' => 'Olá!'], $this->item('https://x.test/img?id=3')->generate($id));
    }

    public function test_upload_rule_accepts_the_supported_media_and_rejects_the_rest(): void
    {
        $rule = ['file_upload' => CampaignItem::uploadRule()];
        $check = fn (UploadedFile $f) => Validator::make(['file_upload' => $f], $rule)->passes();

        $this->assertTrue($check(UploadedFile::fake()->image('a.jpg')));
        $this->assertTrue($check(UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf')));
        $this->assertTrue($check(UploadedFile::fake()->create('v.mp4', 8000, 'video/mp4')));
        $this->assertTrue($check(UploadedFile::fake()->create('a.mp3', 3000, 'audio/mpeg')));

        $this->assertFalse($check(UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')));
        $this->assertFalse($check(UploadedFile::fake()->create('big.pdf', CampaignItem::MAX_UPLOAD_KB + 1, 'application/pdf')));
        $this->assertTrue(Validator::make(['file_upload' => null], $rule)->passes());
    }

    public function test_the_form_script_gets_the_same_extension_map(): void
    {
        $kinds = CampaignItem::kindsByExtension();

        $this->assertSame('document', $kinds['pdf']);
        $this->assertSame('audio', $kinds['ogg']);
        $this->assertSame(array_keys(CampaignItem::MEDIA_TYPES), array_keys($kinds));
    }
}
