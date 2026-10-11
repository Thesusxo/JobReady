import { ApiError, byteLength } from './interview-api.mjs';

export const MAX_CV_FILE_BYTES = 5 * 1024 * 1024;
const messages = {
  upload_missing: 'Choose one PDF or DOCX document.',
  upload_failed: 'The upload did not complete. Select the file again, or paste your CV text.',
  too_large: 'Choose a document no larger than 5 MB.',
  unsupported: 'Use a PDF (.pdf) or modern Word (.docx) file. Other formats are not supported.',
  malformed: 'This document could not be read or does not match its extension. Export a new PDF or DOCX, or paste the text.',
  encrypted: 'Password-protected or encrypted documents are not supported. Upload an unencrypted copy.',
  unsafe_archive: 'This Word document contains unsupported or unsafe archive entries. Export a new DOCX, or paste the text.',
  too_complex: 'This document exceeds safe processing limits. Try a simpler document or paste its text.',
  no_text: 'No readable text was found. Scanned or image-only PDFs need OCR first; you can also paste your CV text.',
  unavailable: 'Document extraction is unavailable on the server. You can still paste your CV text.',
};

export function validateCvFile(file) {
  if (!file || typeof file.name !== 'string' || !Number.isInteger(file.size)) throw new ApiError(messages.upload_missing, 'upload');
  if (!/\.(pdf|docx)$/i.test(file.name)) throw new ApiError(messages.unsupported, 'upload');
  if (file.size > MAX_CV_FILE_BYTES) throw new ApiError(messages.too_large, 'upload');
  if (file.size <= 0) throw new ApiError(messages.malformed, 'upload');
}

export function createCvUploadApi(fetchRequest = globalThis.fetch, timeoutMs = 30000, csrfToken = () => null) {
  return {
    async extract(file) {
      validateCvFile(file);
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const form = new FormData();
        form.append('cv_file', file);
        const response = await fetchRequest('/api/cv/extract', {
          method: 'POST', body: form, signal: controller.signal, credentials: 'same-origin',
          redirect: 'error', cache: 'no-store', headers: { Accept: 'application/json', ...(csrfToken() ? { 'X-CSRF-Token': csrfToken() } : {}) },
        });
        let body;
        try { body = await response.json(); } catch { throw new ApiError(messages.malformed, 'upload', response.status); }
        if (!response.ok) {
          // Fixed messages only. Never echo unrecognized server text or parser output.
          const message = typeof body?.code === 'string' && Object.hasOwn(messages, body.code)
            ? messages[body.code] : (response.status === 413 ? messages.too_large : messages.upload_failed);
          throw new ApiError(message, 'upload', response.status);
        }
        if (!body || typeof body.text !== 'string' || !body.text.trim() || byteLength(body.text) > 64000
          || !['pdf', 'docx'].includes(body.format)) throw new ApiError(messages.malformed, 'upload');
        return { text: body.text, format: body.format };
      } catch (error) {
        if (error instanceof ApiError) throw error;
        throw new ApiError(controller.signal.aborted
          ? 'The document upload timed out. Your existing CV text is unchanged; select the file again or paste text.'
          : 'The document upload connection failed. Your existing CV text is unchanged; select the file again or paste text.', 'upload');
      } finally { clearTimeout(timeout); }
    },
  };
}
