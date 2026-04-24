<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSession extends Model
{
    use HasFactory;

    protected $table = 'ai_sessions';

    protected $fillable = [
        'user_id',
        'instance_id',
        'contact_id',
        'status',
        'human_handoff',
        'last_inbound_at',
        'last_outbound_at',
        'metadata',
    ];

    protected $casts = [
        'human_handoff' => 'boolean',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'ai_session_id', 'id');
    }
}
