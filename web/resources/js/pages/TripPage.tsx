import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, type Answer, type Trip } from '../api';
import Decision from '../components/Decision';
import OffersProposal from '../components/OffersProposal';
import Outcome from '../components/Outcome';
import PaymentCard from '../components/PaymentCard';
import StatusBadge from '../components/StatusBadge';
import Stepper from '../components/Stepper';
import WindowProposal from '../components/WindowProposal';
import { useTrip } from '../useTrip';

export default function TripPage() {
    const { id = '' } = useParams();
    const { trip, track, error, refresh } = useTrip(id);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    // Every write goes through track(), so its response takes part in the
    // same ordering as the polls and can never be overtaken by a stale one.
    async function act(call: () => Promise<Trip>) {
        setBusy(true);
        setActionError(null);
        try {
            await track(call);
        } catch (e) {
            setActionError(e instanceof Error ? e.message : String(e));
            await refresh(); // a 409 means our picture of the trip is stale
        } finally {
            setBusy(false);
        }
    }

    const answer = (a: Answer) => act(() => api.answer(id, a));

    if (error && !trip) {
        return <p className="text-rose-700">Could not load this trip: {error}</p>;
    }

    if (!trip) {
        return <p className="text-slate-500">Loading…</p>;
    }

    const pending = trip.pending;

    return (
        <div className="space-y-6">
            <div className="flex items-start justify-between gap-4">
                <p className="text-sm text-slate-600 italic dark:text-slate-400">“{trip.ask}”</p>
                <StatusBadge status={trip.status} />
            </div>

            <Stepper trip={trip} />

            {trip.status === 'working' && (
                <div className="flex items-center gap-3 rounded-2xl bg-sky-50 p-6 text-sky-950 dark:bg-sky-950/40 dark:text-sky-100" role="status">
                    <span className="size-3 animate-ping rounded-full bg-sky-500" aria-hidden />
                    <span>{trip.phase ?? 'Working…'}</span>
                </div>
            )}

            {trip.status === 'failed' && (
                <div className="rounded-2xl bg-rose-50 p-6 dark:bg-rose-950/40">
                    <p className="font-semibold text-rose-900 dark:text-rose-200">Something failed along the way.</p>
                    <p className="mt-1 text-sm text-rose-800 dark:text-rose-300">{trip.error}</p>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                        Nothing is lost: every step already completed — and any booking already made — is kept.
                    </p>
                    <button
                        onClick={() => act(() => api.retry(id))}
                        disabled={busy}
                        className="mt-4 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white dark:bg-slate-100 dark:text-slate-900"
                    >
                        Retry
                    </button>
                </div>
            )}

            {trip.status === 'waiting' && pending?.type === 'decision' && pending.stage === 'window' && (
                <Decision
                    title="Proposed destination and dates"
                    busy={busy}
                    onApprove={() => answer({ decision: 'approve' })}
                    onRevise={(feedback) => answer({ decision: 'revise', feedback })}
                    suggestions={['Somewhere less rainy', 'A week later', 'Somewhere cheaper', 'Stay in the same country, different city']}
                >
                    <WindowProposal details={pending.details} />
                </Decision>
            )}

            {trip.status === 'waiting' && pending?.type === 'decision' && pending.stage === 'offers' && (
                <Decision
                    title="Proposed flight and hotel"
                    busy={busy}
                    onApprove={() => answer({ decision: 'approve' })}
                    onRevise={(feedback) => answer({ decision: 'revise', feedback })}
                    suggestions={['Direct flights only', 'A 5-star hotel', 'Cheaper, please', 'Free cancellation']}
                >
                    <OffersProposal details={pending.details} />
                </Decision>
            )}

            {trip.status === 'waiting' && pending?.type === 'payment' && (
                <PaymentCard
                    amount={pending.amount}
                    currency={pending.currency}
                    lines={pending.lines}
                    expiresAt={pending.expires_at}
                    busy={busy}
                    onAuthorize={(amount) => answer({ decision: 'authorize', amount })}
                    onDecline={() => answer({ decision: 'decline' })}
                    onExpired={() => answer({ decision: 'decline' })}
                />
            )}

            {trip.status === 'finished' && <Outcome trip={trip} />}

            {actionError && <p className="text-sm text-rose-700 dark:text-rose-300">{actionError}</p>}

            {trip.summary?.feedback && trip.status !== 'finished' && (
                <History window={trip.summary.feedback.window} offers={trip.summary.feedback.offers} />
            )}

            <Link to="/" className="inline-block text-sm text-slate-500 hover:text-slate-900 dark:hover:text-slate-100">
                ← Plan another trip
            </Link>
        </div>
    );
}

function History({ window, offers }: { window: string[]; offers: string[] }) {
    const items = [...window.map((f) => ['Dates', f] as const), ...offers.map((f) => ['Offers', f] as const)];

    if (items.length === 0) return null;

    return (
        <section className="text-sm">
            <h3 className="font-semibold text-slate-500">Changes so far</h3>
            <ul className="mt-2 space-y-1 text-slate-600 dark:text-slate-400">
                {items.map(([stage, f], i) => (
                    <li key={i}>
                        <span className="font-medium">{stage}:</span> {f}
                    </li>
                ))}
            </ul>
        </section>
    );
}
