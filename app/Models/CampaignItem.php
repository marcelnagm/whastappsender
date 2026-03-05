<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use URL;

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
    protected $fillable = ['name', 'text', 'image', 'user_id', 'campaign_id'];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function campaign()
    {
        return $this->hasOne('App\Models\Campaign', 'id', 'campaign_id');
    }




    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    public function generate($client_phone)
    {

        $image = $this->image;
        // $client_phone .= '@s.whatsapp.net';

        if (isset($this->image) && URL::isValidUrl($this->image)) {

            $data  = [
                'number' => $client_phone,
                "mediatype" => $this->imageType(),
                "mimetype"  => "image/png",     // Garanta que corresponde à extensão do arquivo
                "caption"   => $this->text,
                "media"     => $this->image,
                "fileName"  => "teste1.jpg"
            ];
        } else
            $data = array(
                'number' => $client_phone,
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
                SUM(CASE WHEN evolution_status IN ("DELIVERED", "READ", "PLAYED", "delivered", "read", "played") THEN 1 ELSE 0 END) as entregues
            ')
            ->first();

        if (!$stats || $stats->total == 0) return 0;

        return round(($stats->entregues / $stats->total) * 100, 1);
    }
    
}
