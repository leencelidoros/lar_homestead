<?php
namespace App\Filters;

use Illuminate\Http\Request;

class ApiFilter {

    protected $safeParams = [
       
    ];

    protected $columnMap = [
        
    ];

    protected $operatorsToMap = [
    
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
