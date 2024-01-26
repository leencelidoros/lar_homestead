<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Resources\UserResource;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::resource('posts', \App\Http\Controllers\PostController::class)->middleware('auth');
//Route::get('/posts/', [\App\Http\Controllers\PostController::class,'index'])->name('pages.posts.index');
//Route::get('/posts/{id}/edit', [\App\Http\Controllers\PostController::class, 'edit'])->name('pages.posts.edit');
//Route::delete('/posts/{id}', [\App\Http\Controllers\PostController::class, 'destroy'])->name('pages.posts.delete');
//Route::put('/posts/{id}', [\App\Http\Controllers\PostController::class, 'update'])->name('pages.posts.update');
//Route::get('/posts/create',[\App\Http\Controllers\PostController::class,'create'])->name('pages.posts.create');
//Route::post('/posts', [\App\Http\Controllers\PostController::class, 'store'])->name('pages.posts.store');

Route::get('/user/{id}', function (string $id) {
    return UserResource::collection(User::all()->keyBy('id'));

});

