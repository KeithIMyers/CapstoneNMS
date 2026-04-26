<?php

namespace App\Http\Controllers;

use Auth;
use App\Models\User;
use App\Models\News;
use App\Models\Category;
use App\Models\HomeSections;
use App\Models\Pages;

use Illuminate\Http\Request; 
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Session;
 
 
class IndexController extends Controller
{   
 	  
    public function index()
    {
        $slider_news = News::published()
            ->where('is_featured', 1)
            ->orderByDesc('published_at')
            ->get()
            ->chunk(5);

        $trending_news = News::published()
            ->orderByDesc('views')
            ->take(5)
            ->get();

        $recent_news = News::published()
            ->orderByDesc('published_at')
            ->take(5)
            ->get();

        $home_sections = HomeSections::where('status', 1)
            ->orderBy('section_order')
            ->get();

        // Personalized "For you" feed for signed-in readers, with a
        // popularity-weighted fallback for anonymous visitors. The
        // service caches its result per (user, limit), so pageview-
        // heavy traffic doesn't redo the affinity calculation.
        $for_you = Auth::check()
            ? app(\App\Services\Recommendations\RecommendationService::class)->forUser(Auth::user(), 6)
            : collect();

        return view('pages.index', compact(
            'slider_news', 'trending_news', 'recent_news', 'home_sections', 'for_you'
        ));
    }

    public function login()
    {
        if(Auth::check())
        {             
            return redirect('/'); 
        }

        return view('pages.user.login');
    }

    public function postLogin(Request $request)
    { 
   
        $data =  \Request::except(array('_token'));     
        $inputs = $request->all();
        
        if(getcong('recaptcha_on_login'))
        {
            $rule=array(
                'email' => 'required|email',
                'password' => 'required',
                'g-recaptcha-response' => 'required'                
                 );
        }
        else
        {
            $rule=array(
                'email' => 'required|email',
                'password' => 'required'              
                 );
        }
         
         $validator = \Validator::make($data,$rule);
 
        if ($validator->fails())
        {
                Session::flash('login_flash_error', 'required');
                return redirect()->back()->withInput()->withErrors($validator->messages());
         }

          if (getcong('recaptcha_on_login') && ! verify_recaptcha($request)) {
              Session::flash('error_flash_message', 'Captcha timeout or duplicate');
              return redirect()->back();
          }

            $credentials = $request->only('email', 'password');

            $remember_me = $request->has('remember') ? true : false;  
            
            if (Auth::attempt($credentials, $remember_me)) {

                if(Auth::user()->status=='0' AND Auth::user()->deleted_at!=NULL){
                    Auth::logout();

                    Session::flash('login_flash_error', 'required');
                    return redirect('/login')->withInput()->withErrors(trans('words.account_delete_msg'));
                }

                if(Auth::user()->status=='0'){
                    Auth::logout();
                    Session::flash('login_flash_error', 'required');
                    return redirect('/login')->withInput()->withErrors(trans('words.account_banned'));
                 }

                // News-agent personas are byline-only ghost users; they
                // back the public author profile but never authenticate.
                // Reject the login even when the password somehow
                // happens to match (it shouldn't — agents are created
                // with a random unknown bcrypt — but defense in depth).
                if (! Auth::user()->canLogIn()) {
                    Auth::logout();
                    Session::flash('login_flash_error', 'required');
                    return redirect('/login')->withInput()->withErrors(trans('words.email_password_invalid'));
                }

                // Defeat session-fixation: rotate the session id and CSRF
                // token on every successful login so any pre-set session
                // cookie can't be reused to ride a freshly-authenticated
                // session.
                $request->session()->regenerate();
                $request->session()->regenerateToken();

                // If the user has 2FA enrolled, hand off to the public
                // challenge page before letting them through. Auth::login
                // already happened above; the public 2FA middleware
                // (added to /admin via Filament's chain) gates the panel,
                // and for non-admin users the challenge step prevents
                // a stolen password alone from completing the login.
                if (Auth::user()->hasTwoFactorEnabled()) {
                    $intended = Auth::user()->isAuthor() ? '/admin' : '/';
                    $user = Auth::user();
                    Auth::logout();
                    return \App\Http\Controllers\Auth\TwoFactorChallengeController::challenge(
                        $request, $user, $intended, $remember_me
                    );
                }

                return $this->handleUserWasAuthenticated($request);
            }
 
            Session::flash('login_flash_error', 'required'); 
            return redirect('/login')->withInput()->withErrors(trans('words.email_password_invalid'));
 
        
    }
    
     /**
     * Send the response after the user was authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $throttles
     * @return \Illuminate\Http\Response
     */
    protected function handleUserWasAuthenticated(Request $request)
    {

        if (method_exists($this, 'authenticated')) {
            return $this->authenticated($request, Auth::user());
        }
 
        if(Auth::user()->usertype=='Admin' OR Auth::user()->usertype=='Sub_Admin')
        {
            return redirect('admin/dashboard'); 
        }
        else
        {
 
            return redirect('/'); 
        }
        
    }
    

    public function signup()
    {  

        if (Auth::check()) {
                        
            return redirect('/'); 
        }
        
        return view('pages.user.signup');
    }

    public function postSignup(Request $request)
    { 
         

        $data =  \Request::except(array('_token'));
        
        $inputs = $request->all();

        // Note: deliberately NOT using `unique:users` on email here.
        // A unique-validation failure would reveal whether the address
        // already has an account (account enumeration). Instead we
        // accept the request, then either create a new account or
        // silently no-op-and-notify if one already exists. Either path
        // returns the same flash message.
        if(getcong('recaptcha_on_signup'))
        {
            $rule=array(
                'name' => 'required',
                'email' => 'required|email|max:200',
                'password' => 'required|confirmed|min:8',
                'password_confirmation' => 'required',
                'g-recaptcha-response' => 'required'
                 );

        }
        else
        {
            $rule=array(
                'name' => 'required',
                'email' => 'required|email|max:200',
                'password' => 'required|confirmed|min:8',
                'password_confirmation' => 'required'
                 );
        }


         $validator = \Validator::make($data,$rule);

        if ($validator->fails())
        {
                Session::flash('signup_flash_error', 'required');
                return redirect()->back()->withInput()->withErrors($validator->messages());
        }

        if (getcong('recaptcha_on_signup') && ! verify_recaptcha($request)) {
            Session::flash('error_flash_message', 'Captcha timeout or duplicate');
            return redirect()->back();
        }

        // If an account already exists for this email, do NOT create a
        // duplicate row and do NOT reveal that fact in the response —
        // notify the existing owner out of band that someone tried to
        // sign up with their address. Then fall through to the same
        // success flash a brand-new signup would see.
        $existing = User::where('email', $inputs['email'])->first();
        if ($existing) {
            try {
                \Mail::send('emails.signup_attempt_existing', [
                    'name' => $existing->name,
                ], function ($message) use ($existing) {
                    $message->to($existing->email, $existing->name)
                        ->from(getcong('site_email'), getcong('site_name'))
                        ->subject('Someone tried to sign up with your email');
                });
            } catch (\Throwable $e) {
                \Log::info('signup_attempt_existing notice failed: '.$e->getMessage());
            }
            Session::flash('signup_flash_message', trans('words.account_created_successfully'));
            return redirect('signup');
        }

        $user = new User;

        $user->role = 'user';
        $user->name = $inputs['name'];
        $user->email = $inputs['email'];
        $user->password= bcrypt($inputs['password']);
        $user->save();

        // Kick off any drip campaigns that listen for the
        // user_signed_up trigger event. No-op when no sequences
        // listen for it; idempotent on re-entry via the unique
        // (sequence_id, email) index.
        try {
            app(\App\Services\Newsletter\SequenceDispatcher::class)
                ->start(\App\Models\EmailSequence::TRIGGER_USER_SIGNED_UP, $user->email, $user->id);
        } catch (\Throwable $e) {
            \Log::warning('Sequence start (signup) failed: '.$e->getMessage());
        }

        // Referral capture: if the visitor arrived via ?ref=CODE
        // (cookied by CaptureReferralCode middleware), tie the new
        // user to their referrer. Idempotent + first-write-wins so
        // a refresh on the signup page after the cookie expired
        // doesn't strand the relationship.
        try {
            app(\App\Services\Referrals\ReferralService::class)->capture($request, $user);
        } catch (\Throwable $e) {
            \Log::warning('Referral capture failed: '.$e->getMessage());
        }

        //Welcome Email

        try{
            $user_name=$inputs['name'];
            $user_email=$inputs['email'];

            $data_email = array(
                'name' => $user_name,
                'email' => $user_email
                );    

            \Mail::send('emails.welcome', $data_email, function($message) use ($user_name,$user_email){
                $message->to($user_email, $user_name)
                ->from(getcong('site_email'), getcong('site_name'))
                ->subject('Welcome to '.getcong('site_name'));
            });    
        }catch (\Throwable $e) {
                 
            \Log::info($e->getMessage());    
        }        

        
        Session::flash('signup_flash_message', trans('words.account_created_successfully'));

        return redirect('signup');
         
    }

    
    /**
     * Log the user out of the application.
     *
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request)
    {
        $user = Auth::user();

        // Revoke every Sanctum personal-access token the user may have
        // minted. Without this, anyone holding a token (e.g. a leaked
        // one) keeps API access even after the browser session is gone.
        if ($user && method_exists($user, 'tokens')) {
            try { $user->tokens()->delete(); } catch (\Throwable $e) {}
        }

        Auth::logout();

        // Hard-invalidate the session row so a stolen cookie can't ride
        // a still-valid record, and rotate the CSRF token.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }


    public function sitemap()
    {    
        return response()->view('pages.sitemap')->header('Content-Type', 'application/xml'); 
    }

    public function sitemap_static()
    {
        $cat_list = Category::where('status', 1)->orderBy('cat_order')->get();
        $pages_list = Pages::where('status', 1)->orderBy('page_order')->get();

        return response()->view('pages.sitemap_static', compact('cat_list', 'pages_list'))
            ->header('Content-Type', 'application/xml');
    }

    public function sitemap_news()
    {
        // Google News sitemap rules: only articles from the last 48 hours,
        // not more than 1,000 entries.
        $news_list = News::published()
            ->where('published_at', '>=', now()->subHours(48))
            ->orderByDesc('published_at')
            ->take(1000)
            ->get();

        return response()->view('pages.sitemap_news', compact('news_list'))
            ->header('Content-Type', 'application/xml');
    }

}
