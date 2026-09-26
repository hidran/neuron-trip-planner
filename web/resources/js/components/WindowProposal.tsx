import { day, type WindowDetails } from '../api';

export default function WindowProposal({ details }: { details: WindowDetails }) {
    return (
        <>
            <div>
                <p className="text-2xl font-semibold tracking-tight">{details.name}</p>
                <p className="mt-1 text-slate-600 dark:text-slate-400">
                    {day(details.start)} → {day(details.end)}
                </p>
            </div>
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div className="rounded-xl bg-sky-50 p-4 dark:bg-sky-950/40">
                    <dt className="font-medium">Weather</dt>
                    <dd className="mt-1 text-slate-700 dark:text-slate-300">{details.weather}</dd>
                </div>
                <div className="rounded-xl bg-stone-100 p-4 dark:bg-slate-800/60">
                    <dt className="font-medium">Why</dt>
                    <dd className="mt-1 text-slate-700 dark:text-slate-300">{details.reasoning}</dd>
                </div>
            </dl>
        </>
    );
}
