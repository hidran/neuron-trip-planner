import { day, money, type OffersDetails } from '../api';

export default function OffersProposal({ details }: { details: OffersDetails }) {
    const { flight, hotel } = details;

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <article className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <p className="text-xs font-medium text-slate-500">Flight · round trip</p>
                    <p className="mt-1 font-semibold">{flight.airline}</p>
                    <p className="text-sm text-slate-600 dark:text-slate-400">{flight.route}</p>
                    <p className="mt-2 text-sm">
                        {flight.stops === 0 ? 'Direct' : `1 stop via ${flight.via}`} · {flight.hours_each_way} h each way
                    </p>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {day(flight.depart)} – {day(flight.return)}
                    </p>
                    <p className="mt-3 text-lg font-semibold">{money(flight.total_eur)}</p>
                </article>
                <article className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <p className="text-xs font-medium text-slate-500">Hotel</p>
                    <p className="mt-1 font-semibold">{hotel.name}</p>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {'★'.repeat(hotel.stars)} · rated {hotel.guest_rating}/10
                    </p>
                    <p className="mt-2 text-sm">
                        {money(hotel.nightly_rate_eur)} / night ·{' '}
                        {hotel.free_cancellation ? 'Free cancellation' : 'Non-refundable'}
                    </p>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {day(hotel.check_in)} – {day(hotel.check_out)}
                    </p>
                    <p className="mt-3 text-lg font-semibold">{money(hotel.total_eur)}</p>
                </article>
            </div>
            <div className="flex items-baseline justify-between rounded-xl bg-stone-100 p-4 dark:bg-slate-800/60">
                <span className="font-medium">Total</span>
                <span className="text-2xl font-semibold">
                    {money(details.total)}
                    {details.over_budget && (
                        <span className="ml-2 align-middle text-xs font-medium text-rose-700 dark:text-rose-300">over budget</span>
                    )}
                </span>
            </div>
            <p className="text-sm text-slate-700 dark:text-slate-300">{details.reasoning}</p>
        </>
    );
}
