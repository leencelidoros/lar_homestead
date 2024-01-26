@extends('layouts.app')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <div class="card m-3">
                <div class="card-header mt-3 text-center">Edit Post</div>
                <form method="POST" action="{{ route('posts.update', $post->id) }}">
                    @csrf
                    @method('PUT')
                    <div class="form-group m-3">
                        <label for="title">Title:</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ $post->title }}"/>
                    </div>
                    <div class="form-group m-3">
                        <label for="texbody">Body:</label>
                        <textarea class="form-control" id="texbody" name="body" rows="3">{{ $post->body }}</textarea>
                    </div>
                    <div class="col text-center">
                        <button class="btn btn-success text-center" type="submit">Update Post</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
