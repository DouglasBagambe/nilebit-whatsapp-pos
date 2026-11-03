@extends('layouts.connection')
@section('title', 'Connection link unavailable')
@section('content')
<div class="section-kicker">Let’s find your way back</div><h1>This link isn’t available.</h1>
<p class="intro">It may be incomplete or no longer valid. Return to your NileBit POS WhatsApp chat, send Menu and open the newest connection link.</p>
@include('partials.whatsapp-return')
<p class="status-note">Open the full link directly from the bot’s message.</p>
@endsection
