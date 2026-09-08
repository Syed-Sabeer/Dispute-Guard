<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSendingDomain extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['provider_domain_id'];

    protected $casts = ['provider_domain_id' => 'integer'];
}
