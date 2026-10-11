import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createVoiceInterview } from '../public/assets/voice-interview.mjs';

class FakeUtterance {
  constructor(text) { this.text = text; }
}

class FakeRecognition {
  static instances = [];
  static startFailures = 0;
  constructor() { FakeRecognition.instances.push(this); }
  start() {
    this.startCalls = (this.startCalls ?? 0) + 1;
    if (FakeRecognition.startFailures > 0) {
      FakeRecognition.startFailures--;
      const error = new Error('synthetic invalid state');
      error.name = 'InvalidStateError';
      throw error;
    }
  }
  stop() { this.stopCalls = (this.stopCalls ?? 0) + 1; this.onend?.(); }
  abort() { this.abortCalls = (this.abortCalls ?? 0) + 1; this.onend?.(); }
}

function fakeTimers() {
  let nextId = 0;
  const tasks = new Map();
  return {
    tasks,
    setTimeoutFn(callback, delay) {
      const id = ++nextId;
      tasks.set(id, { callback, delay });
      return id;
    },
    clearTimeoutFn(id) { tasks.delete(id); },
    runNext() {
      const entry = tasks.entries().next().value;
      if (!entry) return false;
      const [id, task] = entry;
      tasks.delete(id);
      task.callback();
      return task.delay;
    },
  };
}

function mockBrowser({ speech = true, recognition = true, timers = fakeTimers() } = {}) {
  const utterances = [];
  const synthesis = {
    cancelCalls: 0,
    speak(utterance) { utterances.push(utterance); },
    cancel() { this.cancelCalls++; },
  };
  const windowObject = { document: { documentElement: { lang: 'en-GB' } } };
  if (speech) {
    windowObject.SpeechSynthesisUtterance = FakeUtterance;
    windowObject.speechSynthesis = synthesis;
  }
  if (recognition) windowObject.SpeechRecognition = FakeRecognition;
  return { windowObject, synthesis, utterances, timers };
}

function makeVoice(browser, callbacks = {}) {
  return createVoiceInterview({
    windowObject: browser.windowObject,
    setTimeoutFn: browser.timers.setTimeoutFn,
    clearTimeoutFn: browser.timers.clearTimeoutFn,
    ...callbacks,
  });
}

function finalResult(transcript) {
  const result = [{ transcript }];
  result.isFinal = true;
  return result;
}

test('question speech starts only on Listen, supports Replay/Stop, and cancels on a changed question', () => {
  const browser = mockBrowser();
  const statuses = [];
  const voice = makeVoice(browser, { onStatus: value => statuses.push(value) });
  assert.equal(browser.utterances.length, 0, 'Speech must never autoplay on controller creation.');
  voice.setQuestion('Describe a fictional project.');
  assert.equal(browser.utterances.length, 0, 'Setting a question must not autoplay.');
  assert.equal(voice.speakQuestion(), true);
  assert.equal(browser.utterances[0].text, 'Describe a fictional project.');
  assert.equal(browser.utterances[0].lang, 'en-GB');
  browser.utterances[0].onstart();
  assert.equal(voice.getState().speaking, true);
  assert.equal(voice.replayQuestion(), true);
  assert.equal(browser.utterances.length, 2);
  browser.utterances[1].onstart();
  voice.stopSpeech(true);
  assert.equal(voice.getState().speaking, false);
  voice.speakQuestion();
  const previousUtterance = browser.utterances.at(-1);
  voice.setQuestion('A different fictional question.');
  assert.ok(browser.synthesis.cancelCalls >= 3);
  assert.equal(voice.getState().hasSpokenQuestion, false);
  previousUtterance.onend?.();
  assert.match(statuses.at(-1), /question changed/i);
});

test('continuous dictation pauses, restarts automatically, and preserves each final transcript once', () => {
  FakeRecognition.instances = [];
  FakeRecognition.startFailures = 0;
  const browser = mockBrowser();
  const transcripts = [];
  const voice = makeVoice(browser, { onTranscript: text => transcripts.push(text) });
  assert.equal(voice.startListening(), true);
  assert.equal(voice.getState().dictationEnabled, true);
  assert.equal(voice.getState().recognitionState, 'starting');
  let recognition = FakeRecognition.instances[0];
  assert.equal(recognition.continuous, true);
  assert.equal(recognition.interimResults, true);
  assert.equal(voice.startListening(), false, 'A second click must not create a concurrent recognizer.');
  assert.equal(FakeRecognition.instances.length, 1);

  recognition.onstart();
  assert.equal(voice.getState().recognitionState, 'listening');
  recognition.onresult({ results: [{ 0: { transcript: 'Interim phrase.' }, isFinal: false }] });
  assert.deepEqual(transcripts, [], 'Interim results must not be committed to the answer.');
  recognition.onresult({ results: [finalResult('I wrote fictional PHP tests.')] });
  recognition.onend();
  assert.deepEqual(transcripts, ['I wrote fictional PHP tests.']);
  assert.equal(voice.getState().dictationEnabled, true, 'A natural browser end must not stop the logical session.');
  assert.equal(voice.getState().recognitionState, 'waiting');
  assert.equal(voice.getState().listening, false, 'Waiting must not be presented as active microphone capture.');
  assert.equal(browser.timers.runNext(), 450);
  assert.equal(FakeRecognition.instances.length, 2, 'Recognition should restart without another user click.');
  recognition = FakeRecognition.instances[1];
  assert.equal(recognition.startCalls, 1);
  recognition.onstart();
  recognition.onresult({ results: [finalResult('I added a fictional regression test.')] });
  recognition.onend();
  assert.deepEqual(transcripts, ['I wrote fictional PHP tests.', 'I added a fictional regression test.']);
  assert.equal(voice.getState().dictationEnabled, true);
  assert.equal(voice.getState().recognitionState, 'waiting');
});

test('temporary no-speech silence resumes and explicit Stop finalizes without restarting', () => {
  FakeRecognition.instances = [];
  const browser = mockBrowser();
  const transcripts = [];
  const voice = makeVoice(browser, { onTranscript: value => transcripts.push(value) });
  voice.startListening();
  const first = FakeRecognition.instances[0];
  first.onstart();
  first.onerror({ error: 'no-speech' });
  first.onend();
  assert.equal(voice.getState().dictationEnabled, true);
  assert.equal(voice.getState().recognitionState, 'waiting');
  assert.equal(voice.getState().networkRetries, 0, 'Normal silence should not consume network retry attempts.');
  assert.equal(browser.timers.runNext(), 450);
  assert.equal(FakeRecognition.instances.length, 2);

  const second = FakeRecognition.instances[1];
  second.onstart();
  second.onresult({ results: [finalResult('A fictional spoken answer.')] });
  voice.stopListening();
  assert.deepEqual(transcripts, ['A fictional spoken answer.']);
  assert.equal(voice.getState().dictationEnabled, false);
  assert.equal(voice.getState().recognitionState, 'stopped');
  assert.equal(browser.timers.tasks.size, 0, 'Explicit Stop must cancel pending restarts.');
});

test('Voice Mode disable, navigation, and question changes cancel pending automatic restarts', () => {
  FakeRecognition.instances = [];
  const browser = mockBrowser();
  const transcripts = [];
  const voice = makeVoice(browser, { onTranscript: value => transcripts.push(value) });
  voice.setQuestion('First fictional question.');
  voice.startListening();
  const recognition = FakeRecognition.instances[0];
  recognition.onstart();
  recognition.onresult({ results: [finalResult('Do not attach this to another question.')] });
  voice.setQuestion('Second fictional question.');
  assert.equal(recognition.abortCalls, 1);
  assert.deepEqual(transcripts, [], 'Changing questions discards the unfinished utterance.');
  assert.equal(voice.getState().dictationEnabled, false);
  assert.equal(browser.timers.tasks.size, 0);

  voice.startListening();
  const second = FakeRecognition.instances.at(-1);
  second.onstart();
  second.onend();
  assert.equal(browser.timers.tasks.size, 1, 'A normal pause should queue a restart.');
  voice.setQuestion('Third fictional question.');
  assert.equal(browser.timers.tasks.size, 0, 'Changing the question must cancel a queued restart.');
  assert.equal(voice.getState().dictationEnabled, false);

  voice.startListening();
  FakeRecognition.instances.at(-1).onstart();
  FakeRecognition.instances.at(-1).onend();
  assert.equal(browser.timers.tasks.size, 1);
  voice.stopAll(); // Used by Voice Mode disable and navigation cleanup.
  assert.equal(browser.timers.tasks.size, 0);
  assert.equal(voice.getState().dictationEnabled, false);
  assert.equal(browser.timers.runNext(), false, 'No stale restart should survive cleanup.');
});

test('final results are committed once even if the browser repeats result/end events', () => {
  FakeRecognition.instances = [];
  const browser = mockBrowser();
  const transcripts = [];
  const voice = makeVoice(browser, { onTranscript: value => transcripts.push(value) });
  voice.startListening();
  const recognition = FakeRecognition.instances[0];
  const event = { results: [finalResult('One fictional final phrase.')] };
  recognition.onresult(event);
  recognition.onresult(event);
  recognition.onend();
  recognition.onend();
  assert.deepEqual(transcripts, ['One fictional final phrase.']);
});

test('network retries use bounded backoff and stop after the finite retry cap', () => {
  FakeRecognition.instances = [];
  const browser = mockBrowser();
  const statuses = [];
  const voice = makeVoice(browser, { onStatus: value => statuses.push(value) });
  voice.startListening();
  for (const [expectedDelay, index] of [[500, 0], [1000, 1], [2000, 2]]) {
    const recognition = FakeRecognition.instances[index];
    recognition.onstart();
    recognition.onerror({ error: 'network' });
    recognition.onend();
    assert.equal(voice.getState().dictationEnabled, true);
    assert.equal(browser.timers.runNext(), expectedDelay);
  }
  const finalRecognition = FakeRecognition.instances[3];
  finalRecognition.onstart();
  finalRecognition.onerror({ error: 'network' });
  finalRecognition.onend();
  assert.equal(voice.getState().dictationEnabled, false);
  assert.equal(voice.getState().recognitionState, 'error');
  assert.equal(browser.timers.tasks.size, 0);
  assert.match(statuses.at(-1), /could not reconnect/i);
  assert.equal(voice.getState().canStart, true, 'A stopped failed session should allow a user-controlled retry.');
});

test('permission and microphone failures stop dictation without restart; unsupported browsers keep typing fallback', () => {
  for (const [error, expected] of [
    ['not-allowed', /permission was denied/i],
    ['service-not-allowed', /service is unavailable/i],
    ['audio-capture', /no microphone/i],
  ]) {
    FakeRecognition.instances = [];
    const browser = mockBrowser();
    const statuses = [];
    const voice = makeVoice(browser, { onStatus: value => statuses.push(value) });
    voice.startListening();
    const recognition = FakeRecognition.instances[0];
    recognition.onerror({ error, message: 'SYNTHETIC_PRIVATE_BROWSER_DETAIL' });
    recognition.onend();
    assert.equal(voice.getState().dictationEnabled, false);
    assert.equal(voice.getState().recognitionState, 'error');
    assert.equal(browser.timers.tasks.size, 0);
    assert.match(statuses.at(-1), expected);
    assert.doesNotMatch(statuses.join(' '), /SYNTHETIC_PRIVATE_BROWSER_DETAIL/);
  }

  const unsupported = makeVoice(mockBrowser({ speech: false, recognition: false }));
  assert.equal(unsupported.startListening(), false);
  assert.equal(unsupported.speakQuestion(), false);
  assert.equal(unsupported.getState().recognitionSupported, false);

  FakeRecognition.instances = [];
  const prefixedBrowser = mockBrowser({ speech: false, recognition: false });
  prefixedBrowser.windowObject.webkitSpeechRecognition = FakeRecognition;
  const prefixed = makeVoice(prefixedBrowser);
  assert.equal(prefixed.getState().recognitionSupported, true, 'The WebKit-prefixed API should be supported.');
  assert.equal(prefixed.startListening(), true);
  assert.equal(FakeRecognition.instances.length, 1);

});

test('repeated browser start failures are capped instead of looping forever', () => {
  FakeRecognition.instances = [];
  FakeRecognition.startFailures = 4;
  const browser = mockBrowser();
  const voice = makeVoice(browser);
  voice.startListening();
  for (const delay of [500, 1000, 2000]) {
    assert.equal(browser.timers.runNext(), delay);
  }
  assert.equal(voice.getState().dictationEnabled, false);
  assert.equal(voice.getState().recognitionState, 'error');
  assert.equal(browser.timers.tasks.size, 0);
  FakeRecognition.startFailures = 0;
});

test('the UI explains browser speech processing and preserves explicit submission controls', async () => {
  const html = await readFile(new URL('../public/app.html', import.meta.url), 'utf8');
  const script = await readFile(new URL('../public/assets/app.mjs', import.meta.url), 'utf8');
  assert.match(html, /Browser speech recognition may use an external service/);
  assert.match(html, /JobReady does not record or upload microphone audio/);
  assert.match(html, /id="voice-mode-toggle"[^>]+aria-pressed="false"/);
  assert.match(html, /id="voice-listen-button"/);
  assert.match(html, /id="voice-replay-button"/);
  assert.match(html, /id="voice-stop-speech-button"/);
  assert.match(html, /id="voice-start-listening"/);
  assert.match(html, /id="voice-stop-listening"/);
  assert.match(html, /id="voice-status"[^>]+role="status"/);
  assert.doesNotMatch(script, /MediaRecorder|getUserMedia|audio\.blob/i);
  assert.match(script, /api\.answer\(before\.id, draft\)/);
  assert.doesNotMatch(script, /voice[^\n]*requestSubmit|voice[^\n]*api\.answer/i);
});
