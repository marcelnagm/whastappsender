<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password'
    ];

    protected $appends = ['phone'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'active'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Accessor Transiente: Mascara o campo 'phone'
     * Se o código antigo chamar $user->phone, ele retornará o número da instância ativa.
     */
    public function getInstanceActive()
    {
        // Busca a primeira instância com status 'connected'
        $activeInstance = $this->instances()
            ->where('status', 'connected')
            ->inRandomOrder()
            ->first();

        // Se houver uma instância conectada, retorna o identificador (ou apikey/token se preferir)
        // Se não houver, retorna null ou uma string vazia para não quebrar o layout
        return $activeInstance ? $activeInstance : null;
    }

    /**
     * Define que o campo 'phone' deve ser incluído na conversão para Array ou JSON
     */


    /**
     * Always encrypt password when it is updated.
     *
     * @param $value
     * @return string
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
        return $this;
    }


    /**
     * Check if user is active
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->active;
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function instances()
    {
        return $this->hasMany('App\Models\Instance', 'user_id', 'id');
    }
}
