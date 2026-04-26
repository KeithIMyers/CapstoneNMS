<?php

namespace App\Http\Controllers;

use App\Models\Pages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class PagesController extends Controller
{
    public function details(string $slug)
    {
        if ($slug === 'contact-us') {
            return view('pages.contact');
        }

        $page_info = Pages::where('page_slug', $slug)->firstOrFail();

        if ($page_info->page_slug !== $slug) {
            return redirect()->route('page_details', ['slug' => $page_info->page_slug], 301);
        }

        return view('pages.page', compact('page_info'));
    }

    public function contact(): \Illuminate\Contracts\View\View
    {
        return view('pages.contact');
    }

    public function contact_send(Request $request): RedirectResponse
    {
        $rules = [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:5000',
            'phone' => 'nullable|string|max:40',
        ];
        if (getcong('recaptcha_on_contact_us')) {
            $rules['g-recaptcha-response'] = 'required';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator->messages())->withInput();
        }

        if (getcong('recaptcha_on_contact_us') && ! verify_recaptcha($request)) {
            Session::flash('error_flash_message', 'Captcha timeout or duplicate');
            return redirect()->back();
        }

        $data = [
            'name' => (string) $request->input('name'),
            'email' => (string) $request->input('email'),
            'phone' => (string) $request->input('phone'),
            'subject' => (string) $request->input('subject'),
            'user_message' => (string) $request->input('message'),
        ];

        try {
            Mail::send('emails.contact', $data, function ($message) use ($data) {
                $message->from(getcong('smtp_email'), getcong('site_name'));
                $message->to(getcong('site_email'))->subject($data['subject']);
            });

            Session::flash('flash_message', trans('words.contact_thank_you_msg'));
        } catch (\Throwable $e) {
            Log::error('contact form mail failed', ['error' => $e->getMessage()]);
            Session::flash('error_flash_message', 'We could not deliver your message. Please try again later.');
        }

        return redirect()->back();
    }
}
