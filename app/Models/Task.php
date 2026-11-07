<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = ['user_id', 'title', 'notes', 'due_at', 'assigned_to', 'created_by', 'contact_id', 'chat_id', 'deal_id', 'done_at', 'done_by', 'reminded_at', 'recurrence', 'next_task_id'];

    protected $casts = ['due_at' => 'datetime', 'done_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return !$this->isDone() && $this->due_at && $this->due_at->isPast();
    }
}
