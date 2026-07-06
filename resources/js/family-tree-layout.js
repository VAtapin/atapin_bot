export function calculateFamilyTreePositions(data, options = {}) {
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
            const next = Math.max(
                generations.get(link.child) ?? 0,
                (generations.get(link.parent) ?? 0) + 1,
            );
            if (next !== generations.get(link.child)) {
                generations.set(link.child, next);
                changed = true;
            }
        }
        for (const link of partnerships) {
            const generation = Math.max(
                generations.get(link.one) ?? 0,
                generations.get(link.two) ?? 0,
            );
            if (
                generations.get(link.one) !== generation
                || generations.get(link.two) !== generation
            ) {
                generations.set(link.one, generation);
                generations.set(link.two, generation);
                changed = true;
            }
        }
        if (!changed) break;
    }

    const componentParents = new Map([...people.keys()].map((id) => [id, id]));
    const find = (id) => {
        let root = id;
        while (componentParents.get(root) !== root) root = componentParents.get(root);
        while (componentParents.get(id) !== id) {
            const next = componentParents.get(id);
            componentParents.set(id, root);
            id = next;
        }
        return root;
    };
    const unite = (left, right) => {
        const leftRoot = find(left);
        const rightRoot = find(right);
        if (leftRoot !== rightRoot) componentParents.set(rightRoot, leftRoot);
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

    const personWidth = options.personWidth ?? 220;
    const spouseGap = options.spouseGap ?? 28;
    const familyGap = options.familyGap ?? 110;
    const generationGap = options.generationGap ?? 245;
    const unionOffset = options.unionOffset ?? 76;
    const compactRowGap = options.compactRowGap
        ?? Math.max((options.personHeight ?? 140) + 62, Math.round(generationGap * 0.58));
    const maxRowWidth = options.maxRowWidth ?? Number.POSITIVE_INFINITY;
    const rowEntryGap = Math.max(familyGap, Math.round(personWidth * 0.72));
    const clusters = [...components.entries()].map(([root, members]) => {
        const ordered = orderedMembers(members);
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
            ordered,
            generation: Math.max(...members.map((id) => generations.get(id) ?? 0)),
            parentIds,
            parentKey,
            birthOrder: birth ? Date.parse(birth) : Number.MAX_SAFE_INTEGER,
            width: ordered.length * personWidth
                + Math.max(ordered.length - 1, 0) * spouseGap,
        };
    });
    const positions = new Map();
    const maxGeneration = Math.max(0, ...clusters.map((cluster) => cluster.generation));
    let generationY = 0;

    const groupWidth = (items) => items.reduce((sum, item) => sum + item.width, 0)
        + Math.max(items.length - 1, 0) * familyGap;
    const splitWideGroup = (group) => {
        if (!Number.isFinite(maxRowWidth) || group.width <= maxRowWidth) {
            return [group];
        }

        const chunks = [];
        let currentItems = [];
        let currentWidth = 0;

        for (const item of group.items) {
            const nextWidth = currentWidth
                + (currentItems.length ? familyGap : 0)
                + item.width;

            if (currentItems.length && nextWidth > maxRowWidth) {
                chunks.push({
                    ...group,
                    key: `${group.key}:${chunks.length + 1}`,
                    items: currentItems,
                    width: currentWidth,
                });
                currentItems = [];
                currentWidth = 0;
            }

            currentWidth += (currentItems.length ? familyGap : 0) + item.width;
            currentItems.push(item);
        }

        if (currentItems.length) {
            chunks.push({
                ...group,
                key: `${group.key}:${chunks.length + 1}`,
                items: currentItems,
                width: currentWidth,
            });
        }

        return chunks;
    };

    for (let generation = 0; generation <= maxGeneration; generation++) {
        const groups = new Map();
        for (const cluster of clusters.filter((item) => item.generation === generation)) {
            if (!groups.has(cluster.parentKey)) groups.set(cluster.parentKey, []);
            groups.get(cluster.parentKey).push(cluster);
        }

        const groupEntries = [...groups.entries()].map(([key, items]) => {
            items.sort((left, right) => left.birthOrder - right.birthOrder
                || String(left.root).localeCompare(String(right.root)));
            const parentXs = items
                .flatMap((item) => item.parentIds)
                .map((id) => positions.get(id)?.x)
                .filter((value) => Number.isFinite(value));

            return {
                key,
                items,
                desiredX: parentXs.length
                    ? parentXs.reduce((sum, value) => sum + value, 0) / parentXs.length
                    : Number.POSITIVE_INFINITY,
                width: groupWidth(items),
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

        const layoutEntries = groupEntries.flatMap(splitWideGroup);
        const rows = [{ entries: [], width: 0 }];

        for (const entry of layoutEntries) {
            let row = rows[rows.length - 1];
            const projectedWidth = row.width
                + (row.entries.length ? rowEntryGap : 0)
                + entry.width;

            if (
                Number.isFinite(maxRowWidth)
                && row.entries.length
                && projectedWidth > maxRowWidth
            ) {
                row = { entries: [], width: 0 };
                rows.push(row);
            }

            row.width += (row.entries.length ? rowEntryGap : 0) + entry.width;
            row.entries.push(entry);
        }

        const widestRow = Math.max(0, ...rows.map((row) => row.width));

        rows.forEach((row, rowIndex) => {
            let cursor = Math.max((widestRow - row.width) / 2, 0);
            const y = generationY + rowIndex * compactRowGap;

            for (const group of row.entries) {
                let clusterCursor = cursor;

                for (const cluster of group.items) {
                    let memberX = clusterCursor + personWidth / 2;

                    for (const memberId of cluster.ordered) {
                        positions.set(memberId, { x: memberX, y });
                        memberX += personWidth + spouseGap;
                    }

                    clusterCursor += cluster.width + familyGap;
                }

                cursor += group.width + rowEntryGap;
            }
        });

        generationY += generationGap + Math.max(rows.length - 1, 0) * compactRowGap;
    }

    if (positions.size) {
        const minX = Math.min(...[...positions.values()].map((position) => position.x));
        const maxX = Math.max(...[...positions.values()].map((position) => position.x));
        const offset = (minX + maxX) / 2;
        positions.forEach((position, id) => {
            positions.set(id, { ...position, x: position.x - offset });
        });
    }

    for (const link of partnerships) {
        const unionId = `union-${[link.one, link.two].sort().join('-')}`;
        const one = positions.get(link.one);
        const two = positions.get(link.two);
        if (one && two) {
            positions.set(unionId, {
                x: (one.x + two.x) / 2,
                y: Math.max(one.y, two.y) + unionOffset,
            });
        }
    }

    return positions;
}
