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
