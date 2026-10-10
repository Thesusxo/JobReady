// Small, dependency-free boundary around the existing PHP JSON contract.
export const LIMITS = Object.freeze({ target_role: 200, cv_text: 20000, job_description: 16000, answer: 8000 });
export const uuidPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const text = value => typeof value === 'string' ? value : '';
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
export const byteLength = value => new TextEncoder().encode(value).length;

export function sanitizeDiagnostics(value) {
  if (!record(value)) return Object.freeze({});
  const result = {};
  const allowed = {
    provider: ['gemini', 'openai'],
    category: ['configuration', 'authentication', 'authorization', 'invalid_request', 'model_unavailable',
      'quota_or_rate_limit', 'provider_unavailable', 'transport', 'invalid_output'],
    stage: ['plan', 'decision', 'claim_verification'],
    provider_code: ['INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'NOT_FOUND',
      'RESOURCE_EXHAUSTED', 'INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED'],
    reason: ['API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_SERVICE_BLOCKED',
      'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED', 'API_KEY_ANDROID_APP_BLOCKED',
      'API_KEY_IOS_APP_BLOCKED', 'SERVICE_DISABLED', 'BILLING_DISABLED', 'CONSUMER_INVALID',
      'MISSING_KEY', 'INVALID_KEY_FORMAT', 'INVALID_MODEL_FORMAT', 'CURL_UNAVAILABLE',
      'REQUEST_ENCODING_FAILED', 'TRANSPORT_INITIALIZATION_FAILED', 'RESPONSE_TOO_LARGE',
      'MALFORMED_JSON', 'INVALID_ENVELOPE', 'BLOCKED_OUTPUT', 'INCOMPLETE_OUTPUT',
      'MISSING_TEXT', 'INVALID_JSON_OBJECT', 'INVALID_PLAN', 'INVALID_DECISION', 'INVALID_EVALUATION'],
  };
  for (const [key, values] of Object.entries(allowed)) {
    if (values.includes(value[key])) result[key] = value[key];
  }
  if (Number.isInteger(value.upstream_http_status) && value.upstream_http_status >= 100 && value.upstream_http_status <= 599) {
    result.upstream_http_status = value.upstream_http_status;
  }
  if (Number.isInteger(value.curl_error_code) && value.curl_error_code >= 0 && value.curl_error_code <= 999) {
    result.curl_error_code = value.curl_error_code;
  }
  return Object.freeze(result);
}

export class ApiError extends Error {
  constructor(message, kind, status = null, diagnostics = {}) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.diagnostics = sanitizeDiagnostics(diagnostics);
  }
}

export function validatePreparation(input) {
  const fields = [
    ['target_role', 'Target role', 2], ['cv_text', 'CV text', 30], ['job_description', 'Job description', 30],
  ];
  const result = {};
  for (const [key, label, minimum] of fields) {
    const value = text(input[key]);
    if (byteLength(value.trim()) < minimum) throw new ApiError(`${label} needs a little more detail.`, 'validation');
    if (byteLength(value) > LIMITS[key]) throw new ApiError(`${label} exceeds the ${LIMITS[key].toLocaleString('en-US')}-byte limit. Please shorten it.`, 'validation');
    result[key] = value.trim();
  }
  return result;
}

export function validateAnswer(value) {
  if (typeof value !== 'string' || !value.trim()) throw new ApiError('Write an answer before submitting.', 'validation');
  if (byteLength(value) > LIMITS.answer) throw new ApiError('Your answer exceeds the 8,000-byte limit. Please shorten it.', 'validation');
  return value.trim();
}

export function parseSession(data) {
  // A malformed success response must not clear drafts or advance the UI.
  if (!record(data) || !uuidPattern.test(data.id) || !['active', 'finished'].includes(data.status)
    || !Number.isInteger(data.answer_count) || data.answer_count < 0
    || !record(data.plan) || !Array.isArray(data.plan.focus_areas)
    || !data.plan.focus_areas.every(value => typeof value === 'string')
    || !Array.isArray(data.answers) || !Array.isArray(data.decisions) || !Array.isArray(data.evaluations)
    || (data.status === 'active' && (typeof data.current_question !== 'string' || !data.current_question.trim()))) {
    throw new ApiError('The server returned an unexpected session response. Refresh progress before submitting again.', 'response');
  }
  return {
    id: data.id,
    target_role: text(data.target_role),
    status: data.status,
    answer_count: data.answer_count,
    current_question: data.status === 'finished' ? null : data.current_question,
    plan: { focus_areas: [...data.plan.focus_areas], rationale: text(data.plan.rationale), suggested_depth: text(data.plan.suggested_depth) },
    answers: data.answers.filter(record).map(answer => ({
      id: answer.id, answer_no: answer.answer_no, question_text: text(answer.question_text), answer_text: text(answer.answer_text),
    })),
    decisions: data.decisions.filter(record).map(decision => ({
      sequence_no: decision.sequence_no, action: text(decision.action), rationale: text(decision.rationale),
      arguments: record(decision.arguments) ? { ...decision.arguments } : {},
      result: record(decision.result) ? { ...decision.result } : {},
    })),
    evaluations: data.evaluations.filter(record).map(evaluation => ({
      evaluation_type: text(evaluation.evaluation_type), data: record(evaluation.data) ? { ...evaluation.data } : {},
    })),
  };
}

export function currentQuestionDecision(session) {
  return [...session.decisions].reverse().find(decision => ['ask_question', 'probe_answer'].includes(decision.action)) ?? null;
}

export function readinessReport(session) {
  const report = [...session.evaluations].reverse().find(evaluation => evaluation.evaluation_type === 'readiness_report');
  // The finished decision also carries the same backend report; no client-generated assessment.
  const finish = [...session.decisions].reverse().find(decision => decision.action === 'finish_interview');
  const data = report?.data ?? finish?.result;
  if (!record(data)) return null;
  return { summary: text(data.summary), readiness: ['not_ready', 'developing', 'ready'].includes(data.readiness) ? data.readiness : null };
}

export function sessionAdvanced(before, after) {
  return before.id === after.id && (after.answer_count > before.answer_count
    || after.status === 'finished'
    || currentQuestionDecision(before)?.sequence_no !== currentQuestionDecision(after)?.sequence_no);
}

function errorForStatus(status, diagnostics = {}) {
  const messages = {
    400: 'The request could not be read. Check your input and try again.',
    404: 'This interview could not be found. Check the session ID or start a new session.',
    409: 'This session has changed or is no longer awaiting an answer. Refresh progress to continue.',
    413: 'Your interview brief is too large. Shorten the text and try again.',
    422: 'Some input is missing or outside the allowed limits. Review the fields and try again.',
    429: 'The service is busy. Wait a moment before trying again.',
    502: 'Your coach returned a response that could not be validated. Your draft is still here.',
    503: 'The AI interview service is temporarily unavailable. Your draft is still here.',
  };
  const diagnosticMessages = {
    configuration: 'The AI coach is not configured correctly. Your draft is preserved; the service configuration needs to be checked.',
    authentication: 'The AI service could not authenticate. Your draft is preserved; the service credentials need to be checked.',
    authorization: 'The AI service denied access. Your draft is preserved; the service permissions need to be checked.',
    invalid_request: 'The AI service could not accept this request. Your draft is preserved; the request configuration needs to be checked.',
    model_unavailable: 'The selected AI model is unavailable. Your draft is preserved; the service model needs to be checked.',
    quota_or_rate_limit: 'Your AI coach has reached a usage or rate limit. Your draft is preserved. Wait before trying again.',
    provider_unavailable: 'The AI provider is temporarily unavailable. Your draft is preserved. Try again later.',
    transport: 'The connection to the AI provider failed or timed out. Your draft is preserved.',
    invalid_output: 'Your coach returned an incomplete or unusable reply. Your draft is preserved.',
  };
  const safe = sanitizeDiagnostics(diagnostics);
  return new ApiError(diagnosticMessages[safe.category]
    ?? messages[status] ?? 'The request could not be completed. Your draft is still here.', 'http', status, safe);
}

export function createInterviewApi(fetchRequest = globalThis.fetch, timeoutMs = 90000) {
  async function request(path, method = 'GET', payload) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetchRequest(path, {
        method, signal: controller.signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        headers: { Accept: 'application/json', ...(payload ? { 'Content-Type': 'application/json' } : {}) },
        ...(payload ? { body: JSON.stringify(payload) } : {}),
      });
      if (!response.ok) {
        let diagnostics = {};
        try {
          const failure = await response.json();
          if (record(failure)) diagnostics = sanitizeDiagnostics(failure.diagnostics);
        } catch { /* Non-JSON failures still get a safe status-based message. */ }
        // Never retain free-form error text, raw provider bodies, or request data.
        throw errorForStatus(response.status, diagnostics);
      }
      let data;
      try { data = await response.json(); }
      catch { throw new ApiError('The server returned an unreadable response. Refresh progress before submitting again.', 'response'); }
      return parseSession(data);
    } catch (error) {
      if (error instanceof ApiError) throw error;
      if (controller.signal.aborted) throw new ApiError('The request timed out. It may have reached the server; refresh progress before submitting again.', 'timeout');
      throw new ApiError('The connection was interrupted. Your draft is still here. Refresh progress before submitting again.', 'network');
    } finally {
      clearTimeout(timeout);
    }
  }
  function sessionPath(id) {
    if (!uuidPattern.test(id)) throw new ApiError('Enter a valid interview session ID.', 'validation');
    return `/api/interviews/${id}`;
  }
  return {
    start: input => request('/api/interviews', 'POST', validatePreparation(input)),
    answer: (id, value) => request(`${sessionPath(id)}/answers`, 'POST', { answer: validateAnswer(value) }),
    progress: id => request(sessionPath(id)),
  };
}
