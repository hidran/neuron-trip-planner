import { useEffect, useState } from 'react';
import { money } from '../api';

function useSecondsLeft(expiresAt: string | null): number | null {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    return expiresAt === null ? null : Math.max(0, Math.floor((new Date(expiresAt).getTime() - now) / 1000));
}

/**
 * Authorise a number, not a button: the traveller types the exact total.
 * The server checks it again - this only saves a round trip.
 */
export default function PaymentCard({
    amount,
    currency,
    lines,
    expiresAt,
    busy,
    onAuthorize,
    onDecline,
    onExpired,
}: {
    amount: number;
    currency: string;
    lines: { description: string; amount: number }[];
    expiresAt: string | null;
    busy: boolean;
    onAuthorize: (amount: number) => void;
    onDecline: () => void;
    onExpired: () => void;
}) {
    const [typed, setTyped] = useState('');
    const secondsLeft = useSecondsLeft(expiresAt);
    const expired = secondsLeft === 0;
    const matches = Math.abs(Number(typed.replace(',', '.')) - amount) < 0.005;

    return (
        <section className="rounded-2xl border-2 border-accent-500 bg-white p-6 shadow-sm dark:bg-slate-900">
            <h2 className="text-xs font-semibold tracking-wide text-accent-700 uppercase dark:text-accent-100">
                Authorise payment
            </h2>
            <table className="mt-4 w-full text-sm">
                <tbody>
                    {lines.map((line) => (
                        <tr key={line.description} className="border-b border-slate-100 dark:border-slate-800">
                            <td className="py-2 pr-4">{line.description}</td>
                            <td className="py-2 text-right tabular-nums">{money(line.amount, currency)}</td>
                        </tr>
                    ))}
                    <tr>
                        <td className="pt-3 font-semibold">Total</td>
                        <td className="pt-3 text-right text-xl font-semibold tabular-nums">{money(amount, currency)}</td>
                    </tr>
                </tbody>
            </table>

            {secondsLeft !== null && (
                <p className={`mt-4 text-sm ${expired ? 'text-rose-700 dark:text-rose-300' : 'text-slate-600 dark:text-slate-400'}`}>
                    {expired
                        ? 'This quote has expired. Nothing will be booked.'
                        : `Price held for ${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`}
                </p>
            )}

            {expired ? (
                <button
                    onClick={onExpired}
                    disabled={busy}
                    className="mt-4 rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium dark:border-slate-700"
                >
                    Close this trip
                </button>
            ) : (
                <form
                    className="mt-4 flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (matches) onAuthorize(amount);
                    }}
                >
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium">Type the total to authorise it</span>
                        <input
                            inputMode="decimal"
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            placeholder={amount.toFixed(2)}
                            className="w-40 rounded-xl border border-slate-300 bg-white px-3 py-2 tabular-nums focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none dark:border-slate-700 dark:bg-slate-950"
                        />
                    </label>
                    <button
                        type="submit"
                        disabled={busy || !matches}
                        className="rounded-xl bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-accent-700 disabled:opacity-40"
                    >
                        Authorise {money(amount, currency)}
                    </button>
                    <button type="button" onClick={onDecline} disabled={busy} className="px-2 py-2.5 text-sm text-slate-500">
                        Decline
                    </button>
                </form>
            )}
        </section>
    );
}
