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
        if (isset($this->image) && URL::isValidUrl($this->image)) {
            $ext = substr(strrchr($this->image, '.'), 1);
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return 'image';
            } else if (in_array($ext, ['mp4', 'webm', 'ogg'])) {
                return 'video';
            }
        }
        return 'text';
    }


    public function getOperation()
    {
        return self::OPERATION[$this->imageType()];
    }
}
