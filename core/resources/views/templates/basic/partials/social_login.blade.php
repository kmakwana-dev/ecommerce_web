@php
    $text = Route::is('user.register') ? 'Register with Your ' : 'Login';
@endphp


@if (@gs('socialite_credentials')->linkedin->status || @gs('socialite_credentials')->facebook->status == Status::ENABLE || @gs('socialite_credentials')->google->status == Status::ENABLE)
    <div class="auth-divide col-12">
        <span class="auth-divide-text">@lang('Or')</span>
    </div>
@endif

<style>
    .social-auth-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .social-auth-item { width: 100%; }

    /* ===== Official Google Button ===== */
    .google-signin-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0;
        width: 100%;
        height: 44px;
        background: #fff;
        border: 1px solid #dadce0;
        border-radius: 4px;
        box-shadow: 0 1px 2px 0 rgba(60,64,67,.30), 0 1px 3px 1px rgba(60,64,67,.15);
        cursor: pointer;
        text-decoration: none;
        transition: background 0.18s ease, box-shadow 0.18s ease;
        font-family: 'Roboto', arial, sans-serif;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.25px;
        color: #3c4043;
        overflow: hidden;
    }
    .google-signin-btn:hover {
        background: #f8faff;
        box-shadow: 0 2px 6px 0 rgba(60,64,67,.35);
        color: #3c4043;
        text-decoration: none;
    }
    .google-signin-btn:active { background: #f0f4ff; }
    .google-signin-btn .gsi-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        height: 44px;
        padding: 0 10px;
    }
    .google-signin-btn .gsi-icon svg { width: 20px; height: 20px; }
    .google-signin-btn .gsi-text {
        flex: 1;
        text-align: center;
        padding-right: 44px;
        white-space: nowrap;
    }

    /* ===== Facebook Button ===== */
    .facebook-signin-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        height: 44px;
        background: #1877f2;
        border: none;
        border-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,.25);
        cursor: pointer;
        text-decoration: none;
        transition: background 0.18s ease, box-shadow 0.18s ease;
        font-family: 'Roboto', arial, sans-serif;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.25px;
        color: #fff;
    }
    .facebook-signin-btn:hover {
        background: #166fe5;
        color: #fff;
        text-decoration: none;
        box-shadow: 0 2px 6px rgba(24,119,242,.4);
    }

    /* ===== LinkedIn Button ===== */
    .linkedin-signin-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        height: 44px;
        background: #0a66c2;
        border: none;
        border-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,.25);
        cursor: pointer;
        text-decoration: none;
        transition: background 0.18s ease;
        font-family: 'Roboto', arial, sans-serif;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.25px;
        color: #fff;
    }
    .linkedin-signin-btn:hover {
        background: #004182;
        color: #fff;
        text-decoration: none;
    }
</style>

<ul class="social-auth-list col-12">
    @if (@gs('socialite_credentials')->google->status == Status::ENABLE)
        <li class="social-auth-item">
            <a href="{{ route('user.social.login', 'google') }}" class="google-signin-btn">
                <span class="gsi-icon">
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                        <path fill="none" d="M0 0h48v48H0z"/>
                    </svg>
                </span>
                <span class="gsi-text">
                    @lang(Route::is('user.register') ? 'Sign up with Google' : 'Sign in with Google')
                </span>
            </a>
        </li>
    @endif

    @if (@gs('socialite_credentials')->facebook->status == Status::ENABLE)
        <li class="social-auth-item">
            <a href="{{ route('user.social.login', 'facebook') }}" class="facebook-signin-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#fff">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
                <span>@lang(Route::is('user.register') ? 'Sign up with Facebook' : 'Sign in with Facebook')</span>
            </a>
        </li>
    @endif

    @if (@gs('socialite_credentials')->linkedin->status == Status::ENABLE)
        <li class="social-auth-item">
            <a href="{{ route('user.social.login', 'linkedin') }}" class="linkedin-signin-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="#fff">
                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                </svg>
                <span>@lang(Route::is('user.register') ? 'Sign up with LinkedIn' : 'Sign in with LinkedIn')</span>
            </a>
        </li>
    @endif
</ul>
