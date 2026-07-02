import assert from 'node:assert/strict';
import test from 'node:test';
import { calculateFamilyTreePositions } from '../../resources/js/family-tree-layout.js';

const person = (id, gender, birth_date = null) => ({
    id: String(id),
    gender,
    birth_date,
});
const partnership = (one, two, started_at = null, ended_at = null) => ({
    partner_one_id: String(one),
    partner_two_id: String(two),
    started_at,
    ended_at,
});
const child = (parent, childId) => ({
    parent_id: String(parent),
    child_id: String(childId),
});

test('wife is left of husband and spouses form a compact block', () => {
    const positions = calculateFamilyTreePositions({
        people: [person(1, 'female'), person(2, 'male'), person(3, 'male')],
        partnerships: [partnership(1, 2)],
        parent_child: [],
    });

    assert.ok(positions.get('1').x < positions.get('2').x);
    assert.equal(positions.get('2').x - positions.get('1').x, 248);
    assert.ok(Math.abs(positions.get('3').x - positions.get('2').x) > 248);
});

test('children of one couple are ordered oldest to youngest', () => {
    const positions = calculateFamilyTreePositions({
        people: [
            person(1, 'female'),
            person(2, 'male'),
            person(3, 'female', '1980-01-01'),
            person(4, 'male', '1990-01-01'),
            person(5, 'male', null),
        ],
        partnerships: [partnership(1, 2)],
        parent_child: [
            child(1, 5), child(2, 5),
            child(1, 4), child(2, 4),
            child(1, 3), child(2, 3),
        ],
    });

    assert.ok(positions.get('3').x < positions.get('4').x);
    assert.ok(positions.get('4').x < positions.get('5').x);
});

test('newest husband is closest to the woman and each union gets its own node', () => {
    const positions = calculateFamilyTreePositions({
        people: [person(1, 'female'), person(2, 'male'), person(3, 'male')],
        partnerships: [
            partnership(1, 2, '1990-01-01', '2000-01-01'),
            partnership(1, 3, '2010-01-01'),
        ],
        parent_child: [],
    });

    assert.ok(positions.get('1').x < positions.get('3').x);
    assert.ok(positions.get('3').x < positions.get('2').x);
    assert.equal(positions.get('union-1-3').x, (positions.get('1').x + positions.get('3').x) / 2);
    assert.equal(positions.get('union-1-2').x, (positions.get('1').x + positions.get('2').x) / 2);
});

test('children from different marriages stay below their own family block', () => {
    const positions = calculateFamilyTreePositions({
        people: [
            person(1, 'female'),
            person(2, 'male'),
            person(3, 'male'),
            person(4, 'female', '2001-01-01'),
            person(5, 'male', '2012-01-01'),
        ],
        partnerships: [
            partnership(1, 2, '1990-01-01', '2005-01-01'),
            partnership(1, 3, '2010-01-01'),
        ],
        parent_child: [
            child(1, 4), child(2, 4),
            child(1, 5), child(3, 5),
        ],
    });

    const oldUnionX = positions.get('union-1-2').x;
    const newUnionX = positions.get('union-1-3').x;
    assert.ok(Math.abs(positions.get('4').x - oldUnionX)
        <= Math.abs(positions.get('4').x - newUnionX));
    assert.ok(Math.abs(positions.get('5').x - newUnionX)
        <= Math.abs(positions.get('5').x - oldUnionX));
});
