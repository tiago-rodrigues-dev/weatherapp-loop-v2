import { useEffect, useState } from 'react';
import { isAbortError } from '../api';

/**
 * Runs `run(signal)` whenever `dependencies` change, aborting the previous request.
 * Keeps the previous data while loading. Pass `null` to skip the request.
 */
export function useRequest(run, dependencies) {
    const [state, setState] = useState({ data: null, loading: run !== null, error: null });

    useEffect(() => {
        if (run === null) {
            setState({ data: null, loading: false, error: null });
            return;
        }

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true, error: null }));

        run(controller.signal)
            .then((data) => setState({ data, loading: false, error: null }))
            .catch((error) => {
                if (!isAbortError(error)) setState((current) => ({ ...current, loading: false, error: error.message }));
            });

        return () => controller.abort();
    }, dependencies);

    return state;
}