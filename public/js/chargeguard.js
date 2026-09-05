'use strict';
if (document.body.hasAttribute('data-local-demo')) {
    document.querySelectorAll('[href^="/"]').forEach(link => {
        const path = link.getAttribute('href');
        if (!path.startsWith('/demo')) link.setAttribute('href', '/demo' + path);
    });
    document.querySelectorAll('form[data-api-form] button').forEach(button => button.disabled = true);
    document.querySelectorAll('form[method="get"]').forEach(form => form.action = '/demo/disputes');
}
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.matches('[data-api-form]')) return;
    event.preventDefault();
    if (document.body.hasAttribute('data-local-demo')) return;
    if (!form.reportValidity()) return;
    const submitter = event.submitter;
    if (submitter?.dataset.confirm && !window.confirm(submitter.dataset.confirm)) return;
    const notice = document.getElementById('notice');
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach(button => button.disabled = true);
    form.setAttribute('aria-busy', 'true');
    try {
        const data = Object.fromEntries(new FormData(form));
        form.querySelectorAll('input[type=checkbox]').forEach(input => data[input.name] = input.checked);
        if (submitter?.dataset.confirm) data.confirmed = true;
        const token = await shopify.idToken();
        const response = await fetch(submitter?.dataset.action || form.action, {
            method: submitter?.dataset.method || form.dataset.method || 'POST',
            headers: {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(data),
        });
        const result = await response.json().catch(() => ({message:'The request could not be completed. Please reopen the app and try again.'}));
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message);
        notice.replaceChildren();
        if (result.html !== undefined) {
            document.getElementById('preview-subject').textContent = result.subject;
            // The server returns only tightly controlled formatting HTML with no attributes.
            document.getElementById('preview-body').innerHTML = result.html;
        } else {
            const banner = document.createElement('s-banner');
            banner.setAttribute('tone','success'); banner.textContent = result.message || 'Saved.';
            notice.append(banner);
            shopify.toast.show(result.message || 'Saved.');
        }
        if (result.reload) window.location.reload();
    } catch (error) {
        notice.replaceChildren();
        const banner = document.createElement('s-banner');
        banner.setAttribute('tone','critical'); banner.textContent = error.message; notice.append(banner);
    } finally {
        buttons.forEach(button => button.disabled = false);
        form.removeAttribute('aria-busy');
    }
});
