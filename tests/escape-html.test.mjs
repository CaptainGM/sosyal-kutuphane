import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { escapeHtml } = require('../js/escape-html.js');

test('escapes the five HTML-significant characters', () => {
    assert.equal(escapeHtml('<script>alert(1)</script>'), '&lt;script&gt;alert(1)&lt;/script&gt;');
    assert.equal(escapeHtml('"quoted"'), '&quot;quoted&quot;');
    assert.equal(escapeHtml("it's"), 'it&#39;s');
    assert.equal(escapeHtml('a & b'), 'a &amp; b');
});

test('leaves plain text untouched', () => {
    assert.equal(escapeHtml('Merhaba dünya 123'), 'Merhaba dünya 123');
});

test('returns empty string for null/undefined', () => {
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
});

test('coerces non-string values to string first', () => {
    assert.equal(escapeHtml(42), '42');
    assert.equal(escapeHtml(true), 'true');
});

test('escapes ampersand before other entities (no double-escaping)', () => {
    assert.equal(escapeHtml('&lt;'), '&amp;lt;');
});

test('a realistic stored-XSS payload becomes inert text', () => {
    const payload = '<img src=x onerror="window.__xss_fired=true">';
    const escaped = escapeHtml(payload);
    assert.ok(!escaped.includes('<img'));
    assert.ok(escaped.includes('&lt;img'));
});
