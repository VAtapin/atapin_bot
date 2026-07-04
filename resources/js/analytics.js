const config = window.publicAnalyticsConfig ?? {};
const providerState = { loaded: false };

function safeCall(callback) {
    try {
        callback();
    } catch {
        // An unavailable advertising provider must never break the website.
    }
}

function loadScript(src, attributes = {}) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.async = true;
        script.src = src;
        Object.entries(attributes).forEach(([key, value]) => script.setAttribute(key, value));
        script.addEventListener('load', resolve, { once: true });
        script.addEventListener('error', reject, { once: true });
        document.head.append(script);
    });
}

function loadProviders() {
    if (providerState.loaded || config.consent !== 'granted') return;
    providerState.loaded = true;

    if (config.providers?.ga4) {
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
        safeCall(() => {
            window.gtag('js', new Date());
            window.gtag('config', config.providers.ga4, { anonymize_ip: true });
        });
        loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(config.providers.ga4)}`).catch(() => {});
    }

    if (config.providers?.yandex) {
        window.ym = window.ym || function ym() {
            (window.ym.a = window.ym.a || []).push(arguments);
        };
        window.ym.l = Date.now();
        safeCall(() => window.ym(Number(config.providers.yandex), 'init', {
            clickmap: true,
            trackLinks: true,
            accurateTrackBounce: true,
            webvisor: false,
        }));
        loadScript('https://mc.yandex.ru/metrika/tag.js').catch(() => {});
    }

    if (config.providers?.vk) {
        window._tmr = window._tmr || [];
        window._tmr.push({ id: String(config.providers.vk), type: 'pageView', start: Date.now() });
        loadScript('https://top-fwz1.mail.ru/js/code.js').catch(() => {});
    }
}

function externalEvent(name, parameters = {}, eventId = null) {
    if (config.consent !== 'granted') return;
    loadProviders();
    const payload = { ...parameters, ...(eventId ? { event_id: eventId } : {}) };

    if (config.providers?.ga4) safeCall(() => window.gtag?.('event', name, payload));
    if (config.providers?.yandex) {
        safeCall(() => window.ym?.(Number(config.providers.yandex), 'reachGoal', name, parameters));
    }
    if (config.providers?.vk) {
        safeCall(() => window._tmr?.push({
            id: String(config.providers.vk),
            type: 'reachGoal',
            goal: name,
            value: parameters.value,
        }));
    }
}

async function internalEvent(name, parameters = {}) {
    if (!config.endpoint) return;
    const url = new URL(window.location.href);
    const context = {
        landing_page: `${url.origin}${url.pathname}`,
        referrer: document.referrer || null,
        utm_source: url.searchParams.get('utm_source'),
        utm_medium: url.searchParams.get('utm_medium'),
        utm_campaign: url.searchParams.get('utm_campaign'),
        utm_content: url.searchParams.get('utm_content'),
        utm_term: url.searchParams.get('utm_term'),
    };
    await fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': config.csrf ?? '',
        },
        body: JSON.stringify({ event: name, parameters: { ...context, ...parameters } }),
    }).catch(() => {});
}

export function trackPublicEvent(name, parameters = {}, options = {}) {
    if (!name || config.consent !== 'granted') return;
    if (options.internal !== false) internalEvent(name, parameters);
    externalEvent(name, parameters, options.eventId);
}

function storeConsent(value) {
    config.consent = value;
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `analytics_consent=${value}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
}

export function initializeAnalytics() {
    const banner = document.querySelector('[data-consent-banner]');
    const observed = document.querySelectorAll('[data-analytics-view]');
    const trackViewElement = (element) => {
        if (config.consent !== 'granted' || element.dataset.analyticsTracked === 'true') return false;
        element.dataset.analyticsTracked = 'true';
        trackPublicEvent(element.dataset.analyticsView);

        return true;
    };
    const trackVisibleSections = () => {
        observed.forEach((element) => {
            const rect = element.getBoundingClientRect();
            if (rect.top < window.innerHeight && rect.bottom > 0) trackViewElement(element);
        });
    };
    let pageEventTracked = false;
    const trackPageEvent = () => {
        const pageEvent = document.body.dataset.analyticsEvent;
        if (pageEvent && !pageEventTracked) {
            pageEventTracked = true;
            trackPublicEvent(pageEvent);
        }
    };
    const dispatchPending = async () => {
        if (config.consent !== 'granted' || !config.pendingEndpoint) return;
        const response = await fetch(config.pendingEndpoint, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).catch(() => null);
        if (!response?.ok) return;
        const payload = await response.json().catch(() => ({ events: [] }));
        (payload.events ?? []).forEach((event) => {
            externalEvent(event.name, event.parameters ?? {}, event.event_id);
        });
    };
    if (!config.consent && banner) banner.hidden = false;

    document.querySelector('[data-consent-accept]')?.addEventListener('click', () => {
        storeConsent('granted');
        banner.hidden = true;
        loadProviders();
        trackPageEvent();
        trackVisibleSections();
        dispatchPending();
    });
    document.querySelector('[data-consent-essential]')?.addEventListener('click', () => {
        const providersWereActive = config.consent === 'granted';
        storeConsent('essential');
        banner.hidden = true;
        if (providersWereActive) window.location.reload();
    });
    document.querySelector('[data-consent-settings]')?.addEventListener('click', () => {
        if (banner) {
            banner.hidden = false;
            banner.querySelector('button')?.focus();
        }
    });

    if (config.consent === 'granted') {
        loadProviders();
        trackPageEvent();
        dispatchPending();
    }

    document.querySelectorAll('[data-analytics-click]').forEach((element) => {
        element.addEventListener('click', () => trackPublicEvent(element.dataset.analyticsClick));
    });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                if (
                    entry.target.dataset.analyticsTracked === 'true'
                    || trackViewElement(entry.target)
                ) {
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.35 });
        observed.forEach((element) => observer.observe(element));
    } else if (config.consent === 'granted') {
        trackVisibleSections();
    }

}
