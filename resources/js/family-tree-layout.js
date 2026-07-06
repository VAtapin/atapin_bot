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

    const parseDate = (value) => {
        const timestamp = value ? Date.parse(value) : 0;
        return Number.isFinite(timestamp) ? timestamp : 0;
    };
    const childParents = new Map();
    for (const link of parentLinks) {
        if (!childParents.has(link.child)) childParents.set(link.child, new Set());
        childParents.get(link.child).add(link.parent);
    }
    const youngestChildDate = (one, two) => Math.max(0, ...[...childParents.entries()]
        .filter(([, parents]) => parents.has(one) && parents.has(two))
        .map(([child]) => parseDate(people.get(child)?.birth_date)));
    const isEndedPartnership = (link) => Boolean(link?.ended_at)
        || ['divorced', 'ended'].includes(String(link?.status ?? '').toLowerCase());
    const linksFor = (id) => partnerships.filter((link) => link.one === id || link.two === id);
    const partnershipRank = (link, central, spouse) => {
        if (!link) return [0, 0, 0, 0];

        return [
            isEndedPartnership(link) ? 0 : 1,
            parseDate(link.started_at),
            youngestChildDate(central, spouse),
            parseDate(link.ended_at),
        ];
    };
    const compareRankDesc = (leftRank, rightRank) => {
        for (let index = 0; index < Math.max(leftRank.length, rightRank.length); index++) {
            const diff = (rightRank[index] ?? 0) - (leftRank[index] ?? 0);
            if (diff !== 0) return diff;
        }

        return 0;
    };
    const bestPartnerFor = (central, candidates) => candidates
        .filter((id) => linksFor(central).some((link) => link.one === id || link.two === id))
        .sort((left, right) => {
            const leftLink = linksFor(central).find((link) => link.one === left || link.two === left);
            const rightLink = linksFor(central).find((link) => link.one === right || link.two === right);

            return compareRankDesc(
                partnershipRank(leftLink, central, left),
                partnershipRank(rightLink, central, right),
            ) || Number(left) - Number(right);
        })[0];
    const orderedMembers = (members) => {
        if (members.length < 2) return members;

        const central = [...members].sort((left, right) => {
            const degree = linksFor(right).length - linksFor(left).length;
            return degree || Number(left) - Number(right);
        })[0];
        const centralPerson = people.get(central);
        const spouses = members.filter((id) => id !== central);
        const actualFirst = (left, right) => {
            const leftLink = linksFor(central).find((link) => link.one === left || link.two === left);
            const rightLink = linksFor(central).find((link) => link.one === right || link.two === right);
            const rankDiff = compareRankDesc(
                partnershipRank(leftLink, central, left),
                partnershipRank(rightLink, central, right),
            );

            return rankDiff || Number(left) - Number(right);
        };

        if (centralPerson?.gender === 'male') {
            return [...spouses.sort((left, right) => -actualFirst(left, right)), central];
        }
        if (centralPerson?.gender === 'female') {
            return [central, ...spouses.sort(actualFirst)];
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
        const anchorIndex = Math.max(ordered.indexOf(anchorMember), 0);
        const partnerIndex = Math.max(
            ordered.indexOf(bestPartnerFor(anchorMember, ordered.filter((id) => id !== anchorMember))),
            -1,
        );
        const memberCenterOffset = (index) => personWidth / 2 + index * (personWidth + spouseGap);
        const anchorOffset = partnerIndex >= 0
            ? (memberCenterOffset(anchorIndex) + memberCenterOffset(partnerIndex)) / 2
            : memberCenterOffset(anchorIndex);

        return {
            root,
            ordered,
            generation: Math.max(...members.map((id) => generations.get(id) ?? 0)),
            parentIds,
            parentKey,
            birthOrder: birth ? Date.parse(birth) : Number.MAX_SAFE_INTEGER,
            width: ordered.length * personWidth
                + Math.max(ordered.length - 1, 0) * spouseGap,
            anchorOffset,
        };
    });
    const positions = new Map();
    const maxGeneration = Math.max(0, ...clusters.map((cluster) => cluster.generation));
    let generationY = 0;

    const measureItems = (items) => {
        let width = 0;
        const anchorOffsets = [];

        items.forEach((item, index) => {
            anchorOffsets.push(width + (item.anchorOffset ?? item.width / 2));
            width += item.width;
            if (index < items.length - 1) width += familyGap;
        });

        return {
            width,
            anchorCenterOffset: anchorOffsets.length
                ? anchorOffsets.reduce((sum, value) => sum + value, 0) / anchorOffsets.length
                : width / 2,
        };
    };
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
                const measured = measureItems(currentItems);

                chunks.push({
                    ...group,
                    key: `${group.key}:${chunks.length + 1}`,
                    items: currentItems,
                    width: measured.width,
                    anchorCenterOffset: measured.anchorCenterOffset,
                });
                currentItems = [];
                currentWidth = 0;
            }

            currentWidth += (currentItems.length ? familyGap : 0) + item.width;
            currentItems.push(item);
        }

        if (currentItems.length) {
            const measured = measureItems(currentItems);

            chunks.push({
                ...group,
                key: `${group.key}:${chunks.length + 1}`,
                items: currentItems,
                width: measured.width,
                anchorCenterOffset: measured.anchorCenterOffset,
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
            const measured = measureItems(items);
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
                width: measured.width,
                anchorCenterOffset: measured.anchorCenterOffset,
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
        const rows = [{ placements: [], cursor: null, minX: null, maxX: null }];
        const placeInRow = (row, entry) => {
            const desiredStart = Number.isFinite(entry.desiredX)
                ? entry.desiredX - (entry.anchorCenterOffset ?? entry.width / 2)
                : (row.cursor ?? 0);
            const start = row.cursor === null
                ? desiredStart
                : Math.max(row.cursor, desiredStart);
            const end = start + entry.width;
            const minX = row.minX === null ? start : Math.min(row.minX, start);
            const maxX = row.maxX === null ? end : Math.max(row.maxX, end);

            return {
                start,
                end,
                minX,
                maxX,
                width: maxX - minX,
            };
        };

        for (const entry of layoutEntries) {
            let row = rows[rows.length - 1];
            let placement = placeInRow(row, entry);

            if (
                Number.isFinite(maxRowWidth)
                && row.placements.length
                && placement.width > maxRowWidth
            ) {
                row = { placements: [], cursor: null, minX: null, maxX: null };
                rows.push(row);
                placement = placeInRow(row, entry);
            }

            row.placements.push({ entry, start: placement.start });
            row.cursor = placement.end + rowEntryGap;
            row.minX = placement.minX;
            row.maxX = placement.maxX;
        }

        rows.forEach((row, rowIndex) => {
            const y = generationY + rowIndex * compactRowGap;

            for (const placement of row.placements) {
                const group = placement.entry;
                let clusterCursor = placement.start;

                for (const cluster of group.items) {
                    let memberX = clusterCursor + personWidth / 2;

                    for (const memberId of cluster.ordered) {
                        positions.set(memberId, { x: memberX, y });
                        memberX += personWidth + spouseGap;
                    }

                    clusterCursor += cluster.width + familyGap;
                }
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
