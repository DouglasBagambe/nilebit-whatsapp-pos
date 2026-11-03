<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Connect your POS · NileBit</title>
<style>body{margin:0;background:#f2f6f2;color:#163b2e;font:16px/1.6 system-ui}main{max-width:440px;margin:7vh auto;padding:30px;background:white;border:1px solid #dae4da;border-radius:20px}h1{font-size:30px;line-height:1.2}label{display:block;margin-top:18px;font-weight:600}input,select,button{box-sizing:border-box;width:100%;padding:13px;border-radius:8px;border:1px solid #bccfc0;font:inherit}button{margin-top:22px;background:#08694d;color:white;border:0;font-weight:700;cursor:pointer}small,p{color:#506d5d}.error{background:#fff0ef;padding:13px;color:#8b2c29;border-radius:8px}.shop{display:flex;gap:12px;align-items:center;font-weight:400;padding:12px;background:#f4f8f3;border-radius:10px}.shop input{width:20px}.shop strong{display:block}a{color:#08694d}@media(max-width:540px){main{margin:20px;padding:24px}}</style>
<main><small>NILEBIT POS · WHATSAPP</small><h1>Connect your business.</h1>
<p>Connect to WhatsApp ending {{ $maskedPhone }}. Only continue if you requested this link in your own chat with NileBit POS.</p>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
@if($candidate)
<p>Choose the businesses this WhatsApp connection may use. You can switch between them in chat.</p>
<form method="post" action="{{ route('business-link.approve', ['token' => $token]) }}">@csrf
@foreach($candidate['shops'] as $shop)<label class="shop"><input type="checkbox" name="shops[]" value="{{ $shop['id'] }}"><span><strong>{{ $shop['name'] }}</strong>{{ ucfirst($shop['role']) }} · {{ ucfirst($candidate['backend']) }}</span></label>@endforeach
<button>Connect selected businesses</button></form>
@elseif($backends->isEmpty())
<p>The POS connections are still being configured. Please contact NileBit Labs.</p>
@else
<p>Sign in securely to your existing POS account. Your password stays out of the WhatsApp conversation.</p>
<form method="post" action="{{ route('business-link.authenticate', ['token' => $token]) }}">@csrf
<label for="backend">POS system</label><select id="backend" name="backend">@foreach($backends as $backend)<option value="{{ $backend }}">{{ ucfirst($backend) }}</option>@endforeach</select>
<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required maxlength="255">
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button>Continue securely</button></form>
@endif
<p><small>Links expire after 15 minutes. Your existing POS permissions still apply.</small></p>
<p><small>Read the <a href="https://nilebitlabs.com/whatsapp-pos-privacy" target="_blank" rel="noopener noreferrer">WhatsApp POS Privacy Policy</a> before connecting. For help, email <a href="mailto:info@nilebitlabs.com">info@nilebitlabs.com</a>.</small></p></main></html>
