<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>Verify sender mailbox</title></head>
<body>
<h1>Dispute Guard sender verification</h1>
@isset($confirmed)
<p>{{ $confirmed ? 'Mailbox ownership confirmed. Return to your store in Shopify, complete Postmark confirmation, then select Check verification.' : 'This link is invalid, expired, or already used. Request a new verification email from your Shopify store settings.' }}</p>
@else
<p>Only confirm if you manage the Shopify store named in the verification email and authorize it to send customer follow-ups from this mailbox.</p>
<form method="POST" action="/sender-verification">@csrf
<input type="hidden" name="token" id="verification-token">
<button type="submit">Confirm mailbox ownership</button>
</form>
<script>document.getElementById('verification-token').value = location.hash.slice(1); history.replaceState(null, '', location.pathname);</script>
@endisset
</body></html>
