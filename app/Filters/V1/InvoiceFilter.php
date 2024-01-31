<?php
namespace App\Filters\V1;
use App\Filters\ApiFilter;
use Illuminate\Http\Request;

class InvoiceFilter extends ApiFilter{

    protected $safeParams = [
        'customer_id' => ['eq'],
        'status' => ['eq'],
        'billed_date' => ['eq', 'gt', 'lt','gte','lte'],
        'paid_date' => ['eq', 'gt', 'lt','gte','lte'],
        'amount' => ['eq', 'gt', 'lt','gte','lte']
    ];

    protected $columnMap = [
        'amount' => 'amount'
    ];

    protected $operatorsToMap = [
        'eq' => '=',
        'lt' => '<',
        'gt' => '>',
        'gte' => '=>',
        'lte'=>'<=',
        'ne'=>'!='
    ];


}
