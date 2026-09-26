import { money, type Trip } from '../api';

const titles: Record<string, string> = {
    booked: 'Booked — have a great trip',
    hotel_sold_out: 'The hotel sold out — your flight was cancelled and refunded',
    authorization_expired: 'The quote expired — nothing was booked',
    declined: 'Payment declined — nothing was booked',
    no_agreement: 'No agreement after three proposals',
    dates_not_bookable: 'Those dates cannot be booked',
    not_understood: 'We could not read the request',
    offer_unavailable: 'The offer is no longer available',
    price_changed: 'The price changed after authorisation — nothing was booked',
    authorization_failed: 'The authorised amount never matched',
};

export default function Outcome({ trip }: { trip: Trip }) {
    const booked = trip.outcome === 'booked';
    const bookings = trip.summary?.bookings ?? [];
    const feedback = trip.summary?.feedback;

    return (
        <section
            className={`rounded-2xl p-6 ${booked ? 'bg-accent-50 dark:bg-accent-700/20' : 'bg-stone-100 dark:bg-slate-900'}`}
        >
            <h2 className="text-xl font-semibold">{titles[trip.outcome ?? ''] ?? trip.outcome}</h2>
            {trip.note && <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">{trip.note}</p>}

            {bookings.length > 0 && (
                <ul className="mt-4 space-y-2">
                    {bookings.map((b) => (
                        <li key={b.reference} className="rounded-xl bg-white p-4 text-sm dark:bg-slate-950">
                            <div className="flex justify-between font-medium">
                                <span className="capitalize">{b.kind}</span>
                                <span className="font-mono">{b.reference}</span>
                            </div>
                            <p className="mt-1 text-slate-600 dark:text-slate-400">{b.description}</p>
                            <p className="mt-1 tabular-nums">{money(b.amount)}</p>
                        </li>
                    ))}
                </ul>
            )}

            {trip.outcome === 'no_agreement' && feedback && (
                <ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-400">
                    {[...feedback.window, ...feedback.offers].map((f, i) => (
                        <li key={i}>{f}</li>
                    ))}
                </ul>
            )}
        </section>
    );
}
