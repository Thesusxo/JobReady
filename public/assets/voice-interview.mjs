const noop = () => {};
const SILENCE_RESUME_DELAY_MS = 450;
const NETWORK_RETRY_DELAYS_MS = [500, 1000, 2000];
const MAX_NETWORK_RETRIES = NETWORK_RETRY_DELAYS_MS.length;
const MAX_START_RETRIES = 3;

const recognitionMessages = Object.freeze({
  'not-allowed': 'Microphone permission was denied. Allow access in browser settings, or type your answer.',
  'service-not-allowed': 'The browser speech service is unavailable. Try again later or type your answer.',
  'audio-capture': 'No microphone is available. Connect a microphone or type your answer.',
  'language-not-supported': 'This browser does not support speech recognition for the selected language. You can type your answer.',
  'bad-grammar': 'This browser could not start speech recognition. Try again or type your answer.',
});

export function createVoiceInterview({
  windowObject = globalThis.window,
  onStatus = noop,
  onPreview = noop,
  onTranscript = noop,
  onStateChange = noop,
  setTimeoutFn = globalThis.setTimeout,
  clearTimeoutFn = globalThis.clearTimeout,
} = {}) {
  const synthesis = windowObject?.speechSynthesis ?? null;
  const Utterance = windowObject?.SpeechSynthesisUtterance ?? null;
  const Recognition = windowObject?.SpeechRecognition ?? windowObject?.webkitSpeechRecognition ?? null;
  let currentQuestion = '';
  let currentUtterance = null;
  let speechSequence = 0;
  let speaking = false;
  let hasSpokenQuestion = false;
  let dictationEnabled = false;
  let recognitionState = 'stopped';
  let attemptedRecognition = false;
  let sessionSequence = 0;
  let currentRecognition = null;
  let restartTimer = null;
  let networkRetries = 0;
  let startRetries = 0;

  const speechSupported = () => Boolean(synthesis && typeof synthesis.speak === 'function' && typeof Utterance === 'function');
  const recognitionSupported = () => typeof Recognition === 'function';
  const language = () => windowObject?.document?.documentElement?.lang
    ?? globalThis.document?.documentElement?.lang ?? 'en-US';

  function state() {
    return {
      speaking,
      dictationEnabled,
      recognitionState,
      listening: recognitionState === 'listening',
      canStart: !dictationEnabled && currentRecognition === null,
      hasSpokenQuestion,
      attemptedRecognition,
      speechSupported: speechSupported(),
      recognitionSupported: recognitionSupported(),
      networkRetries,
    };
  }

  function changed() { onStateChange(state()); }
  function status(message) { onStatus(message); }

  function clearRestartTimer() {
    if (restartTimer === null) return;
    clearTimeoutFn(restartTimer);
    restartTimer = null;
  }

  function stopSpeech(announceStop = false) {
    speechSequence++;
    try { synthesis?.cancel(); } catch { /* A broken browser speech engine must not affect typing. */ }
    currentUtterance = null;
    const wasSpeaking = speaking;
    speaking = false;
    changed();
    if (announceStop && wasSpeaking) status('Question playback stopped.');
  }

  function speakQuestion() {
    if (!speechSupported()) {
      status('Spoken playback is not supported in this browser. You can read the question and type your answer.');
      return false;
    }
    if (!currentQuestion.trim()) {
      status('There is no active question to read aloud.');
      return false;
    }
    stopSpeech();
    const sequence = ++speechSequence;
    try {
      const utterance = new Utterance(currentQuestion);
      utterance.lang = language();
      utterance.rate = 1;
      utterance.onstart = () => {
        if (sequence !== speechSequence) return;
        speaking = true;
        hasSpokenQuestion = true;
        changed();
        status('Reading the current interview question.');
      };
      utterance.onend = () => {
        if (sequence !== speechSequence) return;
        currentUtterance = null;
        speaking = false;
        hasSpokenQuestion = true;
        changed();
        status('Question playback finished.');
      };
      utterance.onerror = () => {
        if (sequence !== speechSequence) return;
        currentUtterance = null;
        speaking = false;
        changed();
        status('Spoken playback could not finish. You can read the question and type your answer.');
      };
      currentUtterance = utterance;
      hasSpokenQuestion = true;
      speaking = true;
      synthesis.speak(utterance);
      changed();
      return true;
    } catch {
      currentUtterance = null;
      speaking = false;
      changed();
      status('Spoken playback could not start. You can read the question and type your answer.');
      return false;
    }
  }

  function fatalMessage(error) {
    return recognitionMessages[error] ?? 'Speech recognition stopped unexpectedly. You can retry or type your answer.';
  }

  function appendFinal(record) {
    const transcript = record.finalTranscript.trim();
    if (!transcript || record.discard || record.committed) return;
    record.committed = true;
    try { onTranscript(transcript); } catch {
      status('The transcript could not be added. You can still type your answer.');
    }
  }

  function stopWithError(message, record = null) {
    dictationEnabled = false;
    clearRestartTimer();
    if (record) {
      record.fatal = true;
      try { record.instance.abort(); } catch { /* Permission/audio errors can end recognition without another event. */ }
    }
    recognitionState = 'error';
    status(message);
    changed();
  }

  function scheduleRestart(sessionId, delay, message) {
    if (!dictationEnabled || sessionId !== sessionSequence || restartTimer !== null) return;
    recognitionState = 'waiting';
    status(message);
    changed();
    restartTimer = setTimeoutFn(() => {
      restartTimer = null;
      if (dictationEnabled && sessionId === sessionSequence) startRecognition(sessionId, true);
    }, delay);
  }

  function scheduleBoundedRetry(sessionId, kind, message) {
    const retries = kind === 'network' ? ++networkRetries : ++startRetries;
    const limit = kind === 'network' ? MAX_NETWORK_RETRIES : MAX_START_RETRIES;
    if (retries > limit) {
      const stoppedMessage = kind === 'network'
        ? 'The browser speech service could not reconnect after several attempts. Try again or type your answer.'
        : 'Speech recognition could not start after several attempts. Check browser permission or type your answer.';
      stopWithError(stoppedMessage);
      return;
    }
    const delay = NETWORK_RETRY_DELAYS_MS[Math.min(retries - 1, NETWORK_RETRY_DELAYS_MS.length - 1)];
    scheduleRestart(sessionId, delay, `${message} Retrying in a moment (${retries} of ${limit}).`);
  }

  function finishRecognition(record) {
    if (currentRecognition !== record) return;
    currentRecognition = null;
    onPreview('');
    if (record.sessionId !== sessionSequence) {
      changed();
      return;
    }

    appendFinal(record);
    if (record.userStop) {
      dictationEnabled = false;
      recognitionState = 'stopped';
      status('Dictation stopped. Review your editable answer; it has not been submitted.');
      changed();
      return;
    }
    if (!dictationEnabled) {
      if (!record.fatal && recognitionState !== 'error') recognitionState = 'stopped';
      changed();
      return;
    }
    if (record.fatal) {
      changed();
      return;
    }
    if (record.failure === 'network' || record.failure === 'unexpected') {
      const message = record.failure === 'network'
        ? 'The browser speech service lost its connection.'
        : 'Speech recognition ended unexpectedly.';
      scheduleBoundedRetry(record.sessionId, 'network', message);
      return;
    }
    if (!record.started) {
      scheduleBoundedRetry(record.sessionId, 'start', 'The microphone did not become ready.');
      return;
    }
    if (record.finalTranscript.trim()) {
      networkRetries = 0;
      startRetries = 0;
      scheduleRestart(record.sessionId, SILENCE_RESUME_DELAY_MS, 'Pause detected. Finalized words were added to your editable answer; listening resumes shortly…');
      return;
    }
    const message = record.failure === 'no-speech'
      ? 'No speech heard. Waiting briefly, then resuming…'
      : 'Speech paused. Waiting briefly, then resuming…';
    scheduleRestart(record.sessionId, SILENCE_RESUME_DELAY_MS, message);
  }

  function startRecognition(sessionId, automatic = false) {
    if (!dictationEnabled || sessionId !== sessionSequence || currentRecognition !== null) return false;
    let instance;
    try { instance = new Recognition(); } catch (error) {
      if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
        stopWithError(fatalRecognitionMessage('not-allowed'));
        return false;
      }
      scheduleBoundedRetry(sessionId, 'start', 'The microphone could not be initialized.');
      return false;
    }

    const record = {
      instance,
      sessionId,
      finalTranscript: '',
      committed: false,
      discard: false,
      fatal: false,
      userStop: false,
      started: false,
      failure: null,
    };
    currentRecognition = record;
    recognitionState = 'starting';
    onPreview('');
    status(automatic ? 'Resuming microphone…' : 'Starting microphone…');
    changed();
    instance.lang = language();
    instance.continuous = true;
    instance.interimResults = true;
    instance.maxAlternatives = 1;
    instance.onstart = () => {
      if (currentRecognition !== record || record.sessionId !== sessionSequence) return;
      record.started = true;
      recognitionState = 'listening';
      status('Listening. You can pause naturally; click Stop dictation when your answer is complete.');
      changed();
    };
    instance.onresult = event => {
      if (currentRecognition !== record || record.discard || record.sessionId !== sessionSequence) return;
      const finals = [];
      const interim = [];
      for (let index = 0; index < event.results.length; index++) {
        const result = event.results[index];
        const text = result?.[0]?.transcript;
        if (typeof text !== 'string') continue;
        (result.isFinal ? finals : interim).push(text.trim());
      }
      record.finalTranscript = finals.filter(Boolean).join(' ').trim();
      const preview = interim.filter(Boolean).join(' ').trim();
      onPreview(preview);
      if (preview) status('Listening. Interim words are not added until finalized.');
    };
    instance.onerror = event => {
      if (currentRecognition !== record || record.sessionId !== sessionSequence) return;
      if (record.fatal) return;
      const error = event?.error ?? 'unknown';
      if (error === 'aborted' && record.userStop) return;
      if (error === 'no-speech') {
        record.failure = 'no-speech';
        recognitionState = 'waiting';
        status('No speech heard. Waiting for the recognition engine to finish…');
        changed();
        return;
      }
      if (error === 'network') {
        record.failure = 'network';
        recognitionState = 'waiting';
        status('Speech service connection interrupted. Waiting to retry…');
        changed();
        return;
      }
      if (error === 'aborted' && dictationEnabled) {
        record.failure = 'unexpected';
        recognitionState = 'waiting';
        status('Recognition stopped unexpectedly. Waiting to retry…');
        changed();
        return;
      }
      record.discard = false;
      stopWithError(fatalMessage(error), record);
    };
    instance.onend = () => finishRecognition(record);

    try {
      instance.start();
      return true;
    } catch (error) {
      if (currentRecognition === record) currentRecognition = null;
      if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
        stopWithError(fatalMessage('not-allowed'));
      } else {
        scheduleBoundedRetry(sessionId, 'start', 'The microphone could not start.');
      }
      return false;
    }
  }

  function startListening() {
    if (!recognitionSupported()) {
      status('Speech recognition is not supported in this browser. Typing is always available.');
      return false;
    }
    if (dictationEnabled || currentRecognition !== null || restartTimer !== null) {
      status('Dictation is already active or resuming. Click Stop dictation before starting again.');
      return false;
    }
    dictationEnabled = true;
    attemptedRecognition = true;
    networkRetries = 0;
    startRetries = 0;
    sessionSequence++;
    recognitionState = 'starting';
    return startRecognition(sessionSequence, false);
  }

  function stopListening({ discard = false } = {}) {
    if (!dictationEnabled && currentRecognition === null && restartTimer === null) return false;
    dictationEnabled = false;
    clearRestartTimer();
    if (currentRecognition !== null) {
      currentRecognition.userStop = true;
      currentRecognition.discard = discard;
      recognitionState = 'stopping';
      status('Stopping dictation…');
      changed();
      try {
        currentRecognition.instance.stop();
      } catch {
        const record = currentRecognition;
        currentRecognition = null;
        record.discard = true;
        try { record.instance.abort(); } catch { /* The browser engine may already have ended. */ }
        recognitionState = 'stopped';
        status('Dictation stopped. Review your editable answer; it has not been submitted.');
        changed();
      }
      return true;
    }
    recognitionState = 'stopped';
    status('Dictation stopped. Review your editable answer; it has not been submitted.');
    changed();
    return true;
  }

  function stopAll({ silent = true } = {}) {
    const hadActivity = speaking || dictationEnabled || currentRecognition !== null || restartTimer !== null;
    dictationEnabled = false;
    sessionSequence++;
    clearRestartTimer();
    stopSpeech();
    const record = currentRecognition;
    currentRecognition = null;
    if (record) {
      record.discard = true;
      try { record.instance.abort(); } catch { /* Navigation must not be blocked by browser cleanup. */ }
    }
    onPreview('');
    recognitionState = 'stopped';
    changed();
    if (hadActivity && !silent) status('Dictation stopped. Your answer was not submitted.');
  }

  function setQuestion(question) {
    const next = typeof question === 'string' ? question : '';
    if (next === currentQuestion) return;
    const hadDictation = dictationEnabled || currentRecognition !== null || restartTimer !== null;
    const hadSpeech = speaking;
    stopAll({ silent: true });
    currentQuestion = next;
    hasSpokenQuestion = false;
    changed();
    if (hadDictation || hadSpeech) status('The question changed. Playback/listening stopped; review the new question before continuing.');
  }

  return Object.freeze({
    supportsSpeech: speechSupported,
    supportsRecognition: recognitionSupported,
    getState: state,
    setQuestion,
    speakQuestion,
    replayQuestion: speakQuestion,
    stopSpeech,
    startListening,
    stopListening,
    retryListening: startListening,
    stopAll,
  });
}
