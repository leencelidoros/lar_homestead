<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Invoice extends Model
{
    use HasFactory,HasUuid;

    public function Customer()
    {
       return $this->belongsTo(Customer::class);
    }
}
