<?php

namespace App\Http\Controllers;

use App\Models\CommentLike;
use App\Models\Comments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toggle 👍 on a comment. Idempotent — a second click un-likes.
 * Returns the new like count + whether the current user is liking
 * after the toggle, so the front-end can update both at once.
 */
class CommentLikeController extends Controller
{
    public function toggle(Request $request, Comments $comment): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'auth_required'], 401);
        }

        $existing = CommentLike::where('comment_id', $comment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $isLiking = false;
        } else {
            CommentLike::create(['comment_id' => $comment->id, 'user_id' => $user->id]);
            $isLiking = true;
        }

        return response()->json([
            'ok'       => true,
            'liking'   => $isLiking,
            'count'    => CommentLike::where('comment_id', $comment->id)->count(),
        ]);
    }
}
