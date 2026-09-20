document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
        const message = event.submitter?.dataset.confirm || form.dataset.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });
});
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
document.querySelector('#bank-credit')?.addEventListener('change', event => {
    const option = event.target.selectedOptions[0];
    if (option.dataset.amount) {
        document.querySelector('#payment-amount').value = option.dataset.amount;
        document.querySelector('#payment-date').value = option.dataset.date;
    }
});

const plaidPanel = document.querySelector('#plaid-connect');
if (plaidPanel) {
    const message = document.querySelector('#plaid-message');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const post = async (url, body) => {
        const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'The bank request failed. Please try again.');
        return data;
    };
    const sdk = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
        script.onload = resolve;
        script.onerror = () => reject(new Error('Plaid could not load. Please refresh and try again.'));
        document.head.appendChild(script);
    });
    // Attach an error handler immediately, including when no connect button is clicked.
    sdk.catch(error => { message.textContent = error.message; });
    const open = async (update, resumeToken = null) => {
        const buttons = plaidPanel.querySelectorAll('[data-plaid-open]');
        buttons.forEach(button => { button.disabled = true; });
        try {
            await sdk;
            message.textContent = 'Opening secure bank sign-in...';
            const token = resumeToken || (await post(plaidPanel.dataset.linkUrl, { update })).link_token;
            const handler = window.Plaid.create({
                token,
                ...(resumeToken ? { receivedRedirectUri: window.location.href } : {}),
                onSuccess: async publicToken => {
                    try {
                        message.textContent = 'Saving bank connection...';
                        const result = await post(update ? plaidPanel.dataset.syncUrl : plaidPanel.dataset.exchangeUrl, update ? {} : { public_token: publicToken });
                        window.location.assign(result.redirect);
                    } catch (error) { message.textContent = error.message; buttons.forEach(button => { button.disabled = false; }); }
                },
                onExit: () => { message.textContent = 'Bank sign-in closed. You can try again whenever you are ready.'; buttons.forEach(button => { button.disabled = false; }); handler.destroy(); },
            });
            handler.open();
        } catch (error) { message.textContent = error.message; buttons.forEach(button => { button.disabled = false; }); }
    };
    plaidPanel.querySelectorAll('[data-plaid-open]').forEach(button => button.addEventListener('click', () => open(button.dataset.update === '1')));
    if (plaidPanel.dataset.resumeToken) open(plaidPanel.dataset.resumeUpdate === '1', plaidPanel.dataset.resumeToken);
}
