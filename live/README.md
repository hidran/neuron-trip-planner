# Neuron Trip Planner — live

The [trip planner](../core), with **real online APIs as agent tools**.

`core/` already used two real services (Open-Meteo for geocoding and climate)
and sandboxed everything else. This version gives the agents a toolbox of live
services, so the advisor that picks your destination can check the actual
public holidays, the actual exchange rate and the current travel advisory
before it opens its mouth — and the scout that picks your flight and hotel can
convert the budget into the local currency by asking a bank, not by recalling
a number it saw in training.

Nothing about the workflow changes. Same graph, same three human checkpoints,
same trust boundaries, same booking saga. The difference is one factory,
[`LiveServices`](src/LiveServices.php), and the tools it hands to the agents.

```bash
cd live
composer install
cp .env.example .env          # choose a model; Ollama is free and local
php run/trip.php "Best time to visit Portugal? Two of us from Milan, 8 nights, about 3000 euros, food and old towns"
```

With `TRIP_TRACE=1` (the default in `.env.example`) every request an agent
makes to an online service is printed as it happens:

```
Trip 3f9a1c20. Thinking...
  ↳ GET https://geocoding-api.open-meteo.com/v1/search?name=Lisbon&...  200  212 ms
  ↳ GET https://date.nager.at/api/v3/PublicHolidays/2027/PT  200  143 ms
  ↳ GET https://api.frankfurter.dev/v1/latest?base=EUR&symbols=USD  200  98 ms
```

## The tools

All of them are free and need no key.

| Tool | Service | What the agent gets | Given to |
|---|---|---|---|
| `get_monthly_climate` | [Open-Meteo](https://open-meteo.com) archive (from `core/`) | last year's weather, month by month | advisor |
| `get_weather_forecast` | [Open-Meteo](https://open-meteo.com) forecast | the real forecast, up to 16 days ahead; refuses further out | advisor |
| `get_public_holidays` | [Nager.Date](https://date.nager.at) | holidays between two dates, ~100 countries | advisor, scout |
| `convert_currency` | [Frankfurter](https://frankfurter.dev) (ECB reference rates) | today's rate and the converted amount | advisor, scout |
| `get_country_info` | [REST Countries](https://restcountries.com) | currency, languages, capital, calling code, driving side | advisor |
| `get_travel_advisory` | [travel-advisory.info](https://www.travel-advisory.info) | 0–5 risk score, level, date, source link | advisor |
| `get_destination_guide` | [Wikivoyage](https://en.wikivoyage.org) → Wikipedia | the guide's introduction | advisor |
| `get_attractions` | [OpenStreetMap](https://www.openstreetmap.org) via Overpass | named attractions, museums, viewpoints, best-known first | advisor |
| `web_search` | [Tavily](https://tavily.com) (Neuron's own toolkit) | open web search — **only with `TAVILY_API_KEY`** | advisor |

The scout deliberately gets two tools, not nine. A narrower toolbox is a more
reliable agent: it has fewer wrong turns available.

## Real flights and hotels (optional)

Set `AMADEUS_CLIENT_ID` and `AMADEUS_CLIENT_SECRET` and the planner searches and
re-prices real offers through the [Amadeus Self-Service API](https://developers.amadeus.com),
using the free *test* environment by default. Without keys it uses the sandbox
inventory from `core/`, exactly as before. The switch is one `if` in
`LiveServices`, because both sides implement the same `Inventory` interface —
which is what that interface was for.

Be clear-eyed about three things:

1. **Payments and bookings stay a sandbox, always.** Searching real fares is a
   good demo. Spending real money from one is not.
2. **`AmadeusInventory` was written from Amadeus's published schemas** and is
   tested against hand-written fixtures. Run `php bin/smoke-amadeus.php Milan Lisbon 45`
   with your own keys before you show it to anyone: it searches, re-prices and
   prints what the planner would display, which is the one check this repository
   could not run for you. Amadeus's test environment returns cached and limited
   data, and offers carry no star ratings (the planner shows `0*`, meaning unknown).
3. **Check that Amadeus Self-Service is still open to new keys** at
   developers.amadeus.com before you plan a workshop around it. If it is not,
   [Duffel](https://duffel.com) or any other provider drops in behind `Inventory`;
   nothing else in the application changes.

## How it plugs in

`core/` gained one small extension point: `TripServices` takes an
`agentTools` map, and `wire()` attaches whatever is registered for the agent's
class. The nodes already pass every agent through `wire()`, so no node changed.

```php
new TripServices(
    // ... provider, places, climate, inventory, bookings, exactly as before ...
    agentTools: [
        SeasonAdvisorAgent::class => [new TravelResearchToolkit($places, $apis)],
        OfferScoutAgent::class => [
            new TravelResearchToolkit($places, $apis)->only([ConvertCurrencyTool::class, GetPublicHolidaysTool::class]),
        ],
    ],
);
```

[`TravelResearchToolkit`](src/TravelResearchToolkit.php) is a Neuron toolkit.
Its `guidelines()` text — *every fact must come from a tool result; cite it and
date it; a failing tool is not a reason to guess* — is added to the agent's
instructions whenever the toolkit is attached. The rules for using the tools
live next to the tools instead of being pasted into every prompt.

## What makes an API tool a good tool

Every tool in [`src/Tools`](src/Tools) follows the same four rules, which
are most of what separates an agent that uses web services from one that gets
hurt by them:

1. **The model never supplies coordinates.** It names a `place_id` that an
   earlier search returned; the tool looks the coordinates up. A hallucinated
   latitude is not possible, and a `place_id` nobody returned is refused.
2. **A failing service is a result, not an exception.** The tool returns an
   instruction the model can read — *could not check; tell the traveller; do not
   guess* — and the conversation carries on. A 503 from a holiday calendar
   must not end a trip.
3. **The output is small.** The tool returns the five fields a decision needs,
   not the service's whole payload. Every token returned is paid for again on
   every following turn.
4. **The tool says what it does not know.** No forecast beyond 16 days, no
   holidays for an uncovered country, no guide page: each is an explicit answer
   that tells the model not to fill the gap from memory.

## How this differs from the Python trip planners

The best-known Python example, [CrewAI's `trip_planner`](https://github.com/crewAIInc/crewAI-examples/tree/main/crews/trip_planner),
gives its agents three generic tools: a Google search (Serper, key required), a
website scraper (Browserless, key required — it feeds each page to *another*
agent to summarise), and a calculator. Everything else is the model reading the
web.

That is flexible and it is fragile: the facts are whatever a search snippet
happened to say, summarised by a model, with nothing checking them. This
version makes the opposite trade. Each tool is one narrow, structured,
keyless service that returns typed fields; the application — not the model —
geocodes, prices and enforces dates; and open web search (`web_search`, the
same idea as Serper) is an opt-in extra rather than the foundation.

## Tests

```bash
composer test
```

58 tests (6 skipped until you record fixtures), none of which touch the network:

- `ApiClientsTest`, `ToolsTest`, `AmadeusInventoryTest` run against
  **hand-written fixtures** in `tests/fixtures/`, written to each service's
  documented response shape. They prove the code does what it was designed to do.
- `AgentWithRealToolsTest` runs the unchanged core workflow with a scripted
  model whose advisor calls a real-API tool, and checks that the tool ran, that
  its result reached the model, and that a dead service does not stop the trip.
- `RecordedApisTest` replays **real responses**. It is skipped until you record
  them: run `php bin/record-fixtures.php` once, online, and commit
  `tests/fixtures/recorded/`. From then on the test fails the day a service
  changes its answer.

The code that calls the real services was built in an environment with no
outbound access, so no request in this repository has been made against a live
API by its author: the first `record-fixtures` run is the moment the hand-written
shapes meet the real ones. Treat any failure there as the test doing its job.

## Layout

```
live/
├── src/
│   ├── LiveServices.php          the factory — the one difference from core
│   ├── TravelResearchToolkit.php the seven tools as a toolkit, with usage rules
│   ├── Tools/                    one class per tool
│   ├── Api/                      one thin client per service, plus Http and its decorators
│   │                             (cache, trace, record, replay)
│   └── Amadeus/                  OAuth client and the Inventory implementation
├── run/trip.php                  the interactive runner
├── bin/record-fixtures.php       capture real responses for the replay test
├── bin/smoke-amadeus.php         check your Amadeus keys against the live service
└── tests/
```

## Good citizenship

These are free community services. The planner sends a descriptive
`User-Agent`, remembers every successful GET on disk for six hours
(`TRIP_CACHE_TTL`), and asks Overpass for one small query per destination. If
you build something on top of this for real users, host your own instance of
the services you depend on, or use their paid tiers.
