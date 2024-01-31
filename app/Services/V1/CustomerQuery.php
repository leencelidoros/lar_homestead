<?php
namespace App\Services\V1;

use Illuminate\Http\Request;

class CustomerQuery {

    protected $safeParams = [
        'name' => ['eq'],
        'type' => ['eq'],
        'email' => ['eq'],
        'address' => ['eq'],
        'city' => ['eq'],
        'state' => ['eq'],
        'postalCode' => ['eq', 'gt', 'lt']
    ];

    protected $columnMap = [
        'postalCode' => 'postalcode'
    ];

    protected $operatorsToMap = [
        'eq' => '=',
        'lt' => '<',
        'gt' => '>',
        'gte' => '=>'
    ];

    public function transform(Request $request)
    {
        $eleQuery = [];

        foreach ($this->safeParams as $param => $operators) {
            $query = $request->query($param);

            if (isset($query)) {
                $column = $this->columnMap[$param] ?? $param;
                foreach ($operators as $operator) {
                    if (isset($query[$operator])) {
                        $eleQuery[] = [$column, $this->operatorsToMap($operator), $query[$operator]];
                    }
                }
            }
        }

        return $eleQuery;
    }

    protected function operatorsToMap($operator)
    {
        return $this->operatorsToMap[$operator] ?? $operator;
    }
}
