import test from 'node:test';
import assert from 'node:assert/strict';
import { firstUnansweredIndex, resumeQuestionIndex } from '../../resources/js/lib/questionSequence.js';

const registration = [{ key: 'name' }, { key: 'email' }, { key: 'social_link', optional: true }];
const website = [
    { key: 'description', fields: ['description'] },
    { key: 'areas', fields: ['service_areas', 'booking'], optional: true },
    { key: 'style', fields: ['style'] },
];

test('starts at the first question and follows answered field keys', () => {
    assert.equal(firstUnansweredIndex(registration, {}), 0);
    assert.equal(firstUnansweredIndex(registration, { name: 'Ana', social_link: '' }), 1);
    assert.equal(firstUnansweredIndex(registration, { name: 'Ana', email: 'ana@example.test', social_link: '' }), 3);
});
test('optional questions remain in order until answered or explicitly skipped', () => {
    assert.equal(firstUnansweredIndex(registration, { name: 'Ana', email: 'ana@example.test' }), 2);
    assert.equal(firstUnansweredIndex(registration, { name: 'Ana', email: 'ana@example.test', social_link: null }), 3);
});
test('multipart questions need all fields and preserve explicit optional empty values', () => {
    assert.equal(firstUnansweredIndex(website, { description: 'Company', service_areas: ['Cavite'], style: 'modern' }), 1);
    assert.equal(firstUnansweredIndex(website, { description: 'Company', service_areas: [], booking: '', style: 'modern' }), 3);
});
test('stale numeric draft position cannot skip newly inserted questions', () => {
    const questions = [{ key: 'name' }, { key: 'new_question' }, { key: 'email' }];
    assert.equal(resumeQuestionIndex(questions, { idx: 2, phase: 'review', answers: { name: 'Ana', email: 'ana@example.test' } }), 1);
});
test('unsubmitted typed answer stays on its current key after reload', () => {
    assert.equal(resumeQuestionIndex(website, {
        idx: 2, currentKey: 'description', answers: { description: 'Still typing' },
    }), 0);
});
test('reordering follows earliest missing question even when a draft has a later current key', () => {
    assert.equal(resumeQuestionIndex(website, { currentKey: 'style', answers: { description: 'Company' } }), 1);
});
test('deleted draft current key is ignored and empty collections are handled', () => {
    assert.equal(resumeQuestionIndex(registration, { currentKey: 'deleted', answers: { name: 'Ana' } }), 1);
    assert.equal(resumeQuestionIndex([], null), 0);
});
test('undefined is missing while a deliberate empty optional answer is complete', () => {
    assert.equal(firstUnansweredIndex([{ key: 'extra' }], { extra: undefined }), 0);
    assert.equal(firstUnansweredIndex([{ key: 'extra', optional: true }], { extra: '' }), 1);
});
test('specialized widgets preserve nullable-field semantics after Continue', () => {
    const questions = [
        { key: 'areas', type: 'areas', optional: false, fields: ['service_areas', 'booking'] },
        { key: 'media', type: 'media', optional: false, fields: ['colors'] },
    ];
    assert.equal(firstUnansweredIndex(questions, { service_areas: [], booking: '', colors: '' }), 2);
});
test('legacy unsent text cannot advance beyond its last saved position', () => {
    assert.equal(resumeQuestionIndex(website, {
        idx: 2, phase: 'chat', answers: { description: 'Company', service_areas: [], booking: '', style: 'modern' },
    }), 2);
});
