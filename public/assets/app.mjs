import {
  ApiError, LIMITS, byteLength, createDemoAccessApi, createInterviewApi, currentQuestionDecision, readinessReport, sanitizeDiagnostics, sessionAdvanced, uuidPattern,
} from './interview-api.mjs';
import { captureDraft, createDraftStore, recoverDraft } from './answer-draft.mjs';
import { createCvUploadApi } from './cv-upload.mjs';

const $ = id => document.getElementById(id);
let csrfToken = null;
let accessRequired = false;
const demoAccessApi = createDemoAccessApi();
const api = createInterviewApi(globalThis.fetch, 90000, () => csrfToken);
const uploadApi = createCvUploadApi(globalThis.fetch, 30000, () => csrfToken);
const storageKey = 'jobready.session-id';
const draftStore = createDraftStore();
let session = null;
let busy = false;
let needsRefresh = false;
let failedSubmission = null;
let submittingAnswer = false;
const controls = [...document.querySelectorAll('form input, form textarea, button')];

// Every dynamic candidate/AI value is a text node. Never render it as markup.
function element(tag, className, content) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (content !== undefined) node.textContent = content;
  return node;
}
function announce(message) { $('announcement').textContent = message; }
function clearTechnicalDetails() {
  $('error-details').hidden = true;
  $('error-details').open = false;
  for (const field of ['category', 'stage', 'status']) {
    $(`diagnostic-${field}`).textContent = '';
    $(`diagnostic-${field}-row`).hidden = true;
  }
}
function clearError() { $('error-notice').hidden = true; $('error-message').textContent = ''; clearTechnicalDetails(); }
function showError(error, suffix = '') {
  clearTechnicalDetails();
  $('error-message').textContent = (error instanceof ApiError ? error.message : 'Something went wrong. Your draft is still here.') + suffix;
  // ErrorResponse on the backend supplies diagnostics only for local/development + APP_DEBUG=true.
  // No client URL flag or browser-storage setting can enable this section in production.
  const diagnostics = error instanceof ApiError ? sanitizeDiagnostics(error.diagnostics) : {};
  for (const [field, key] of [['category', 'category'], ['stage', 'stage'], ['status', 'upstream_http_status']]) {
    if (diagnostics[key] !== undefined) {
      $(`diagnostic-${field}`).textContent = String(diagnostics[key]);
      $(`diagnostic-${field}-row`).hidden = false;
      $('error-details').hidden = false;
    }
  }
  $('error-notice').hidden = false;
  $('error-notice').scrollIntoView({ block: 'nearest', behavior: 'auto' });
}
function remember(id) { try { sessionStorage.setItem(storageKey, id); } catch { /* Storage can be disabled. */ } }
function remembered() { try { return sessionStorage.getItem(storageKey); } catch { return null; } }
function forget() { try { sessionStorage.removeItem(storageKey); } catch { /* Storage can be disabled. */ } }

function draftStatus(message) {
  if ($('draft-storage-status').textContent !== message) $('draft-storage-status').textContent = message;
}
function persistDraft(pending = needsRefresh) {
  const snapshot = captureDraft(session, $('answer-text').value, pending);
  if (!snapshot) return;
  const saved = snapshot.text || pending ? draftStore.write(snapshot)
    : draftStore.clearFor(snapshot.session_id, snapshot.question_sequence);
  draftStatus(saved ? (snapshot.text ? 'Draft remembered in this tab.' : 'Unsent drafts are remembered in this tab.')
    : 'Browser storage is unavailable. Keep this tab open to retain your draft.');
}

function updateControls() {
  for (const control of controls) control.disabled = busy;
  $('submit-answer').disabled = busy || needsRefresh || !session || session.status !== 'active';
  $('answer-text').disabled = busy || !session || session.status !== 'active';
  $('back-to-session').hidden = !session;
}
function setBusy(value, title = '', message = '') {
  busy = value;
  $('main').setAttribute('aria-busy', String(value));
  $('loading-notice').hidden = !value;
  $('loading-title').textContent = title;
  $('loading-message').textContent = message;
  updateControls();
  if (value) announce(title);
}
function updateCounter(inputId, counterId, limit) {
  const input = $(inputId);
  const count = byteLength(input.value);
  $(counterId).textContent = `${count.toLocaleString('en-US')} / ${limit.toLocaleString('en-US')} bytes`;
  $(counterId).classList.toggle('over-limit', count > limit);
  input.setCustomValidity(count > limit ? `Shorten this text to ${limit.toLocaleString('en-US')} bytes or fewer.` : '');
}
function updateCounters() {
  updateCounter('cv-text', 'cv-counter', LIMITS.cv_text);
  updateCounter('job-description', 'job-counter', LIMITS.job_description);
  updateCounter('answer-text', 'answer-counter', LIMITS.answer);
  $('target-role').setCustomValidity(byteLength($('target-role').value) > LIMITS.target_role ? 'Shorten the role name to 200 bytes or fewer.' : '');
}
function setStep(step) {
  const steps = ['prepare', 'interview', 'report'];
  for (const [index, name] of steps.entries()) {
    const node = $(`step-${name}`);
    node.classList.toggle('current', name === step);
    node.classList.toggle('done', index < steps.indexOf(step));
    if (name === step) node.setAttribute('aria-current', 'step');
    else node.removeAttribute('aria-current');
  }
}
function showPreparation() {
  if (busy) return;
  clearError();
  $('preparation-screen').hidden = false;
  $('workspace-screen').hidden = true;
  $('page-location').textContent = 'Interview preparation';
  setStep('prepare');
  updateControls();
  $('preparation-title').focus();
}

function showAccessGate() {
  $('demo-access-screen').hidden = false;
  $('preparation-screen').hidden = true;
  $('workspace-screen').hidden = true;
  $('demo-access-title').focus();
}

function showApplication() {
  $('demo-access-screen').hidden = true;
  $('demo-logout-button').hidden = !accessRequired;
  if (session) renderSession();
  else {
    $('preparation-screen').hidden = false;
    $('workspace-screen').hidden = true;
    const id = remembered();
    if (id && uuidPattern.test(id)) {
      $('resume-id').value = id;
      loadSession(id, true);
    }
  }
}

async function initializeDemoAccess() {
  try {
    const result = await demoAccessApi.status();
    accessRequired = result.access_required;
    csrfToken = result.csrf_token;
    if (result.authenticated) showApplication();
    else showAccessGate();
  } catch (error) {
    showAccessGate();
    showError(error);
  }
}

function renderEvaluations() {
  const list = $('evaluation-list');
  list.replaceChildren();
  for (const evaluation of session.evaluations) {
    if (evaluation.evaluation_type !== 'claim_verification') continue;
    const data = evaluation.data;
    const review = element('article', 'evaluation-item');
    review.append(element('h3', '', 'Claim evidence review'));
    const labels = { supported: 'Supported by supplied context', unclear: 'Evidence is unclear', unsupported: 'Not supported by supplied context' };
    if (labels[data.evidence]) review.append(element('span', 'evidence-badge', labels[data.evidence]));
    if (typeof data.explanation === 'string') review.append(element('p', '', data.explanation));
    if (typeof data.follow_up_focus === 'string') review.append(element('p', 'evaluation-focus', `Follow-up focus: ${data.follow_up_focus}`));
    list.append(review);
  }
  $('evaluation-empty').hidden = list.childElementCount > 0;
  $('evaluation-empty').textContent = session.status === 'finished'
    ? 'No separate claim evidence reviews were returned in this session. See your readiness report above.'
    : 'No evaluation has been returned yet. Your coach may ask a follow-up before drawing a conclusion.';
}

function renderHistory() {
  const list = $('history-list');
  list.replaceChildren();
  for (const answer of session.answers) {
    const entry = element('details', 'journal-entry');
    const index = Number.isInteger(answer.answer_no) ? answer.answer_no : session.answers.indexOf(answer) + 1;
    entry.append(element('summary', '', `Answer ${index} · ${answer.question_text}`));
    entry.append(element('p', 'journal-label', 'YOUR ANSWER'), element('p', '', answer.answer_text));
    list.append(entry);
  }
  $('history-empty').hidden = session.answers.length > 0;
  $('journal-count').textContent = session.answer_count ? `${session.answer_count} saved` : 'No saved answers yet';
}

function renderSession(focusQuestion = false) {
  $('preparation-screen').hidden = true;
  $('workspace-screen').hidden = false;
  const finished = session.status === 'finished';
  const decision = currentQuestionDecision(session);
  const focus = typeof decision?.arguments.focus === 'string' ? decision.arguments.focus : '';
  $('session-role').textContent = session.target_role;
  $('session-id').textContent = session.id;
  $('session-state-label').textContent = finished ? 'Session complete' : 'In progress';
  $('answer-count').textContent = `${session.answer_count} ${session.answer_count === 1 ? 'answer' : 'answers'} saved`;
  $('page-location').textContent = finished ? 'Readiness report' : 'Interview practice';
  $('workspace-title').textContent = finished ? 'Good practice. Clearer direction.' : 'Let your experience speak.';
  setStep(finished ? 'report' : 'interview');
  $('plan-depth').textContent = ['beginner', 'intermediate', 'advanced'].includes(session.plan.suggested_depth)
    ? `${session.plan.suggested_depth[0].toUpperCase()}${session.plan.suggested_depth.slice(1)} depth` : 'Personalized plan';
  $('plan-rationale').textContent = session.plan.rationale;
  $('focus-list').replaceChildren();
  for (const area of session.plan.focus_areas) {
    const item = element('li', area === focus && !finished ? 'active' : '', area);
    $('focus-list').append(item);
  }
  $('question-card').hidden = finished;
  $('answer-card').hidden = finished;
  $('readiness-card').hidden = !finished;
  if (finished) {
    const report = readinessReport(session);
    const labels = { ready: 'Ready', developing: 'Developing', not_ready: 'Not ready yet' };
    $('readiness-label').hidden = !report?.readiness;
    $('readiness-label').textContent = report?.readiness ? labels[report.readiness] : '';
    $('readiness-label').className = `readiness-label ${report?.readiness ?? ''}`;
    $('readiness-summary').textContent = report?.summary || 'The interview is complete, but no readiness summary was returned.';
  } else {
    const probing = decision?.action === 'probe_answer';
    $('question-kind').textContent = probing ? 'Follow-up' : 'New question';
    $('question-kind').classList.toggle('follow-up', probing);
    $('question-focus').textContent = focus;
    $('question-text').textContent = session.current_question;
    $('probe-reason').hidden = !probing || typeof decision.arguments.probe_reason !== 'string';
    $('probe-reason').textContent = probing && typeof decision.arguments.probe_reason === 'string' ? decision.arguments.probe_reason : '';
    const questionCount = session.decisions.filter(item => ['ask_question', 'probe_answer'].includes(item.action)).length;
    $('question-number').textContent = `Question ${questionCount}`;
  }
  renderEvaluations();
  renderHistory();
  remember(session.id);
  updateControls();
  if (needsRefresh) showError(new ApiError('Refresh progress to confirm your saved session before submitting again.', 'validation'));
  if (focusQuestion) {
    (finished ? $('readiness-title') : $('question-text')).focus();
    announce(finished ? 'Interview complete. Your readiness report is available.' : `${decision?.action === 'probe_answer' ? 'Follow-up' : 'New question'} ready.`);
  }
}

$('preparation-form').addEventListener('submit', async event => {
  event.preventDefault();
  if (busy) return;
  updateCounters();
  if (!$('preparation-form').reportValidity()) return;
  clearError();
  setBusy(true, 'Building your personalized interview', 'Your coach is reviewing your brief and choosing an opening question. This can take a moment.');
  try {
    const next = await api.start({ target_role: $('target-role').value, cv_text: $('cv-text').value, job_description: $('job-description').value });
    session = next;
    failedSubmission = null;
    needsRefresh = false;
    draftStore.clear();
    $('preparation-form').reset();
    $('cv-upload-status').textContent = 'Or paste your CV below. Review and edit the text before starting.';
    $('answer-text').value = '';
    $('draft-backup').hidden = true;
    $('draft-backup-text').textContent = '';
    draftStatus('Unsent drafts are remembered in this tab.');
    updateCounters();
    renderSession(true);
  } catch (error) {
    const ambiguous = error instanceof ApiError && ['network', 'timeout', 'response'].includes(error.kind);
    showError(error, ambiguous ? ' A session may have been created, but its ID was not received. Starting again would create a separate session.' : '');
    if (error instanceof ApiError && error.status === 401 && accessRequired) showAccessGate();
  } finally { setBusy(false); }
});

$('answer-form').addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || needsRefresh || !session || session.status !== 'active') return;
  updateCounters();
  if (!$('answer-form').reportValidity()) return;
  clearError();
  const before = session;
  const draft = $('answer-text').value;
  submittingAnswer = true;
  persistDraft(true); // Write before the POST so navigation cannot lose an in-flight answer.
  setBusy(true, 'Your coach is considering your answer', 'Looking at the details and deciding whether to go deeper, explore another focus, or finish the interview.');
  try {
    session = await api.answer(before.id, draft);
    draftStore.clearFor(before.id, currentQuestionDecision(before)?.sequence_no);
    $('answer-text').value = '';
    failedSubmission = null;
    needsRefresh = false;
    draftStatus('Unsent drafts are remembered in this tab.');
    updateCounters();
    renderSession(true);
  } catch (error) {
    if (!(error instanceof ApiError) || error.kind !== 'validation') {
      needsRefresh = true;
      failedSubmission = { before, draft };
    }
    persistDraft(needsRefresh);
    showError(error, needsRefresh ? ' Use Refresh progress to check the saved session before resubmitting.' : '');
    if (error instanceof ApiError && error.status === 401 && accessRequired) showAccessGate();
  } finally { submittingAnswer = false; setBusy(false); }
});

async function loadSession(id, restoring = false) {
  if (busy) return;
  clearError();
  setBusy(true, restoring ? 'Restoring your interview' : 'Checking your saved progress', 'Loading the latest saved session. This does not ask the AI for another question.');
  try {
    const next = await api.progress(id);
    const inMemory = captureDraft(session, $('answer-text').value, needsRefresh);
    const snapshot = inMemory && (inMemory.text || inMemory.needs_refresh) ? inMemory : draftStore.read();
    const recovery = recoverDraft(snapshot, next);
    let restoredPending = false;
    if (recovery.kind === 'current') {
      $('answer-text').value = recovery.text;
      // A reload never triggers another POST. Previously pending submissions require an explicit refresh.
      restoredPending = restoring && recovery.needs_refresh;
      const saved = draftStore.write({ ...snapshot, needs_refresh: restoredPending });
      draftStatus(saved ? 'Draft recovered in this tab.' : 'Browser storage is unavailable. Keep this tab open to retain your draft.');
    } else if (recovery.kind === 'saved') {
      $('answer-text').value = '';
      draftStore.clearFor(snapshot.session_id, snapshot.question_sequence);
      draftStatus('Your previous answer is already saved.');
    } else if (recovery.kind === 'previous') {
      $('draft-backup-text').textContent = recovery.text;
      $('draft-backup').hidden = !recovery.text.trim();
      $('answer-text').value = '';
      draftStore.write({ ...snapshot, needs_refresh: false });
      draftStatus('The saved session has moved on. Your unsent draft is kept below for reference.');
    }
    // Reconcile ambiguous POSTs before resubmission. Do not attach an old draft to a new question.
    const previous = failedSubmission?.before ?? session;
    if (recovery.kind === 'unrelated' && previous && (previous.id !== next.id || sessionAdvanced(previous, next))) {
      const draft = $('answer-text').value;
      const wasSaved = next.answers.some(answer => answer.answer_text === draft.trim()
        && answer.question_text === previous.current_question);
      if (draft.trim() && !wasSaved) {
        $('draft-backup-text').textContent = draft;
        $('draft-backup').hidden = false;
      }
      $('answer-text').value = '';
    }
    session = next;
    failedSubmission = null;
    needsRefresh = restoredPending;
    updateCounters();
    renderSession(true);
  } catch (error) {
    if (restoring && error instanceof ApiError && error.status === 404) forget();
    showError(error);
    if (error instanceof ApiError && error.status === 401 && accessRequired) showAccessGate();
  } finally { setBusy(false); }
}
$('refresh-button').addEventListener('click', () => { if (session) loadSession(session.id); });
$('resume-form').addEventListener('submit', event => {
  event.preventDefault();
  if (busy || !$('resume-form').reportValidity()) return;
  const id = $('resume-id').value.trim();
  if (!uuidPattern.test(id)) { showError(new ApiError('Enter a valid interview session ID.', 'validation')); return; }
  loadSession(id, true);
});
$('demo-access-form').addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !$('demo-access-form').reportValidity()) return;
  clearError();
  const code = $('demo-access-code').value;
  setBusy(true, 'Checking your invitation', 'Verifying the judge access code securely.');
  $('demo-access-code').value = '';
  try {
    const result = await demoAccessApi.login(code);
    accessRequired = result.access_required;
    csrfToken = result.csrf_token;
    if (accessRequired && (!csrfToken || !/^[a-f0-9]{64}$/.test(csrfToken))) {
      throw new ApiError('The secure demo session could not be established. Reload and try again.', 'access');
    }
    if (session) {
      $('demo-access-screen').hidden = true;
      await loadSession(session.id, true);
    } else {
      showApplication();
      announce('Judge access granted. Prepare your fictional practice session.');
    }
  } catch (error) {
    showAccessGate();
    showError(error);
    $('demo-access-code').focus();
  } finally { setBusy(false); }
});
$('demo-logout-button').addEventListener('click', async () => {
  if (busy || !accessRequired || !csrfToken) return;
  clearError();
  setBusy(true, 'Signing out', 'Closing this judge access session.');
  try {
    await demoAccessApi.logout(csrfToken);
    csrfToken = null;
    session = null;
    failedSubmission = null;
    needsRefresh = false;
    draftStore.clear();
    forget();
    showAccessGate();
    announce('Signed out of the JobReady judge preview.');
  } catch (error) { showError(error); }
  finally { setBusy(false); }
});
$('back-to-session').addEventListener('click', () => { if (!busy && session) { clearError(); renderSession(true); } });
async function uploadCv(file) {
  if (busy || !file) return;
  clearError();
  setBusy(true, 'Reading your CV document', 'Extracting readable text on the server. You will review it before starting an interview.');
  let loaded = false;
  try {
    const result = await uploadApi.extract(file);
    $('cv-text').value = result.text;
    $('cv-upload-status').textContent = byteLength(result.text) > LIMITS.cv_text
      ? 'Text extracted. Shorten it to 20,000 bytes or fewer, then review before starting.'
      : `${result.format.toUpperCase()} text extracted. Review and edit it below before starting.`;
    updateCounters();
    loaded = true;
    announce('CV text extracted. Review it before starting your interview.');
  } catch (error) {
    $('cv-upload-status').textContent = 'Your existing CV text is unchanged. Choose another file or paste text.';
    showError(error);
  } finally {
    $('cv-file').value = '';
    setBusy(false);
    if (loaded) $('cv-text').focus();
  }
}
$('cv-file').addEventListener('change', () => uploadCv($('cv-file').files?.[0]));
$('cv-dropzone').addEventListener('dragover', event => {
  event.preventDefault();
  if (!busy) $('cv-dropzone').classList.toggle('drag-active', true);
});
$('cv-dropzone').addEventListener('dragleave', () => $('cv-dropzone').classList.toggle('drag-active', false));
$('cv-dropzone').addEventListener('drop', event => {
  event.preventDefault();
  $('cv-dropzone').classList.toggle('drag-active', false);
  if (busy) return;
  const files = event.dataTransfer?.files;
  if (!files || files.length !== 1) { showError(new ApiError('Choose one PDF or DOCX document at a time.', 'upload')); return; }
  return uploadCv(files[0]);
});
for (const id of ['new-session-button', 'report-new-session']) $(id).addEventListener('click', showPreparation);
for (const id of ['cv-text', 'job-description', 'target-role', 'answer-text']) $(id).addEventListener('input', updateCounters);
$('answer-text').addEventListener('input', () => persistDraft());
$('answer-text').addEventListener('keydown', event => {
  if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
    event.preventDefault();
    if (!busy && !needsRefresh) $('answer-form').requestSubmit();
  }
});
// Warn only while an operation or unsaved answer could be lost; never log private input.
window.addEventListener('beforeunload', event => {
  persistDraft(needsRefresh || submittingAnswer);
  if (busy || $('answer-text').value.trim() || (!$('draft-backup').hidden && $('draft-backup-text').textContent.trim())) {
    event.preventDefault(); event.returnValue = '';
  }
});
window.addEventListener('pagehide', () => persistDraft(needsRefresh || submittingAnswer));
updateCounters();
updateControls();
initializeDemoAccess();
