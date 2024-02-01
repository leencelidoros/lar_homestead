<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Http\Resources\V1\CustomerCollection;
use App\Filters\V1\CustomerFilter;
use Illuminate\Http\Request;


class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    //     public function index(Request $request)
    // {
    //     $filter = new CustomerFilter();
    //     $filterItems = $filter->transform($request);

    //     $customersQuery = Customer::query();
    //     $includeInvoices = boolval($request->query('includeInvoices')); 

    //     if (count($filterItems) > 0) {
    //         $customersQuery->where($filterItems);
    //     }

    //     if ($includeInvoices) {
    //         $customersQuery->with(['invoices' => function ($query) {
    //             $query->latest();
    //         }]);
    //     }

    //     $customers = $customersQuery->paginate();

    //     $appendedData = array_merge($request->query(), $filterItems);
    //     $customers->appends($appendedData);

    //     return new CustomerCollection($customers);
    // }
      public function index (Request $request)
      {
        return Customer::filter($request)->with('invoices')->get();
      }


    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)

    {
    //   Customer::create([
    //     'name'=>$request['name'],

    //   ])   
     }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        $includeInvoices = request()->query('includeInvoices');

        if ($includeInvoices) {
            return new CustomerResource($customer->load('invoices'));
        }

        return new CustomerResource($customer);
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        //
    }
}
