import { Link, Outlet } from 'react-router';

export default function Layout() {
    return (
        <div className="mx-auto flex min-h-screen max-w-3xl flex-col px-4 sm:px-6">
            <header className="flex items-center justify-between gap-3 py-6">
                <Link to="/" className="flex shrink-0 items-center gap-2 text-lg font-semibold tracking-tight whitespace-nowrap">
                    <span aria-hidden className="grid size-8 place-items-center rounded-lg bg-accent-600 text-white">
                        ✈
                    </span>
                    Trip planner
                </Link>
                <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium whitespace-nowrap text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                    Sandbox<span className="hidden sm:inline"> — nothing is really booked</span>
                </span>
            </header>
            <main className="flex-1 pb-16">
                <Outlet />
            </main>
            <footer className="border-t border-slate-200 py-6 text-xs text-slate-500 dark:border-slate-800">
                Real weather from Open-Meteo. Flights, hotels and payments are simulated. Built with NeuronAI v4, Laravel and
                React.
            </footer>
        </div>
    );
}
