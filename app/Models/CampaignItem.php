<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use URL;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Class CampaignItem
 *
 * @property $id
 * @property $name
 * @property $text
 * @property $user_id
 * @property $campaign_id
 * @property $created_at
 * @property $updated_at
 * @property $image
 *
 * @property Campaign $campaign
 * @property User $user
 * @package App
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class CampaignItem extends Model
{
    protected $table = 'campaign_item';
    static $rules = [
        'name' => 'required',
        'text' => 'required',
    ];


    const OPERATION = [
        'text' => '/message/sendText/',
        'image' => '/message/sendMedia/',
        'video' => '/message/sendMedia/',
        'document' => '/message/sendMedia/',
        'audio' => '/message/sendWhatsAppAudio/', // voice note: WhatsApp has no caption for audio
    ];

    /** Largest upload accepted (KB). WhatsApp's own limit for media is 16 MB. */
    const MAX_UPLOAD_KB = 16384;

    /** extension => [kind, mime type]. Used to validate uploads, pick the Evolution endpoint and build the payload. */
    const MEDIA_TYPES = [
        'jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'],
        'gif' => ['image', 'image/gif'], 'webp' => ['image', 'image/webp'], 'bmp' => ['image', 'image/bmp'],
        'mp4' => ['video', 'video/mp4'], 'webm' => ['video', 'video/webm'], 'mov' => ['video', 'video/quicktime'],
        'avi' => ['video', 'video/x-msvideo'],
        'mp3' => ['audio', 'audio/mpeg'], 'ogg' => ['audio', 'audio/ogg'], 'oga' => ['audio', 'audio/ogg'],
        'opus' => ['audio', 'audio/ogg'], 'm4a' => ['audio', 'audio/mp4'], 'wav' => ['audio', 'audio/wav'],
        'aac' => ['audio', 'audio/aac'],
        'pdf' => ['document', 'application/pdf'], 'doc' => ['document', 'application/msword'],
        'docx' => ['document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['document', 'application/vnd.ms-excel'],
        'xlsx' => ['document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['document', 'application/vnd.ms-powerpoint'],
        'pptx' => ['document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt' => ['document', 'text/plain'], 'csv' => ['document', 'text/csv'],
    ];

    /** Laravel validation rule for the upload field (same list for the web form and the API). */
    public static function uploadRule(): string
    {
        return 'nullable|file|mimes:' . implode(',', array_keys(self::MEDIA_TYPES)) . '|max:' . self::MAX_UPLOAD_KB;
    }

    /** ext => kind, for the form's preview script */
    public static function kindsByExtension(): array
    {
        return array_map(fn ($v) => $v[0], self::MEDIA_TYPES);
    }

    protected $perPage = 20;

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'text', 'image', 'user_id', 'campaign_id', 'welcome_enabled'];

    protected $casts = [
        'welcome_enabled' => 'boolean',
    ];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function campaign()
    {
        return $this->hasOne('App\Models\Campaign', 'id', 'campaign_id');
    }


    public function delete()
    {
        $fullUrl = $this->image;

        if ($fullUrl) {
            // 1. Take only the path after host/port
            // From: http://s3.example.local:9000/ads/ads/1/15.jpeg
            // To:   /ads/ads/1/15.jpeg
            $path = parse_url($fullUrl, PHP_URL_PATH);

            // 2. Strip the first bucket segment ('ads') and any leading slash.
            // Laravel's 's3' disk already targets the bucket, so the stored path
            // must not repeat the bucket name.

            // Turn '/ads/ads/1/15.jpeg' into 'ads/1/15.jpeg'
            $relativePath = preg_replace('/^\/?ads\//', '', $path);

            // 3. Delete from storage
            if (Storage::disk('s3')->exists($relativePath)) {
                Storage::disk('s3')->delete($relativePath);
            } else {
                // Misaligned path — log for investigation
                \Log::warning("Delete attempt failed: file not found at {$relativePath}");
            }
        }
        return parent::delete();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }


    public function generate($contact_id)
    {

        $contact = Contact::find($contact_id);

        $image = $this->image;
        // $client_phone .= '@s.whatsapp.net';

        $kind = $this->imageType();

        // Unknown extension: plain text, as before (the media endpoints need a known type)
        if (isset($this->image) && URL::isValidUrl($this->image) && $kind !== 'text') {
            $ext = $this->mediaExtension();
            $mime = self::MEDIA_TYPES[$ext][1] ?? 'application/octet-stream';

            if ($kind === 'audio') {
                // sendWhatsAppAudio: a voice note, converted to OGG/Opus by Evolution (the text cannot go with it)
                $data = [
                    'number' => $contact->contact,
                    'audio' => $this->image,
                    'encoding' => true,
                ];
            } else {
                $data = [
                    'number' => $contact->contact,
                    'mediatype' => $kind,
                    'mimetype' => $mime,
                    'caption' => $this->text,
                    'media' => $this->image,
                    'fileName' => $this->mediaFileName($kind, $ext),
                ];
            }
        } else
            $data = array(
                'number' => $contact->contact,
                'text' => $this->text
            );

        return $data;
    }


    /** Lower-case extension of the media URL (query string ignored), or null */
    public function mediaExtension(): ?string
    {
        if (!isset($this->image) || !filter_var($this->image, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = parse_url($this->image, PHP_URL_PATH);

        return $path ? (strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: null) : null;
    }

    /** image | video | audio | document, or 'text' when there is no (recognised) media */
    public function imageType()
    {
        $ext = $this->mediaExtension();

        // Unknown extension: treat as a plain text payload
        return ($ext && isset(self::MEDIA_TYPES[$ext])) ? self::MEDIA_TYPES[$ext][0] : 'text';
    }

    /** Documents arrive under the item's name ("catalogo.pdf"), not under the storage key ("15.pdf") */
    private function mediaFileName(string $kind, ?string $ext): string
    {
        $path = (string) parse_url((string) $this->image, PHP_URL_PATH);
        $base = rawurldecode(basename($path)) ?: 'file' . ($ext ? ".{$ext}" : '');

        if ($kind === 'document' && $ext && ($slug = \Illuminate\Support\Str::slug((string) $this->name))) {
            return "{$slug}.{$ext}";
        }

        return $base;
    }


    public function getOperation()
    {
        return self::OPERATION[$this->imageType()];
    }
    public function getDeliveryRate()
    {
        $stats = \App\Models\WhatsappJob::where('campaign_item_id', $this->id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN evolution_status IN ("DELIVERED", "READ", "PLAYED", "delivered","delivery_ack", "read", "played") THEN 1 ELSE 0 END) as delivered_count
            ')
            ->first();

        if (!$stats || $stats->total == 0) return 0;

        return round(($stats->delivered_count / $stats->total) * 100, 1);
    }

    public function summary()
    {
        return WhatsappJob::where('campaign_item_id',$this->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(case when status = 'error' or evolution_status='error' then 1 end) as errors")
            ->selectRaw("count(case when evolution_status = 'SERVER_ACK' then 1 end) as sent")
            ->selectRaw("count(case when evolution_status = 'DELIVERED_ACK' then 1 end) as delivered")
            ->selectRaw("count(case when evolution_status = 'READ' then 1 end) as read_count")
            ->first();
        
    }

}
