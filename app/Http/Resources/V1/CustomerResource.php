<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'id'=>$this->id,
            'name'=>$this->name,
            'amount'=>$this->amount,
            'type'=>$this->type,
            'email'=>$this->email,
            'city'=>$this->city,
            'state'=>$this->id,
            'address'=>$this->address,
            'postalCode'=>$this->postalcode,
            // 'invoices'=>$this->invoices
            'invoices'=>InvoiceResource::collection($this->invoices)

        ]; 
    }

}
