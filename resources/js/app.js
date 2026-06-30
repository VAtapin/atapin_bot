import cytoscape from 'cytoscape';
import dagre from 'cytoscape-dagre';
import '../css/app.css';

cytoscape.use(dagre);

const telegram = window.Telegram?.WebApp;
const state = {
    cytoscape: null,
    people: new Map(),
    activeTab: 'tree',
};

const $ = (selector) => document.querySelector(selector);
const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

try {
    telegram?.ready();
    telegram?.expand();
    telegram?.setHeaderColor?.('secondary_bg_color');
} catch {
    // The official script exposes a limited object outside the Telegram client.
}

async function api(path) {
    const response = await fetch(path, {
        headers: {
            Accept: 'application/json',
            'X-Telegram-Init-Data': telegram?.initData ?? '',
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.message || 'Не удалось загрузить данные');
    }

    return data;
}

function setLoading(isLoading) {
    $('#loading').hidden = !isLoading;
}

function showError(message) {
    $('#error-message').textContent = message;
    $('#error').hidden = false;
    setLoading(false);
}

function personElements(data) {
    state.people = new Map(data.people.map((person) => [String(person.id), person]));

    const nodes = data.people.map((person) => ({
        data: {
            id: String(person.id),
            label: person.name,
            years: person.life_years || '',
            photo: person.photo_url || '',
            gender: person.gender,
        },
        classes: person.photo_url ? 'person has-photo' : 'person',
    }));

    const unionByPair = new Map();
    const unionNodes = [];
    const partnershipEdges = [];

    for (const link of data.partnerships) {
        const partners = [String(link.partner_one_id), String(link.partner_two_id)].sort();
        const pairKey = partners.join('-');
        const unionId = `union-${pairKey}`;
        unionByPair.set(pairKey, unionId);
        unionNodes.push({
            data: { id: unionId, label: '♥', kind: 'union' },
            classes: 'union',
        });
        partners.forEach((partnerId) => partnershipEdges.push({
            data: {
                id: `partner-${partnerId}-${unionId}`,
                source: partnerId,
                target: unionId,
                kind: 'partner',
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

    return [...nodes, ...unionNodes, ...parentEdges, ...partnershipEdges];
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
                    width: 150,
                    height: 66,
                    shape: 'round-rectangle',
                    'background-color': '#fffdf8',
                    'border-color': '#d7cbb9',
                    'border-width': 1.5,
                    label: 'data(label)',
                    color: '#2b251e',
                    'font-family': 'system-ui, sans-serif',
                    'font-size': 11,
                    'font-weight': 650,
                    'text-wrap': 'wrap',
                    'text-max-width': 105,
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
                    'background-fit': 'cover',
                    'background-width': 50,
                    'background-height': 50,
                    'background-position-x': 9,
                    'background-position-y': '50%',
                    'background-clip': 'none',
                    'text-halign': 'center',
                    'text-margin-x': 24,
                },
            },
            {
                selector: 'node.person[gender = "female"]',
                style: { 'border-color': '#d7a7ad' },
            },
            {
                selector: 'node.person[gender = "male"]',
                style: { 'border-color': '#9eb8c2' },
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
                selector: 'node.person:selected',
                style: {
                    'border-width': 3,
                    'border-color': '#6d7651',
                },
            },
        ],
        layout: {
            name: 'dagre',
            rankDir: 'TB',
            rankSep: 80,
            nodeSep: 34,
            edgeSep: 18,
            padding: 45,
        },
    });

    state.cytoscape.on('tap', 'node.person', (event) => showPerson(event.target.id()));
    state.cytoscape.fit(undefined, 36);
}

function showPerson(id) {
    const person = state.people.get(String(id));

    if (!person) return;

    const photo = person.photo_url
        ? `<img class="person-photo" src="${escapeHtml(person.photo_url)}" alt="">`
        : `<div class="person-photo person-photo--empty">${escapeHtml(person.name.slice(0, 1))}</div>`;
    const facts = [
        person.life_years && ['Годы жизни', person.life_years],
        person.maiden_name && ['Девичья фамилия', person.maiden_name],
        person.birth_place && ['Место рождения', person.birth_place],
        person.city && ['Город', person.city],
        person.occupation && ['Род занятий', person.occupation],
    ].filter(Boolean);

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
        ${person.bio ? `<p class="person-bio">${escapeHtml(person.bio)}</p>` : ''}
    `;
    $('#person-sheet').hidden = false;
}

function treeQuery() {
    const form = new FormData($('#filters'));
    const query = new URLSearchParams();

    for (const [key, value] of form.entries()) {
        if (value !== '') query.set(key, value);
    }

    return query.toString();
}

async function loadTree() {
    setLoading(true);
    $('#error').hidden = true;

    try {
        const data = await api(`/api/family/tree?${treeQuery()}`);
        renderTree(data);
        fillCities(data.filters.cities);
    } catch (error) {
        showError(error.message);
    } finally {
        setLoading(false);
    }
}

function fillCities(cities) {
    const select = $('#city');
    const current = select.value;
    const options = ['<option value="">Все города</option>'];

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
        $('#birthday-list').innerHTML = data.birthdays.length
            ? data.birthdays.map((item) => `
                <article class="birthday-card">
                    ${item.photo_url
                        ? `<img src="${escapeHtml(item.photo_url)}" alt="">`
                        : `<span class="birthday-avatar">${escapeHtml(item.name.slice(0, 1))}</span>`}
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <span>${escapeHtml(new Date(`${item.date}T12:00:00`).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }))} · ${item.age} лет</span>
                    </div>
                    <em>${item.days === 0 ? 'сегодня' : `через ${item.days} дн.`}</em>
                </article>
            `).join('')
            : '<p class="empty-list">Дни рождения пока не добавлены.</p>';
    } catch (error) {
        showError(error.message);
    } finally {
        setLoading(false);
    }
}

function switchTab(tab) {
    state.activeTab = tab;
    document.querySelectorAll('[data-tab]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.tab === tab);
    });
    $('#tree-view').hidden = tab !== 'tree';
    $('#birthdays-view').hidden = tab !== 'birthdays';

    if (tab === 'birthdays') loadBirthdays();
    else state.cytoscape?.resize();
}

let debounce;
$('#filters').addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(loadTree, 280);
});
$('#filters').addEventListener('change', loadTree);
$('#reset-filters').addEventListener('click', () => {
    $('#filters').reset();
    loadTree();
});
$('#fit-tree').addEventListener('click', () => state.cytoscape?.fit(undefined, 36));
$('#close-person').addEventListener('click', () => { $('#person-sheet').hidden = true; });
$('#person-sheet').addEventListener('click', (event) => {
    if (event.target.id === 'person-sheet') event.currentTarget.hidden = true;
});
document.querySelectorAll('[data-tab]').forEach((button) => {
    button.addEventListener('click', () => switchTab(button.dataset.tab));
});

loadTree();
