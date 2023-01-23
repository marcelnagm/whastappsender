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
 *
 * @property Campaign $campaign
 * @property User $user
 * @package App
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class CampaignItem extends Model
{
    protected $table= 'campaign_item';
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
    protected $fillable = ['name','text','image','user_id','campaign_id'];


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
    public function generate( $client_phone)
    {

        if(!URL::isValidUrl($this->image))
        $image =  env('APP_URL').'/'.$this->image;
        else $image =$this->image;
        $client_phone .= '@s.whatsapp.net';
        if(isset($image)){
          
            if(getimagesize($image)!= false)    
            $data = array(
                "type" => "number",
                'jid' => $client_phone, // NUMERO A SER ENVIADO EM FORMATO WHATSAPP            
                'message' => [
                    'image' => ['url' => $image, ]
                        ,'caption' =>$this->text
                ]// MENSAGEM PARA SER ENVIADA   
                    );
                    else
                    $data = array(
                        "type" => "number",
                        'jid' => $client_phone, // NUMERO A SER ENVIADO EM FORMATO WHATSAPP            
                        'message' => [
                            'video' => ['url' => $image, ]
                                ,'caption' =>$this->text
                        ]// MENSAGEM PARA SER ENVIADA   
                            );
        }else
            $data = array(
                    "type" => "number",
                    'jid' => $client_phone, // NUMERO A SER ENVIADO EM FORMATO WHATSAPP            
                    'message' => ['text' =>$this->text]// MENSAGEM PARA SER ENVIADA   
                        )
                ;

        //dd($data);
        return $data;
    }
    



}
