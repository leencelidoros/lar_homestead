<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return[
            'id'=>$this->id,
            'customer_id'=>$this->customer_id,
            'status'=>$this->status,
            'amount'=>$this->amount,
            'billed_date'=>$this->billed_date,
            'paid_date'=>$this->paid_date,
        ];
    }
}
