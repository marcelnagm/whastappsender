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
        'video' => '/message/sendMedia/'
    ];

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
            // 1. Pegamos apenas o caminho após o domínio e porta
            // De: http://s3.meusistema.local:9000/ads/ads/1/15.jpeg
            // Para: /ads/ads/1/15.jpeg
            $path = parse_url($fullUrl, PHP_URL_PATH);

            // 2. Removemos a primeira ocorrência do nome do bucket ('ads') 
            // e qualquer barra sobrando no início.
            // O Laravel 's3' já entra no bucket automaticamente, então o path
            // não pode começar com o nome do bucket.

            // Transformamos '/ads/ads/1/15.jpeg' em 'ads/1/15.jpeg'
            $relativePath = preg_replace('/^\/?ads\//', '', $path);

            // 3. Execução da deleção
            if (Storage::disk('s3')->exists($relativePath)) {
                Storage::disk('s3')->delete($relativePath);
            } else {
                // Log de segurança caso o path ainda esteja desalinhado
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

        if (isset($this->image) && URL::isValidUrl($this->image)) {

            $data  = [
                'number' => $contact->contact,
                "mediatype" => $this->imageType(),
                "mimetype"  => "image/png",     // Garanta que corresponde à extensão do arquivo
                "caption"   => $this->text,
                "media"     => $this->image,
                "fileName"  => "teste1.jpg"
            ];
        } else
            $data = array(
                'number' => $contact->contact,
                'text' => $this->text
            );

        return $data;
    }


    public function imageType()
    {
        // 1. Validação básica: existe imagem e é uma URL válida?
        if (!isset($this->image) || !filter_var($this->image, FILTER_VALIDATE_URL)) {
            return 'text';
        }

        // 2. Extrair apenas o PATH da URL (remove ?width=1280, etc.)
        $path = parse_url($this->image, PHP_URL_PATH);
        if (!$path) {
            return 'text';
        }

        // 3. Pegar a extensão de forma limpa e converter para minúsculo
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // 4. Mapeamento de tipos permitidos
        $formats = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            'video' => ['mp4', 'webm', 'ogg', 'mov', 'avi'],
        ];

        foreach ($formats as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }

        // Se não for imagem nem vídeo reconhecido, trata como texto ou erro
        return 'text';
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
                SUM(CASE WHEN evolution_status IN ("DELIVERED", "READ", "PLAYED", "delivered","delivery_ack", "read", "played") THEN 1 ELSE 0 END) as entregues
            ')
            ->first();

        if (!$stats || $stats->total == 0) return 0;

        return round(($stats->entregues / $stats->total) * 100, 1);
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
