<form data-api-form data-method="PUT" action="/settings/email-sender">
<div class="form-grid">
<label>Sender name<input name="sender_name" required maxlength="100" value="{{ $sender?->sender_name ?? $shop->settings?->store_display_name ?? $shop->store_name }}"></label>
<label>Sender email<input name="sender_email" required type="email" maxlength="254" value="{{ $sender?->sender_email ?? $shop->settings?->support_email ?? $shop->email }}"></label>
<label>Verification method<select name="sender_mode"><option value="SIGNATURE" @selected(!$sender || $sender->sender_mode === 'SIGNATURE')>Standard — email verification, no DNS</option><option value="DOMAIN" @selected($sender?->sender_mode === 'DOMAIN')>Advanced — domain authentication</option></select></label>
</div>
<s-paragraph>Saving an email is not verification. Changing the sender email or verification method requires new verification. Queued emails never switch identities. Emails display your store display name.</s-paragraph>
<div class="actions"><button>Save sender</button><s-link href="/settings/email-sender">Verify or change sender</s-link></div>
</form>