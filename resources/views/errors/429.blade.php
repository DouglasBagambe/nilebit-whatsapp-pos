@extends('layouts.connection')
@section('title', 'Please wait a moment')
@section('content')
<div class="section-kicker">Let’s take a moment</div><h1>A few too many attempts.</h1>
<p class="intro">Please wait a minute before trying again. This temporary limit helps protect your account.</p>
<div class="instructions"><div class="instruction"><span>1</span><div><strong>Wait one minute</strong><p>Avoid submitting the form again while you wait.</p></div></div><div class="instruction"><span>2</span><div><strong>Return to your NileBit POS chat</strong><p>Send Menu and use a fresh connection link if the old one has expired.</p></div></div></div>
@include('partials.whatsapp-return')
@endsection
