# JobReady

An AI-powered adaptive interview preparation agent built for neatHack 2026.

## What it does

JobReady helps job seekers prepare for interviews using their CV, target job description, and personalised practice sessions.

## Core features

- Analyse a candidate's CV and job description
- Generate relevant interview questions
- Evaluate candidate responses
- Ask adaptive follow-up questions
- Track interview progress
- Produce a personalised readiness report

## Technology

- PHP 8.2
- HTML, CSS and JavaScript
- Gemini API (default), with optional OpenAI provider
- neatlogs for agent observability

## Project status

Hackathon development in progress.

## Local setup

Requirements: PHP 8.2 with `PDO`, `pdo_sqlite`, `curl`, `fileinfo`, `dom`, `zip`, `zlib`, and `iconv` enabled. Confirm the modules with `php -m`. Document extraction also requires an available PHP CLI executable and `proc_open`.

1. Copy `.env.example` to `.env` and adjust local settings as needed. The example contains placeholders only; credentials belong in `.env` and must not be committed. An empty `DEMO_ACCESS_CODE_HASH` preserves the local-only development workflow; the public demo must set it as described below.
2. Install the locked, lightweight document-parser dependency:

   ```sh
   composer install --no-interaction --no-plugins --no-scripts --prefer-dist
   ```

3. Start PHP's built-in server from the repository root, with upload limits sufficient for a 5 MB document and its multipart wrapper:

   ```sh
   php -d upload_max_filesize=5M -d post_max_size=6M -d display_errors=0 -S 127.0.0.1:8000 -t public
   ```

4. Open `http://127.0.0.1:8000/`. The health endpoint returns a JSON status and initializes the SQLite database at `data/jobready.sqlite` on first request. Use `http://127.0.0.1:8000/app.html` for the candidate interface.

## Candidate interface

Open **`http://127.0.0.1:8000/app.html`** for the JobReady frontend. It is vanilla HTML/CSS/JavaScript, with no build step, external fonts, or frontend dependencies. The existing `/` health endpoint and interview APIs are preserved.

The interface includes a preparation brief, adaptive interview workspace, backend-provided focus areas, saved-answer journal, claim evidence reviews when returned, and a completion/readiness report. It distinguishes `probe_answer` follow-ups from new questions. It does not invent evaluation scores, percentage progress, or a fixed question count. All candidate and AI text is rendered with text nodes rather than HTML.

Use **Refresh progress** to load the current saved session, or paste an existing ID into **Already have an interview session?**. The session ID and latest unsent answer draft are remembered in tab-scoped `sessionStorage`; CVs, job descriptions, full answer history, and reports are not persisted in browser storage. Treat the ID as private because the backend currently uses it to access the session. Drafts are bound to the session ID, question decision sequence, and saved-answer count. They survive page reloads in the same tab when browser storage is available, and are cleared once the corresponding saved answer is confirmed or a new session is created. If storage is unavailable, the interface reports that the draft is retained only in memory.

After an uncertain or failed submission, an explicit **Refresh progress** is required before another POST—even after a page reload. Reload restoration performs only a progress GET and never auto-submits a draft. A draft for a changed question is displayed separately for reference rather than inserted into the next answer. There are no automatic POST retries. Existing server sessions and saved answers are not reset by these frontend recovery controls.

Error messages distinguish recognized provider failures such as quota limits, unavailable models, transport failures, and invalid AI output without echoing raw provider messages. A collapsed **Development technical details** section shows only allowlisted category, stage, and upstream HTTP status when the backend supplies development-gated diagnostics. The backend's existing local/development + `APP_DEBUG=true` gate remains authoritative; production responses without metadata do not reveal the section. Credentials, headers, candidate inputs, and raw response bodies are never included. Safe provider/cURL metadata is retained in the frontend error object, not persisted in draft storage.

Controls support keyboard navigation, visible focus, labeled inputs, status announcements, and Ctrl/Cmd+Enter for answer submission. The layout adapts to desktop and mobile and respects reduced-motion preferences. The backend must be running on the same origin; do not open `app.html` directly as a local file. Loading the interface does not create an interview. Starting a session or submitting an answer makes real AI calls when credentials are configured.

### Upload a CV document

Choose or drop one **PDF or modern Word DOCX**, up to **5 MB (5,242,880 bytes)**, in the preparation screen. Paste-text input remains available. Extracted text fills the CV field for review/editing; it is not sent to Gemini until you explicitly start the interview. Failed extraction leaves existing pasted CV text unchanged. Scanned/image-only PDFs require OCR elsewhere or pasted text; this MVP does not run OCR. If extracted text exceeds the existing 20,000-byte interview CV limit, shorten it in the field before starting.

`POST /api/cv/extract` accepts `multipart/form-data` with one `cv_file` and returns `{"text":"...", "format":"pdf"}` (or `docx`). Errors return a fixed `error` message and an allowlisted `code`. Extension, actual bytes/MIME, PHP upload errors, and actual size are checked. It uses bootstrap for demo authentication/rate limits and SQLite rate-limit counters, but does not call AI/neatlogs or create an interview.

PDFs use **smalot/pdfparser 2.12.5**, with its small Symfony mbstring polyfill dependency; versions are locked in `composer.lock`, and `/vendor/` remains Git-ignored. DOCX uses native PHP ZIP/DOM support. Extraction runs in a separate trusted PHP worker with a 96 MB memory limit, eight-second deadline, bounded output, external XML entity loading disabled, and URL/process-execution functions disabled. Uploaded data is parsed, never executed. PDF decompression and page counts are bounded; encrypted files are refused. DOCX packages are never unpacked to disk: unsafe paths, symlinks, encrypted entries, embedded objects/macros, excessive entry counts/sizes, high compression ratios, DTDs/entities, and malformed XML/packages are rejected. Readable output is capped at 64,000 bytes; overly complex documents receive a clear error.

The PHP-owned temporary upload is removed on success or rejection; uploaded files are not permanently stored or written to SQLite. Keep the extraction worker and `vendor/` outside the public web root. No Poppler, Word, OCR service, frontend dependency, or build tool is required. Restart an existing development server using the upload-limit command above; PHP's default upload limit may be below 5 MB. If the dependency or worker is unavailable, paste-text preparation remains usable.

Interview workflow data (including candidate-provided CV, job description, and answers) is stored in SQLite. AI credentials are read server-side and never sent to the browser. Model calls use bounded inputs, timeouts, and structured JSON validation. Candidate text is treated as untrusted content, and raw candidate data is not written to application logs or observability events.

### AI provider setup

Configure these values privately in `.env` (preserve your existing private key):

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=replace-with-your-gemini-api-key
GEMINI_MODEL=gemini-3.1-flash-lite
```

Gemini is the default when `AI_PROVIDER` is absent. The client uses Google's [official REST generateContent API](https://ai.google.dev/api/generate-content), with the key in the `x-goog-api-key` header and JSON response mode. The default model is **Gemini 3.1 Flash-Lite (`gemini-3.1-flash-lite`)**, validated with JobReady's live interview workflow. Access, pricing, and any free-tier allowance depend on your account's quota, region, and current [model availability](https://ai.google.dev/gemini-api/docs/models) and [pricing](https://ai.google.dev/gemini-api/docs/pricing). Verify availability for your account before a live test; free-tier quota is not guaranteed. Set `GEMINI_MODEL` to another compatible JSON-capable Gemini text model if needed.

Use `AI_PROVIDER=openai` with `OPENAI_API_KEY` and `OPENAI_MODEL` to select the existing OpenAI client. Unsupported provider names fail configuration. Existing process environment variables take precedence over `.env`, so restart the PHP server after changing configuration and check for stale exported settings.

To explicitly select **GroqCloud** as an alternative, configure `AI_PROVIDER=groq`, `GROQ_API_KEY`, and optionally `GROQ_MODEL` (default `openai/gpt-oss-20b`). The Groq client uses Groq's [OpenAI-compatible Chat Completions API](https://console.groq.com/docs/openai), JSON-object output, bounded `max_completion_tokens`, verified HTTPS, and no automatic provider fallback. The GPT-OSS 20B model is documented for [JSON mode and strict structured outputs](https://console.groq.com/docs/structured-outputs); JobReady keeps its existing JSON parsing and server-side validation. Groq documents a [Free Plan](https://console.groq.com/docs/rate-limits), currently with per-model request and token caps (including 30 RPM, 1,000 RPD, 8,000 TPM, and 200,000 TPD for this model); verify the active limits in your account and remain on the Free Plan to avoid usage charges. The application's largest accepted CV/job-description inputs can exceed that free TPM allowance, so keep demo inputs concise. Groq's GPT-OSS guidance recommends avoiding system messages; JobReady preserves its existing system/user prompt separation, so validate instruction-following with fictional data before selecting it for a public demo. No automatic fallback occurs if Groq is unavailable.

For a private Groq configuration, add these non-secret settings and your own key to the private `.env` or server process environment. Keep the Gemini settings and key intact so switching back is explicit:

```dotenv
AI_PROVIDER=groq
GROQ_API_KEY=replace-with-your-groq-api-key
GROQ_MODEL=openai/gpt-oss-20b
```

Do not place a real key in `.env.example`, source control, browser code, or chat.

For one fictional provider-only check, run `php scripts/test-groq.php --send` from the repository root after privately configuring `GROQ_API_KEY` (and optionally `GROQ_MODEL`). It calls `GroqClient::createPlan()` directly and validates the result without application bootstrap, interview creation, SQLite, neatlogs, or the JobReady interview-start rate limiter. It prints only a pass/fail message and makes one Groq API request. Running it without `--send` does not load `.env` or make a request.

Gemini timeouts, quota/rate-limit errors, safety blocks, and malformed/truncated output fail safely. Failed interview turns roll back and leave the previous question available for retry; there are no automatic provider retries or fallbacks. The existing PHP action/tool validator still determines which decisions can execute. Merely loading bootstrap or the health endpoint makes no AI request; starting an interview or submitting an answer does.

### Restricted hackathon judge access

Before publishing a temporary tunnel, set `APP_ENV=production` and `APP_DEBUG=false`. The required demo-gate settings are `DEMO_ACCESS_CODE_HASH` and (for a Quick Tunnel whose connector reaches a loopback-bound PHP server) `APP_TRUSTED_PROXY_IPS=127.0.0.1,::1`. Keep the selected `AI_PROVIDER` and its corresponding private provider key/model settings configured; `APP_DB_PATH` must point to writable persistent storage (the default is `data/jobready.sqlite`). `NEATLOGS_WRITE_KEY` and `NEATLOGS_PROJECT` are optional for best-effort tracing. Set `DEMO_ACCESS_CODE_HASH` to a PHP `password_hash()` verifier; do not put the judge's plaintext access code in `.env`, a command argument, source, or browser code. Generate a verifier locally without placing the code in shell history:

```sh
php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
```

Type a random judge code into standard input, then place only the resulting verifier in private `.env` as `DEMO_ACCESS_CODE_HASH=...`. Share the code with judges through a separate private channel. A missing/invalid verifier fails closed in production. The login exchanges the code for a PHP session cookie (`HttpOnly`, `Secure`, `SameSite=Strict`) and a session-bound CSRF token. Interview, answer, retrieval, status, and upload API routes require the authenticated session; mutating routes also require the CSRF token. No judge code is returned to or persisted by the frontend.

Required demo settings are `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_ACCESS_CODE_HASH=<password_hash verifier>`, and (for a Cloudflare Quick Tunnel to a locally bound server) `APP_TRUSTED_PROXY_IPS=127.0.0.1,::1`. Keep the selected `AI_PROVIDER` and its matching private key/model configuration (`GEMINI_API_KEY`/`GEMINI_MODEL`, `OPENAI_API_KEY`/`OPENAI_MODEL`, or `GROQ_API_KEY`/`GROQ_MODEL`); `NEATLOGS_WRITE_KEY` and `NEATLOGS_PROJECT` enable optional best-effort tracing. `APP_DB_PATH` may be left at its existing default or set to a private persistent path. Never put the plaintext judge code in `.env`; only its verifier belongs there. If using a different proxy, trust only its verified immediate peer IP. The app honors `CF-Visitor`/`CF-Connecting-IP` only when `REMOTE_ADDR` exactly matches this allowlist. Bind PHP/cloudflared to loopback, not a public interface. `/api/dev/status` remains unavailable in production.

Server-enforced SQLite-backed fixed-window limits are deliberately conservative for a small demo: **2 interview starts per client IP per hour and 10 globally per day; up to 20 answers per interview per hour, 20 answers per client IP per hour, and 40 globally per day; 3 document extractions per client IP per hour and 20 globally per day; 10 failed/correct login attempts per IP per hour and 100 globally per hour.** The per-interview answer cap matches the backend's maximum of 20 submitted answers; per-client and global limits still bound aggregate use. A turn can cause several provider calls, so also set a low provider-side quota/budget. Rate-limit identities are stored as HMAC buckets rather than plaintext IPs/session IDs. An unauthenticated upload can consume ingress bandwidth/temp space before PHP applies app-level limits; keep the server bound to loopback and configure the tunnel or host edge with a request-body limit near 6 MB and an additional request rate cap. Keep SQLite on one persistent host and stop the demo tunnel/server after judging.

Set `APP_TRUSTED_PROXY_IPS` only to the actual local connector addresses (the example loopback pair is for a tunnel to a server bound on loopback). If you do not use a reverse proxy, leave it empty; direct HTTPS is detected by PHP's `HTTPS` server variable. Do not trust arbitrary `X-Forwarded-*` values.

The public preview is for **fictional candidate data only**. CV text and interview answers are stored in the SQLite database and sent to the selected AI provider when an interview starts or an answer is submitted. They are not encrypted by this application, and the MVP has no user accounts or deletion/retention workflow. Do not solicit real CVs or personal information. The document upload endpoint requires PHP's `proc_open` and CLI binary and is subject to the same authenticated session, CSRF protection, per-client/global limits, and 5 MB validation.

#### Safe development diagnostics

To diagnose an explicitly approved local AI test, set these non-secret values in your private `.env` yourself and restart the PHP server:

```dotenv
APP_ENV=local
APP_DEBUG=true
```

Diagnostics are exposed only when `APP_ENV` is exactly `local` or `development` **and** `APP_DEBUG=true`. Other environments, or debug disabled, retain the existing generic error response. Restore `APP_DEBUG=false` after local diagnosis. Existing exported process settings take precedence over `.env`.

Failed AI requests can include an allowlisted `diagnostics` object containing `provider`, `category`, `stage` (`plan`, `decision`, or `claim_verification`), and, when known, `upstream_http_status`, numeric `curl_error_code`, `provider_code`, or a fixed `reason`. Categories distinguish configuration, authentication, authorization, invalid request, model availability, quota/rate limits, provider availability, transport, and invalid output. PHP plan/action/evaluation validation errors retain HTTP 502 while identifying invalid output. Diagnostic metadata never includes credentials, URLs, headers, free-form provider messages, raw response bodies, prompts, or candidate text; no raw error body is logged. HTTP-based categories indicate the failure class, not definitive proof of the responsible service (for example, an intermediary can also return HTTP 403).

Temporary runtime probe: `GET /api/dev/status` is available only with the same exact local/development and debug gate; otherwise it returns HTTP 404. It reports exactly `APP_ENV`, `debug_enabled`, `AI_PROVIDER`, `GEMINI_MODEL`, `diagnostics_enabled`, and `sqlite_driver_available`. Provider/model values use the effective configuration and defaults; malformed model names are reported as `null` rather than echoed. It exposes no keys, database paths, environment-file contents, or candidate data, and makes no AI or neatlogs requests. Inspect `http://127.0.0.1:8000/api/dev/status` before another approved live interview test.

neatlogs uses the documented [`POST /v1/trace` HTTP ingest contract](https://docs.neatlogs.com/sdk/http-injection). Configure `NEATLOGS_WRITE_KEY` and `NEATLOGS_PROJECT` in `.env`; the key is read only by PHP and is never returned to the browser. Each successful or rolled-back interview turn is represented as a root workflow trace grouped with `neatlogs.session.id`, with nested spans for actual plan/decision/claim-verification model calls, output-validation guardrails, selected agent actions, persistence tasks, and transaction outcomes. Trace metadata is allowlisted, includes measured durations and the configured model when available, and omits prompts, document contents, answers, generated questions, provider output, and identifiers other than the opaque interview session ID. No `TOOL` span is emitted because the workflow does not invoke an external tool. Transport and HTTP failures are handled without interrupting JobReady, and error logs contain no token, payload, or response body. The local test submits exactly one minimal `jobready.integration_test` trace with no candidate data.

## Interview API (JSON)

- `POST /api/interviews` — start an individualized session. JSON body: `{"cv_text":"...", "job_description":"...", "target_role":"..."}`. Returns the session ID, generated plan, and first question.
- `POST /api/interviews/{id}/answers` — submit a response. JSON body: `{"answer":"..."}`. The agent evaluates context and chooses its next action; it may ask a new question, probe the answer, verify a claim against supplied evidence, or finish.
- `GET /api/interviews/{id}` — retrieve progress, questions, answers, decisions, evaluations, and any current question. CV and job-description fields are not returned.

Requests must use `Content-Type: application/json`. CV text is limited to 20 KB, job descriptions to 16 KB, role names to 200 bytes, answers to 8 KB, and interviews to 20 submitted answers. Agent decisions are bounded to three per API turn.

Run all offline tests:

```sh
php tests/InterviewWorkflowTest.php
php tests/DemoAccessTest.php
php tests/DemoAccessHttpTest.php
php tests/GeminiClientTest.php
php tests/GeminiDiagnosticsTest.php
php tests/NeatlogsClientTest.php
php tests/InterviewTracingTest.php
php tests/DevStatusEndpointTest.php
php tests/HealthEndpointTest.php
php tests/InterviewConnectivityTest.php
php tests/FrontendHttpTest.php
php tests/DocumentExtractionTest.php
php tests/CvUploadHttpTest.php
node --test tests/FrontendTest.mjs tests/CvUploadFrontendTest.mjs
```

These use fake model/HTTP responses and SQLite in-memory databases. They do not load private `.env` or make Gemini, OpenAI, or neatlogs requests. The Gemini tests cover response parsing, request shape, invalid responses/actions, adaptive workflow compatibility, quota/timeouts, and transactional recovery.

The frontend JavaScript tests use Node.js 20+ built-in testing tools (development only, no packages). They verify the PHP response contract, UTF-8 input limits, exact API paths, request timeouts, no automatic retries, allowlisted error diagnostics, production fallback messages, text-only rendering, follow-up display, tab-scoped draft persistence, page-reload recovery, submission guards, and completion reports with fake DOM/fetch fixtures. The frontend HTTP test checks HTML/assets/module MIME types on an isolated PHP server and verifies the health endpoint remains unchanged.

Document tests generate fictional PDF/DOCX fixtures in temporary directories. They cover readable/Unicode text, scanned PDFs, encryption, format spoofing, malformed documents/XML, upload errors and size limits, archive traversal/symlinks/macros, compression bombs, entity rejection, real multipart extraction, and prompt temporary-file cleanup. No private `.env` or personal documents are used, and no provider requests are made.

The diagnostics suite also uses credential-free isolated PHP servers to verify the actual API exposure gate. Its valid fictional requests fail on the fixture's missing key before any external request; it covers generic production responses and development-only metadata.

Run the local HTTP health test with `php tests/HealthEndpointTest.php`. It starts an isolated PHP server on a temporary loopback port, asserts that `GET /` returns HTTP 200 and JSON containing `status: "ok"` and `service: "JobReady"`, and cleans up afterward. It uses a disposable application copy, credential-free environment fixture, and temporary SQLite database; it does not read your private `.env` or contact any external API. PHP `proc_open` and the existing cURL extension must be available.

Run `php tests/InterviewConnectivityTest.php` to check the interview API route without AI calls. It posts an empty JSON object to `/api/interviews` and expects HTTP 422 with a structured missing-field error, then checks malformed JSON returns HTTP 400. Both HTTP tests share the same isolated-server helper and require no existing development server. A pass establishes local routing/validation connectivity in the fixture, not Gemini availability or the state of your separate running server. Failure output includes only a fixed diagnostic phase.

## Development Verification

Run these offline checks from the JobReady repository root—the directory containing `README.md` and `composer.json`—to verify the interview workflow, document extraction, and frontend HTTP assets:

```sh
php tests/InterviewWorkflowTest.php
php tests/DocumentExtractionTest.php
php tests/FrontendHttpTest.php
```

The interview workflow test uses a fake AI client, while the document extraction test processes fictional PDF/DOCX fixtures locally. Both run offline without Gemini API requests or API credits; the frontend HTTP test uses an isolated local server.

### Explicit neatlogs integration test

The development test submits exactly one minimal trace named `jobready.integration_test` and contains no candidate data. It is **not sent automatically**. After setting a valid write key and project in your local `.env`, explicitly approve sending by running:

```sh
php scripts/test-neatlogs.php --send
```

The script refuses to run outside `APP_ENV=local` or `APP_ENV=development`. Running it without `--send` does not send anything. The neatlogs write key must remain private and must never be pasted into source files, browser tools, logs, or screenshots. The application remains framework-free; Composer dependencies are limited to local PDF parsing.
