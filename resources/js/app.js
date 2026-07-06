import { calculateFamilyTreePositions } from './family-tree-layout.js';
import '../css/app.css';

const i18n = window.familyAppI18n ?? {};
const t = (key, replacements = {}) => {
    const value = key.split('.').reduce((result, part) => result?.[part], i18n) ?? key;

    return Object.entries(replacements).reduce(
        (text, [name, replacement]) => String(text).replaceAll(`:${name}`, String(replacement)),
        String(value),
    );
};
const dateLocale = ({ ru: 'ru-RU', de: 'de-DE', en: 'en-US', uk: 'uk-UA' })[
    window.familyAppConfig?.locale
] ?? 'ru-RU';

const mourningRibbonImage = `data:image/svg+xml;utf8,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M10 43L43 10" stroke="#171717" stroke-width="11" stroke-linecap="square"/></svg>',
)}`;
const telegram = window.Telegram?.WebApp;
const telegramLaunchParams = new URLSearchParams(window.location.hash.replace(/^#/, ''));
const telegramInitData = telegram?.initData
    ?? telegramLaunchParams.get('tgWebAppData')
    ?? new URLSearchParams(window.location.search).get('tgWebAppData')
    ?? '';
let cytoscapeLoader;
const loadCytoscape = () => {
    cytoscapeLoader ??= import('cytoscape').then((module) => module.default);

    return cytoscapeLoader;
};
const initialParams = new URLSearchParams(window.location.search);
const vkLaunchParams = initialParams.has('vk_user_id') && initialParams.has('sign')
    ? initialParams.toString()
    : '';
const startAction = parseMiniAppStartParameter(
    telegram?.initDataUnsafe?.start_param
    ?? new URLSearchParams(telegramInitData).get('start_param')
    ?? initialParams.get('tgWebAppStartParam'),
);
const requestedTab = startAction?.tab ?? initialParams.get('tab');
const state = {
    cytoscape: null,
    people: new Map(),
    activeTab: ['tree', 'list', 'gallery', 'birthdays', 'events', 'me'].includes(requestedTab)
        ? requestedTab
        : 'tree',
    scope: startAction?.scope ?? 'branch',
    lastTreeData: null,
    galleryPhotos: new Map(),
    galleryCursor: null,
    galleryLoading: false,
    photoViewerPhotos: [],
    photoViewerIndex: 0,
    photoViewerTouchStartX: null,
    photoViewerTouchStartY: null,
    focusId: startAction?.focus
        ?? window.familyAppConfig?.focusId
        ?? initialParams.get('focus'),
    pendingOpenPersonId: startAction?.openPerson
        ?? window.familyAppConfig?.openPersonId
        ?? null,
    treeId: startAction?.treeId ?? window.familyAppConfig?.treeId ?? null,
    treeSlug: startAction?.treeId ? null : (window.familyAppConfig?.treeSlug ?? null),
};

const $ = (selector) => document.querySelector(selector);
const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

$('#miniapp-language')?.addEventListener('change', (event) => {
    const url = new URL(window.location.href);
    url.searchParams.set('lang', event.currentTarget.value);
    window.location.assign(url.toString());
});

function parseMiniAppStartParameter(value) {
    if (!value) return null;

    let treeId = null;
    let normalized = String(value);
    const treeMatch = normalized.match(/^tree_(\d+)_(.+)$/);
    if (treeMatch) {
        treeId = treeMatch[1];
        normalized = treeMatch[2];
    }

    let match = normalized.match(/^person_(\d+)$/);
    if (match) {
        return {
            tab: 'tree',
            focus: match[1],
            openPerson: match[1],
            scope: 'branch',
            treeId,
        };
    }

    match = normalized.match(/^list_(grandchildren|nephews)_(\d+)$/);
    if (match) {
        return {
            tab: 'list',
            relation: match[1],
            focus: match[2],
            scope: 'branch',
            treeId,
        };
    }

    match = normalized.match(/^tab_(tree|list|gallery|birthdays|events|me)$/);
    return match ? { tab: match[1], treeId } : null;
}

try {
    telegram?.ready();
    telegram?.expand();
    telegram?.setHeaderColor?.('secondary_bg_color');
} catch {
    // The official script exposes a limited object outside the Telegram client.
}

if (vkLaunchParams || window.familyAppConfig?.platform === 'vk') {
    import('@vkontakte/vk-bridge')
        .then(({ default: bridge }) => bridge.send('VKWebAppInit'))
        .catch(() => {});
}

async function api(path, options = {}) {
    const body = options.body;
    const headers = {
        Accept: 'application/json',
        'X-Telegram-Init-Data': telegramInitData,
        'X-VK-Launch-Params': vkLaunchParams,
        'X-Family-Tree-ID': state.treeId ?? '',
        'X-Family-Tree': state.treeSlug ?? '',
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]')?.content ?? '',
        ...(options.headers ?? {}),
    };

    if (body && !(body instanceof FormData) && typeof body !== 'string') {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = data.errors
            ? Object.values(data.errors).flat().join(' ')
            : null;
        const serverMessage = response.status >= 500
            ? t('server_error')
            : data.message;
        const error = new Error(validationMessage || serverMessage || t('load_error'));
        error.status = response.status;
        error.payload = data;
        throw error;
    }

    return data;
}

function setLoading(isLoading) {
    $('#loading').hidden = !isLoading;
}

function showError(message, payload = {}) {
    if (payload.password_login) {
        $('#auth-error').textContent = message;
        $('#telegram-login-link').hidden = !payload.login_url;
        if (payload.login_url) $('#telegram-login-link').href = payload.login_url;
        $('#auth-panel').hidden = false;
        setLoading(false);
        return;
    }

    $('#error-message').textContent = message;
    $('#error-actions').innerHTML = payload.login_url
        ? `<a class="telegram-login" href="${escapeHtml(payload.login_url)}">${escapeHtml(t('telegram_login'))}</a>`
        : '';
    $('#error').hidden = false;
    setLoading(false);
}

function treeMetrics() {
    const mobile = window.matchMedia('(max-width: 700px)').matches;
    const viewportWidth = Math.max(window.innerWidth || 1200, 320);

    return mobile
        ? { mobile, personWidth: 112, personHeight: 136, photoSize: 48, badgeSize: 26, spouseGap: 16, familyGap: 72, generationGap: 228, unionOffset: 90, maxRowWidth: Number.POSITIVE_INFINITY }
        : { mobile, personWidth: 126, personHeight: 146, photoSize: 54, badgeSize: 28, spouseGap: 22, familyGap: 104, generationGap: 248, unionOffset: 96, maxRowWidth: Number.POSITIVE_INFINITY };
}

function personElements(data, metrics) {
    state.people = new Map(data.people.map((person) => [String(person.id), person]));
    const positions = calculateFamilyTreePositions(data, metrics);

    const nodes = data.people.map((person) => ({
        data: {
            id: String(person.id),
            label: '',
            years: person.life_years || '',
            photo: person.photo_url || '',
            gender: person.gender,
            relation: person.relation || '',
        },
        classes: [
            'person',
            person.photo_url ? 'has-photo' : '',
            person.death_date ? 'deceased' : '',
            person.relation ? `relation-${person.relation}` : '',
        ].filter(Boolean).join(' '),
        position: positions.get(String(person.id)),
        grabbable: false,
    }));
    const textNodes = data.people
        .filter((person) => positions.has(String(person.id)))
        .map((person) => {
            const position = positions.get(String(person.id));

            return {
                data: {
                    id: `text-${person.id}`,
                    personId: String(person.id),
                    label: `${person.name}${treeDateLabel(person) ? `\n${treeDateLabel(person)}` : ''}`,
                },
                classes: 'person-text',
                position: {
                    x: position.x,
                    y: position.y + (metrics.personHeight / 2) - (metrics.mobile ? 36 : 39),
                },
                grabbable: false,
                selectable: false,
            };
        });
    const photoNodes = data.people
        .filter((person) => person.photo_url && positions.has(String(person.id)))
        .map((person) => {
            const position = positions.get(String(person.id));

            return {
                data: {
                    id: `photo-${person.id}`,
                    personId: String(person.id),
                    photo: person.photo_url,
                },
                classes: [
                    'person-photo',
                    person.gender ? `photo-${person.gender}` : '',
                    person.death_date ? 'photo-deceased' : '',
                ].filter(Boolean).join(' '),
                position: {
                    x: position.x,
                    y: position.y - (metrics.personHeight / 2) + (metrics.photoSize / 2) + 10,
                },
                grabbable: false,
                selectable: false,
            };
        });
    const mourningNodes = data.people
        .filter((person) => person.death_date && positions.has(String(person.id)))
        .map((person) => {
            const position = positions.get(String(person.id));

            return {
                data: {
                    id: `mourning-${person.id}`,
                    kind: 'mourning',
                    image: mourningRibbonImage,
                },
                classes: 'mourning-ribbon',
                position: {
                    x: position.x + (metrics.personWidth / 2) - 15,
                    y: position.y + (metrics.personHeight / 2) - 15,
                },
                grabbable: false,
                selectable: false,
            };
        });
    const continuationNodes = data.people
        .filter((person) => person.hidden_relations > 0 && positions.has(String(person.id)))
        .map((person) => {
            const position = positions.get(String(person.id));

            return {
                data: {
                    id: `continuation-${person.id}`,
                    kind: 'continuation',
                    personId: String(person.id),
                    icon: '⇱',
                },
                classes: 'branch-continuation',
                position: {
                    x: position.x + (metrics.personWidth / 2) - 12,
                    y: position.y - (metrics.personHeight / 2) + 12,
                },
                grabbable: false,
                selectable: false,
            };
        });
    const birthdayNodes = data.people
        .filter((person) => person.birthday && person.birthday.days <= 30 && positions.has(String(person.id)))
        .map((person) => {
            const position = positions.get(String(person.id));

            return {
                data: {
                    id: `birthday-${person.id}`,
                    kind: 'birthday',
                    personId: String(person.id),
                    recipient: person.name,
                    date: person.birthday.date,
                    icon: '🎂',
                },
                classes: 'birthday-candle',
                position: {
                    x: position.x - (metrics.personWidth / 2) + 12,
                    y: position.y - (metrics.personHeight / 2) + 12,
                },
                grabbable: false,
                selectable: false,
            };
        });

    const unionByPair = new Map();
    const unionNodes = [];
    const partnershipEdges = [];

    for (const link of data.partnerships) {
        const partners = [String(link.partner_one_id), String(link.partner_two_id)].sort();
        const pairKey = partners.join('-');
        const unionId = `union-${pairKey}`;
        unionByPair.set(pairKey, unionId);
        unionNodes.push({
            data: {
                id: unionId,
                label: '♥',
                kind: 'union',
                status: link.status,
                startedAt: link.started_at,
                endedAt: link.ended_at,
            },
            classes: link.ended_at || ['divorced', 'ended'].includes(link.status)
                ? 'union ended'
                : 'union',
            position: positions.get(unionId),
        });
        partners.forEach((partnerId) => partnershipEdges.push({
            data: {
                id: `partner-${partnerId}-${unionId}`,
                source: partnerId,
                target: unionId,
                kind: 'partner',
                status: link.status,
            },
        }));
    }

    const parentsByChild = new Map();
    data.parent_child.forEach((link) => {
        const childId = String(link.child_id);
        const parents = parentsByChild.get(childId) ?? [];
        parents.push(String(link.parent_id));
        parentsByChild.set(childId, parents);
    });

    const parentEdges = [];
    const parentBranchNodes = [];
    const parentTrunkEdges = [];
    const parentBranchEdges = [];
    const childrenByUnion = new Map();

    for (const [childId, parents] of parentsByChild) {
        const pairKey = [...parents].sort().join('-');
        const unionId = parents.length === 2 ? unionByPair.get(pairKey) : null;

        if (unionId) {
            const childIds = childrenByUnion.get(unionId) ?? [];
            childIds.push(childId);
            childrenByUnion.set(unionId, childIds);
        } else {
            parents.forEach((parentId) => parentEdges.push({
                data: {
                    id: `parent-${parentId}-${childId}`,
                    source: parentId,
                    target: childId,
                    kind: 'parent',
                },
            }));
        }
    }

    for (const [unionId, childIds] of childrenByUnion) {
        const unionPosition = positions.get(unionId);
        const childPositions = childIds
            .map((childId) => positions.get(childId))
            .filter(Boolean);

        if (! unionPosition || childPositions.length === 0) {
            childIds.forEach((childId) => parentEdges.push({
                data: {
                    id: `parent-${unionId}-${childId}`,
                    source: unionId,
                    target: childId,
                    kind: 'parent',
                },
            }));

            continue;
        }

        const firstChildY = Math.min(...childPositions.map((position) => position.y));
        const branchY = Math.max(
            unionPosition.y + Math.round(metrics.generationGap * 0.35),
            firstChildY - (metrics.personHeight / 2) - 18,
        );
        const branchId = `${unionId}-children-branch`;

        parentBranchNodes.push({
            data: {
                id: branchId,
                kind: 'parent-branch-node',
            },
            position: {
                x: unionPosition.x,
                y: branchY,
            },
            classes: 'parent-branch-node',
            grabbable: false,
            selectable: false,
        });

        parentTrunkEdges.push({
            data: {
                id: `parent-trunk-${unionId}`,
                source: unionId,
                target: branchId,
                kind: 'parent-trunk',
            },
        });

        childIds.forEach((childId) => parentBranchEdges.push({
            data: {
                id: `parent-${branchId}-${childId}`,
                source: branchId,
                target: childId,
                kind: 'parent-branch',
            },
        }));
    }

    return [
        ...nodes,
        ...photoNodes,
        ...mourningNodes,
        ...continuationNodes,
        ...birthdayNodes,
        ...textNodes,
        ...unionNodes,
        ...parentBranchNodes,
        ...parentTrunkEdges,
        ...parentBranchEdges,
        ...parentEdges,
        ...partnershipEdges,
    ];
}

function familyTreePositions(data) {
    const people = new Map(data.people.map((person) => [String(person.id), person]));
    const parentLinks = data.parent_child.map((link) => ({
        parent: String(link.parent_id),
        child: String(link.child_id),
    }));
    const partnerships = data.partnerships.map((link) => ({
        ...link,
        one: String(link.partner_one_id),
        two: String(link.partner_two_id),
    }));
    const generations = new Map([...people.keys()].map((id) => [id, 0]));

    for (let iteration = 0; iteration < Math.max(people.size, 1); iteration++) {
        let changed = false;

        for (const link of parentLinks) {
            const next = Math.max(generations.get(link.child) ?? 0, (generations.get(link.parent) ?? 0) + 1);
            if (next !== generations.get(link.child)) {
                generations.set(link.child, next);
                changed = true;
            }
        }

        for (const link of partnerships) {
            const generation = Math.max(generations.get(link.one) ?? 0, generations.get(link.two) ?? 0);
            if (generations.get(link.one) !== generation || generations.get(link.two) !== generation) {
                generations.set(link.one, generation);
                generations.set(link.two, generation);
                changed = true;
            }
        }

        if (!changed) break;
    }

    const parent = new Map([...people.keys()].map((id) => [id, id]));
    const find = (id) => {
        let root = id;
        while (parent.get(root) !== root) root = parent.get(root);
        while (parent.get(id) !== id) {
            const next = parent.get(id);
            parent.set(id, root);
            id = next;
        }
        return root;
    };
    const unite = (left, right) => {
        const leftRoot = find(left);
        const rightRoot = find(right);
        if (leftRoot !== rightRoot) parent.set(rightRoot, leftRoot);
    };
    partnerships.forEach((link) => unite(link.one, link.two));

    const components = new Map();
    for (const id of people.keys()) {
        const root = find(id);
        if (!components.has(root)) components.set(root, []);
        components.get(root).push(id);
    }

    const partnershipDate = (link) => {
        const value = link.started_at || link.ended_at || '';
        const timestamp = value ? Date.parse(value) : 0;
        return Number.isFinite(timestamp) ? timestamp : 0;
    };
    const linksFor = (id) => partnerships.filter((link) => link.one === id || link.two === id);
    const orderedMembers = (members) => {
        if (members.length < 2) return members;

        const central = [...members].sort((left, right) => {
            const degree = linksFor(right).length - linksFor(left).length;
            return degree || Number(left) - Number(right);
        })[0];
        const centralPerson = people.get(central);
        const spouses = members.filter((id) => id !== central);
        const newestFirst = (left, right) => {
            const leftLink = linksFor(central).find((link) => link.one === left || link.two === left);
            const rightLink = linksFor(central).find((link) => link.one === right || link.two === right);
            return partnershipDate(rightLink ?? {}) - partnershipDate(leftLink ?? {});
        };

        if (centralPerson?.gender === 'male') {
            return [...spouses.sort((left, right) => -newestFirst(left, right)), central];
        }

        if (centralPerson?.gender === 'female') {
            return [central, ...spouses.sort(newestFirst)];
        }

        return [...members].sort((left, right) => {
            const genderOrder = { female: 0, unknown: 1, male: 2 };
            return (genderOrder[people.get(left)?.gender] ?? 1)
                - (genderOrder[people.get(right)?.gender] ?? 1);
        });
    };

    const clusters = [...components.entries()].map(([root, members]) => {
        const ordered = orderedMembers(members);
        const memberSet = new Set(members);
        const generation = Math.max(...members.map((id) => generations.get(id) ?? 0));
        const childMembers = members.filter((id) => parentLinks.some((link) => link.child === id));
        const anchorMember = [...childMembers, ...members][0];
        const parentIds = parentLinks
            .filter((link) => link.child === anchorMember)
            .map((link) => link.parent)
            .sort();
        const parentKey = parentIds.length ? parentIds.join('-') : `root-${root}`;
        const birth = people.get(anchorMember)?.birth_date;

        return {
            root,
            members,
            memberSet,
            ordered,
            generation,
            parentIds,
            parentKey,
            birthOrder: birth ? Date.parse(birth) : Number.MAX_SAFE_INTEGER,
            width: ordered.length * 220 + Math.max(ordered.length - 1, 0) * 28,
        };
    });
    const positions = new Map();
    const personWidth = 220;
    const spouseGap = 28;
    const familyGap = 110;
    const generationGap = 245;
    const maxGeneration = Math.max(0, ...clusters.map((cluster) => cluster.generation));

    for (let generation = 0; generation <= maxGeneration; generation++) {
        const levelClusters = clusters.filter((cluster) => cluster.generation === generation);
        const groups = new Map();

        for (const cluster of levelClusters) {
            const key = cluster.parentKey;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(cluster);
        }

        const groupEntries = [...groups.entries()].map(([key, items]) => {
            items.sort((left, right) => left.birthOrder - right.birthOrder || String(left.root).localeCompare(String(right.root)));
            const parentXs = items.flatMap((item) => item.parentIds)
                .map((id) => positions.get(id)?.x)
                .filter((value) => Number.isFinite(value));

            return {
                key,
                items,
                desiredX: parentXs.length
                    ? parentXs.reduce((sum, value) => sum + value, 0) / parentXs.length
                    : Number.POSITIVE_INFINITY,
                width: items.reduce((sum, item) => sum + item.width, 0)
                    + Math.max(items.length - 1, 0) * familyGap,
            };
        });

        groupEntries.sort((left, right) => {
            const leftFinite = Number.isFinite(left.desiredX);
            const rightFinite = Number.isFinite(right.desiredX);
            if (leftFinite && rightFinite) return left.desiredX - right.desiredX;
            if (leftFinite) return -1;
            if (rightFinite) return 1;
            return left.key.localeCompare(right.key);
        });

        let cursor = 0;
        for (const group of groupEntries) {
            const desiredStart = Number.isFinite(group.desiredX)
                ? group.desiredX - group.width / 2
                : cursor;
            let clusterCursor = Math.max(cursor, desiredStart);

            for (const cluster of group.items) {
                let memberX = clusterCursor + personWidth / 2;
                const y = generation * generationGap;

                for (const memberId of cluster.ordered) {
                    positions.set(memberId, { x: memberX, y });
                    memberX += personWidth + spouseGap;
                }

                clusterCursor += cluster.width + familyGap;
            }

            cursor = clusterCursor;
        }
    }

    if (positions.size) {
        const minX = Math.min(...[...positions.values()].map((position) => position.x));
        const maxX = Math.max(...[...positions.values()].map((position) => position.x));
        const offset = (minX + maxX) / 2;
        positions.forEach((position, id) => positions.set(id, { ...position, x: position.x - offset }));
    }

    for (const link of partnerships) {
        const partners = [link.one, link.two].sort();
        const unionId = `union-${partners.join('-')}`;
        const one = positions.get(link.one);
        const two = positions.get(link.two);
        if (one && two) {
            positions.set(unionId, {
                x: (one.x + two.x) / 2,
                y: Math.max(one.y, two.y) + 76,
            });
        }
    }

    return positions;
}

function compactDate(value, precision = 'day') {
    if (!value) return '';
    const [year, month, day] = value.split('-');
    if (precision === 'year') return year;
    if (precision === 'month') return [month, year].filter(Boolean).join('.');
    return [day, month, year].filter(Boolean).join('.');
}

function treeDateLabel(person) {
    const birth = compactDate(person.birth_date, person.birth_date_precision);
    const death = compactDate(person.death_date, person.death_date_precision);

    if (birth && death) return `${birth} — ${death}`;
    if (death) return `† ${death}`;
    if (birth) return t('born', { date: birth });
    return '';
}

async function renderTree(data) {
    const cytoscape = await loadCytoscape();
    const metrics = treeMetrics();
    state.cytoscape?.destroy();
    $('#empty').hidden = data.people.length > 0;

    state.cytoscape = cytoscape({
        container: $('#tree'),
        elements: personElements(data, metrics),
        minZoom: 0.25,
        maxZoom: 2.5,
        wheelSensitivity: 0.2,
        autoungrabify: true,
        style: [
            {
                selector: 'node.person',
                style: {
                    width: metrics.personWidth,
                    height: metrics.personHeight,
                    shape: 'round-rectangle',
                    'background-color': '#fffdf8',
                    'border-color': '#d7cbb9',
                    'border-width': 1.5,
                    label: 'data(label)',
                    color: '#2b251e',
                    'font-family': 'system-ui, sans-serif',
                    'font-size': metrics.mobile ? 9 : 10,
                    'font-weight': 700,
                    'text-wrap': 'wrap',
                    'text-max-width': metrics.personWidth - 12,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'text-margin-x': 0,
                    'text-margin-y': (metrics.photoSize / 2) + 24,
                    'text-background-color': '#fffdf8',
                    'text-background-opacity': 0.9,
                    'text-background-shape': 'round-rectangle',
                    'text-background-padding': 3,
                    'overlay-opacity': 0,
                    'shadow-blur': 14,
                    'shadow-color': '#6f6049',
                    'shadow-opacity': 0.12,
                    'shadow-offset-y': 4,
                },
            },
            {
                selector: 'node.person.has-photo',
                style: {},
            },
            {
                selector: 'node.person-text',
                style: {
                    width: metrics.personWidth - 12,
                    height: metrics.mobile ? 56 : 62,
                    shape: 'round-rectangle',
                    'background-color': '#fffdf8',
                    'background-opacity': 0.92,
                    'border-width': 0,
                    label: 'data(label)',
                    color: '#2b251e',
                    'font-family': 'system-ui, sans-serif',
                    'font-size': metrics.mobile ? 10.5 : 11.5,
                    'font-weight': 800,
                    'line-height': 1.08,
                    'text-wrap': 'wrap',
                    'text-max-width': metrics.personWidth - 18,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'text-margin-x': 0,
                    'text-margin-y': 0,
                    'events': 'no',
                    'overlay-opacity': 0,
                    'z-index': 26,
                },
            },
            {
                selector: 'node.person-photo',
                style: {
                    width: metrics.photoSize,
                    height: metrics.photoSize,
                    shape: 'ellipse',
                    'background-image': 'data(photo)',
                    'background-fit': 'cover',
                    'background-width': metrics.photoSize,
                    'background-height': metrics.photoSize,
                    'background-position-x': '50%',
                    'background-position-y': '50%',
                    'background-clip': 'node',
                    'border-width': 3,
                    'border-color': '#fffdf8',
                    'overlay-opacity': 0,
                    'z-index': 24,
                    'events': 'yes',
                },
            },
            {
                selector: 'node.person-photo.photo-female',
                style: { 'border-color': '#f3c1c7' },
            },
            {
                selector: 'node.person-photo.photo-male',
                style: { 'border-color': '#bde8f3' },
            },
            {
                selector: 'node.person.relation-self',
                style: {
                    'border-width': 4,
                    'shadow-opacity': 0.28,
                },
            },
            {
                selector: 'node.person.relation-children, node.person.relation-grandchildren',
                style: {
                    'border-width': 2.5,
                    'shadow-opacity': 0.2,
                },
            },
            {
                selector: 'node.person[gender = "female"]',
                style: {
                    'border-color': '#e48f99',
                    'background-color': '#fff0f1',
                },
            },
            {
                selector: 'node.person[gender = "male"]',
                style: {
                    'border-color': '#55b8d4',
                    'background-color': '#e8f8fc',
                },
            },
            {
                selector: 'node.union',
                style: {
                    width: 25,
                    height: 25,
                    shape: 'ellipse',
                    label: 'data(label)',
                    color: '#ffffff',
                    'font-size': 13,
                    'font-weight': 700,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'background-color': '#c68f91',
                    'border-width': 0,
                    'overlay-opacity': 0,
                },
            },
            {
                selector: 'node.parent-branch-node',
                style: {
                    width: 1,
                    height: 1,
                    label: '',
                    opacity: 0,
                    'background-opacity': 0,
                    'border-width': 0,
                    'events': 'no',
                    'overlay-opacity': 0,
                    'z-index': 1,
                },
            },
            {
                selector: 'node.mourning-ribbon',
                style: {
                    width: 40,
                    height: 40,
                    shape: 'rectangle',
                    'background-opacity': 0,
                    'background-image': 'data(image)',
                    'background-fit': 'contain',
                    'border-width': 0,
                    'events': 'no',
                    'overlay-opacity': 0,
                    'z-index': 20,
                },
            },
            {
                selector: 'node.branch-continuation, node.birthday-candle',
                style: {
                    width: metrics.badgeSize,
                    height: metrics.badgeSize,
                    shape: 'ellipse',
                    'background-color': '#fffdf8',
                    label: 'data(icon)',
                    color: '#2b251e',
                    'font-size': metrics.badgeSize - 10,
                    'font-weight': 800,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'border-width': 1.5,
                    'border-color': '#d7cbb9',
                    'overlay-opacity': 0,
                    'z-index': 30,
                },
            },
            {
                selector: 'edge[kind = "parent"]',
                style: {
                    width: 1.6,
                    'line-color': '#9b8f7e',
                    'target-arrow-color': '#9b8f7e',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'taxi',
                    'taxi-direction': 'downward',
                    'taxi-turn': '86%',
                },
            },
            {
                selector: 'edge[kind = "parent-trunk"]',
                style: {
                    width: 1.6,
                    'line-color': '#9b8f7e',
                    'target-arrow-shape': 'none',
                    'curve-style': 'straight',
                },
            },
            {
                selector: 'edge[kind = "parent-branch"]',
                style: {
                    width: 1.6,
                    'line-color': '#9b8f7e',
                    'target-arrow-color': '#9b8f7e',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'taxi',
                    'taxi-direction': 'downward',
                    'taxi-turn': 0,
                },
            },
            {
                selector: 'edge[kind = "partner"]',
                style: {
                    width: 2,
                    'line-color': '#c68f91',
                    'curve-style': 'straight',
                },
            },
            {
                selector: 'edge[kind = "partner"][status = "divorced"], edge[kind = "partner"][status = "ended"]',
                style: {
                    'line-style': 'dashed',
                    'line-color': '#8c8580',
                },
            },
            {
                selector: 'node.union.ended',
                style: {
                    'background-color': '#8c8580',
                },
            },
            {
                selector: 'node.person:selected',
                style: {
                    'border-width': 3,
                    'border-color': '#6d7651',
                },
            },
        ],
        layout: {
            name: 'preset',
            fit: true,
            padding: 45,
        },
    });

    state.cytoscape.on('tap', 'node.person', (event) => showPerson(event.target.id()));
    state.cytoscape.on('tap', 'node.person-photo', (event) => showPerson(event.target.data('personId')));
    state.cytoscape.on('tap', 'node.branch-continuation', async (event) => {
        state.focusId = String(event.target.data('personId'));
        state.scope = 'branch';
        await loadTree();
    });
    state.cytoscape.on('tap', 'node.birthday-candle', (event) => {
        openCongratulation({
            occasion: 'birthday',
            personId: String(event.target.data('personId')),
            recipient: event.target.data('recipient'),
            date: event.target.data('date'),
            calendarTitle: t('birthday_calendar_title', { name: event.target.data('recipient') }),
        });
    });
    const focusNode = data.focus_id ? state.cytoscape.getElementById(String(data.focus_id)) : null;

    state.cytoscape.fit(undefined, 48);

    if (state.cytoscape.zoom() < 0.68 && focusNode?.length) {
        state.cytoscape.zoom(0.68);
        state.cytoscape.center(focusNode);
    }

}

const relationLabels = {
    self: t('relations.self'),
    parents: t('relations.parents'),
    grandparents: t('relations.grandparents'),
    spouses: t('relations.spouses'),
    children: t('relations.children'),
    grandchildren: t('relations.grandchildren'),
    siblings: t('relations.siblings'),
    nephews: t('relations.nephews'),
};

function renderList(data) {
    const list = $('#people-list');
    const people = new Map(data.people.map((person) => [String(person.id), person]));
    const row = (person) => `
            <button class="person-row" type="button" data-person-id="${person.id}">
                <span class="person-avatar-wrap ${person.death_date ? 'is-deceased' : ''}">
                    ${person.photo_url
                        ? `<img src="${escapeHtml(person.photo_url)}" alt="">`
                        : `<span class="person-row-avatar">${escapeHtml(person.name.slice(0, 1))}</span>`}
                </span>
                <span class="person-row-main">
                    <strong>${escapeHtml(person.name)}</strong>
                    <small>${escapeHtml(relationLabels[person.relation] || person.life_years || t('relations.relative'))}</small>
                </span>
                <span class="person-row-date">
                    ${person.birth_date ? `<b>${escapeHtml(formatDate(person.birth_date))}</b>` : ''}
                    ${person.birth_place ? `<small>${escapeHtml(person.birth_place)}</small>` : ''}
                </span>
                <span class="person-row-date person-row-death">
                    ${person.death_date ? `<b>${escapeHtml(formatDate(person.death_date))}</b>` : ''}
                    ${person.death_place ? `<small>${escapeHtml(person.death_place)}</small>` : ''}
                </span>
                <span class="person-row-chevron">›</span>
            </button>
        `;
    const groups = (data.list_groups ?? [])
        .map((group) => ({
            ...group,
            people: group.person_ids.map((id) => people.get(String(id))).filter(Boolean),
        }))
        .filter((group) => group.people.length);

    list.innerHTML = data.people.length
        ? (groups.length
            ? groups.map((group) => `
                <section class="family-list-group">
                    <h3>${escapeHtml(group.label)}</h3>
                    ${group.people.map(row).join('')}
                </section>
            `).join('')
            : data.people.map(row).join(''))
        : `<p class="empty-list">${escapeHtml(t('empty_filter'))}</p>`;
    list.querySelectorAll('[data-person-id]').forEach((row) => {
        row.addEventListener('click', () => showPerson(row.dataset.personId));
    });
}

function formatDate(value, precision = 'day') {
    if (!value) return '';
    if (precision === 'year') return value.slice(0, 4);
    if (precision === 'month') {
        return new Date(`${value}T12:00:00`).toLocaleDateString('ru-RU', {
            month: 'long',
            year: 'numeric',
        });
    }
    return new Date(`${value}T12:00:00`).toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function showPerson(id) {
    const person = state.people.get(String(id));

    if (!person) return;

    const photo = person.photo_url
        ? `<button class="person-photo-button" type="button" data-view-main-photo>
            <span class="person-photo-wrap ${person.death_date ? 'is-deceased' : ''}">
                <img class="person-photo" src="${escapeHtml(person.photo_url)}" alt="">
            </span>
        </button>`
        : `<div class="person-photo-wrap ${person.death_date ? 'is-deceased' : ''}">
            <div class="person-photo person-photo--empty">${escapeHtml(person.name.slice(0, 1))}</div>
        </div>`;
    const facts = [
        person.birth_date && [t('fields.birth_date'), formatDate(person.birth_date, person.birth_date_precision)],
        person.death_date && [t('fields.death_date'), formatDate(person.death_date, person.death_date_precision)],
        person.life_years && [t('fields.life_years'), person.life_years],
        person.maiden_name && [t('fields.maiden_name'), person.maiden_name],
        person.birth_place && [t('fields.birth_place'), person.birth_place],
        person.death_place && [t('fields.death_place'), person.death_place],
        person.burial_place && [t('fields.burial_place'), person.burial_place],
        person.city && [t('fields.city'), person.city],
        person.address && [t('fields.address'), person.address],
        person.occupation && [t('fields.occupation'), person.occupation],
    ].filter(Boolean);
    const relatives = [
        [t('fields.parents'), person.relatives?.parents],
        [t('fields.spouses'), person.relatives?.spouses],
        [t('fields.children'), person.relatives?.children],
    ].filter(([, items]) => items?.length);
    const photos = person.photos ?? [];

    $('#person-content').innerHTML = `
        <div class="person-heading">
            ${photo}
            <div>
                <h2>${escapeHtml(person.name)}</h2>
                ${person.life_years ? `<p>${escapeHtml(person.life_years)}</p>` : ''}
            </div>
        </div>
        <dl class="person-facts">
            ${facts.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}
        </dl>
        ${relatives.map(([label, items]) => `
            <section class="relative-block">
                <h3>${escapeHtml(label)}</h3>
                <div class="relative-chips">
                    ${items.map((relative) => `
                        <button type="button" data-relative-id="${relative.id}">
                            ${relative.photo_url ? `<img src="${escapeHtml(relative.photo_url)}" alt="">` : ''}
                            <span>${escapeHtml(relative.name)}</span>
                        </button>
                    `).join('')}
                </div>
            </section>
        `).join('')}
        ${photos.length ? `
            <section class="person-photo-strip">
                <h3>${escapeHtml(t('fields.photos'))}</h3>
                <div>${photos.map((item, index) => `
                    <button type="button" data-person-photo-index="${index}">
                        <img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.title || person.name)}">
                    </button>
                `).join('')}</div>
            </section>
        ` : ''}
        ${person.bio ? `<p class="person-bio">${escapeHtml(person.bio)}</p>` : ''}
        <button id="person-focus" class="person-focus" type="button">${escapeHtml(t('show_branch'))}</button>
    `;
    $('#person-content').querySelectorAll('[data-relative-id]').forEach((button) => {
        button.addEventListener('click', async () => {
            const relativeId = button.dataset.relativeId;

            if (!state.people.has(String(relativeId))) {
                state.focusId = relativeId;
                state.scope = 'branch';
                await loadTree();
            }

            showPerson(relativeId);
        });
    });
    const viewerPhotos = [
        person.photo_url ? {
            url: person.photo_url,
            title: person.name,
            person_id: person.id,
            person_name: person.name,
        } : null,
        ...photos.map((item) => ({
            ...item,
            person_id: person.id,
            person_name: person.name,
        })),
    ].filter(Boolean);
    $('#person-content').querySelector('[data-view-main-photo]')?.addEventListener('click', () => {
        showPhotoViewer({
            photos: viewerPhotos,
            index: 0,
        });
    });
    $('#person-content').querySelectorAll('[data-person-photo-index]').forEach((button) => {
        button.addEventListener('click', () => {
            showPhotoViewer({
                photos: viewerPhotos,
                index: Number(button.dataset.personPhotoIndex) + (person.photo_url ? 1 : 0),
            });
        });
    });
    $('#person-focus').addEventListener('click', () => {
        state.focusId = String(person.id);
        state.scope = 'branch';
        $('#filters [name="q"]').value = '';
        $('#person-sheet').hidden = true;
        loadTree();
    });
    $('#person-sheet').hidden = false;
}

function treeQuery() {
    const form = new FormData($('#filters'));
    const query = new URLSearchParams();

    for (const [key, value] of form.entries()) {
        if (value !== '') query.set(key, value);
    }

    query.set('scope', state.scope);
    if (state.focusId) query.set('focus', state.focusId);

    return query.toString();
}

async function loadTree() {
    setLoading(true);
    $('#error').hidden = true;

    try {
        const data = await api(`/api/family/tree?${treeQuery()}`);
        if (state.treeSlug && data.tree?.slug && String(state.treeSlug) !== String(data.tree.slug)) {
            throw new Error(t('wrong_tree'));
        }
        if (data.tree?.id) {
            state.treeId = data.tree.id;
            state.treeSlug = data.tree.slug;
        }
        state.lastTreeData = data;
        state.focusId = data.focus_id;
        state.people = new Map(data.people.map((person) => [String(person.id), person]));
        if (data.tree?.name) document.querySelector('.brand h1').textContent = data.tree.name;
        const meTab = document.querySelector('[data-tab="me"]');
        if (meTab) meTab.hidden = !data.viewer?.has_person;
        const birthdayTab = document.querySelector('[data-tab="birthdays"]');
        if (birthdayTab) {
            birthdayTab.textContent = data.viewer?.unread_congratulations > 0
                ? `${t('birthdays')} · ${data.viewer.unread_congratulations}`
                : t('birthdays');
        }
        $('#my-branch').disabled = !data.viewer?.has_person;
        $('#filters [name="relation"]').disabled = !data.viewer?.has_person;
        if (state.activeTab === 'tree') await renderTree(data);
        renderList(data);
        fillCities(data.filters.cities);
        $('#tree-meta').textContent = t('shown', { shown: data.shown_people, total: data.total_people });

        if (state.pendingOpenPersonId && state.people.has(String(state.pendingOpenPersonId))) {
            const personId = state.pendingOpenPersonId;
            state.pendingOpenPersonId = null;
            showPerson(personId);
        }
    } catch (error) {
        if (state.lastTreeData) {
            $('#tree-meta').textContent = t('stale');
        } else {
            showError(error.message, error.payload);
        }
    } finally {
        setLoading(false);
    }
}

function fillCities(cities) {
    const select = $('#city');
    const current = select.value;
    const options = [`<option value="">${escapeHtml(t('all_places'))}</option>`];

    for (const city of cities) {
        options.push(`<option value="${escapeHtml(city)}">${escapeHtml(city)}</option>`);
    }

    select.innerHTML = options.join('');
    select.value = current;
}

function openCongratulation({
    occasion,
    personId = '',
    partnershipId = '',
    recipient = '',
    date = '',
    calendarTitle = '',
}) {
    const form = $('#congratulation-form');
    form.reset();
    form.elements.occasion.value = occasion;
    form.elements.person_id.value = personId;
    form.elements.partnership_id.value = partnershipId;
    form.elements.message.value = occasion === 'birthday' ? t('birthday_wish') : t('anniversary_wish');
    $('#congratulation-recipient').textContent = recipient;
    $('#congratulation-message').textContent = '';
    const calendarButton = $('#congratulation-calendar');
    calendarButton.hidden = !date;
    calendarButton.dataset.date = date;
    calendarButton.dataset.title = calendarTitle;
    $('#congratulation-modal').hidden = false;
}

function downloadCalendarEvent({ date, title }) {
    const compact = String(date).replaceAll('-', '');
    const next = new Date(`${date}T12:00:00`);
    next.setDate(next.getDate() + 1);
    const nextDate = `${next.getFullYear()}${String(next.getMonth() + 1).padStart(2, '0')}${String(next.getDate()).padStart(2, '0')}`;
    const escapeIcs = (value) => String(value).replaceAll('\\', '\\\\').replaceAll('\n', '\\n').replaceAll(',', '\\,').replaceAll(';', '\\;');
    const content = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Idommoy//Family Calendar//RU', 'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT', `UID:${crypto.randomUUID?.() ?? `${Date.now()}@idommoy.com`}`,
        `DTSTAMP:${new Date().toISOString().replaceAll(/[-:]/g, '').replace(/\.\d{3}/, '')}`,
        `DTSTART;VALUE=DATE:${compact}`, `DTEND;VALUE=DATE:${nextDate}`,
        `SUMMARY:${escapeIcs(title)}`, 'TRANSP:TRANSPARENT', 'END:VEVENT', 'END:VCALENDAR',
    ].join('\r\n');
    const url = URL.createObjectURL(new Blob([content], { type: 'text/calendar;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${compact}-${String(title).replaceAll(/[^\p{L}\p{N}]+/gu, '-')}.ics`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function loadBirthdays() {
    setLoading(true);

    try {
        const data = await api('/api/family/birthdays');
        const birthdayTab = document.querySelector('[data-tab="birthdays"]');
        if (birthdayTab) birthdayTab.textContent = t('birthdays');
        $('#birthday-list').innerHTML = data.birthdays.length
            ? data.birthdays.map((item) => `
                <article class="birthday-card">
                    <img src="${escapeHtml(item.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="" loading="lazy">
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${escapeHtml(new Date(`${item.date}T12:00:00`).toLocaleDateString(dateLocale, { day: 'numeric', month: 'long' }))} · ${escapeHtml(t('years', { count: item.age }))}</span>
                    </div>
                    <div class="birthday-side">
                        <em>${item.days === 0 ? escapeHtml(t('today')) : escapeHtml(t('in_days', { count: item.days }))}</em>
                        ${String(item.id) !== String(data.viewer_person_id ?? '')
                            ? `<button class="congratulate-button" type="button" data-occasion="birthday" data-person-id="${item.id}" data-recipient="${escapeHtml(item.name)}" data-date="${item.date}" data-calendar-title="${escapeHtml(t('birthday_calendar_title', { name: item.name }))}">${escapeHtml(t('congratulate'))}</button>`
                            : ''}
                        <button class="calendar-button" type="button" data-date="${item.date}" data-title="${escapeHtml(t('birthday_calendar_title', { name: item.name }))}">${escapeHtml(t('add_calendar'))}</button>
                    </div>
                </article>
            `).join('')
            : `<p class="empty-list">${escapeHtml(t('no_birthdays'))}</p>`;
        $('#anniversary-list').innerHTML = data.anniversaries?.length
            ? `<h3>${escapeHtml(t('anniversaries'))}</h3>${data.anniversaries.map((item) => `
                <article class="birthday-card anniversary-card">
                    <span class="couple-avatar">
                        <img src="${escapeHtml(item.partner_one.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="">
                        <img src="${escapeHtml(item.partner_two.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="">
                    </span>
                    <div>
                        <strong>${escapeHtml(item.title)}</strong>
                        <span>${escapeHtml(new Date(`${item.date}T12:00:00`).toLocaleDateString(dateLocale, { day: 'numeric', month: 'long' }))} · ${escapeHtml(t('years', { count: item.years }))}</span>
                    </div>
                    <div class="birthday-side">
                        <em>${item.days === 0 ? escapeHtml(t('today')) : escapeHtml(t('in_days', { count: item.days }))}</em>
                        <button class="congratulate-button" type="button" data-occasion="anniversary" data-partnership-id="${item.id}" data-recipient="${escapeHtml(item.title)}" data-date="${item.date}" data-calendar-title="${escapeHtml(t('anniversary_calendar_title', { name: item.title }))}">${escapeHtml(t('congratulate'))}</button>
                        <button class="calendar-button" type="button" data-date="${item.date}" data-title="${escapeHtml(t('anniversary_calendar_title', { name: item.title }))}">${escapeHtml(t('add_calendar'))}</button>
                    </div>
                </article>
            `).join('')}`
            : '';
        $('#congratulation-inbox').innerHTML = data.congratulations?.length
            ? `<h3>${escapeHtml(t('received'))}</h3>${data.congratulations.map((item) => `
                <article class="congratulation-card">
                    <strong>${escapeHtml(item.from)}</strong>
                    <p>${escapeHtml(item.message)}</p>
                    <small>${escapeHtml(new Date(item.created_at).toLocaleString('ru-RU'))}</small>
                </article>
            `).join('')}`
            : '';
        document.querySelectorAll('.congratulate-button').forEach((button) => {
            button.addEventListener('click', () => openCongratulation({
                occasion: button.dataset.occasion,
                personId: button.dataset.personId ?? '',
                partnershipId: button.dataset.partnershipId ?? '',
                recipient: button.dataset.recipient ?? '',
                date: button.dataset.date ?? '',
                calendarTitle: button.dataset.calendarTitle ?? '',
            }));
        });
        document.querySelectorAll('.calendar-button').forEach((button) => {
            button.addEventListener('click', () => downloadCalendarEvent({
                date: button.dataset.date,
                title: button.dataset.title,
            }));
        });
    } catch (error) {
        showError(error.message, error.payload);
    } finally {
        setLoading(false);
    }
}

async function loadGallery(reset = true) {
    if (state.galleryLoading) return;
    state.galleryLoading = true;
    setLoading(true);

    try {
        if (reset) {
            state.galleryCursor = null;
            state.galleryPhotos = new Map();
            $('#gallery-grid').innerHTML = '';
        }
        const query = new URLSearchParams({ per_page: '36' });
        if (state.galleryCursor) query.set('cursor', state.galleryCursor);
        const data = await api(`/api/family/gallery?${query}`);
        data.photos.forEach((photo) => state.galleryPhotos.set(String(photo.id), photo));
        const markup = data.photos.length
            ? data.photos.map((photo) => `
                <button class="gallery-item" type="button" data-photo-id="${photo.id}">
                    <img src="${escapeHtml(photo.thumbnail_url ?? photo.url)}" data-fallback="/images/photo-placeholder.svg" alt="${escapeHtml(photo.person_name)}" loading="lazy" decoding="async">
                    <span>
                        <strong>${escapeHtml(photo.person_name)}</strong>
                        ${photo.taken_at ? `<small>${escapeHtml(formatDate(photo.taken_at))}</small>` : ''}
                    </span>
                </button>
            `).join('')
            : (reset ? `<p class="empty-list">${escapeHtml(t('no_photos'))}</p>` : '');
        $('#gallery-grid').insertAdjacentHTML('beforeend', markup);
        $('#gallery-grid').querySelectorAll('[data-photo-id]:not([data-bound])').forEach((item) => {
            item.dataset.bound = '1';
            item.addEventListener('click', () => {
                const photoIds = [...$('#gallery-grid').querySelectorAll('[data-photo-id]')]
                    .map((button) => String(button.dataset.photoId));
                const photos = photoIds
                    .map((id) => state.galleryPhotos.get(id))
                    .filter(Boolean);
                const index = Math.max(0, photos.findIndex((photo) => String(photo.id) === String(item.dataset.photoId)));
                showPhotoViewer({ photos, index });
            });
        });
        state.galleryCursor = data.next_cursor;
        $('#gallery-more').hidden = !data.has_more;
    } catch (error) {
        showError(error.message, error.payload);
    } finally {
        state.galleryLoading = false;
        setLoading(false);
    }
}

const eventIcons = {
    birthday: '🎂',
    anniversary: '💍',
    wedding: '💒',
    reunion: '👨‍👩‍👧‍👦',
    memorial: '🕯️',
    other: '📅',
};

function eventCards(items) {
    return items.length
        ? items.map((item) => `
            <article class="event-card">
                <span class="event-icon">${eventIcons[item.type] || '📅'}</span>
                <div>
                    <strong>${escapeHtml(item.title)}</strong>
                    <time>${escapeHtml(formatDate(item.date))}${item.time ? ` · ${escapeHtml(String(item.time).slice(0, 5))}` : ''}</time>
                    ${item.person_name ? `<small>${escapeHtml(item.person_name)}</small>` : ''}
                    ${item.place ? `<small>📍 ${escapeHtml(item.place)}</small>` : ''}
                    ${item.description ? `<p>${escapeHtml(item.description)}</p>` : ''}
                </div>
                ${item.annual ? `<em>${escapeHtml(t('annual'))}</em>` : ''}
            </article>
        `).join('')
        : `<p class="empty-list">${escapeHtml(t('no_events'))}</p>`;
}

async function loadEvents() {
    setLoading(true);
    try {
        const data = await api('/api/family/events');
        $('#events-list').innerHTML = eventCards(data.upcoming);
        $('#events-archive').innerHTML = eventCards(data.archive);
    } catch (error) {
        showError(error.message, error.payload);
    } finally {
        setLoading(false);
    }
}

function renderPhotoViewer() {
    const photo = state.photoViewerPhotos[state.photoViewerIndex];

    if (!photo) return;

    $('#photo-viewer-image').src = photo.url;
    $('#photo-viewer-image').alt = photo.title || photo.person_name || t('family_photo');
    $('#photo-viewer-caption').innerHTML = `
        ${(photo.title || photo.person_name)
            ? `<strong>${escapeHtml(photo.title || photo.person_name)}</strong>`
            : ''}
        ${photo.description ? `<p>${escapeHtml(photo.description)}</p>` : ''}
        ${photo.taken_at ? `<small>${escapeHtml(formatDate(photo.taken_at))}</small>` : ''}
        ${photo.person_id ? `
            <button type="button" data-viewer-person="${photo.person_id}">
                ${escapeHtml(t('open_person', { name: photo.person_name || t('relations.relative') }))}
            </button>
        ` : ''}
    `;
    $('#photo-viewer-caption [data-viewer-person]')?.addEventListener('click', async (event) => {
        const personId = event.currentTarget.dataset.viewerPerson;
        $('#photo-viewer').hidden = true;
        state.focusId = personId;
        state.scope = 'branch';
        state.pendingOpenPersonId = personId;
        state.lastTreeData = null;
        switchTab('tree');
    });
    const hasMany = state.photoViewerPhotos.length > 1;
    $('#photo-viewer-prev').hidden = !hasMany;
    $('#photo-viewer-next').hidden = !hasMany;
    $('#photo-viewer-counter').hidden = !hasMany;
    $('#photo-viewer-counter').textContent = hasMany
        ? `${state.photoViewerIndex + 1} / ${state.photoViewerPhotos.length}`
        : '';
}

function showPhotoViewer(payload) {
    const photos = Array.isArray(payload?.photos) ? payload.photos : [payload].filter(Boolean);

    state.photoViewerPhotos = photos.filter((photo) => photo?.url);
    state.photoViewerIndex = Math.min(
        Math.max(Number(payload?.index ?? 0), 0),
        Math.max(state.photoViewerPhotos.length - 1, 0),
    );

    renderPhotoViewer();
    $('#photo-viewer').hidden = !state.photoViewerPhotos.length;
}

function movePhotoViewer(direction) {
    if ($('#photo-viewer').hidden || state.photoViewerPhotos.length < 2) return;

    state.photoViewerIndex = (
        state.photoViewerIndex + direction + state.photoViewerPhotos.length
    ) % state.photoViewerPhotos.length;
    renderPhotoViewer();
}

function field(name, label, value = '', type = 'text') {
    return `<label><span>${escapeHtml(label)}</span><input name="${name}" type="${type}" value="${escapeHtml(value ?? '')}"></label>`;
}

function personEditFields(person) {
    return `
        ${field('last_name', t('editor.last_name'), person.last_name)}
        ${field('first_name', t('editor.first_name'), person.first_name)}
        ${field('middle_name', t('editor.middle_name'), person.middle_name)}
        ${field('maiden_name', t('editor.maiden_name'), person.maiden_name)}
        <label><span>${escapeHtml(t('editor.gender'))}</span><select name="gender">
            <option value="unknown" ${person.gender === 'unknown' ? 'selected' : ''}>${escapeHtml(t('editor.gender_unknown'))}</option>
            <option value="male" ${person.gender === 'male' ? 'selected' : ''}>${escapeHtml(t('editor.gender_male'))}</option>
            <option value="female" ${person.gender === 'female' ? 'selected' : ''}>${escapeHtml(t('editor.gender_female'))}</option>
        </select></label>
        ${field('birth_date', t('fields.birth_date'), person.birth_date, 'date')}
        ${field('death_date', t('fields.death_date'), person.death_date, 'date')}
        ${field('birth_place', t('fields.birth_place'), person.birth_place)}
        ${field('current_city', t('editor.current_city'), person.current_city)}
        ${field('occupation', t('fields.occupation'), person.occupation)}
        <label class="wide"><span>${escapeHtml(t('editor.biography'))}</span><textarea name="bio">${escapeHtml(person.bio ?? '')}</textarea></label>
    `;
}

function formObject(form) {
    return Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [
        key,
        value === '' ? null : value,
    ]));
}

function renderMe(data) {
    const person = data.person;
    const relatives = [
        ...data.relatives.spouses.map((item) => ({ ...item, kind: t('editor.spouse') })),
        ...data.relatives.children.map((item) => ({ ...item, kind: t('editor.child') })),
        ...data.relatives.grandchildren.map((item) => ({ ...item, kind: t('editor.grandchild') })),
        ...data.relatives.child_spouses.map((item) => ({ ...item, kind: t('editor.child_spouse') })),
    ];

    $('#me-content').innerHTML = `
        ${data.can_edit ? '' : `<div class="manage-card readonly-notice">${escapeHtml(t('editor.readonly'))}</div>`}
        <section class="manage-card">
            <div class="manage-heading">
                ${person.photo_url ? `<img src="${escapeHtml(person.photo_url)}" alt="">` : '<span>👤</span>'}
                <div><h2>${escapeHtml(person.name)}</h2><p>${escapeHtml(t('editor.your_profile'))}</p></div>
            </div>
            <form id="profile-form" class="manage-form">
                ${personEditFields(person)}
                <button type="submit">${escapeHtml(t('editor.save_profile'))}</button>
            </form>
        </section>

        <section class="manage-card">
            <h2>${escapeHtml(t('editor.my_branch'))}</h2>
            <div class="relative-editor-list">
                ${relatives.length ? relatives.map((relative) => `
                    <details data-relative="${relative.id}">
                        <summary>
                            <span>${escapeHtml(relative.name)}</span>
                            <small>${escapeHtml(relative.kind)}</small>
                        </summary>
                        <form class="manage-form relative-form">
                            ${personEditFields(relative)}
                            <div class="form-actions">
                                <button type="submit">${escapeHtml(t('editor.save'))}</button>
                                <button type="button" class="danger unlink-relative">${escapeHtml(t('editor.unlink'))}</button>
                            </div>
                        </form>
                    </details>
                `).join('') : `<p class="empty-list">${escapeHtml(t('editor.empty_relatives'))}</p>`}
            </div>
            <details class="add-box">
                <summary>＋ ${escapeHtml(t('editor.add_relative'))}</summary>
                <form id="relative-add-form" class="manage-form">
                    <label><span>${escapeHtml(t('editor.relative_kind'))}</span><select name="kind">
                        <option value="spouse">${escapeHtml(t('editor.add_spouse'))}</option>
                        <option value="child">${escapeHtml(t('editor.add_child'))}</option>
                        <option value="grandchild">${escapeHtml(t('editor.add_grandchild'))}</option>
                        <option value="child_spouse">${escapeHtml(t('editor.add_child_spouse'))}</option>
                    </select></label>
                    <label><span>${escapeHtml(t('editor.through_child'))}</span><select name="related_person_id">
                        <option value="">${escapeHtml(t('editor.not_required'))}</option>
                        ${data.relatives.children.map((child) => `<option value="${child.id}">${escapeHtml(child.name)}</option>`).join('')}
                    </select></label>
                    ${personEditFields({ gender: 'unknown' })}
                    <button type="submit">${escapeHtml(t('editor.add_tree'))}</button>
                </form>
            </details>
        </section>

        <section class="manage-card">
            <h2>${escapeHtml(t('editor.albums'))}</h2>
            <div class="album-list">
                ${data.albums.map((album) => `
                    <span>${escapeHtml(album.title)} · ${album.photos_count}
                        <button type="button" data-delete-album="${album.id}" aria-label="${escapeHtml(t('editor.delete'))}">×</button>
                    </span>
                `).join('') || `<p class="empty-list">${escapeHtml(t('editor.no_albums'))}</p>`}
            </div>
            <form id="album-form" class="inline-form">
                <input name="title" placeholder="${escapeHtml(t('editor.album_title'))}" required>
                <button type="submit">${escapeHtml(t('editor.create'))}</button>
            </form>
        </section>

        <section class="manage-card">
            <h2>${escapeHtml(t('editor.my_photos'))}</h2>
            <form id="photo-form" class="photo-upload-form">
                <input name="photo" type="file" accept="image/*" required>
                <input name="title" placeholder="${escapeHtml(t('editor.photo_caption'))}">
                <select name="photo_album_id">
                    <option value="">${escapeHtml(t('editor.no_album'))}</option>
                    ${data.albums.map((album) => `<option value="${album.id}">${escapeHtml(album.title)}</option>`).join('')}
                </select>
                <label class="check"><input name="is_primary" type="checkbox" value="1"> ${escapeHtml(t('editor.make_primary'))}</label>
                <button type="submit">${escapeHtml(t('editor.upload'))}</button>
            </form>
            <div class="my-photo-grid">
                ${data.photos.map((photo) => `
                    <figure>
                        <img src="${escapeHtml(photo.url)}" alt="">
                        ${photo.is_primary ? `<b>${escapeHtml(t('editor.primary'))}</b>` : ''}
                        <button type="button" data-delete-photo="${photo.id}" aria-label="${escapeHtml(t('editor.delete'))}">×</button>
                    </figure>
                `).join('') || `<p class="empty-list">${escapeHtml(t('editor.first_photo'))}</p>`}
            </div>
        </section>

        <details class="manage-card danger-zone">
            <summary>${escapeHtml(t('editor.delete_profile'))}</summary>
            <p>${escapeHtml(t('editor.delete_profile_text'))}</p>
            <form id="delete-me-form" class="inline-form">
                <input name="confirmation" required>
                <button class="danger" type="submit">${escapeHtml(t('editor.delete_profile_button'))}</button>
            </form>
        </details>
        <details class="manage-card privacy-zone">
            <summary>${escapeHtml(t('editor.personal_data'))}</summary>
            <p>${escapeHtml(t('editor.personal_data_text'))}</p>
            <div class="form-actions">
                <button id="privacy-export" type="button">${escapeHtml(t('editor.download_data'))}</button>
            </div>
            <form id="delete-account-form" class="inline-form">
                <input name="confirmation" placeholder="${escapeHtml(t('editor.delete_account_placeholder'))}" required>
                <button class="danger" type="submit">${escapeHtml(t('editor.delete_account'))}</button>
            </form>
        </details>
        <p id="manage-message" class="manage-message"></p>
    `;

    if (!data.can_edit) {
        $('#me-content').querySelectorAll(
            '#profile-form input, #profile-form textarea, #profile-form select, #profile-form button, '
            + '.relative-form input, .relative-form textarea, .relative-form select, .relative-form button, '
            + '#relative-add-form, #album-form, #photo-form, .danger-zone',
        ).forEach((element) => {
            if (element.matches('form, details')) element.hidden = true;
            else element.disabled = true;
        });
    }

    bindManageActions();
}

function manageMessage(message, isError = false) {
    const element = $('#manage-message');
    if (!element) return;
    element.textContent = message;
    element.classList.toggle('is-error', isError);
    element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function loadMe() {
    setLoading(true);

    try {
        renderMe(await api('/api/family/me'));
    } catch (error) {
        $('#me-content').innerHTML = `<div class="manage-card"><p>${escapeHtml(error.message)}</p></div>`;
    } finally {
        setLoading(false);
    }
}

function bindManageActions() {
    $('#privacy-export')?.addEventListener('click', async () => {
        try {
            const data = await api('/api/family/privacy-export');
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'my-idommoy-data.json';
            link.click();
            URL.revokeObjectURL(link.href);
        } catch (error) {
            manageMessage(error.message, true);
        }
    });
    $('#delete-account-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await api('/api/family/account', {
                method: 'DELETE',
                body: formObject(event.currentTarget),
            });
            window.location.href = '/';
        } catch (error) {
            manageMessage(error.message, true);
        }
    });
    $('#profile-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const result = await api('/api/family/me', { method: 'PUT', body: formObject(event.currentTarget) });
            manageMessage(result.message);
        } catch (error) {
            manageMessage(error.message, true);
        }
    });

    document.querySelectorAll('.relative-form').forEach((form) => {
        const personId = form.closest('[data-relative]').dataset.relative;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const result = await api(`/api/family/me/relatives/${personId}`, {
                    method: 'PUT',
                    body: formObject(form),
                });
                manageMessage(result.message);
            } catch (error) {
                manageMessage(error.message, true);
            }
        });
        form.querySelector('.unlink-relative').addEventListener('click', async () => {
            if (!confirm(t('editor.confirm_unlink'))) return;
            try {
                await api(`/api/family/me/relatives/${personId}`, { method: 'DELETE' });
                await loadMe();
            } catch (error) {
                manageMessage(error.message, true);
            }
        });
    });

    $('#relative-add-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await api('/api/family/me/relatives', {
                method: 'POST',
                body: formObject(event.currentTarget),
            });
            await loadMe();
        } catch (error) {
            manageMessage(error.message, true);
        }
    });

    $('#album-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await api('/api/family/me/albums', {
                method: 'POST',
                body: formObject(event.currentTarget),
            });
            await loadMe();
        } catch (error) {
            manageMessage(error.message, true);
        }
    });

    document.querySelectorAll('[data-delete-album]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm(t('editor.confirm_album'))) return;
            try {
                await api(`/api/family/me/albums/${button.dataset.deleteAlbum}`, { method: 'DELETE' });
                await loadMe();
            } catch (error) {
                manageMessage(error.message, true);
            }
        });
    });

    $('#photo-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await api('/api/family/me/photos', { method: 'POST', body: new FormData(event.currentTarget) });
            await loadMe();
        } catch (error) {
            manageMessage(error.message, true);
        }
    });

    document.querySelectorAll('[data-delete-photo]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm(t('editor.confirm_photo'))) return;
            try {
                await api(`/api/family/me/photos/${button.dataset.deletePhoto}`, { method: 'DELETE' });
                await loadMe();
            } catch (error) {
                manageMessage(error.message, true);
            }
        });
    });

    $('#delete-me-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!confirm(t('editor.confirm_profile'))) return;
        try {
            await api('/api/family/me', { method: 'DELETE', body: formObject(event.currentTarget) });
            window.location.reload();
        } catch (error) {
            manageMessage(error.message, true);
        }
    });
}

function switchTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll('[data-tab]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.tab === tab);
    });
    $('#tree-view').hidden = !['tree', 'list'].includes(tab);
    $('#birthdays-view').hidden = tab !== 'birthdays';
    $('#gallery-view').hidden = tab !== 'gallery';
    $('#events-view').hidden = tab !== 'events';
    $('#me-view').hidden = tab !== 'me';
    $('#tree').hidden = tab === 'list';
    $('#people-list').hidden = tab !== 'list';
    document.querySelector('.tree-controls').hidden = tab !== 'tree';

    if (tab === 'birthdays') loadBirthdays();
    else if (tab === 'events') loadEvents();
    else if (tab === 'gallery') loadGallery(true);
    else if (tab === 'me') loadMe();
    else if (tab === 'list') {
        if (state.lastTreeData) renderList(state.lastTreeData);
        else loadTree();
    } else {
        if (state.lastTreeData) renderTree(state.lastTreeData);
        else loadTree();
    }
}

let navigationRequestPending = false;

async function syncMiniAppNavigation() {
    if (navigationRequestPending) return;

    navigationRequestPending = true;

    try {
        const { action } = await api('/api/family/navigation', { method: 'POST' });

        if (!action?.tab) return;

        if (Object.hasOwn(action, 'q')) {
            $('#filters [name="q"]').value = action.q ?? '';
        } else if (['tree', 'list'].includes(action.tab)) {
            $('#filters [name="q"]').value = '';
        }

        $('#filters [name="relation"]').value = action.relation ?? '';
        state.scope = action.scope ?? 'branch';
        if (action.tree_id && String(action.tree_id) !== String(state.treeId ?? '')) {
            state.treeSlug = null;
        }
        state.treeId = action.tree_id ? String(action.tree_id) : state.treeId;
        if (action.tree_name) {
            document.querySelector('.brand h1').textContent = action.tree_name;
        }
        state.focusId = action.focus ? String(action.focus) : null;
        state.pendingOpenPersonId = action.open_person
            ? String(action.open_person)
            : null;
        state.lastTreeData = null;
        switchTab(action.tab);
    } catch {
        // Авторизация и ошибки API уже показываются основными загрузчиками.
    } finally {
        navigationRequestPending = false;
    }
}

let debounce;
$('#filters').addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(loadTree, 280);
});
$('#filters').addEventListener('change', loadTree);
const filtersPanel = $('#filters');
const filterBackdrop = $('#filter-backdrop');
const openFiltersButton = $('#open-filters');
const setFiltersOpen = (open) => {
    filtersPanel.classList.toggle('is-open', open);
    filterBackdrop.hidden = !open;
    openFiltersButton.setAttribute('aria-expanded', String(open));
    if (open) {
        window.setTimeout(() => filtersPanel.querySelector('[name="q"]')?.focus(), 80);
    }
};
openFiltersButton.addEventListener('click', () => setFiltersOpen(true));
$('#close-filters').addEventListener('click', () => setFiltersOpen(false));
$('#apply-filters').addEventListener('click', () => setFiltersOpen(false));
filterBackdrop.addEventListener('click', () => setFiltersOpen(false));
$('#reset-filters').addEventListener('click', () => {
    $('#filters').reset();
    loadTree();
});
$('#fit-tree').addEventListener('click', () => state.cytoscape?.fit(undefined, 36));
$('#zoom-in').addEventListener('click', () => state.cytoscape?.zoom({
    level: Math.min((state.cytoscape?.zoom() ?? 1) * 1.25, 2.5),
    renderedPosition: { x: window.innerWidth / 2, y: window.innerHeight / 2 },
}));
$('#zoom-out').addEventListener('click', () => state.cytoscape?.zoom({
    level: Math.max((state.cytoscape?.zoom() ?? 1) / 1.25, 0.25),
    renderedPosition: { x: window.innerWidth / 2, y: window.innerHeight / 2 },
}));
$('#my-branch').addEventListener('click', () => {
    state.scope = 'branch';
    state.focusId = null;
    $('#filters [name="q"]').value = '';
    loadTree();
});
$('#all-tree').addEventListener('click', () => {
    state.scope = 'all';
    $('#filters [name="q"]').value = '';
    loadTree();
});
$('#gallery-more').addEventListener('click', () => loadGallery(false));
document.addEventListener('error', (event) => {
    if (!(event.target instanceof HTMLImageElement)) return;
    const fallback = event.target.dataset.fallback ?? '/images/person-placeholder.svg';
    if (event.target.src.endsWith(fallback)) return;
    event.target.src = fallback;
}, true);
$('#close-person').addEventListener('click', () => { $('#person-sheet').hidden = true; });
$('#person-sheet').addEventListener('click', (event) => {
    if (event.target.id === 'person-sheet') event.currentTarget.hidden = true;
});
$('#close-photo-viewer').addEventListener('click', () => { $('#photo-viewer').hidden = true; });
$('#photo-viewer-prev').addEventListener('click', () => movePhotoViewer(-1));
$('#photo-viewer-next').addEventListener('click', () => movePhotoViewer(1));
$('#photo-viewer').addEventListener('click', (event) => {
    if (event.target.id === 'photo-viewer') event.currentTarget.hidden = true;
});
$('#photo-viewer').addEventListener('touchstart', (event) => {
    const touch = event.changedTouches[0];
    state.photoViewerTouchStartX = touch?.clientX ?? null;
    state.photoViewerTouchStartY = touch?.clientY ?? null;
}, { passive: true });
$('#photo-viewer').addEventListener('touchend', (event) => {
    if (state.photoViewerTouchStartX === null || state.photoViewerTouchStartY === null) return;

    const touch = event.changedTouches[0];
    const deltaX = (touch?.clientX ?? state.photoViewerTouchStartX) - state.photoViewerTouchStartX;
    const deltaY = (touch?.clientY ?? state.photoViewerTouchStartY) - state.photoViewerTouchStartY;
    state.photoViewerTouchStartX = null;
    state.photoViewerTouchStartY = null;

    if (Math.abs(deltaX) < 45 || Math.abs(deltaX) < Math.abs(deltaY) * 1.2) return;
    movePhotoViewer(deltaX < 0 ? 1 : -1);
}, { passive: true });
document.addEventListener('keydown', (event) => {
    if ($('#photo-viewer').hidden) return;

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        movePhotoViewer(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        movePhotoViewer(1);
    } else if (event.key === 'Escape') {
        $('#photo-viewer').hidden = true;
    }
});
$('#report-issue-button').addEventListener('click', () => {
    $('#report-issue-modal').hidden = false;
});
document.querySelector('.report-issue-close').addEventListener('click', () => {
    $('#report-issue-modal').hidden = true;
});
document.querySelector('.congratulation-close').addEventListener('click', () => {
    $('#congratulation-modal').hidden = true;
});
$('#congratulation-calendar').addEventListener('click', (event) => {
    downloadCalendarEvent({
        date: event.currentTarget.dataset.date,
        title: event.currentTarget.dataset.title,
    });
});
$('#congratulation-modal').addEventListener('click', (event) => {
    if (event.target.id === 'congratulation-modal') event.currentTarget.hidden = true;
});
$('#congratulation-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const payload = formObject(form);
    payload.person_id = payload.person_id || null;
    payload.partnership_id = payload.partnership_id || null;
    try {
        const result = await api('/api/family/congratulations', {
            method: 'POST',
            body: payload,
        });
        const telegramDelivered = result.deliveries?.filter((item) => item.telegram === 'delivered').length ?? 0;
        $('#congratulation-message').textContent = telegramDelivered
            ? t('sent_telegram', { count: telegramDelivered })
            : t('saved_site');
        setTimeout(() => { $('#congratulation-modal').hidden = true; }, 1800);
    } catch (error) {
        $('#congratulation-message').textContent = error.message;
    }
});
$('#report-issue-modal').addEventListener('click', (event) => {
    if (event.target.id === 'report-issue-modal') event.currentTarget.hidden = true;
});
$('#report-issue-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const message = $('#report-issue-message');
    message.textContent = t('sending');

    try {
        const result = await api('/api/family/issues', {
            method: 'POST',
            body: formObject(form),
        });
        message.textContent = result.message;
        form.reset();
        window.setTimeout(() => { $('#report-issue-modal').hidden = true; }, 1300);
    } catch (error) {
        message.textContent = error.message;
    }
});
document.querySelectorAll('[data-tab]').forEach((button) => {
    button.addEventListener('click', () => switchTab(button.dataset.tab));
});

if (window.familyAppConfig?.authError) {
    showError(window.familyAppConfig.authError);
}

if (window.familyAppConfig?.loginError) {
    $('#auth-error').textContent = window.familyAppConfig.loginError;
    $('#auth-panel').hidden = false;
}

if (startAction?.relation || initialParams.get('relation')) {
    $('#filters [name="relation"]').value = startAction?.relation ?? initialParams.get('relation');
}

switchTab(state.activeTab);

if (telegramInitData) {
    telegram.onEvent?.('activated', syncMiniAppNavigation);
    window.addEventListener('focus', syncMiniAppNavigation);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) syncMiniAppNavigation();
    });
    window.setInterval(syncMiniAppNavigation, 2500);
    syncMiniAppNavigation();
}
