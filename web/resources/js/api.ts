// Types and calls for the trip planner API (routes/api.php).
// Everything here mirrors TripResource and TripRunner::pending() on the server.

export type TripStatus = 'working' | 'waiting' | 'finished' | 'failed';

export interface Place {
    id: number;
    name: string;
    country: string;
    countryCode: string;
    latitude: number;
    longitude: number;
    region: string;
    population: number;
}

export interface WindowDetails {
    place: Place;
    name: string;
    start: string;
    end: string;
    weather: string;
    reasoning: string;
}

export interface FlightSummary {
    id: string;
    airline: string;
    route: string;
    depart: string;
    return: string;
    stops: number;
    via: string;
    hours_each_way: number;
    price_per_person_eur: number;
    total_eur: number;
}

export interface HotelSummary {
    id: string;
    name: string;
    stars: number;
    guest_rating: number;
    check_in: string;
    check_out: string;
    nightly_rate_eur: number;
    total_eur: number;
    free_cancellation: boolean;
}

export interface OffersDetails {
    flight: FlightSummary;
    hotel: HotelSummary;
    total: number;
    over_budget: boolean;
    reasoning: string;
}

export type Pending =
    | { type: 'decision'; stage: 'window'; message: string; details: WindowDetails }
    | { type: 'decision'; stage: 'offers'; message: string; details: OffersDetails }
    | {
          type: 'payment';
          amount: number;
          currency: string;
          lines: { description: string; amount: number }[];
          expires_at: string | null;
      };

export interface Booking {
    reference: string;
    kind: 'flight' | 'hotel' | 'cancellation';
    offerId: string;
    amount: number;
    description: string;
}

export interface Summary {
    request: {
        origin_city: string;
        origin_country_code: string;
        travellers: number;
        nights: number;
        budget: number;
        preferences: string;
    } | null;
    origin: Place | null;
    dates: { earliest: string; latest: string } | null;
    window: WindowDetails | null;
    selection: { flight_id: string; hotel_id: string; reasoning: string } | null;
    bookings: Booking[];
    feedback: { window: string[]; offers: string[] };
}

export interface Trip {
    id: string;
    ask: string;
    status: TripStatus;
    phase: string | null;
    pending: Pending | null;
    summary: Summary | null;
    outcome: string | null;
    note: string | null;
    error: string | null;
    created_at: string;
    updated_at: string;
}

export type Answer =
    | { decision: 'approve' }
    | { decision: 'revise'; feedback: string }
    | { decision: 'authorize'; amount: number }
    | { decision: 'decline' };

export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

async function request<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
    const response = await fetch(`/api${path}`, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        // Laravel validation (422) and our own 409s both carry a message.
        throw new ApiError(json.message ?? `Request failed (${response.status})`, response.status);
    }

    return json.data ?? json;
}

export const api = {
    trips: () => request<Trip[]>('GET', '/trips'),
    trip: (id: string) => request<Trip>('GET', `/trips/${id}`),
    create: (ask: string) => request<Trip>('POST', '/trips', { ask }),
    answer: (id: string, answer: Answer) => request<Trip>('POST', `/trips/${id}/answer`, answer),
    retry: (id: string) => request<Trip>('POST', `/trips/${id}/retry`),
};

export const money = (amount: number, currency = 'EUR') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);

export const day = (iso: string) =>
    new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(
        new Date(`${iso}T00:00:00`),
    );
