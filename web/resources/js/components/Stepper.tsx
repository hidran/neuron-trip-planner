import type { Trip } from '../api';

const steps = ['Request', 'Dates', 'Flight & hotel', 'Payment', 'Done'] as const;

/** Where the trip is, derived from what the server knows - never from client state. */
function currentStep(trip: Trip): number {
    if (trip.status === 'finished') return 4;
    if (trip.pending?.type === 'payment') return 3;
    if (trip.pending?.type === 'decision') return trip.pending.stage === 'window' ? 1 : 2;
    if (trip.summary?.selection) return 3;
    if (trip.summary?.window) return 2;
    if (trip.summary?.request) return 1;
    return 0;
}

export default function Stepper({ trip }: { trip: Trip }) {
    const current = currentStep(trip);

    return (
        <ol className="grid grid-cols-5 gap-2 text-xs" aria-label="Progress">
            {steps.map((label, i) => (
                <li key={label} className="flex flex-col gap-1.5" aria-current={i === current ? 'step' : undefined}>
                    <span
                        className={`h-1.5 rounded-full ${
                            i < current || trip.status === 'finished'
                                ? 'bg-accent-600'
                                : i === current
                                  ? 'bg-accent-500/50'
                                  : 'bg-slate-200 dark:bg-slate-800'
                        }`}
                    />
                    <span className={i === current ? 'font-semibold' : 'text-slate-500'}>{label}</span>
                </li>
            ))}
        </ol>
    );
}
