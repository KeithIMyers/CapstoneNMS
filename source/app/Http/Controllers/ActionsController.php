<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActionsController extends Controller
{
    public function ajax_actions(Request $request)
    {
        $inputs = $request->all();

        $post_id = $inputs['id'];
        $action_for = $inputs['action_for'];

        if ($action_for == 'news_favorite') {
            if (Auth::check()) {
                $user_id = Auth::user()->id;

                $fav_info = Favorite::where('post_id', $post_id)
                    ->where('user_id', $user_id)
                    ->first();

                if ($fav_info) {
                    $fav_info->delete();

                    $response['set_title'] = 'Set Favorite';
                    $response['msg_text'] = trans('words.fav_deleted');
                    $response['fav_del'] = 'Yes';
                } else {
                    $fav_obj = new Favorite;
                    $fav_obj->post_id = $post_id;
                    $fav_obj->user_id = $user_id;
                    $fav_obj->save();

                    $response['set_title'] = 'Favorite';
                    $response['msg_text'] = trans('words.fav_success');
                    $response['fav_del'] = 'no';
                }

                $response['status'] = 1;
            } else {
                $response['msg_text'] = trans('words.login_req');
                $response['status'] = 0;
            }
        } elseif ($action_for == 'fav_delete') {
            $user_id = Auth::user()->id;

            Favorite::where('user_id', $user_id)
                ->where('post_id', $post_id)
                ->delete();

            $response['status'] = 1;
        } else {
            $response['status'] = 0;
        }

        return response()->json($response);
    }
}
