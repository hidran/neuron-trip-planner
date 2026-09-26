import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { api, type Trip } from '../api';
import StatusBadge from '../components/StatusBadge';

const examples = [
    'Best time to visit Japan? Two of us from Milan, 10 nights, around 6000 euros. We love temples and food.',
    'Somewhere warm with beaches in February, from London, one week, 3000 pounds budget, just me.',
    'Family of four from São Paulo wants to see Europe in the second half of June, 12 nights, 12000 euros.',
];

export default function Home() {
    const navigate = useNavigate();
    const [ask, setAsk] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [trips, setTrips] = useState<Trip[]>([]);

    useEffect(() => {
        api.trips().then(setTrips).catch(() => setTrips([]));
    }, []);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const trip = await api.create(ask);
            navigate(`/trips/${trip.id}`);
        } catch (err) {
            setError(err instanceof Error ? err.message : String(err));
            setBusy(false);
        }
    }

    return (
        <div className="space-y-12">
            <section className="pt-6">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Where do you want to go?</h1>
                <p className="mt-3 max-w-xl text-slate-600 dark:text-slate-400">
                    Describe the trip the way you would to a travel agent. The agents read real weather data, find flights and
                    a hotel, and ask you before every decision — including the payment.
                </p>

                <form onSubmit={submit} className="mt-6 space-y-3">
                    <label htmlFor="ask" className="sr-only">
                        Your trip
                    </label>
                    <textarea
                        id="ask"
                        rows={4}
                        value={ask}
                        onChange={(e) => setAsk(e.target.value)}
                        placeholder="Best time to visit Japan? Two of us from Milan, 10 nights…"
                        className="w-full rounded-2xl border border-slate-300 bg-white p-4 text-base shadow-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none dark:border-slate-700 dark:bg-slate-900"
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            disabled={busy || ask.trim().length < 10}
                            className="rounded-xl bg-accent-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-accent-700 disabled:opacity-40"
                        >
                            {busy ? 'Starting…' : 'Plan my trip'}
                        </button>
                        {error && <p className="text-sm text-rose-700 dark:text-rose-300">{error}</p>}
                    </div>
                </form>

                <div className="mt-6 space-y-2">
                    <p className="text-xs font-medium text-slate-500 uppercase">Try</p>
                    {examples.map((ex) => (
                        <button
                            key={ex}
                            onClick={() => setAsk(ex)}
                            className="block w-full rounded-xl border border-dashed border-slate-300 px-4 py-2 text-left text-sm text-slate-700 hover:border-accent-500 hover:bg-white dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900"
                        >
                            {ex}
                        </button>
                    ))}
                </div>
            </section>

            {trips.length > 0 && (
                <section>
                    <h2 className="text-sm font-semibold text-slate-500 uppercase">Recent trips</h2>
                    <ul className="mt-3 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                        {trips.map((t) => (
                            <li key={t.id}>
                                <Link
                                    to={`/trips/${t.id}`}
                                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-stone-50 dark:hover:bg-slate-800/50"
                                >
                                    <span className="truncate text-sm">{t.summary?.window?.name ?? t.ask}</span>
                                    <StatusBadge status={t.status} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
