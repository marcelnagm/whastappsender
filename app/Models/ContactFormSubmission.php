<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactFormSubmission extends Model
{
    protected $fillable = ['contact_form_id', 'user_id', 'contact_id', 'chat_id', 'data', 'origin', 'ip', 'delivery', 'ai_requested', 'status', 'note', 'consent_text'];

    protected $casts = ['data' => 'array', 'ai_requested' => 'boolean'];

    public function form()
    {
        return $this->belongsTo(ContactForm::class, 'contact_form_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
