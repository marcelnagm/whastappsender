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
        $client_phone .= '@s.whatsapp.net';

        if (isset($this->image) && !URL::isValidUrl($this->image)) {
            $data = array(
                "type" => "number",
                "options" => array(
                    "externalAttributes" => "<any> - optional",
                    "delay" => 1200,
                    "presence" => "composing"
                ),
                'number' => $client_phone, // NUMERO A SER ENVIADO EM FORMATO WHATSAPP            
                'mediaMessage' => [
                    "mediatype" => "image",
                    'media'  => $image,
                    'caption' => $this->text
                ] // MENSAGEM PARA SER ENVIADA   
            );
        } else
            $data = array(
                "type" => "number",
                'number' => $client_phone, // NUMERO A SER ENVIADO EM FORMATO WHATSAPP            
                'textMessage' => ['text' => $this->text] // MENSAGEM PARA SER ENVIADA   
            );

        //dd($data);
        return $data;
    }
}
