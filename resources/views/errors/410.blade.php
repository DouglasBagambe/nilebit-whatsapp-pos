@extends('layouts.connection')
@section('title', 'Connection link expired')
@section('content')
<div class="status-icon warning"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
<div class="section-kicker">Let’s pick up from here</div><h1>You need a fresh link.</h1>
<p class="intro">This connection link has expired or was already used. Request a new one to continue securely.</p>
<div class="instructions"><div class="instruction"><span>1</span><div><strong>Send Menu in WhatsApp</strong><p>Return to your conversation with NileBit POS.</p></div></div><div class="instruction"><span>2</span><div><strong>Open the newest connection link</strong><p>Sign in within 15 minutes. Each link can be used once.</p></div></div></div>
@include('partials.whatsapp-return')
<p class="status-note">Already connected your business? Send <strong>Menu</strong> to choose it. You can close this page.</p>
@endsection
