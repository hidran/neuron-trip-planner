# Trip planner — core

The library and its command-line runner. See the [repository README](../README.md) for the overview.

## An agentic workflow with a human in the loop

Ask for "the best time to visit Japan", "somewhere warm with beaches in
February" or "Europe in the second half of June", from any city in the world,
and four agents and a durable NeuronAI v4 workflow take it from there: they
pick the place and the dates from real weather data, find the best-value
flight and hotel, and book both — stopping for your decision at every point
that matters, and never spending a cent you did not authorise.

```bash
composer install
cp .env.example .env        # set OPENAI_API_KEY, or choose another NEURON_PROVIDER
php run/trip.php "Best time to visit Japan? Two of us from Milan, 10 nights, around 6000 euros, temples and food."
```

```
PROPOSED TRIP: Kyoto, Japan, 2026-11-10 to 2026-11-20
  Weather: …  (from last year's observed data)
'yes' to approve, or say what to change (Enter to pause): yes

PROPOSED BOOKING
  Flight  Polaris Connect, Milan - Kyoto, 1 stop via Frankfurt, 14.9h each way     1612.62 EUR
  Hotel   Casa Kyoto Boutique, 4*, rated 9.1, free cancellation     1650.00 EUR
  Total   3262.62 EUR
'yes' to approve, or say what to change (Enter to pause): yes

PAYMENT AUTHORISATION
  TOTAL                                                      3262.62 EUR
This quote is held until 13:02.
Type the exact total to authorise payment, 'no' to decline, Enter to pause: 3262.62

BOOKED
```

Press Enter at any question and the trip waits on disk; continue later, from
another process, with `--resume <trip-id>`. The same workflow also runs behind
a **web interface** — a Laravel API and a React SPA — in
[`../web`](../web).

**Geocoding and weather are real** (Open-Meteo, free, no key). **Flights,
hotels and payments are a sandbox**: deterministic, priced by great-circle
distance and by the destination's seasons, and nothing is ever booked. Every
one of them sits behind an interface, so a real provider plugs in without
touching the workflow.

## The shape

```
StartEvent ─► IntakeNode ─► WindowNode ⟲ ─► OffersNode ⟲ ─► AuthorizeNode ⟲ ─► BookingNode ─► Stop
               2 agents     agent + tool     agent + tools    quote, no LLM      saga
               + geocoder   HUMAN #1         HUMAN #2         HUMAN #3
                            where & when     flight + hotel   exact amount,
                                                              15-minute hold
```

| File | What it shows |
|---|---|
| [`TripWorkflow`](src/TripWorkflow.php) | The graph. The trip ID *is* the workflow ID, so any process can continue a trip. |
| [`IntakeNode`](src/Nodes/IntakeNode.php) | Structured extraction, then geocoding the origin — a city the model read becomes a place the geocoder knows. |
| [`WindowNode`](src/Nodes/WindowNode.php) | An agent inside a node; `memoize()` per round; a feedback loop that is the graph, not a PHP loop; the traveller's dates enforced in code; only places a tool returned. |
| [`OffersNode`](src/Nodes/OffersNode.php) | The trust boundary: the model returns **IDs**, the application looks them up and prices them. |
| [`AuthorizeNode`](src/Nodes/AuthorizeNode.php) | An interrupt with a deadline; the answer must repeat the exact amount. |
| [`BookingNode`](src/Nodes/BookingNode.php) | Two bookings, no shared transaction: memoized, idempotency-keyed, compensated on "sold out", recoverable on a timeout. |
| [`MonthlyClimateTool`](src/Tools/MonthlyClimateTool.php) | Any city on Earth: the model names it, the tool geocodes it and returns a `place_id` with twelve months of weather. |
| [`TripServices`](src/TripServices.php) | Everything live — model, geocoder, climate, inventory, bookings — injected, never persisted. The CLI, Laravel and the tests each build their own. |

## Why each piece is there

**Durable steps.** Every completed node is persisted. The process can die
anywhere and a plain `run()` continues from the last completed step — the
trip does not start over, and no model call is paid for twice.

**`memoize()` around every model call.** A paused node re-executes from the
top when you answer it. Without the memo, answering "yes" to a proposal would
generate a *new* proposal and approve that one instead. Remove one `memoize()`
and most of the test suite fails.

**Places come from the geocoder, never from the model.** The advisor names
cities; the climate tool geocodes them and hands back a `place_id`. The node
accepts only an ID a tool really returned, and rejects the traveller's own
city as a destination — so every coordinate, every distance, every fare came
from data the application trusts.

**Your dates are a constraint, not a suggestion.** "The second half of June",
or feedback like "we can only travel from 10 to 17 March", is extracted into a
start-date range (and a length of stay, when you give both ends) and *enforced
in code*: a proposal outside it is sent back before you ever see it.

**The model chooses; the application prices.** The offer scout returns two IDs
and a reason — no prices. An ID the search never returned (a hallucination, or
an injection hidden in a hotel name) is rejected, and every figure you see
comes from the inventory.

**Authorise a number, not a button.** Fares move. The payment step re-quotes,
holds the quote for fifteen minutes, and only accepts an answer that repeats
the exact total. A stale or mistyped amount loops back for a fresh quote; an
expired hold ends the trip with nothing booked.

**Two bookings, no transaction.** Each booking is memoized *and* carries an
idempotency key — the memo stops a recovered run from calling again, the key
covers the one gap a memo cannot (a crash after the airline said yes, before
the memo was written). A sold-out hotel cancels the flight (a saga's
compensation); a timeout fails the run, and resuming finishes it without
booking the flight twice.

**Bounded loops, graceful failure.** Every feedback loop stops after three
rounds with a readable reason. A model that cannot produce a valid proposal
gets another round with the validation errors as feedback, not a stack trace.

## PHP 8.5

The package requires PHP 8.5 and uses it where it makes the code clearer:

| Feature | Where |
|---|---|
| Pipe operator `\|>` | Climate aggregation, the offer filters in the search tools |
| `clone($obj, [...])` | `FlightOffer::withPricePerPerson()`, `HotelOffer::withNightlyRate()` on readonly classes |
| `#[\NoDiscard]` | The immutable withers, `Place::distanceTo()`, `Inventory::quote*()` |
| `array_first()` | Picking the most populous geocoding match, the best connecting hub |
| URI extension | `Uri\Rfc3986\Uri` builds the Open-Meteo requests |
| `final` promoted properties | `Place` |
| Closures in constant expressions | `AuthorizeNode`'s clock, a `static function` default parameter |

One PHP 8.5.4 engine bug shapes the style: inside a namespace, piping into an
*unqualified* internal-function reference (`|> trim(...)`) corrupts the heap.
Every pipe here calls closures or fully qualified functions (`|> \trim(...)`),
and [`PipeStyleTest`](tests/PipeStyleTest.php) fails if one slips through.

## Tests — no model, no network

```bash
composer test
```

Nineteen end-to-end scenarios with a scripted model (`FakeAIProvider`) and an
in-memory gazetteer, each step built as a **new** workflow instance against
file persistence — exactly what a second process would have. Among them: the
three decisions end to end; revising the dates; a period in the request
enforced against a model that prefers February; exact dates given as feedback;
a place ID the climate tool never returned; the traveller's own city as a
destination; an origin nobody can find; seasons reversed below the equator; a
long-haul flight connecting through a sensible hub; an invented offer ID; a
mistyped amount; an expired authorisation; a sold-out hotel; and a crash
between the two bookings.

## Which model

The season advisor has to call a tool for several cities, read twelve months
of data for each and argue for a choice — in one structured answer. That needs
a capable model.

- **Verified:** OpenAI `gpt-5.4-mini` (`NEURON_PROVIDER=openai` in `.env`).
  Any comparable Anthropic, Gemini or Mistral model should behave the same.
- **Too small:** `llama3.2` (3B), a common local default. It
  runs, but returns weather summaries like `"{}"`; the workflow rejects those
  rounds and ends with a readable "no agreement" instead of booking anything.

## Making it real

Replace the sandbox in [`TripServices::sandbox()`](src/TripServices.php):

- `Inventory` → a client for a flight/hotel API such as Amadeus or Duffel.
  Keep the contract: search returns IDs, IDs are re-priced before payment.
- `BookingGateway` → your booking provider, passing the idempotency key it
  receives through to the provider's own idempotency header.
- `FilePersistence` → `DatabasePersistence`, `EloquentPersistence` or
  `RedisPersistence` once more than one process can touch a trip — which is
  exactly what [`../web`](../web) does.
