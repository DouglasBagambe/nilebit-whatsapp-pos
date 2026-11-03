@extends('layouts.connection')
@section('title', 'Sign-in session expired')
@section('content')
<div class="status-icon warning"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
<div class="section-kicker">A fresh start</div><h1>Your sign-in session expired.</h1>
<p class="intro">Your connection wasn’t completed. Send Menu in your NileBit POS WhatsApp chat, open the newest link, and sign in again.</p>
@include('partials.whatsapp-return')
<p class="status-note">Keep the connection page in the same browser until you finish choosing your businesses.</p>
@endsection
