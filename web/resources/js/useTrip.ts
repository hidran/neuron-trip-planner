import { useCallback, useEffect, useRef, useState } from 'react';
import { api, type Trip } from './api';

/**
 * Loads a trip and keeps it fresh while the agents are working.
 *
 * The server does the work in a queued job, so the only way to learn it has
 * finished a step is to ask. Polling stops as soon as the trip is waiting for
 * the traveller, finished or failed - there is nothing to wait for then.
 *
 * Responses can arrive out of order: a poll issued before an answer may land
 * after the answer's own response. Every request therefore gets a sequence
 * number, and a response older than the newest one already applied is
 * dropped - otherwise the page could jump back to "working" after it had
 * already shown the next question.
 */
export function useTrip(id: string) {
    const [trip, setTripState] = useState<Trip | null>(null);
    const [error, setError] = useState<string | null>(null);
    const issued = useRef(0);
    const applied = useRef(0);

    const track = useCallback(async (request: () => Promise<Trip>): Promise<Trip | null> => {
        const seq = ++issued.current;
        const result = await request();

        if (seq < applied.current) {
            return null; // a newer response is already on screen
        }

        applied.current = seq;
        setTripState(result);

        return result;
    }, []);

    const refresh = useCallback(async () => {
        try {
            await track(() => api.trip(id));
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
        }
    }, [id, track]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    useEffect(() => {
        if (trip?.status !== 'working') return;
        const timer = window.setInterval(() => void refresh(), 2000);
        return () => window.clearInterval(timer);
    }, [trip?.status, refresh]);

    return { trip, track, error, refresh };
}
