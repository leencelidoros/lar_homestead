<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UssdController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Api\V1\CustomerController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/login', [LoginController::class, 'login']);
Route::post('/register', [RegisterController::class, 'register']);

Route::post('/ussd', [App\Http\Controllers\UssdController::class, 'ussd']);
//api//v1/
// Route::group(['prefix'=>'v1','namespace'=>'App\Http\Controllers\Api\V1'],function(){
//     Route::get('customers',CustomerController::class);
//     Route::apiResource('invoices',InvoiceController::class);
// }); 
Route::group([
    'prefix' => 'v1', 'middleware'=>'auth:sanctum'
],function(){
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::controller(CustomerController::class)->group(function () {
    Route::get('customers','index');
    
    // Route::get('invoices','index');
});
});
