# Trip planner — web

See the [repository README](../README.md) for the overview.

A Laravel 13 API and a React SPA (Tailwind v4) for the
[trip planner](../core): tell it where you would like to go, and three
agents and a durable NeuronAI v4 workflow plan the trip — asking you before
every decision, including the payment.

![Proposed destination](docs/window.png)

## Run it

Requires PHP 8.5 and Node 22+.

```bash
composer install
cp .env.example .env            # then set OPENAI_KEY, or pick another NEURON_AI_PROVIDER
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan dev                 # web server, queue worker and Vite, together
```

Open the URL `php artisan dev` prints. Weather and geocoding are real
(Open-Meteo, no key); flights, hotels and payments are a sandbox — nothing is
ever booked or charged.

Without `php artisan dev`, run the pieces yourself: `php artisan serve`,
`php artisan queue:work --timeout=600`, and `php artisan schedule:work` (which
settles payment holds nobody answered).

## How it fits together

```
React SPA ──POST /api/trips──────────────► TripController ──► RunTripSegment (queued)
    │                                                             │
    │  polls GET /api/trips/{id}                                  ▼
    │  every 2 s while "working"                          TripRunner ──► TripWorkflow (NeuronAI v4)
    │                                                             │         │
    ◄── trips table: status, pending question, summary ◄──────────┘         └─► workflow_store
    │                                                                           (EloquentPersistence)
    └──POST /api/trips/{id}/answer ─► validated against the pending question ─► RunTripSegment
```

- **Agents take tens of seconds, so HTTP never waits for them.** Every write
  answers `202 Accepted` and queues a job; the SPA polls until the trip needs
  the traveller again.
- **Two stores, two jobs.** The workflow's durable state lives in
  `workflow_store`, through NeuronAI's `EloquentPersistence` — it is not meant
  to be queried. The `trips` table is the application's own projection:
  status, the open question, a summary. The API only reads that.
- **Answers are fenced.** Each trip remembers the run ID and execution attempt
  that paused, and `resume()` presents them: a double click or a redelivered
  job cannot answer a question that has already moved on (book, Chapter 22).
- **An answer must fit the question.** "authorize" sent to a date proposal is
  a 422; any answer to a trip that is not waiting is a 409.
- **Failures are recoverable.** A provider timeout or a booking-service error
  marks the trip failed; **Retry** resumes it with every completed step and
  every booking already made reused.
- **Expired holds settle themselves.** `trips:settle-expired`, scheduled every
  minute, resumes waiting trips whose quote has expired; the workflow ends them
  with nothing booked.

| Endpoint | |
|---|---|
| `GET /api/trips` | The 20 most recent trips |
| `POST /api/trips` `{ask}` | Start a trip (202) |
| `GET /api/trips/{id}` | Status, pending question, summary |
| `POST /api/trips/{id}/answer` | `{decision: approve}` · `{decision: revise, feedback}` · `{decision: authorize, amount}` · `{decision: decline}` |
| `POST /api/trips/{id}/retry` | Retry a failed trip |

**There is no authentication.** The unguessable ULID in the URL is the only
key to a trip. Put the routes behind your auth before this goes anywhere near
real money.

## Checks

```bash
php artisan test                 # the API end to end, with a scripted model (FakeAIProvider)
vendor/bin/phpstan analyse       # level 8, with Larastan
npm run typecheck && npm run build
```
