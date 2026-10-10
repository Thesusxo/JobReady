import test from 'node:test';
import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import { createCvUploadApi, MAX_CV_FILE_BYTES, validateCvFile } from '../public/assets/cv-upload.mjs';

test('CV uploads enforce extension and 5 MB metadata limits before any request', () => {
  assert.throws(() => validateCvFile({ name: 'cv.doc', size: 100 }), /PDF/);
  assert.throws(() => validateCvFile({ name: 'cv.pdf', size: MAX_CV_FILE_BYTES + 1 }), /5 MB/);
  assert.throws(() => validateCvFile({ name: 'cv.pdf', size: 0 }), /could not be read/);
  assert.doesNotThrow(() => validateCvFile({ name: 'cv.PDF', size: MAX_CV_FILE_BYTES }));
  assert.doesNotThrow(() => validateCvFile({ name: 'cv.docx', size: 500 }));
});

test('multipart extraction calls only the upload endpoint and returns editable text, not an interview', async () => {
  const file = new File(['fictional fixture bytes'], 'fictional.docx', { type: 'application/octet-stream' });
  const requests = [];
  const api = createCvUploadApi(async (url, options) => {
    requests.push([url, options]);
    return { ok: true, status: 200, json: async () => ({ text: '<script>Inert CV text, not markup</script>', format: 'docx' }) };
  });
  const result = await api.extract(file);
  assert.equal(requests.length, 1);
  assert.equal(requests[0][0], '/api/cv/extract');
  assert.equal(requests[0][1].method, 'POST');
  assert.equal(requests[0][1].body.get('cv_file').name, 'fictional.docx');
  assert.equal(requests[0][1].headers['Content-Type'], undefined, 'Browser must supply the multipart boundary.');
  assert.equal(result.text, '<script>Inert CV text, not markup</script>');
  assert.deepEqual(Object.keys(result), ['text', 'format']);
});

test('encrypted/scanned failures and malformed replies show safe fixed errors without retry', async () => {
  const file = new File(['fictional bytes'], 'fixture.pdf');
  for (const [code, pattern] of [['encrypted', /encrypted/], ['no_text', /OCR/], ['too_complex', /safe processing/], ['PRIVATE_SENTINEL', /did not complete/]]) {
    let calls = 0;
    const api = createCvUploadApi(async () => {
      calls++;
      return { ok: false, status: 422, json: async () => ({ code, error: 'PRIVATE_SENTINEL', headers: 'PRIVATE_SENTINEL' }) };
    });
    await assert.rejects(api.extract(file), error => pattern.test(error.message) && !error.message.includes('PRIVATE_SENTINEL'));
    assert.equal(calls, 1);
  }
  const malformed = createCvUploadApi(async () => ({ ok: true, status: 200, json: async () => ({ format: 'pdf', text: 'x'.repeat(64001) }) }));
  await assert.rejects(malformed.extract(file), /could not be read/);
  const timeout = createCvUploadApi((url, options) => new Promise((resolve, reject) => {
    options.signal.addEventListener('abort', () => reject(new Error('PRIVATE_SENTINEL')), { once: true });
  }), 5);
  await assert.rejects(timeout.extract(file), error => /timed out/.test(error.message) && !error.message.includes('PRIVATE_SENTINEL'));
});
