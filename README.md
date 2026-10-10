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

Requirements: PHP 8.2 with `PDO`, `pdo_sqlite`, and `curl` enabled. Confirm the modules with `php -m`.

1. Copy `.env.example` to `.env` and adjust local settings as needed. The example contains placeholders only; credentials belong in `.env` and must not be committed.
2. Start PHP's built-in server from the repository root:

   ```sh
   php -S 127.0.0.1:8000 -t public
   ```

3. Open `http://127.0.0.1:8000/`. The health endpoint returns a JSON status and initializes the SQLite database at `data/jobready.sqlite` on first request.

Interview workflow data (including candidate-provided CV, job description, and answers) is stored in SQLite. AI credentials are read server-side and never sent to the browser. Model calls use bounded inputs, timeouts, and structured JSON validation. Candidate text is treated as untrusted content, and raw candidate data is not written to application logs or observability events.

### AI provider setup

Configure these values privately in `.env` (preserve your existing private key):

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=replace-with-your-gemini-api-key
GEMINI_MODEL=gemini-2.5-flash-lite
```

Gemini is the default when `AI_PROVIDER` is absent. The client uses Google's [official REST generateContent API](https://ai.google.dev/api/generate-content), with the key in the `x-goog-api-key` header and JSON response mode. The selected model is Gemini 2.5 Flash-Lite, which has offered free-tier text usage, subject to your account's quota, region, and current [model availability](https://ai.google.dev/gemini-api/docs/models) and [pricing](https://ai.google.dev/gemini-api/docs/pricing). Verify availability for your account before a live test; free-tier quota is not guaranteed. Set `GEMINI_MODEL` to another compatible JSON-capable Gemini text model if needed.

Use `AI_PROVIDER=openai` with `OPENAI_API_KEY` and `OPENAI_MODEL` to select the existing OpenAI client. Unsupported provider names fail configuration. Existing process environment variables take precedence over `.env`, so restart the PHP server after changing configuration and check for stale exported settings.

Gemini timeouts, quota/rate-limit errors, safety blocks, and malformed/truncated output fail safely. Failed interview turns roll back and leave the previous question available for retry; there are no automatic provider retries or fallbacks. The existing PHP action/tool validator still determines which decisions can execute. Merely loading bootstrap or the health endpoint makes no AI request; starting an interview or submitting an answer does.

neatlogs uses the documented [`POST /v1/trace` HTTP ingest contract](https://docs.neatlogs.com/sdk/http-injection). Configure `NEATLOGS_WRITE_KEY` and `NEATLOGS_PROJECT` in `.env`; the key is read only by PHP and is never returned to the browser. When either value is absent, tracing is a no-op. Transport and HTTP failures are handled without interrupting JobReady, and error logs contain no token, payload, or response body.

## Interview API (JSON)

- `POST /api/interviews` — start an individualized session. JSON body: `{"cv_text":"...", "job_description":"...", "target_role":"..."}`. Returns the session ID, generated plan, and first question.
- `POST /api/interviews/{id}/answers` — submit a response. JSON body: `{"answer":"..."}`. The agent evaluates context and chooses its next action; it may ask a new question, probe the answer, verify a claim against supplied evidence, or finish.
- `GET /api/interviews/{id}` — retrieve progress, questions, answers, decisions, evaluations, and any current question. CV and job-description fields are not returned.

Requests must use `Content-Type: application/json`. CV text is limited to 20 KB, job descriptions to 16 KB, role names to 200 bytes, answers to 8 KB, and interviews to 20 submitted answers. Agent decisions are bounded to three per API turn. There is no visual frontend in this milestone.

Run all offline tests:

```sh
php tests/InterviewWorkflowTest.php
php tests/GeminiClientTest.php
```

These use fake model/HTTP responses and SQLite in-memory databases. They do not load private `.env` or make Gemini, OpenAI, or neatlogs requests. The Gemini tests cover response parsing, request shape, invalid responses/actions, adaptive workflow compatibility, quota/timeouts, and transactional recovery.

Run the local HTTP health test with `php tests/HealthEndpointTest.php`. It starts an isolated PHP server on a temporary loopback port, asserts that `GET /` returns HTTP 200 and JSON containing `status: "ok"` and `service: "JobReady"`, and cleans up afterward. It uses a disposable application copy, credential-free environment fixture, and temporary SQLite database; it does not read your private `.env` or contact any external API. PHP `proc_open` and the existing cURL extension must be available.

Run `php tests/InterviewConnectivityTest.php` to check the interview API route without AI calls. It posts an empty JSON object to `/api/interviews` and expects HTTP 422 with a structured missing-field error, then checks malformed JSON returns HTTP 400. Both HTTP tests share the same isolated-server helper and require no existing development server. A pass establishes local routing/validation connectivity in the fixture, not Gemini availability or the state of your separate running server. Failure output includes only a fixed diagnostic phase.

### Explicit neatlogs integration test

The development test submits exactly one minimal trace named `jobready.integration_test` and contains no candidate data. It is **not sent automatically**. After setting a valid write key and project in your local `.env`, explicitly approve sending by running:

```sh
php scripts/test-neatlogs.php --send
```

The script refuses to run outside `APP_ENV=local` or `APP_ENV=development`. Running it without `--send` does not send anything. The neatlogs write key must remain private and must never be pasted into source files, browser tools, logs, or screenshots. The application remains framework-free and has no third-party runtime dependencies.
