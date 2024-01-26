<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function create()
    {
        return view('pages.posts.create');
    }
    public function store(StorePostRequest $request)
    {
        $validatedData = $request->validated();
        $post = Post::create($validatedData);
        return redirect()->route('posts.index')->with('success', 'Post created successfully');
    }

    public function index()
    {
        $posts = Post::all();
        return view('pages.posts.index', compact('posts'));
    }

    public function edit($id)
    {
        $post = Post::findOrFail($id);
        return view('pages.posts.edit', compact('post'));
    }

    public function update(StorePostRequest $request,$id)
    {
        $validatedData = $request->validated();
        $post = Post::findOrFail($id);
        $post->update($validatedData=$request->validated());
        return redirect()->route('posts.index')->with('success', 'Post updated successfully.');
    }
    public function show(Post $post)
    {
        return view('pages.posts.show', compact('post'));
    }

    public function destroy($id)
    {
        $post = Post::findOrFail($id);
        $post->delete();
        return redirect()->route('posts.index')->with('success', 'Post deleted successfully.');
    }


}
