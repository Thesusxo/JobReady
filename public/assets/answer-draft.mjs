import { byteLength, currentQuestionDecision, uuidPattern } from './interview-api.mjs';

export const DRAFT_STORAGE_KEY = 'jobready.answer-draft';

function sanitizeDraft(value) {
  if (!value || typeof value !== 'object' || !uuidPattern.test(value.session_id)
    || !Number.isInteger(value.question_sequence) || value.question_sequence < 1
    || !Number.isInteger(value.answer_count) || value.answer_count < 0
    || typeof value.text !== 'string' || value.text.length > 8000 || byteLength(value.text) > 32000
    || typeof value.needs_refresh !== 'boolean') return null;
  // Never persist the profile, complete session, diagnostics, or unrelated storage fields.
  return { session_id: value.session_id, question_sequence: value.question_sequence,
    answer_count: value.answer_count, text: value.text, needs_refresh: value.needs_refresh };
}

export function captureDraft(session, text, needsRefresh = false) {
  if (!session || session.status !== 'active') return null;
  return sanitizeDraft({ session_id: session.id, question_sequence: currentQuestionDecision(session)?.sequence_no,
    answer_count: session.answer_count, text, needs_refresh: needsRefresh });
}

export function recoverDraft(draft, session) {
  const snapshot = sanitizeDraft(draft);
  if (!snapshot || snapshot.session_id !== session.id) return { kind: 'unrelated' };
  if (session.status === 'active' && session.answer_count === snapshot.answer_count
    && currentQuestionDecision(session)?.sequence_no === snapshot.question_sequence) {
    return { kind: 'current', text: snapshot.text, needs_refresh: snapshot.needs_refresh };
  }
  const saved = session.answers.some(answer => answer.answer_no === snapshot.answer_count + 1
    && answer.answer_text === snapshot.text.trim());
  return saved ? { kind: 'saved' } : { kind: 'previous', text: snapshot.text };
}

export function createDraftStore(storageAccessor = () => globalThis.sessionStorage) {
  let memory;
  return {
    read() {
      if (memory !== undefined) return memory;
      try {
        const raw = storageAccessor().getItem(DRAFT_STORAGE_KEY);
        return raw ? sanitizeDraft(JSON.parse(raw)) : null;
      } catch { return null; }
    },
    write(value) {
      const draft = sanitizeDraft(value);
      if (!draft) return false;
      memory = draft;
      try { storageAccessor().setItem(DRAFT_STORAGE_KEY, JSON.stringify(draft)); return true; }
      catch { return false; }
    },
    clear() {
      memory = null;
      try { storageAccessor().removeItem(DRAFT_STORAGE_KEY); return true; }
      catch { return false; }
    },
    clearFor(sessionId, sequence) {
      const draft = this.read();
      if (draft?.session_id === sessionId && draft.question_sequence === sequence) return this.clear();
      return true;
    },
  };
}
