import { fetchApp } from './api.js';

function setStatus(form, message, isError = false) {
    const node = form.querySelector('[data-ajax-status]');

    if (!node) {
        return;
    }

    node.textContent = message;
    node.hidden = message === '';
    node.dataset.state = isError ? 'error' : 'ok';
}

/**
 * Wire forms marked data-ajax — POST/PUT to __PINOOX__.url.APP + action via fetch.
 */
export function bindAjaxForms(root = document) {
    root.querySelectorAll('form[data-ajax]').forEach((form) => {
        if (form.dataset.ajaxBound === '1') {
            return;
        }

        form.dataset.ajaxBound = '1';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const action = form.getAttribute('action') || '';
            const method = (form.getAttribute('method') || 'post').toUpperCase();
            const submit = form.querySelector('[type="submit"]');

            if (submit) {
                submit.disabled = true;
            }

            setStatus(form, '');

            try {
                const body = method === 'GET'
                    ? undefined
                    : new FormData(form);

                const data = await fetchApp(action, { method, body });
                const message = typeof data === 'object' && data?.message
                    ? String(data.message)
                    : 'Saved.';

                setStatus(form, message, false);
                form.dispatchEvent(new CustomEvent('ajax:success', { detail: data }));
            } catch (error) {
                setStatus(form, error?.message ?? 'Request failed.', true);
                form.dispatchEvent(new CustomEvent('ajax:error', { detail: error }));
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });
    });
}
