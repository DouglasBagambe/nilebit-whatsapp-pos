@extends('layouts.connection')
@section('title', 'Connect your POS')
@section('content')
<nav class="steps" aria-label="Connection progress">
    <span class="step {{ $candidate ? 'done' : 'active' }}" @if(! $candidate) aria-current="step" @endif><span class="step-number">{{ $candidate ? '✓' : '1' }}</span>Sign in</span><span class="step-line"></span>
    <span class="step {{ $candidate ? 'active' : '' }}" @if($candidate) aria-current="step" @endif><span class="step-number">2</span>Choose business</span><span class="step-line"></span>
    <span class="step"><span class="step-number">3</span>Start chatting</span>
</nav>
<div class="section-kicker">{{ $candidate ? 'Make it yours' : 'Your business, connected' }}</div>
<h1>{{ $candidate ? 'Choose your businesses.' : 'Connect your business.' }}</h1>
<p class="intro">{{ $candidate ? 'Pick the businesses you want to manage on WhatsApp. You can switch between them in chat.' : 'Sign in to your existing NileBit POS account to bring your business into WhatsApp.' }}</p>
<div class="recipient"><span class="recipient-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a9 9 0 0 1-13 8L3 21l1.5-5A9 9 0 1 1 21 11.5Z"/><path d="M9 8c0 4 3 7 6 7l1-2-2-1-1 1-2-2 1-1-1-2Z"/></svg></span><span><small>Connecting to your WhatsApp</small><strong>Number ending {{ $maskedPhone }}</strong></span><span class="connected-dot" aria-hidden="true"></span></div>
@if($errors->any())
<div class="notice" role="alert" tabindex="-1" id="connection-error"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg><div>
    @if(str_contains($errors->first(), 'scoped WhatsApp access'))
        <strong>Your POS connection needs an update</strong><p>We need to update this POS before you can connect it to WhatsApp. Your account details don’t need to change.</p><a href="mailto:info@nilebitlabs.com">Contact NileBit support ↗</a>
    @else
        <strong>Let’s get you connected</strong><p>{{ $errors->first() }}</p>
    @endif
</div></div>
@endif
@if($candidate)
<form method="post" action="{{ route('business-link.approve', ['token' => $token]) }}" data-connection-form data-approval>@csrf
    <fieldset class="choices"><legend>Available in {{ ucfirst($candidate['backend']) }}</legend><div class="shop-list">
    @foreach($candidate['shops'] as $shop)
        <label class="shop"><input type="checkbox" name="shops[]" value="{{ $shop['id'] }}" @checked(in_array($shop['id'], old('shops', [])))><span><strong>{{ $shop['name'] }}</strong><small>{{ ucfirst($shop['role']) }} access · {{ ucfirst($candidate['backend']) }}</small></span><span class="choice-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18l-2-6H5l-2 6Zm2 0v10h14V10M9 20v-6h6v6"/></svg></span></label>
    @endforeach
    </div></fieldset>
    <button class="primary" type="submit" data-submit data-busy-label="Connecting your businesses…">Connect selected businesses <span aria-hidden="true">→</span></button>
    <p class="form-note" data-selection-status aria-live="polite">Only the businesses you select will be linked.</p>
</form>
@elseif($backends->isEmpty())
<div class="empty-card"><h2>We’re getting things ready.</h2><p>Business connections are being configured. Contact NileBit support and we’ll help you get started.</p><a class="primary" href="mailto:info@nilebitlabs.com">Contact support ↗</a></div>
@else
<form method="post" action="{{ route('business-link.authenticate', ['token' => $token]) }}" data-connection-form>@csrf
    <fieldset class="choices"><legend>Which POS do you use?</legend><div class="choice-grid">
    @foreach($backends as $backend)
        <label class="choice"><input type="radio" name="backend" value="{{ $backend }}" @checked(old('backend', $backends->first()) === $backend) required><span class="choice-icon"><svg viewBox="0 0 24 24" aria-hidden="true">@if($backend === 'retail')<path d="M3 10h18l-2-6H5l-2 6Zm2 0v10h14V10M9 20v-6h6v6"/>@else<path d="M3 21V10l6 3V9l6 3V4h4l2 17H3Zm3-4h1m4 0h1m4 0h1"/>@endif</svg></span><span><strong>{{ ucfirst($backend) }}</strong><small>{{ $backend === 'retail' ? 'Shops & inventory' : 'Make & manage' }}</small></span><span class="radio-dot" aria-hidden="true"></span></label>
    @endforeach
    </div></fieldset>
    <div class="field"><label class="field-label" for="email">Email address</label><input class="input" id="email" name="email" type="email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="you@yourbusiness.com" value="{{ old('email') }}" required maxlength="255"></div>
    <div class="field"><label class="field-label" for="password">Password</label><div class="password-wrap"><input class="input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Your POS password" required maxlength="1000"><button class="reveal" type="button" data-reveal hidden aria-controls="password" aria-pressed="false">Show</button></div><p class="field-help">Use the same details you use on your POS website.</p></div>
    <button class="primary" type="submit" data-submit data-busy-label="Connecting to your POS…">Continue securely <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg></button>
    <p class="form-note" data-form-status role="status"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg>Your password stays out of the WhatsApp chat.</p>
</form>
@endif
<div class="notice" data-expiry-notice role="alert" hidden><div><strong>This link has expired</strong><p>Send Menu in your NileBit POS WhatsApp chat and open the newest link.</p></div></div>
<div class="access-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><strong>Your connection, your control.</strong><br><span data-expiry="{{ $expiresAt->getTimestamp() }}" data-server-time="{{ now()->getTimestamp() }}">Links last 15 minutes.</span> Your existing POS permissions apply. Only continue if you requested this in your own WhatsApp chat.</span></div>
@endsection
