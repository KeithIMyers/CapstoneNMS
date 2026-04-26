<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReactionController extends Controller
{
    public function store(Request $request, News $article): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:'.implode(',', Reaction::getTypes()),
        ]);

        if (! Auth::check()) {
            return response()->json([
                'status' => 0,
                'msg_text' => trans('words.login_req'),
            ], 401);
        }

        $userId = auth()->id();

        $existing = Reaction::where('user_id', $userId)
            ->where('news_id', $article->id)
            ->first();

        if ($existing) {
            if ($existing->type === $request->type) {
                $existing->delete();
                $message = 'Reaction removed';
            } else {
                $existing->update(['type' => $request->type]);
                $message = 'Reaction updated';
            }
        } else {
            Reaction::create([
                'user_id' => $userId,
                'news_id' => $article->id,
                'type' => $request->type,
            ]);
            $message = 'Reaction added';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'reactions' => $article->getReactionCounts(),
            'userReaction' => $article->getUserReaction($userId)?->type,
        ]);
    }

    public function show(News $article): JsonResponse
    {
        return response()->json([
            'reactions' => $article->getReactionCounts(),
            'userReaction' => $article->getUserReaction()?->type,
            'types' => Reaction::getTypes(),
        ]);
    }
}
