<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index()
    {
        $posts = Post::published()->paginate(max(1, (int) site('blog.per_page', 6)));

        return view('site.blog.index', compact('posts'));
    }

    public function show(Post $post)
    {
        abort_unless($post->is_published && (! $post->published_at || $post->published_at->isPast()), 404);

        $comments = $post->approvedComments()->get();

        return view('site.blog.show', compact('post', 'comments'));
    }

    public function comment(Request $request, Post $post)
    {
        abort_unless($post->is_published && $post->allow_comments, 404);

        // Honeypot: bots fill every field.
        if (filled($request->input('website'))) {
            return back()->with('comment_status', site('blog.detail.success'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $post->comments()->create($data);

        return redirect(route('blog.show', $post) . '#comment-form')->with('comment_status', site('blog.detail.success'));
    }
}
