import cytoscape from 'cytoscape';
import bridge from '@vkontakte/vk-bridge';
import { calculateFamilyTreePositions } from './family-tree-layout.js';
import '../css/app.css';

const mourningRibbonImage = `data:image/svg+xml;utf8,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M10 43L43 10" stroke="#171717" stroke-width="11" stroke-linecap="square"/></svg>',
)}`;
const telegram = window.Telegram?.WebApp;
const initialParams = new URLSearchParams(window.location.search);
const vkLaunchParams = initialParams.has('vk_user_id') && initialParams.has('sign')
    ? initialParams.toString()
    : '';
const startAction = parseMiniAppStartParameter(
    telegram?.initDataUnsafe?.start_param
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
    bridge.send('VKWebAppInit').catch(() => {
        // В обычном браузере VK Bridge ожидаемо недоступен.
    });
}

async function api(path, options = {}) {
    const body = options.body;
    const headers = {
        Accept: 'application/json',
        'X-Telegram-Init-Data': telegram?.initData ?? '',
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
            ? 'Ошибка сервера. Обновите страницу или попробуйте ещё раз немного позже.'
            : data.message;
        const error = new Error(validationMessage || serverMessage || 'Не удалось загрузить данные');
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
        ? `<a class="telegram-login" href="${escapeHtml(payload.login_url)}">Войти через Telegram</a>`
        : '';
    $('#error').hidden = false;
    setLoading(false);
}

function personElements(data) {
    state.people = new Map(data.people.map((person) => [String(person.id), person]));
    const positions = calculateFamilyTreePositions(data);

    const nodes = data.people.map((person) => ({
        data: {
            id: String(person.id),
            label: `${person.name}${treeDateLabel(person) ? `\n${treeDateLabel(person)}` : ''}`,
            years: person.life_years || '',
            photo: person.photo_url || '',
            gender: person.gender,
        },
        classes: [
            'person',
            person.photo_url ? 'has-photo' : '',
            person.death_date ? 'deceased' : '',
        ].filter(Boolean).join(' '),
        position: positions.get(String(person.id)),
    }));
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
                position: { x: position.x + 92, y: position.y + 34 },
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
    for (const [childId, parents] of parentsByChild) {
        const pairKey = [...parents].sort().join('-');
        const unionId = parents.length === 2 ? unionByPair.get(pairKey) : null;

        if (unionId) {
            parentEdges.push({
                data: {
                    id: `parent-${unionId}-${childId}`,
                    source: unionId,
                    target: childId,
                    kind: 'parent',
                },
            });
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

    return [...nodes, ...mourningNodes, ...unionNodes, ...parentEdges, ...partnershipEdges];
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
    if (birth) return `р. ${birth}`;
    return '';
}

function renderTree(data) {
    state.cytoscape?.destroy();
    $('#empty').hidden = data.people.length > 0;

    state.cytoscape = cytoscape({
        container: $('#tree'),
        elements: personElements(data),
        minZoom: 0.25,
        maxZoom: 2.5,
        wheelSensitivity: 0.2,
        style: [
            {
                selector: 'node.person',
                style: {
                    width: 220,
                    height: 96,
                    shape: 'round-rectangle',
                    'background-color': '#fffdf8',
                    'border-color': '#d7cbb9',
                    'border-width': 1.5,
                    label: 'data(label)',
                    color: '#2b251e',
                    'font-family': 'system-ui, sans-serif',
                    'font-size': 14,
                    'font-weight': 700,
                    'text-wrap': 'wrap',
                    'text-max-width': 178,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'text-margin-x': 0,
                    'overlay-opacity': 0,
                    'shadow-blur': 14,
                    'shadow-color': '#6f6049',
                    'shadow-opacity': 0.12,
                    'shadow-offset-y': 4,
                },
            },
            {
                selector: 'node.person.has-photo',
                style: {
                    'background-image': 'data(photo)',
                    'background-fit': 'none',
                    'background-width': 68,
                    'background-height': 68,
                    'background-position-x': '9%',
                    'background-position-y': '50%',
                    'background-clip': 'node',
                    'text-halign': 'center',
                    'text-margin-x': 39,
                    'text-max-width': 126,
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
                selector: 'node.mourning-ribbon',
                style: {
                    width: 44,
                    height: 44,
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
                selector: 'edge[kind = "parent"]',
                style: {
                    width: 1.6,
                    'line-color': '#9b8f7e',
                    'target-arrow-color': '#9b8f7e',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'taxi',
                    'taxi-direction': 'downward',
                    'taxi-turn': 24,
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
    const focusNode = data.focus_id ? state.cytoscape.getElementById(String(data.focus_id)) : null;

    state.cytoscape.fit(undefined, 48);

    if (state.cytoscape.zoom() < 0.68 && focusNode?.length) {
        state.cytoscape.zoom(0.68);
        state.cytoscape.center(focusNode);
    }

}

const relationLabels = {
    self: 'Это вы',
    parents: 'Родитель',
    grandparents: 'Бабушка / дедушка',
    spouses: 'Супруг / супруга',
    children: 'Ребёнок',
    grandchildren: 'Внук / внучка',
    siblings: 'Брат / сестра',
    nephews: 'Племянник / племянница',
};

function renderList(data) {
    const list = $('#people-list');
    list.innerHTML = data.people.length
        ? data.people.map((person) => `
            <button class="person-row" type="button" data-person-id="${person.id}">
                <span class="person-avatar-wrap ${person.death_date ? 'is-deceased' : ''}">
                    ${person.photo_url
                        ? `<img src="${escapeHtml(person.photo_url)}" alt="">`
                        : `<span class="person-row-avatar">${escapeHtml(person.name.slice(0, 1))}</span>`}
                </span>
                <span class="person-row-main">
                    <strong>${escapeHtml(person.name)}</strong>
                    <small>${escapeHtml(relationLabels[person.relation] || person.life_years || 'Родственник')}</small>
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
        `).join('')
        : '<p class="empty-list">По выбранному фильтру никого нет.</p>';
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
        person.birth_date && ['Дата рождения', formatDate(person.birth_date, person.birth_date_precision)],
        person.death_date && ['Дата смерти', formatDate(person.death_date, person.death_date_precision)],
        person.life_years && ['Годы жизни', person.life_years],
        person.maiden_name && ['Девичья фамилия', person.maiden_name],
        person.birth_place && ['Место рождения', person.birth_place],
        person.death_place && ['Место смерти', person.death_place],
        person.burial_place && ['Место захоронения', person.burial_place],
        person.city && ['Город', person.city],
        person.address && ['Адрес', person.address],
        person.occupation && ['Род занятий', person.occupation],
    ].filter(Boolean);
    const relatives = [
        ['Родители', person.relatives?.parents],
        ['Супруги и партнёры', person.relatives?.spouses],
        ['Дети', person.relatives?.children],
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
                <h3>Фотографии</h3>
                <div>${photos.map((item, index) => `
                    <button type="button" data-person-photo-index="${index}">
                        <img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.title || person.name)}">
                    </button>
                `).join('')}</div>
            </section>
        ` : ''}
        ${person.bio ? `<p class="person-bio">${escapeHtml(person.bio)}</p>` : ''}
        <button id="person-focus" class="person-focus" type="button">Показать семейную ветвь</button>
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
    $('#person-content').querySelector('[data-view-main-photo]')?.addEventListener('click', () => {
        showPhotoViewer({
            url: person.photo_url,
            title: person.name,
            person_id: person.id,
            person_name: person.name,
        });
    });
    $('#person-content').querySelectorAll('[data-person-photo-index]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = photos[Number(button.dataset.personPhotoIndex)];
            showPhotoViewer({
                ...item,
                person_id: person.id,
                person_name: person.name,
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
            throw new Error('Сервер вернул данные другого семейного дерева. Обновите страницу.');
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
                ? `Дни рождения · ${data.viewer.unread_congratulations}`
                : 'Дни рождения';
        }
        $('#my-branch').disabled = !data.viewer?.has_person;
        $('#filters [name="relation"]').disabled = !data.viewer?.has_person;
        if (state.activeTab === 'tree') renderTree(data);
        renderList(data);
        fillCities(data.filters.cities);
        $('#tree-meta').textContent = `Показано ${data.shown_people} из ${data.total_people}`;

        if (state.pendingOpenPersonId && state.people.has(String(state.pendingOpenPersonId))) {
            const personId = state.pendingOpenPersonId;
            state.pendingOpenPersonId = null;
            showPerson(personId);
        }
    } catch (error) {
        if (state.lastTreeData) {
            $('#tree-meta').textContent = 'Не удалось обновить данные. Показана последняя загруженная версия.';
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
    const options = ['<option value="">Все места</option>'];

    for (const city of cities) {
        options.push(`<option value="${escapeHtml(city)}">${escapeHtml(city)}</option>`);
    }

    select.innerHTML = options.join('');
    select.value = current;
}

async function loadBirthdays() {
    setLoading(true);

    try {
        const data = await api('/api/family/birthdays');
        const birthdayTab = document.querySelector('[data-tab="birthdays"]');
        if (birthdayTab) birthdayTab.textContent = 'Дни рождения';
        $('#birthday-list').innerHTML = data.birthdays.length
            ? data.birthdays.map((item) => `
                <article class="birthday-card">
                    <img src="${escapeHtml(item.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="" loading="lazy">
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${escapeHtml(new Date(`${item.date}T12:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }))} · ${item.age} лет</span>
                    </div>
                    <div class="birthday-side">
                        <em>${item.days === 0 ? 'сегодня' : `через ${item.days} дн.`}</em>
                        ${String(item.id) !== String(data.viewer_person_id ?? '')
                            ? `<button class="congratulate-button" type="button" data-occasion="birthday" data-person-id="${item.id}" data-recipient="${escapeHtml(item.name)}">Поздравить</button>`
                            : ''}
                    </div>
                </article>
            `).join('')
            : '<p class="empty-list">Дни рождения пока не добавлены.</p>';
        $('#anniversary-list').innerHTML = data.anniversaries?.length
            ? `<h3>Годовщины</h3>${data.anniversaries.map((item) => `
                <article class="birthday-card anniversary-card">
                    <span class="couple-avatar">
                        <img src="${escapeHtml(item.partner_one.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="">
                        <img src="${escapeHtml(item.partner_two.photo_url)}" data-fallback="/images/person-placeholder.svg" alt="">
                    </span>
                    <div>
                        <strong>${escapeHtml(item.title)}</strong>
                        <span>${escapeHtml(new Date(`${item.date}T12:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }))} · ${item.years} лет</span>
                    </div>
                    <div class="birthday-side">
                        <em>${item.days === 0 ? 'сегодня' : `через ${item.days} дн.`}</em>
                        <button class="congratulate-button" type="button" data-occasion="anniversary" data-partnership-id="${item.id}" data-recipient="${escapeHtml(item.title)}">Поздравить</button>
                    </div>
                </article>
            `).join('')}`
            : '';
        $('#congratulation-inbox').innerHTML = data.congratulations?.length
            ? `<h3>Полученные поздравления</h3>${data.congratulations.map((item) => `
                <article class="congratulation-card">
                    <strong>${escapeHtml(item.from)}</strong>
                    <p>${escapeHtml(item.message)}</p>
                    <small>${escapeHtml(new Date(item.created_at).toLocaleString('ru-RU'))}</small>
                </article>
            `).join('')}`
            : '';
        document.querySelectorAll('.congratulate-button').forEach((button) => {
            button.addEventListener('click', () => {
                const form = $('#congratulation-form');
                form.reset();
                form.elements.occasion.value = button.dataset.occasion;
                form.elements.person_id.value = button.dataset.personId ?? '';
                form.elements.partnership_id.value = button.dataset.partnershipId ?? '';
                form.elements.message.value = button.dataset.occasion === 'birthday'
                    ? 'С днём рождения! Желаю здоровья, радости и семейного тепла!'
                    : 'Поздравляю с годовщиной! Желаю любви, согласия и ещё многих счастливых лет вместе!';
                $('#congratulation-recipient').textContent = button.dataset.recipient ?? '';
                $('#congratulation-message').textContent = '';
                $('#congratulation-modal').hidden = false;
            });
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
            : (reset ? '<p class="empty-list">Фотографий пока нет.</p>' : '');
        $('#gallery-grid').insertAdjacentHTML('beforeend', markup);
        $('#gallery-grid').querySelectorAll('[data-photo-id]:not([data-bound])').forEach((item) => {
            item.dataset.bound = '1';
            item.addEventListener('click', () => {
                const photo = state.galleryPhotos.get(String(item.dataset.photoId));
                showPhotoViewer(photo);
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
                ${item.annual ? '<em>ежегодно</em>' : ''}
            </article>
        `).join('')
        : '<p class="empty-list">Событий пока нет.</p>';
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

function showPhotoViewer(photo) {
    if (!photo) return;

    $('#photo-viewer-image').src = photo.url;
    $('#photo-viewer-image').alt = photo.title || photo.person_name || 'Семейная фотография';
    $('#photo-viewer-caption').innerHTML = `
        ${(photo.title || photo.person_name)
            ? `<strong>${escapeHtml(photo.title || photo.person_name)}</strong>`
            : ''}
        ${photo.description ? `<p>${escapeHtml(photo.description)}</p>` : ''}
        ${photo.taken_at ? `<small>${escapeHtml(formatDate(photo.taken_at))}</small>` : ''}
        ${photo.person_id ? `
            <button type="button" data-viewer-person="${photo.person_id}">
                Открыть карточку ${escapeHtml(photo.person_name || 'человека')}
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
    $('#photo-viewer').hidden = false;
}

function field(name, label, value = '', type = 'text') {
    return `<label><span>${escapeHtml(label)}</span><input name="${name}" type="${type}" value="${escapeHtml(value ?? '')}"></label>`;
}

function personEditFields(person) {
    return `
        ${field('last_name', 'Фамилия', person.last_name)}
        ${field('first_name', 'Имя', person.first_name)}
        ${field('middle_name', 'Отчество', person.middle_name)}
        ${field('maiden_name', 'Девичья фамилия', person.maiden_name)}
        <label><span>Пол</span><select name="gender">
            <option value="unknown" ${person.gender === 'unknown' ? 'selected' : ''}>Не указан</option>
            <option value="male" ${person.gender === 'male' ? 'selected' : ''}>Мужской</option>
            <option value="female" ${person.gender === 'female' ? 'selected' : ''}>Женский</option>
        </select></label>
        ${field('birth_date', 'Дата рождения', person.birth_date, 'date')}
        ${field('death_date', 'Дата смерти', person.death_date, 'date')}
        ${field('birth_place', 'Место рождения', person.birth_place)}
        ${field('current_city', 'Город проживания', person.current_city)}
        ${field('occupation', 'Род занятий', person.occupation)}
        <label class="wide"><span>Биография</span><textarea name="bio">${escapeHtml(person.bio ?? '')}</textarea></label>
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
        ...data.relatives.spouses.map((item) => ({ ...item, kind: 'Супруг / супруга' })),
        ...data.relatives.children.map((item) => ({ ...item, kind: 'Ребёнок' })),
        ...data.relatives.grandchildren.map((item) => ({ ...item, kind: 'Внук / внучка' })),
        ...data.relatives.child_spouses.map((item) => ({ ...item, kind: 'Зять / невестка' })),
    ];

    $('#me-content').innerHTML = `
        ${data.can_edit ? '' : '<div class="manage-card readonly-notice">У вас гостевой доступ. Данные доступны только для просмотра.</div>'}
        <section class="manage-card">
            <div class="manage-heading">
                ${person.photo_url ? `<img src="${escapeHtml(person.photo_url)}" alt="">` : '<span>👤</span>'}
                <div><h2>${escapeHtml(person.name)}</h2><p>Ваша карточка в семейном архиве</p></div>
            </div>
            <form id="profile-form" class="manage-form">
                ${personEditFields(person)}
                <button type="submit">Сохранить мои данные</button>
            </form>
        </section>

        <section class="manage-card">
            <h2>Моя семейная ветвь</h2>
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
                                <button type="submit">Сохранить</button>
                                <button type="button" class="danger unlink-relative">Удалить связь</button>
                            </div>
                        </form>
                    </details>
                `).join('') : '<p class="empty-list">Пока никого не добавлено.</p>'}
            </div>
            <details class="add-box">
                <summary>＋ Добавить родственника</summary>
                <form id="relative-add-form" class="manage-form">
                    <label><span>Кого добавляем</span><select name="kind">
                        <option value="spouse">Супруга / супругу</option>
                        <option value="child">Ребёнка</option>
                        <option value="grandchild">Внука / внучку</option>
                        <option value="child_spouse">Зятя / невестку</option>
                    </select></label>
                    <label><span>Через кого из детей (для внука, зятя или невестки)</span><select name="related_person_id">
                        <option value="">Не требуется</option>
                        ${data.relatives.children.map((child) => `<option value="${child.id}">${escapeHtml(child.name)}</option>`).join('')}
                    </select></label>
                    ${personEditFields({ gender: 'unknown' })}
                    <button type="submit">Добавить в дерево</button>
                </form>
            </details>
        </section>

        <section class="manage-card">
            <h2>Фотоальбомы</h2>
            <div class="album-list">
                ${data.albums.map((album) => `
                    <span>${escapeHtml(album.title)} · ${album.photos_count}
                        <button type="button" data-delete-album="${album.id}" aria-label="Удалить">×</button>
                    </span>
                `).join('') || '<p class="empty-list">Альбомов пока нет.</p>'}
            </div>
            <form id="album-form" class="inline-form">
                <input name="title" placeholder="Название нового альбома" required>
                <button type="submit">Создать</button>
            </form>
        </section>

        <section class="manage-card">
            <h2>Мои фотографии</h2>
            <form id="photo-form" class="photo-upload-form">
                <input name="photo" type="file" accept="image/*" required>
                <input name="title" placeholder="Подпись к фотографии">
                <select name="photo_album_id">
                    <option value="">Без альбома</option>
                    ${data.albums.map((album) => `<option value="${album.id}">${escapeHtml(album.title)}</option>`).join('')}
                </select>
                <label class="check"><input name="is_primary" type="checkbox" value="1"> Сделать основной</label>
                <button type="submit">Загрузить фотографию</button>
            </form>
            <div class="my-photo-grid">
                ${data.photos.map((photo) => `
                    <figure>
                        <img src="${escapeHtml(photo.url)}" alt="">
                        ${photo.is_primary ? '<b>Основная</b>' : ''}
                        <button type="button" data-delete-photo="${photo.id}" aria-label="Удалить">×</button>
                    </figure>
                `).join('') || '<p class="empty-list">Загрузите первую фотографию.</p>'}
            </div>
        </section>

        <details class="manage-card danger-zone">
            <summary>Удаление моей карточки</summary>
            <p>Карточка будет скрыта, а привязка Telegram снята. Введите слово «УДАЛИТЬ».</p>
            <form id="delete-me-form" class="inline-form">
                <input name="confirmation" required>
                <button class="danger" type="submit">Удалить мою карточку</button>
            </form>
        </details>
        <details class="manage-card privacy-zone">
            <summary>Мои персональные данные</summary>
            <p>Можно скачать все данные своей учётной записи или полностью удалить аккаунт.</p>
            <div class="form-actions">
                <button id="privacy-export" type="button">Скачать мои данные</button>
            </div>
            <form id="delete-account-form" class="inline-form">
                <input name="confirmation" placeholder="УДАЛИТЬ АККАУНТ" required>
                <button class="danger" type="submit">Удалить аккаунт</button>
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
            if (!confirm('Удалить семейную связь? Карточка человека останется в архиве.')) return;
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
            if (!confirm('Удалить альбом? Фотографии останутся.')) return;
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
            if (!confirm('Удалить фотографию?')) return;
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
        if (!confirm('Это действие действительно удалит вашу карточку. Продолжить?')) return;
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
$('#photo-viewer').addEventListener('click', (event) => {
    if (event.target.id === 'photo-viewer') event.currentTarget.hidden = true;
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
            ? `Сохранено на сайте и отправлено в Telegram: ${telegramDelivered}.`
            : 'Сохранено на семейном сайте. Telegram у получателя не подключён или недоступен.';
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
    message.textContent = 'Отправляем…';

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

if (telegram?.initData) {
    telegram.onEvent?.('activated', syncMiniAppNavigation);
    window.addEventListener('focus', syncMiniAppNavigation);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) syncMiniAppNavigation();
    });
    window.setInterval(syncMiniAppNavigation, 2500);
    syncMiniAppNavigation();
}
