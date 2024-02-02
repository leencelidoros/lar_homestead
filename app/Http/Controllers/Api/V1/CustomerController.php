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
    
      public function index (Request $request)
      {
        return Customer::filter($request)->with('invoices')->get();
      }


    /**
     * Show the form for creating a new resource.
     */

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'email' => 'required|email|unique:customers,email',
            'city' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postalcode' => 'required|string|max:20',
        ]);

        Customer::create([
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'email' => $request->input('email'),
            'city' => $request->input('city'),
            'address' => $request->input('address'),
            'state' => $request->input('state'),
            'postalcode' => $request->input('postalcode'),
        ]);
        return response()->json([
            'message'=>'customer Created Succesfully',
            'status'=>true
        ]);
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
        public function show(Request $request)
    {
        $filteredCustomers = Customer::filter($request)->with('invoices')->get();

        return $filteredCustomers;
    }

    


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request,Customer $customer)
    {
        
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'email' => 'required|email|unique:customers,email,' ,
            'city' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postalcode' => 'required|string|max:20',
        ]);

        $customer->update([
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'email' => $request->input('email'),
            'city' => $request->input('city'),
            'address' => $request->input('address'),
            'state' => $request->input('state'),
            'postalcode' => $request->input('postalcode'),
        ]);

        
         return response()->json([
            'message'=>'customer Updated Succesfully',
            'status'=>true
        ]);
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
    
    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

}
