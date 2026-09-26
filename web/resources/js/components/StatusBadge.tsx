import type { TripStatus } from '../api';

const styles: Record<TripStatus, string> = {
    working: 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200',
    waiting: 'bg-accent-100 text-accent-700 dark:bg-accent-700/30 dark:text-accent-100',
    finished: 'bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
    failed: 'bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-200',
};

const labels: Record<TripStatus, string> = {
    working: 'Working',
    waiting: 'Needs you',
    finished: 'Finished',
    failed: 'Failed',
};

export default function StatusBadge({ status }: { status: TripStatus }) {
    return <span className={`shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[status]}`}>{labels[status]}</span>;
}
