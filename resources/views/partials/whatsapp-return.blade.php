@if(preg_match('/^[1-9][0-9]{5,14}$/D', (string) config('whatsapp.chat_number')))
<a class="primary" href="https://wa.me/{{ config('whatsapp.chat_number') }}?text=Menu" rel="noreferrer">Return to WhatsApp <span aria-hidden="true">↗</span></a>
<p class="form-note">Tap send in WhatsApp to request your menu.</p>
@endif
