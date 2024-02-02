<?php

namespace App\Models;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [
        'id'
    ];
    use HasFactory,HasUuid;

    public function invoices()
    {
       return $this->hasMany(Invoice::class);
    }
    public function scopeFilter($query)
    {
        if (!is_null(request('type')) && !empty(request('type')))
         {
   
            $query->where('type',request('type'));
         }
        if (!is_null(request('state')) && !empty(request('state')))
        {
    
            $query->where('state',request('state'));
        }

    }
    public function getRouteKeyName()
    {
        return 'uuid';
    }
}

