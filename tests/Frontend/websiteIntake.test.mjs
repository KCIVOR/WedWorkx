import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { visibleIntakeFields, intakeComplete, intakeIssue, intakeStartIndex, normalizeIntakePatch, reusableContactValue, normalizePublicPhone } from '../../resources/js/lib/websiteIntake.js';
import { firstUnansweredIndex } from '../../resources/js/lib/questionSequence.js';
import { summarize } from '../../resources/js/lib/summarize.js';
const catalog = JSON.parse(fs.readFileSync(new URL('../../database/data/website-intake-v2.json', import.meta.url)));
const q = (key) => { const row = catalog.find((r) => r.key === key); return { ...row, intake: row.intake_schema }; };

test('public phones reject masked and incomplete digits but accept complete mobile and landline contacts', () => {
    const field = {key:'public_phone',type:'text',required:false};
    for (const phone of ['+639**3154','+6393154','1234']) assert.ok(intakeIssue(field, phone));
    for (const phone of ['0917 123 4567','+639171234567','02 8123 4567','+14155552671']) assert.equal(intakeIssue(field, phone),null);
    assert.equal(normalizePublicPhone('0917 123 4567'),'+639171234567');
    assert.equal(intakeIssue(field,null),null);
});

test('guided service summaries preserve package inclusions', () => {
    const text = summarize(q('services'),{services_status:'now',services:[{name:'Essential',price:'',details:'Eight hours and an album.'}]});
    assert.ok(text.includes('Eight hours and an album.'));
});

test('guided draft resumes the saved unsent subfield rather than skipping typed text', () => {
    const question = q('description');
    const a = { description: 'Typed but not submitted', business_experience: null };
    assert.equal(intakeStartIndex(question, a, 'description'), 0);
    assert.equal(intakeStartIndex(question, a, 'business_experience'), 1);
});
test('nested conditions ignore retained answers in inactive ancestor branches', () => {
    const question = q('pricing_display');
    const a = { pricing_display: 'team', pricing_currency: 'other', pricing_currency_other: 'EUR' };
    assert.deepEqual(visibleIntakeFields(question, a).map((f) => f.key), ['pricing_display']);
    assert.deepEqual(normalizeIntakePatch(question, a), { pricing_display: 'team', approved_prices: null, pricing_currency: null, pricing_currency_other: null });
});
test('optional fields require explicit skips while hidden fields do not block completion', () => {
    const question = q('domain_platform');
    assert.equal(intakeComplete(question, {}), false);
    assert.equal(intakeComplete(question, { domain_platform: null }), true);
    assert.equal(firstUnansweredIndex([question], { domain_platform: 'existing', existing_website_url: 'https://example.com' }), 0);
    assert.equal(firstUnansweredIndex([question], { domain_platform: 'existing', existing_website_url: 'https://example.com', existing_cms: null }), 1);
});
test('required services and uploads cannot inherit legacy empty-array exemptions', () => {
    assert.equal(firstUnansweredIndex([q('services')], { services_status: 'now', services: [] }), 0);
    // A logo-only upload is valid; the server verifies an actual owned logo exists.
    assert.equal(firstUnansweredIndex([q('media')], { asset_plan: 'now', photo_ids: [], asset_permission: 'approved', colors: null, brand_fonts: null, brand_guidelines: null }), 1);
    assert.ok(intakeIssue({ type: 'uploads', required: true }, ['5']));
});
test('private contact reuse requires selected values and separate permission decision', () => {
    const question = q('public_contacts');
    const contacts = { email: 'private@example.test', phone: '123', socials: 'https://example.test', location: 'Cavite' };
    assert.equal(reusableContactValue('public_email', contacts), contacts.email);
    assert.equal(normalizeIntakePatch(question, { public_contact_mode: 'reuse' }).public_email, null);
    assert.equal(intakeComplete(question, { public_contact_mode: 'reuse', public_email: contacts.email, public_phone: null, public_socials: null, public_address_mode: 'none' }), false);
    const pending = { public_contact_mode: 'reuse', public_email: contacts.email, public_phone: null, public_socials: null, public_address_mode: 'none', public_contact_permission: 'review' };
    assert.equal(intakeComplete(question, pending), true);
    assert.equal(normalizeIntakePatch(question, pending).public_email, null);
    assert.equal(normalizeIntakePatch(question, { ...pending, public_contact_mode: 'later' }).public_email, null);
});
test('summaries use descriptor labels and service rows, never raw objects', () => {
    const text = summarize(q('services'), { services_status: 'now', services: [{ name: 'Coordination', price: 'PHP 5000' }] });
    assert.match(text, /Services and packages: Coordination \(PHP 5000\)/);
    assert.doesNotMatch(text, /object Object/);
});
