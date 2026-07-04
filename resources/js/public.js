import '@fontsource-variable/nunito-sans';
import '@fontsource-variable/cormorant-garamond';
import '../css/public.css';
import { initializeAnalytics } from './analytics.js';

const menuButton = document.querySelector('[data-menu-toggle]');
const menu = document.querySelector('[data-site-nav]');

function setMenuOpen(open) {
    if (!menuButton || !menu) return;

    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', open ? menuButton.dataset.closeLabel : menuButton.dataset.openLabel);
    menu.classList.toggle('is-open', open);
    document.body.classList.toggle('is-menu-open', open);
}

menuButton?.addEventListener('click', () => {
    setMenuOpen(menuButton.getAttribute('aria-expanded') !== 'true');
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setMenuOpen(false);
});

window.addEventListener('resize', () => {
    if (window.innerWidth > 940) setMenuOpen(false);
});

document.querySelector('[data-locale-select]')?.addEventListener('change', (event) => {
    const url = new URL(window.location.href);
    const parts = url.pathname.split('/').filter(Boolean);
    if (['ru', 'de', 'en', 'uk'].includes(parts[0])) {
        parts[0] = event.target.value;
    } else {
        parts.unshift(event.target.value);
    }
    url.pathname = `/${parts.join('/')}`;
    url.searchParams.delete('lang');
    window.location.assign(url);
});

document.querySelectorAll('[data-slug]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.querySelector('[name="tree_slug"]');
        if (input) {
            input.value = button.dataset.slug;
            input.focus();
        }
    });
});

document.querySelector('[data-history-back]')?.addEventListener('click', () => window.history.back());
document.querySelector('[data-reload]')?.addEventListener('click', () => window.location.reload());

document.querySelectorAll('.cms-content img').forEach((image) => {
    if (!image.hasAttribute('loading')) image.loading = 'lazy';
    if (!image.hasAttribute('decoding')) image.decoding = 'async';
});

const faqInput = document.querySelector('[data-faq-search]');
if (faqInput) {
    const sections = [...document.querySelectorAll('[data-faq-section]')];
    const empty = document.querySelector('[data-faq-empty]');
    const locale = document.documentElement.lang || 'ru';
    const normalize = (value) => String(value)
        .toLocaleLowerCase(locale)
        .replaceAll('ё', 'е')
        .trim();

    faqInput.addEventListener('input', () => {
        const query = normalize(faqInput.value);
        let total = 0;

        sections.forEach((section) => {
            let visible = 0;
            section.querySelectorAll('[data-faq-item]').forEach((item) => {
                const matches = !query || normalize(item.dataset.search).includes(query);
                item.hidden = !matches;
                if (matches) visible += 1;
            });
            section.hidden = visible === 0;
            total += visible;
        });

        if (empty) empty.hidden = total !== 0;
    });
}

initializeAnalytics();
