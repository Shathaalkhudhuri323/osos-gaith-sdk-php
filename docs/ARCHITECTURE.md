# Architecture

## 1. What this is and isn't

`osos/gaith-sdk-php` is a **thin, server-side client library**. It runs inside a consuming PHP/Laravel
backend and talks to GAITH's public chatbot HTTP/SSE API. It ships:

- no server, no UI, no persistence
- pure request/response (and SSE-stream) plumbing plus typed models
- a static, chatbot-scoped API key as its entire trust boundary (`X-API-Key`) — never a per-user token

**GAITH is the system of record for chat history.** The consumer is explicitly not expected to store
messages or transcripts itself; the SDK's `listConversations`/`getMessages` calls exist so a consumer
can read that history back on demand, not so it can cache it.

## 2. Layering and request flow

```
Consumer code
   │  new GaithChatbotClient(...) or resolved via Laravel container
   ▼
GaithChatbotClient            — public API surface; 8 methods; owns the SSE resume-loop for streamChat()
   │
   ├──────────────────────────────┬─────────────────────────────────────
   │ (7 buffered JSON/multipart   │ (1 method: streamChat — long-lived,
   │  calls: meta, conversations, │  incremental byte delivery required)
   │  messages, delete, upload,   │
   │  download)                   │
   ▼                              ▼
GaithHttpTransport             StreamingHttpClientInterface
  — PSR-18 + PSR-17 only          — SDK-owned contract, not PSR-18
  — X-API-Key on every request    — default impl: GuzzleStreamingClient
  — maps non-2xx → typed          — no retry middleware, ever
    GaithApiException subclass
   │                              │
   ▼                              ▼
Any PSR-18 ClientInterface     Guzzle client, 'stream' => true
   │                              │
   └──────────────┬───────────────┘
                   ▼
      GAITH Backend: {host}/api/v1/chatbots/{chatbot_id}/{meta|conversations|.../chat}
```

The single most consequential decision in this codebase is that **split**: two independent transports,
chosen per call based on traffic shape, not one HTTP client used everywhere. §3 explains why, because
every other structural decision in the SDK downstream of it exists to preserve that split.

## 3. Why two transports, not one

### 3.1 The problem a single client would create

A single HTTP client configuration — one timeout, one retry policy — cannot correctly serve both:

- **Short, idempotent JSON calls** (`GET /meta`, `GET/DELETE /conversations/*`, `POST /attachments`),
  which *want* a bounded total timeout and safe automatic retries.
- **The long-lived SSE stream** (`POST /chat`), which must have no upper bound on total duration (a
  model can legitimately think for tens of seconds) and, critically, **must never be retried by generic
  HTTP middleware** — retrying a `POST /chat` re-runs the chat turn server-side, producing a duplicate
  reply the user never asked for.

Applying one client's config to both either truncates long chat turns or risks double-charging a turn on
transient network blips. Neither is acceptable, so the SDK doesn't try to make one client do both jobs.

### 3.2 The resolution

| | `GaithHttpTransport` | `StreamingHttpClientInterface` |
|---|---|---|
| Contract | `Psr\Http\Client\ClientInterface` (PSR-18) | SDK-owned (`src/Streaming/StreamingHttpClientInterface.php`) |
| Why this contract | Response is fully buffered anyway (deserializing JSON needs the whole body), so PSR-18's "give me a complete response" semantics are a perfect fit — and staying on a standard interface means a consumer can plug in *any* PSR-18 client (Symfony HttpClient, a retry-wrapped Guzzle, etc.) without touching this SDK. | PSR-18 does not guarantee incremental delivery — many PSR-18 implementations (including Guzzle's own PSR-18 adapter by default) buffer the whole response before returning it. That would silently defeat streaming. So the SDK defines its own two-method contract (`sendStreaming(): StreamHandle`, `StreamHandle::read(): ?string`) specifically to make "give me bytes as they arrive" an explicit, checkable requirement rather than an implementation detail a consumer could get wrong invisibly. |
| Default implementation | any injected PSR-18 client | `Streaming\Adapters\GuzzleStreamingClient`, built on Guzzle's `'stream' => true` request option |
| Retry policy | consumer's responsibility (attach to their PSR-18 client) | **hard-coded absence of retries** — the Guzzle client backing this adapter is constructed with no retry middleware, ever; see §3.1 |

This is the one place the SDK isn't fully transport-agnostic (the streaming half ships a concrete Guzzle
adapter as its default), and that's an intentional, documented trade-off, not an oversight — a consumer
who genuinely cannot depend on Guzzle can implement `StreamingHttpClientInterface` themselves (e.g. over
raw cURL); the interface is small by design specifically to make that a realistic option.

### 3.3 What this buys you as an architect evaluating the codebase

- **A consumer's retry/backoff policy choice can never accidentally apply to `/chat`.** It's structurally
  impossible, not just discouraged by convention — the streaming path doesn't go through whatever PSR-18
  client the consumer configured retries on.
- **The core package (`GaithHttpTransport`, `GaithChatbotClient`, models, exceptions) has zero hard
  dependency on Guzzle.** `composer.json`'s `require` section only lists `psr/http-client`,
  `psr/http-factory`, `psr/http-message`, plus Guzzle itself (needed for the bundled streaming adapter —
  see §3.2's note on that trade-off) and `php-http/multipart-stream-builder`. A consumer already
  standardized on a different PSR-18 client for the rest of their app reuses it here for free.

## 4. `streamChat()` and the resume-on-drop protocol

This is the highest-risk piece of logic in the SDK — the one place where getting a boolean condition
wrong has a real, hard-to-notice production consequence (a silently duplicated chat turn or a silently
truncated response). It's worth an architect understanding the state machine directly rather than trusting
a summary.

### 4.1 The protocol, in words

1. POST `/chat` with the caller's message. The response is an SSE stream (`text/event-stream`).
2. The stream's *first* event is always `meta`, carrying `stream_id` — the server-side handle for this
   turn — and (usually) an `id:` SSE line the client can echo back as `Last-Event-ID` on resume.
3. If the connection drops mid-stream (not a clean end, not a terminal event — an actual `StreamDroppedException`):
   - if we're still within **60 seconds** of the stream's `meta` event **and** we captured a `stream_id`,
     re-POST the identical `/chat` body, this time carrying `X-Stream-Id` (always, when resuming) and
     `Last-Event-ID` (only if the stream had emitted one before dropping) — the server continues the same
     turn rather than starting a new one.
   - otherwise, the drop propagates to the caller as an exception. No silent retry, no silent duplication.
4. A **terminal event** (`done` / `error` / `safety_block`) or a **clean stream end with no terminal
   event** both stop the generator via `return` — neither ever triggers a resume attempt. Only an actual
   connection failure does.

### 4.2 Why resume eligibility is `stream_id` + time window, and *not* also `Last-Event-ID`

An earlier revision of this SDK required `Last-Event-ID` to also be non-null before attempting a resume —
reasoning that resuming *without* an event id to anchor on felt unsafe. That was reverted deliberately, to
match the .NET reference SDK: eligibility is governed by `stream_id` + the 60s window alone.
`Last-Event-ID` is attached to the resume request opportunistically, when available, but its absence
doesn't block the attempt — `X-Stream-Id` alone is sufficient for the server to identify which turn to
continue. Requiring both was stricter than the wire contract needs and created a real behavioral gap
between the two SDKs: a drop occurring after `meta` but before any event carried an `id:` line would
resume successfully in .NET and fail outright in PHP for the identical failure. See `GaithChatbotClient::buildChatRequest()`
for the current (correct) header-composition logic — `X-Stream-Id` is sent whenever resuming; `Last-Event-ID`
is layered on top only when available.

### 4.3 Why the resume window is time-based, not attempt-count-based

A `MAX_RESUME_ATTEMPTS` cap was tried and removed. The reasoning an architect should carry forward if this
question resurfaces: the 60-second window is *already* the safety bound this protocol was designed
around — it exists specifically because retrying `/chat` duplicates a turn (§3.1), so the window's whole
job is "don't let a resume attempt happen once the server has plausibly given up on this turn." A count
cap solves a *different* problem (runaway reconnect loops) that the window doesn't address and that
wasn't in the original design brief — and it introduces a new failure mode of its own: a connection that
flaps quickly could exhaust a count-based cap well inside a still-valid time window, giving up on a
recoverable turn for no reason tied to the actual safety property the protocol cares about. If runaway
reconnect protection is genuinely needed, it should be scoped and designed as its own concern (e.g. with
backoff), not bolted onto the resume-eligibility check as an incidental side effect.

### 4.4 Layering: where the resume logic lives, and where it deliberately doesn't

- `Streaming\SseReader` — parses raw SSE bytes into `SseFrame` objects (`id`, `event`, `data`). Knows
  nothing about resume semantics, `stream_id`, or the wire contract's specific event names. Purely a
  framing parser, chunk-boundary-safe (a frame split across two `read()` calls reassembles correctly).
- `Streaming\ChatEvent::fromFrame()` — maps a raw `SseFrame` to a typed `ChatEvent` subclass (`MetaEvent`,
  `TokenEvent`, …). Unknown event names return `null` (forward-compatibility: skip, never throw) — this
  is what lets the server add a new SSE event type without breaking every deployed SDK version.
- `GaithChatbotClient::streamChat()` — **owns the resume protocol itself.** This is a deliberate layering
  decision, mirrored from the .NET SDK: the "was this a clean end or a network drop, and should I
  resume" decision lives in the client, not in the SSE parser or the transport. `SseReader` and
  `ChatEvent` stay reusable/testable in isolation; the stateful, side-effecting resume decision is
  concentrated in exactly one place.

### 4.5 Handle lifecycle

Each stream attempt's `StreamHandle` is closed deterministically via a `try { foreach (...) { ... } }
finally { $handle->close(); }` around the read loop — on a terminal event, a clean end, an exception, or
the caller cancelling by breaking out of the generator's `foreach`. This matters in long-running PHP
processes (queue workers, Octane) where leaving socket cleanup to refcounting/GC would hold connections
open non-deterministically.

## 5. Exception hierarchy

A closed, typed hierarchy rooted at `GaithApiException`, keyed off HTTP status **exactly**:

| Status | Exception |
|---|---|
| 401 | `GaithAuthException` |
| 403 | `GaithForbiddenException` |
| 404 | `GaithNotFoundException` |
| 410 | `GaithGoneException` |
| 422 | `GaithValidationException` |
| 429 | `GaithRateLimitException` |
| anything else | base `GaithApiException` |

Both transports (`GaithHttpTransport::mapError()` and `GuzzleStreamingClient::mapErrorResponse()`) run
the identical status→class switch — duplicated deliberately (two independent transports, not one shared
base class doing HTTP work), not accidentally.

**Constructor signature:** `__construct(int $statusCode, ?string $serverCode, string $message, string $responseBody)`
— this order matches the .NET SDK's `GaithApiException` constructor exactly. It was previously
`(statusCode, serverCode, responseBody, message)`; the message/responseBody positions were swapped to
close a cross-SDK API-parity gap (see §8) before any external consumer could depend on the mismatched
order.

**Lenient, 3-shape error body parsing** (`parseErrorBody`/`parseDetail`): the GAITH backend is a FastAPI
service, confirmed by the shape of its validation-error bodies. A port must tolerate all three documented
shapes — `{"error":{"code","message"}}`, FastAPI's `{"detail": ...}` (string, object, or a list of
`{"loc","msg","type"}`), and a bare `{"code","message"}` at the root — falling back to the raw body or
the HTTP reason phrase if none parse. This lives entirely in `GaithHttpTransport`; the streaming adapter's
`mapErrorResponse()` handles only the first shape, since a non-2xx response on the streaming path is
rarer (auth/validation failures on the initial POST, not FastAPI's typical validation-error shape) — see
Known Gaps (§9) if this asymmetry ever needs closing.

## 6. Public API surface

`GaithChatbotClient`, 8 methods:

| Method | Auth scope | Shape |
|---|---|---|
| `streamChat()` | user-scoped | `Generator<ChatEvent>` |
| `getMeta()` | chatbot-scoped | buffered |
| `listConversations()` | user-scoped | buffered, paged |
| `getConversation()` | user-scoped | buffered |
| `getMessages()` | user-scoped | buffered, paged (`after_seq` cursor) |
| `deleteConversation()` | user-scoped | buffered |
| `uploadAttachment()` | user-scoped | buffered (multipart) |
| `downloadAttachment()` | user-scoped | buffered, returns raw `StreamInterface` |

`GaithUser` — every user-scoped call takes one, never a bare `string $externalUserId`. Constructible only
via `GaithUser::for(?string $id)` (falls back to a generated anonymous id on null/empty) or
`GaithUser::anonymous()` — no public constructor. Cheap type-safety: prevents a caller from accidentally
passing some other bare string (a conversation id, a message) where a user id was expected; the compiler
catches the type mismatch instead of the bug surfacing as cross-user data leakage in production.

`ChatEvent` — a closed hierarchy: `MetaEvent`, `TokenEvent`, `ToolCallEvent`, `ToolResultEvent`,
`FileEvent`, `LimitEvent`, and three terminal events (`DoneEvent`, `ErrorEvent`, `SafetyBlockEvent`).
`isTerminal(): bool` is `false` on the base class, overridden to `true` on exactly those three — this is
what `streamChat()`'s resume loop keys off to decide "generator should exit now, cleanly."

## 7. Laravel bridge (`src/Laravel/`)

Purely additive — the core package has zero Laravel dependency (`illuminate/*` is `require-dev`/
`suggest` only, never `require`).

- `GaithChatbotServiceProvider` — `register()` merges `config/gaith-chatbot.php`, binds
  `GaithChatbotClientFactory` as a singleton, binds the default (unnamed) `GaithChatbotClient` resolved
  through the factory; `boot()` publishes the config file.
- `GaithChatbotClientFactory` — resolves named connections (`config('gaith-chatbot.connections.<name>')`),
  memoizes resolved clients per name, throws `\InvalidArgumentException` for an unknown name. This is
  what lets one Laravel app talk to several distinct GAITH chatbots (e.g. "hr", "rms") without instance
  collisions, mirroring the .NET SDK's `IGaithChatbotClientFactory`.

**Known architectural gap, not yet closed:** the factory currently constructs its own `GuzzleHttp\Client`
instances directly rather than resolving a PSR-18 client from the container. This means a Laravel
consumer who wants to supply their own PSR-18 client with custom retry middleware attached (the intended
consumer-owns-resilience-policy design from §3.2) has no supported path to do so through the bridge today
— see §9.

## 8. Cross-SDK parity with `osos-gaith-sdk-dotnet`

This SDK is a deliberate architectural port, not an independent implementation that happens to talk to
the same API. The load-bearing decisions below are expected to match the .NET reference exactly; anything
that doesn't should be either a documented, intentional PHP-specific deviation or a bug.

| Decision | .NET | PHP | Status |
|---|---|---|---|
| Two-client/two-transport split by traffic shape | ✅ | ✅ | Matched |
| No retry middleware on the streaming client | ✅ | ✅ | Matched |
| Resume eligibility = `stream_id` + 60s window (not also requiring a last-event-id) | ✅ | ✅ | Matched (fixed — see §4.2) |
| No cap on resume attempt count | ✅ | ✅ | Matched (fixed — see §4.3) |
| `GaithApiException` constructor param order `(statusCode, serverCode, message, responseBody)` | ✅ | ✅ | Matched (fixed — see §5) |
| Typed, closed exception hierarchy keyed off HTTP status | ✅ | ✅ | Matched |
| `GaithUser`-style typed wrapper instead of a bare string | ✅ | ✅ | Matched |
| Multi-chatbot support via named registrations + a factory | ✅ (DI-based) | ✅ (Laravel bridge) | Matched, PHP-idiomatic |
| JSON serialization strategy | source-generated (AOT-friendly) | plain `json_encode`/hydrate-by-hand | Intentionally different — no AOT concern in PHP |
| Native async streaming (`IAsyncEnumerable`) | ✅ | `Generator` (`yield`), synchronous | Intentionally different — PHP has no true-async equivalent; caller cancellation is a `break` out of the `foreach`, not a `CancellationToken` |

**When extending either SDK, check this table first.** A change to the resume protocol, the exception
hierarchy's shape, or the two-transport split's rules should land in both SDKs (or be added here as a
deliberate, reasoned divergence) — not drift silently until a cross-SDK diff catches it after the fact.

## 9. Known gaps / deliberately deferred

Recorded here so they're a decision backlog, not a surprise for the next person who reads the code:

- **Laravel factory is container-blind** (§7): no supported way to inject a custom PSR-18 client with
  retry middleware through the bridge; the factory hardcodes Guzzle construction.
- **The 60s resume window is measured from the turn's start** (`meta` event timestamp), not from the
  moment of disconnect. This matches the current implementation on both SDKs, but the wire contract's own
  wording ("within ~60s of a mid-turn disconnect") arguably describes measuring from disconnect instead —
  which would matter for any turn running longer than 60 seconds before it drops, since resume would be
  permanently unavailable for exactly the long-running turns it exists to protect. This needs a decision
  from whoever owns the GAITH backend's stream-buffer TTL, not a unilateral code change on either SDK.
- **The window-expiry branch of the resume gate has no direct unit test** — `time()` is called inline in
  `streamChat()`, making the boundary condition unfalsifiable without a wall-clock-dependent test. An
  injectable clock (a `callable $clock` constructor arg defaulting to `'time'`) would fix this.
- **`GuzzleStreamHandle::read()`'s error-body parsing** only handles the `{"error":{...}}` shape, not the
  full 3-shape leniency `GaithHttpTransport` implements (§5) — a non-2xx on the streaming path with a
  FastAPI-style `detail` body would produce a less-informative exception message than the same error on
  the JSON path.
- No `LICENSE` file or CI workflow yet, despite `composer.json` declaring `"license": "MIT"`.

## 10. Testing philosophy

Per-layer unit tests, TDD throughout (105 tests at time of writing). The two areas given deliberately
heavier coverage, because they're the two places a subtle bug has an outsized, hard-to-observe
consequence:

- **`SseReader`** — chunk-boundary-split frames, multi-`data:`-line joins, comment/heartbeat skipping,
  trailing-incomplete-frame handling. Tested with real SSE byte strings, not mocked framing.
- **`GaithChatbotClient::streamChat()`** — every resume-loop branch is a named test: yields-until-done,
  unknown-events-skipped, resumes-within-window, resumes-using-stream-id-only (no last-event-id),
  drop-before-meta-propagates-without-resume, clean-end-without-terminal-stops-without-resume,
  options-cannot-override-authenticated-user. Assertions target the *actual outgoing request* on resume
  (headers, body) via a real `GaithHttpTransport` instance rather than a mock that could silently echo
  back whatever the assertion expects.
