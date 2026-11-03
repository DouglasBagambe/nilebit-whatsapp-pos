@extends('layouts.connection')
@section('title', 'Business connected')
@section('content')
<div class="steps" aria-label="Connection complete"><span class="step done"><span class="step-number">✓</span>Signed in</span><span class="step-line"></span><span class="step done"><span class="step-number">✓</span>Business linked</span><span class="step-line"></span><span class="step active"><span class="step-number">3</span>Start chatting</span></div>
<div class="status-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></div>
<div class="section-kicker">You’re all set</div><h1>Business connected.</h1>
<p class="intro">Your workspace is ready for WhatsApp. Your business data stays in sync with your POS website.</p>
<div class="instructions"><div class="instruction"><span>1</span><div><strong>Return to your NileBit POS chat</strong><p>Go back to the WhatsApp conversation that sent you here.</p></div></div><div class="instruction"><span>2</span><div><strong>Send Menu</strong><p>Choose your business, then select what you want to do.</p></div></div></div>
@include('partials.whatsapp-return')
<p class="status-note">You can close this page. If you connected more than one business, you can switch between them in chat.</p>
@endsection
