<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function profile()
    {
        if (! Auth::check()) {
            Session::flash('error_flash_message', trans('words.access_denied'));
            return redirect('login');
        }

        // Staff land in the admin panel; readers see the user profile.
        if (Auth::user()->isAuthor()) {
            return redirect('/admin');
        }

        $user = User::findOrFail(Auth::user()->id);
        return view('pages.user.profile', compact('user'));
    }

    public function editprofile(Request $request): RedirectResponse
    {
        $id = Auth::user()->id;
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email,'.$id,
            'phone' => 'nullable|string|max:40',
            'password' => 'nullable|string|min:8',
            'user_image' => 'nullable|image|mimes:jpg,jpeg,gif,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator->messages());
        }

        $disk = getcong('site_storage') ?: 'public';

        if ($request->hasFile('user_image')) {
            $upload = $request->file('user_image');
            // Laravel's `mimes:` rule only consults the request-supplied
            // Content-Type, which a client can spoof. Verify the actual
            // bytes with getimagesize() so a renamed PHP / SVG / HTML
            // file can't slip into the public uploads dir.
            $info = @getimagesize($upload->getPathname());
            $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
            if (! $info || ! in_array($info[2] ?? null, $allowed, true)) {
                return redirect()->back()->withErrors([
                    'user_image' => 'Profile picture must be a real JPEG, PNG, GIF, or WEBP image.',
                ]);
            }
            $user->image = $upload->store('user_photos', $disk);
        }

        $user->name = (string) $request->input('name');
        $user->email = (string) $request->input('email');
        $user->phone = (string) $request->input('phone');

        if ($request->filled('password')) {
            $user->password = bcrypt($request->input('password'));
        }

        $user->save();

        Session::flash('flash_message', trans('words.successfully_updated'));
        return redirect()->back();
    }

    public function user_favorite()
    {
        if (! Auth::check()) {
            Session::flash('error_flash_message', trans('words.access_denied'));
            return redirect('login');
        }

        if (Auth::user()->isAuthor()) {
            return redirect('/admin');
        }

        $favorites_list = Favorite::with(['posts.category', 'posts.user'])
            ->where('user_id', Auth::user()->id)
            ->orderByDesc('id')
            ->paginate(10);

        return view('pages.user.favorites_list', compact('favorites_list'));
    }
}
