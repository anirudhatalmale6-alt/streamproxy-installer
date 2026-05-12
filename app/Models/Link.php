<?php

namespace App\Models;

use App\Services\LinkCrypt;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    protected $fillable = ['name', 'original_url', 'type'];

    protected $appends = ['proxied_url'];

    public function getProxiedUrlAttribute(): string
    {
        return url('/view/' . app(LinkCrypt::class)->encrypt($this->original_url));
    }
}
