<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    public const DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact your operator.';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->id === null) {
                $user->id = DB::transaction(function () {
                    $counter = DB::table('account_sequences')->where('name', 'users')->lockForUpdate()->first();
                    $id = max((int) $counter->next_id, (int) DB::table('users')->max('id') + 1);
                    DB::table('account_sequences')->where('name', 'users')->update(['next_id' => $id + 1]);

                    return $id;
                });
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'status',
        'refundLimit',
        'schedule',
        'createdBy',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'approval_pin',
        'normalized_email',
    ];

    public function getAuthIdentifierName(): string
    {
        return 'email';
    }

    public function normalizedRole(): string
    {
        $role = Str::of((string) $this->role)->lower()->replace('-', '_')->replace(' ', '_')->toString();

        return $role === 'superadmin' ? 'super_admin' : $role;
    }

    public function isActive(): bool
    {
        return strtolower((string) ($this->status ?? 'Active')) === 'active';
    }

    public function verifyApprovalPin(string $pin): bool
    {
        return is_string($this->approval_pin) && password_verify($pin, $this->approval_pin);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : Str::lower(trim($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'approval_pin' => 'hashed',
        ];
    }
}
