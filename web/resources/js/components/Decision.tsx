import { useState, type ReactNode } from 'react';

/**
 * The shape shared by the two proposal checkpoints: show the proposal, then
 * approve it or say - in words - what to change.
 */
export default function Decision({
    title,
    children,
    busy,
    onApprove,
    onRevise,
    suggestions,
}: {
    title: string;
    children: ReactNode;
    busy: boolean;
    onApprove: () => void;
    onRevise: (feedback: string) => void;
    suggestions: string[];
}) {
    const [revising, setRevising] = useState(false);
    const [feedback, setFeedback] = useState('');

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h2 className="text-xs font-semibold tracking-wide text-accent-700 uppercase dark:text-accent-100">{title}</h2>
            <div className="mt-3 space-y-4">{children}</div>

            {revising ? (
                <form
                    className="mt-6 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (feedback.trim()) onRevise(feedback.trim());
                    }}
                >
                    <label htmlFor="feedback" className="block text-sm font-medium">
                        What should change?
                    </label>
                    <textarea
                        id="feedback"
                        autoFocus
                        rows={3}
                        value={feedback}
                        onChange={(e) => setFeedback(e.target.value)}
                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/30 focus:outline-none dark:border-slate-700 dark:bg-slate-950"
                        placeholder="Say it the way you would to a travel agent."
                    />
                    <div className="flex flex-wrap gap-2">
                        {suggestions.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setFeedback(s)}
                                className="rounded-full border border-slate-300 px-3 py-1 text-xs hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                            >
                                {s}
                            </button>
                        ))}
                    </div>
                    <div className="flex gap-3">
                        <button
                            type="submit"
                            disabled={busy || !feedback.trim()}
                            className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-40 dark:bg-slate-100 dark:text-slate-900"
                        >
                            Send changes
                        </button>
                        <button type="button" onClick={() => setRevising(false)} className="px-2 text-sm text-slate-500">
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <div className="mt-6 flex flex-wrap gap-3">
                    <button
                        onClick={onApprove}
                        disabled={busy}
                        className="rounded-xl bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-accent-700 disabled:opacity-40"
                    >
                        Looks good
                    </button>
                    <button
                        onClick={() => setRevising(true)}
                        disabled={busy}
                        className="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        Change something
                    </button>
                </div>
            )}
        </section>
    );
}
