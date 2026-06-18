import './bootstrap.dev.js';
import { bindAjaxForms } from './forms.js';
import { getBoot, hasBoot } from './boot.js';
import './assets/app.css';

const boot = getBoot();

if (boot.locale) {
    document.documentElement.lang = boot.locale;
}

if (boot.direction) {
    document.documentElement.dir = boot.direction;
}

bindAjaxForms();

const root = document.getElementById('app');

if (root && !hasBoot()) {
    root.innerHTML = '<p><strong>Dev:</strong> run via PHP/Twig so pinoox_bootstrap() sets __PINOOX__.</p>';
}
