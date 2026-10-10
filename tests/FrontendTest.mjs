import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { File } from 'node:buffer';
import {
  ApiError, byteLength, parseSession, currentQuestionDecision, readinessReport, sanitizeDiagnostics, sessionAdvanced, createInterviewApi,
} from '../public/assets/interview-api.mjs';
import { captureDraft, createDraftStore, DRAFT_STORAGE_KEY, recoverDraft } from '../public/assets/answer-draft.mjs';

const id = '123e4567-e89b-42d3-a456-426614174000';
const unsafeText = '<img src=x onerror="alert(1)">';
function initial() {
  return {
    id, target_role: 'Junior PHP Developer', status: 'active', answer_count: 0,
    plan: { focus_areas: ['Debugging', 'Security'], rationale: 'Role-specific practice.', suggested_depth: 'beginner' },
    current_question: 'Describe a bug you fixed.', answers: [],
    decisions: [{ sequence_no: 1, action: 'ask_question', rationale: 'Explore experience.', arguments: { question: 'Describe a bug you fixed.', focus: 'Debugging' }, result: { question: 'Describe a bug you fixed.', focus: 'Debugging' }, created_at: '2026-01-01T00:00:00Z' }],
    evaluations: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
  };
}
function followedUp() {
  const data = initial();
  data.answer_count = 1;
  data.answers.push({ id: 1, answer_no: 1, question_text: data.current_question, answer_text: 'I checked some code.', created_at: data.created_at });
  data.current_question = `Which debugging steps did you take? ${unsafeText}`;
  data.decisions.push({ sequence_no: 2, action: 'probe_answer', rationale: 'The answer is vague.', arguments: { question: data.current_question, focus: 'Debugging', probe_reason: 'Give concrete technical details.' }, result: { question: data.current_question, focus: 'Debugging' } });
  return data;
}
function finished() {
  const data = followedUp();
  data.status = 'finished';
  data.answer_count = 2;
  data.answers.push({ id: 2, answer_no: 2, question_text: data.current_question, answer_text: 'I used PHP logs and checked a SQL query.' });
  data.current_question = null;
  data.evaluations.push({ evaluation_type: 'claim_verification', data: { evidence: 'unclear', explanation: 'More measurement evidence is needed.', follow_up_focus: 'Measurement' } });
  const report = { summary: `More concrete examples would help. ${unsafeText}`, readiness: 'developing' };
  data.evaluations.push({ evaluation_type: 'readiness_report', data: report });
  data.decisions.push({ sequence_no: 3, action: 'finish_interview', rationale: 'Enough evidence.', arguments: report, result: report });
  return data;
}
const response = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const preparation = { target_role: 'Junior PHP Developer', cv_text: 'Fictional developer with PHP and SQL inventory experience.', job_description: 'Junior PHP role requiring APIs, debugging, and security.' };

test('views consume the PHP question, probe, evaluation, and readiness contracts without inventing scores', () => {
  const start = parseSession(initial());
  const probe = parseSession(followedUp());
  const report = parseSession(finished());
  assert.equal(currentQuestionDecision(start).action, 'ask_question');
  assert.equal(currentQuestionDecision(probe).action, 'probe_answer');
  assert.ok(probe.current_question.includes(unsafeText));
  assert.equal(readinessReport(start), null);
  assert.equal(readinessReport(report).readiness, 'developing');
  assert.ok(readinessReport(report).summary.includes(unsafeText));
  assert.equal('score' in readinessReport(report), false);
  assert.equal(sessionAdvanced(start, probe), true);
  assert.equal(sessionAdvanced(probe, parseSession(followedUp())), false);
  assert.equal(sessionAdvanced(probe, report), true);
  assert.throws(() => parseSession({ ...initial(), current_question: null }), ApiError);
  assert.throws(() => parseSession({ ...initial(), id: '../../private' }), ApiError);
});

test('API adapter uses only the three existing endpoints, validates UTF-8 byte limits, and never retries', async () => {
  const requests = [];
  const api = createInterviewApi(async (path, options) => {
    requests.push({ path, options });
    return response(initial(), options.method === 'POST' && path === '/api/interviews' ? 201 : 200);
  });
  await api.start(preparation);
  await api.answer(id, ' A fictional answer. ');
  await api.progress(id);
  assert.deepEqual(requests.map(item => [item.path, item.options.method]), [
    ['/api/interviews', 'POST'], [`/api/interviews/${id}/answers`, 'POST'], [`/api/interviews/${id}`, 'GET'],
  ]);
  assert.deepEqual(JSON.parse(requests[0].options.body), preparation);
  assert.deepEqual(JSON.parse(requests[1].options.body), { answer: 'A fictional answer.' });
  assert.equal(requests[0].options.headers['Content-Type'], 'application/json');
  assert.equal(requests[2].options.body, undefined);
  assert.equal(byteLength('é'), 2);
  assert.throws(() => api.start({ ...preparation, cv_text: 'é'.repeat(10001) }), ApiError);
  assert.throws(() => api.answer(id, '   '), ApiError);
  assert.throws(() => api.progress('../wrong'), ApiError);
  assert.equal(requests.length, 3);

  let calls = 0;
  const unavailable = createInterviewApi(async () => { calls++; return response({ error: 'PRIVATE_SENTINEL', diagnostics: { raw: 'PRIVATE_SENTINEL' } }, 503); });
  await assert.rejects(unavailable.answer(id, 'A fictional answer.'), error => error.status === 503 && !error.message.includes('PRIVATE_SENTINEL'));
  assert.equal(calls, 1);
  const malformed = createInterviewApi(async () => ({ ok: true, status: 200, json: async () => { throw new Error('PRIVATE_SENTINEL'); } }));
  await assert.rejects(malformed.progress(id), error => error.kind === 'response' && !error.message.includes('PRIVATE_SENTINEL'));
});

test('timeouts and connection errors preserve safe failure categories without retries', async () => {
  let calls = 0;
  const timeout = createInterviewApi((path, options) => {
    calls++;
    return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('PRIVATE_SENTINEL')), { once: true }));
  }, 5);
  await assert.rejects(timeout.answer(id, 'A fictional answer.'), error => error.kind === 'timeout');
  assert.equal(calls, 1);
  const network = createInterviewApi(async () => { throw new TypeError('PRIVATE_SENTINEL'); });
  await assert.rejects(network.progress(id), error => error.kind === 'network' && !error.message.includes('PRIVATE_SENTINEL'));
});

test('error metadata is allowlisted and human messages never echo raw errors or sensitive data', async () => {
  const safe = { provider: 'gemini', category: 'quota_or_rate_limit', stage: 'decision', upstream_http_status: 429,
    curl_error_code: 28, provider_code: 'RESOURCE_EXHAUSTED' };
  const api = createInterviewApi(async () => response({ error: 'PRIVATE_SENTINEL', diagnostics: {
    ...safe, api_key: 'PRIVATE_SENTINEL', headers: 'PRIVATE_SENTINEL', cv_text: 'PRIVATE_SENTINEL', raw_body: 'PRIVATE_SENTINEL',
  } }, 503));
  await assert.rejects(api.answer(id, 'A fictional answer.'), error => {
    assert.deepEqual(error.diagnostics, safe);
    assert.match(error.message, /usage or rate limit/);
    assert.ok(!JSON.stringify(error.diagnostics).includes('PRIVATE_SENTINEL'));
    assert.ok(!error.message.includes('PRIVATE_SENTINEL'));
    return true;
  });
  assert.deepEqual(sanitizeDiagnostics({ category: unsafeText, stage: 'PRIVATE_SENTINEL', upstream_http_status: '429', curl_error_code: -1 }), {});
  assert.deepEqual(sanitizeDiagnostics({ upstream_http_status: 999, curl_error_code: 1000 }), {});
  assert.deepEqual(sanitizeDiagnostics(null), {});
  assert.deepEqual(sanitizeDiagnostics({ provider_code: 'PRIVATE_SENTINEL', reason: unsafeText }), {});
  const incomplete = createInterviewApi(async () => response({ diagnostics: {
    provider: 'gemini', category: 'invalid_output', stage: 'decision', upstream_http_status: 200, reason: 'INCOMPLETE_OUTPUT',
  } }, 503));
  await assert.rejects(incomplete.answer(id, 'A fictional answer.'), error => error.diagnostics.reason === 'INCOMPLETE_OUTPUT' && /incomplete/.test(error.message));
  const production = createInterviewApi(async () => response({ error: 'PRIVATE_SENTINEL' }, 503));
  await assert.rejects(production.answer(id, 'A fictional answer.'), error => Object.keys(error.diagnostics).length === 0);
  const nonJson = createInterviewApi(async () => ({ ok: false, status: 503, json: async () => { throw new Error('PRIVATE_SENTINEL'); } }));
  await assert.rejects(nonJson.progress(id), error => error.status === 503 && !error.message.includes('PRIVATE_SENTINEL'));
});

test('question-bound drafts survive reload, reconcile committed answers, and never move to a different question', () => {
  const storage = new Map();
  const accessor = () => ({ getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) });
  const fifth = initial();
  fifth.answer_count = 4;
  fifth.decisions[0].sequence_no = 5;
  const draft = captureDraft(fifth, `A fictional unsent answer. ${unsafeText}`, true);
  const first = createDraftStore(accessor);
  assert.equal(first.write({ ...draft, cv_text: 'PRIVATE_SENTINEL', headers: 'PRIVATE_SENTINEL' }), true);
  assert.ok(!storage.get(DRAFT_STORAGE_KEY).includes('PRIVATE_SENTINEL'));
  const reloaded = createDraftStore(accessor);
  assert.deepEqual(reloaded.read(), draft);
  assert.deepEqual(recoverDraft(reloaded.read(), fifth), { kind: 'current', text: draft.text, needs_refresh: true });
  assert.equal(captureDraft({ ...fifth, status: 'finished' }, 'A draft.'), null);
  assert.equal(recoverDraft(draft, { ...fifth, id: '223e4567-e89b-42d3-a456-426614174000' }).kind, 'unrelated');
  const advanced = { ...fifth, answer_count: 5, decisions: [{ ...fifth.decisions[0], sequence_no: 6 }], answers: [{ answer_no: 5, answer_text: draft.text.trim() }] };
  assert.equal(recoverDraft(draft, advanced).kind, 'saved');
  assert.equal(recoverDraft({ ...draft, text: 'An edited, unsent detail.' }, advanced).kind, 'previous');
  assert.equal(recoverDraft(draft, { ...advanced, answers: [{ answer_no: 1, answer_text: draft.text.trim() }] }).kind, 'previous', 'An identical earlier answer must not be mistaken for the pending answer.');
  assert.equal(reloaded.clearFor(id, 6), true);
  assert.deepEqual(reloaded.read(), draft, 'A different question must not clear this draft.');
  reloaded.clearFor(id, 5);
  assert.equal(reloaded.read(), null);
  assert.equal(storage.has(DRAFT_STORAGE_KEY), false);
  storage.set(DRAFT_STORAGE_KEY, 'bad JSON');
  assert.equal(createDraftStore(accessor).read(), null);
  const unavailable = createDraftStore(() => { throw new Error('Storage disabled.'); });
  assert.equal(unavailable.write(draft), false);
  assert.deepEqual(unavailable.read(), draft, 'Storage failures must still retain in-memory draft recovery.');
});

test('preparation, follow-up, refresh recovery, and completion UI work with a fake DOM and fake fetch only', async () => {
  const html = await readFile(new URL('../public/app.html', import.meta.url), 'utf8');
  const mainScript = await readFile(new URL('../public/assets/app.mjs', import.meta.url), 'utf8');
  assert.doesNotMatch(mainScript, /innerHTML|outerHTML|insertAdjacentHTML|eval\(/);
  let nodes = new Map();
  class Node {
    constructor(tag, attributes = '') {
      this.tag = tag; this.value = ''; this.hidden = /\bhidden\b/.test(attributes);
      this.disabled = /\bdisabled\b/.test(attributes); this.children = []; this.listeners = new Map(); this.attributes = new Map();
      this.classes = new Set(); this.classList = { toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name) };
    }
    set textContent(value) { this.content = String(value); this.children = []; }
    get textContent() { return this.children.length ? this.children.map(child => child.textContent).join('') : this.content ?? ''; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; this.content = ''; }
    get childElementCount() { return this.children.length; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    addEventListener(name, handler) {
      const previous = this.listeners.get(name);
      this.listeners.set(name, previous ? async event => { await previous(event); return handler(event); } : handler);
    }
    setCustomValidity(value) { this.validityMessage = value; }
    reportValidity() { return !this.validityMessage; }
    focus() { document.activeElement = this; }
    scrollIntoView() {}
    reset() { for (const name of ['target-role', 'cv-text', 'job-description']) nodes.get(name).value = ''; }
    async fire(name, extras = {}) { await this.listeners.get(name)?.({ preventDefault() {}, ...extras }); }
    requestSubmit() { return this.fire('submit'); }
  }
  function resetDocument() {
    nodes = new Map();
    for (const match of html.matchAll(/<([a-z][\w-]*)\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
      assert.equal(nodes.has(match[3]), false, `Duplicate element ID: ${match[3]}`);
      nodes.set(match[3], new Node(match[1], match[2]));
    }
  }
  resetDocument();
  for (const match of mainScript.matchAll(/\$\('([^']+)'\)/g)) assert.ok(nodes.has(match[1]), `Missing UI element: ${match[1]}`);
  for (const match of html.matchAll(/<label[^>]+for="([^"]+)"/g)) assert.ok(nodes.has(match[1]), 'Label target is missing.');
  const stored = new Map();
  globalThis.document = {
    getElementById: name => nodes.get(name),
    createElement: tag => new Node(tag),
    querySelectorAll: () => [...nodes.values()].filter(node => ['input', 'textarea', 'button'].includes(node.tag)),
  };
  globalThis.window = { addEventListener() {} };
  globalThis.sessionStorage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
  const requests = [];
  const queue = [response({ error: 'PRIVATE_SENTINEL', code: 'encrypted' }, 422),
    response({ text: preparation.cv_text, format: 'docx' }), response(initial(), 201), response({ error: 'PRIVATE_SENTINEL', diagnostics: {
    provider: 'gemini', category: 'quota_or_rate_limit', stage: 'decision', upstream_http_status: 429, raw: 'PRIVATE_SENTINEL',
  } }, 503), response(initial()), response(initial()), response(followedUp()), new Error('PRIVATE_SENTINEL'), response(finished())];
  globalThis.fetch = async (path, options) => {
    requests.push([path, options.method]);
    const item = queue.shift();
    if (item instanceof Error) throw item;
    assert.ok(item, 'Unexpected automatic request.');
    return item;
  };
  await import('../public/assets/app.mjs');
  assert.equal(requests.length, 0, 'Fresh page must not create an interview automatically.');
  nodes.get('target-role').value = preparation.target_role;
  nodes.get('cv-text').value = preparation.cv_text;
  nodes.get('job-description').value = preparation.job_description;
  nodes.get('cv-file').files = [new File(['fictional fixture bytes'], 'fictional.docx')];
  await nodes.get('cv-file').fire('change');
  assert.equal(nodes.get('cv-text').value, preparation.cv_text, 'Failed extraction must preserve pasted CV text.');
  assert.equal(requests[0][0], '/api/cv/extract');
  assert.match(nodes.get('error-message').textContent, /encrypted/);
  await nodes.get('cv-file').fire('change');
  assert.equal(nodes.get('cv-text').value, preparation.cv_text);
  assert.equal(requests[1][0], '/api/cv/extract');
  assert.equal(requests.length, 2, 'Uploading must not start an interview or call its API automatically.');
  const starting = nodes.get('preparation-form').fire('submit');
  assert.equal(nodes.get('start-button').disabled, true, 'Start must be disabled while the request is in flight.');
  await nodes.get('preparation-form').fire('submit');
  await starting;
  assert.equal(nodes.get('question-text').textContent, initial().current_question);
  assert.equal(nodes.get('workspace-screen').hidden, false);
  assert.equal(nodes.get('cv-text').value, '');
  assert.deepEqual([...stored.entries()], [['jobready.session-id', id]], 'Only the session ID may be persisted in this tab.');

  nodes.get('answer-text').value = 'I checked some code.';
  await nodes.get('answer-text').fire('input');
  assert.equal(JSON.parse(stored.get(DRAFT_STORAGE_KEY)).text, 'I checked some code.');
  await nodes.get('answer-form').fire('submit');
  assert.equal(nodes.get('answer-text').value, 'I checked some code.');
  assert.equal(nodes.get('submit-answer').disabled, true, 'A failed POST must require progress reconciliation.');
  assert.equal(nodes.get('error-details').hidden, false);
  assert.equal(nodes.get('diagnostic-category').textContent, 'quota_or_rate_limit');
  assert.equal(nodes.get('diagnostic-stage').textContent, 'decision');
  assert.equal(nodes.get('diagnostic-status').textContent, '429');
  assert.ok(!nodes.get('error-message').textContent.includes('PRIVATE_SENTINEL'));
  await nodes.get('answer-form').fire('submit');
  assert.equal(requests.length, 4, 'No automatic retry or duplicate POST is allowed.');
  // Simulate page reload: new document and controller, same tab storage. Only GET recovery is allowed.
  resetDocument();
  await import('../public/assets/app.mjs?draft-reload-fixture');
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(nodes.get('answer-text').value, 'I checked some code.');
  assert.equal(nodes.get('submit-answer').disabled, true, 'Reload of a failed/pending POST must require explicit refresh.');
  assert.equal(requests.at(-1)[1], 'GET');
  await nodes.get('answer-form').fire('submit');
  assert.equal(requests.length, 5, 'Recovered drafts must never be auto-submitted.');
  // Let the explicit progress GET finish before testing resubmission.
  nodes.get('refresh-button').listeners.get('click')();
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(nodes.get('submit-answer').disabled, false);
  assert.equal(JSON.parse(stored.get(DRAFT_STORAGE_KEY)).needs_refresh, false);
  assert.equal(nodes.get('answer-text').value, 'I checked some code.');
  await nodes.get('answer-form').fire('submit');
  assert.equal(nodes.get('question-kind').textContent, 'Follow-up');
  assert.equal(nodes.get('question-text').textContent, followedUp().current_question);
  assert.equal(nodes.get('question-text').childElementCount, 0, 'AI markup must remain a text node.');
  assert.equal(nodes.get('evaluation-empty').hidden, false, 'No scores/evaluations should be invented for a probe.');
  assert.equal(nodes.get('answer-text').value, '');
  assert.equal(stored.has(DRAFT_STORAGE_KEY), false, 'Confirmed successful answer must clear its stored draft.');

  nodes.get('answer-text').value = 'I used PHP logs and checked a SQL query.';
  await nodes.get('answer-form').fire('submit');
  assert.equal(nodes.get('submit-answer').disabled, true);
  assert.equal(nodes.get('error-details').hidden, true, 'Non-diagnostic failures must not retain earlier development details.');
  assert.equal(nodes.get('diagnostic-category').textContent, '');
  const editedDraft = 'I used PHP logs and checked a SQL query. An additional unsent detail.';
  nodes.get('answer-text').value = editedDraft;
  await nodes.get('answer-text').fire('input');
  nodes.get('refresh-button').listeners.get('click')();
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(nodes.get('readiness-card').hidden, false);
  assert.equal(nodes.get('answer-card').hidden, true);
  assert.equal(nodes.get('readiness-label').textContent, 'Developing');
  assert.ok(nodes.get('readiness-summary').textContent.includes(unsafeText));
  assert.equal(nodes.get('readiness-summary').childElementCount, 0);
  assert.equal(nodes.get('answer-text').value, '', 'An already-saved answer must not be re-submitted.');
  assert.equal(nodes.get('draft-backup').hidden, false, 'An edited unsent draft must remain available after a changed question.');
  assert.equal(nodes.get('draft-backup-text').textContent, editedDraft);
  assert.equal(nodes.get('evaluation-empty').hidden, true);
  assert.equal(nodes.get('evaluation-list').childElementCount, 1);
  assert.deepEqual(requests.map(item => item[1]), ['POST', 'POST', 'POST', 'POST', 'GET', 'GET', 'POST', 'POST', 'GET']);
  assert.equal(queue.length, 0);
  assert.ok(!nodes.get('error-message').textContent.includes('PRIVATE_SENTINEL'));
  assert.equal(stored.get('jobready.session-id'), id, 'Recovery must preserve the original session.');
});
