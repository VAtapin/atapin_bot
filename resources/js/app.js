import cytoscape from 'cytoscape';
import dagre from 'cytoscape-dagre';
import '../css/app.css';

cytoscape.use(dagre);

const telegram = window.Telegram?.WebApp;
const initialParams = new URLSearchParams(window.location.search);
const requestedTab = initialParams.get('tab');
const state = {
    cytoscape: null,
    people: new Map(),
    activeTab: ['tree', 'list', 'gallery', 'birthdays', 'me'].includes(requestedTab)
        ? requestedTab
        : 'tree',
    scope: 'branch',
    lastTreeData: null,
    galleryPhotos: new Map(),
    focusId: window.familyAppConfig?.focusId
        ?? initialParams.get('focus'),
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

async function api(path, options = {}) {
    const body = options.body;
    const headers = {
        Accept: 'application/json',
        'X-Telegram-Init-Data': telegram?.initData ?? '',
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
        const error = new Error(validationMessage || data.message || 'Не удалось загрузить данные');
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

    const nodes = data.people.map((person) => ({
        data: {
            id: String(person.id),
            label: person.life_years ? `${person.name}\n${person.life_years}` : person.name,
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
                    width: 190,
                    height: 88,
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
                    'text-max-width': 145,
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
                    'background-position-x': '7%',
                    'background-position-y': '50%',
                    'background-clip': 'node',
                    'text-halign': 'right',
                    'text-margin-x': -10,
                    'text-max-width': 105,
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
            rankSep: 115,
            nodeSep: 58,
            edgeSep: 18,
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

    if (state.scope === 'all' && focusNode?.length) {
        state.cytoscape.zoom(0.8);
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
                ${person.photo_url
                    ? `<img src="${escapeHtml(person.photo_url)}" alt="">`
                    : `<span class="person-row-avatar">${escapeHtml(person.name.slice(0, 1))}</span>`}
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

function formatDate(value) {
    if (!value) return '';
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
        ? `<img class="person-photo" src="${escapeHtml(person.photo_url)}" alt="">`
        : `<div class="person-photo person-photo--empty">${escapeHtml(person.name.slice(0, 1))}</div>`;
    const facts = [
        person.birth_date && ['Дата рождения', formatDate(person.birth_date)],
        person.death_date && ['Дата смерти', formatDate(person.death_date)],
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
        ${photos.length > 1 ? `
            <section class="person-photo-strip">
                <h3>Фотографии</h3>
                <div>${photos.map((item) => `<img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.title || person.name)}">`).join('')}</div>
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
        state.lastTreeData = data;
        state.focusId = data.focus_id;
        state.people = new Map(data.people.map((person) => [String(person.id), person]));
        if (state.activeTab === 'tree') renderTree(data);
        renderList(data);
        fillCities(data.filters.cities);
        $('#tree-meta').textContent = `Показано ${data.shown_people} из ${data.total_people}`;
    } catch (error) {
        showError(error.message, error.payload);
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
        showError(error.message, error.payload);
    } finally {
        setLoading(false);
    }
}

async function loadGallery() {
    setLoading(true);

    try {
        const data = await api('/api/family/gallery');
        state.galleryPhotos = new Map(data.photos.map((photo) => [String(photo.id), photo]));
        $('#gallery-grid').innerHTML = data.photos.length
            ? data.photos.map((photo) => `
                <button class="gallery-item" type="button" data-photo-id="${photo.id}">
                    <img src="${escapeHtml(photo.url)}" alt="${escapeHtml(photo.person_name)}" loading="lazy">
                    <span>
                        <strong>${escapeHtml(photo.person_name)}</strong>
                        ${photo.taken_at ? `<small>${escapeHtml(formatDate(photo.taken_at))}</small>` : ''}
                    </span>
                </button>
            `).join('')
            : '<p class="empty-list">Фотографий пока нет.</p>';
        $('#gallery-grid').querySelectorAll('[data-photo-id]').forEach((item) => {
            item.addEventListener('click', async () => {
                const photo = state.galleryPhotos.get(String(item.dataset.photoId));

                if (!photo?.is_associated) {
                    showStandalonePhoto(photo);
                    return;
                }

                state.focusId = photo.person_id;
                state.scope = 'branch';
                await loadTree();
                showPerson(photo.person_id);
            });
        });
    } catch (error) {
        showError(error.message, error.payload);
    } finally {
        setLoading(false);
    }
}

function showStandalonePhoto(photo) {
    if (!photo) return;

    $('#person-content').innerHTML = `
        <div class="standalone-photo">
            <img src="${escapeHtml(photo.url)}" alt="${escapeHtml(photo.person_name)}">
            <h2>${escapeHtml(photo.person_name)}</h2>
            ${photo.description ? `<p>${escapeHtml(photo.description)}</p>` : ''}
            <small>Фотография пока не привязана к человеку в семейном древе.</small>
        </div>
    `;
    $('#person-sheet').hidden = false;
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
    ];

    $('#me-content').innerHTML = `
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
            <h2>Супруги и дети</h2>
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
                <summary>＋ Добавить супруга или ребёнка</summary>
                <form id="relative-add-form" class="manage-form">
                    <label><span>Кого добавляем</span><select name="kind">
                        <option value="spouse">Супруга / супругу</option>
                        <option value="child">Ребёнка</option>
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
        <p id="manage-message" class="manage-message"></p>
    `;

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
    $('#me-view').hidden = tab !== 'me';
    $('#tree').hidden = tab === 'list';
    $('#people-list').hidden = tab !== 'list';
    document.querySelector('.tree-controls').hidden = tab !== 'tree';

    if (tab === 'birthdays') loadBirthdays();
    else if (tab === 'gallery') loadGallery();
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
        state.focusId = action.focus ? String(action.focus) : null;
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
$('#close-person').addEventListener('click', () => { $('#person-sheet').hidden = true; });
$('#person-sheet').addEventListener('click', (event) => {
    if (event.target.id === 'person-sheet') event.currentTarget.hidden = true;
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

if (initialParams.get('relation')) {
    $('#filters [name="relation"]').value = initialParams.get('relation');
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
