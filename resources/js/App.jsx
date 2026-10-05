import { useEffect, useRef, useState } from 'react';
import { fetchHistory, fetchQueriedCities, isAbortError, registerWeatherQuery } from './api';
import { Alert } from './components/Alert';
import { EMPTY_FILTERS, HistoryFilters } from './components/HistoryFilters';
import { HistoryTable } from './components/HistoryTable';
import { Icon } from './components/Icon';
import { LoopLogo } from './components/LoopLogo';
import { Pagination } from './components/Pagination';
import { SearchBar } from './components/SearchBar';
import { TelemetryCard } from './components/TelemetryCard';
import { useRequest } from './hooks/useRequest';
import { toIso } from './utils/format';

export function App() {
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0); // incrementing it reloads every list
    const [selected, setSelected] = useState(null);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const searchController = useRef(null);

    const period = { from: toIso(filters.from), to: toIso(filters.to) };
    const history = useRequest((signal) => fetchHistory({ city: filters.city, ...period, page, perPage: 10 }, signal), [filters, page, version]);
    const cities = useRequest((signal) => fetchQueriedCities(signal), [version]);
    const cityHistory = useRequest(
        selected ? (signal) => fetchHistory({ city: selected.cidade, ...period, perPage: 50 }, signal) : null,
        [selected?.cidade, filters, version],
    );

    // While a new city loads, the previous city's records are still in memory: keep only the selected city's.
    const cityRecords = (cityHistory.data?.data ?? []).filter((record) => record.cidade === selected?.cidade);

    useEffect(() => {
        if (!selected && history.data?.data.length) setSelected(history.data.data[0]);
    }, [history.data]);

    async function search(city) {
        const controller = new AbortController();
        searchController.current = controller;
        setSearching(true);
        setError(null);
        setNotice(null);

        try {
            const result = await registerWeatherQuery(city, controller.signal);
            setSelected(result.data);
            if (result.atualizado) {
                setNotice(`As condições em ${result.data.cidade} não mudaram desde a última consulta: só a data e a hora do registro foram atualizadas.`);
            }
            setPage(1);
            setVersion((current) => current + 1);
        } catch (failure) {
            if (isAbortError(failure)) setNotice('Consulta cancelada.');
            else setError(failure.message);
        } finally {
            setSearching(false);
        }
    }

    return (
        <div className="min-h-screen">
            <header className="bg-white border-b border-border-strong py-4 shadow-sm flex flex-col items-center gap-1.5">
                <LoopLogo className="h-10 w-auto" />
                <span className="text-xs text-text-muted font-medium">Consulta meteorológica</span>
            </header>

            <main className="max-w-6xl mx-auto px-4 py-6 flex flex-col gap-6">
                <SearchBar searching={searching} onSearch={search} onCancel={() => searchController.current?.abort()} />

                {error && <Alert type="error" message={error} onClose={() => setError(null)} />}
                {notice && <Alert type="info" message={notice} onClose={() => setNotice(null)} />}

                <section className="bg-white rounded-lg border border-border-strong p-5 shadow-sm flex flex-col gap-4">
                    <div className="flex items-start justify-between pb-3 border-b border-slate-100">
                        <div className="flex items-start gap-2.5">
                            <Icon name="history_toggle_off" className="text-primary text-2xl" />
                            <div>
                                <h2 className="font-display text-base font-bold text-slate-900">Histórico de Consultas</h2>
                                <p className="text-xs text-slate-500">Consultas meteorológicas registradas, das mais recentes para as mais antigas</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => setVersion((current) => current + 1)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#F0F6FB] hover:bg-[#E2EDF7] border border-[#CDE1F2] text-xs font-semibold text-primary-dark rounded cursor-pointer"
                        >
                            <Icon name="sync" className={`text-sm ${history.loading ? 'animate-spin' : ''}`} />
                            Recarregar
                        </button>
                    </div>

                    <HistoryFilters
                        cities={cities.data ?? []}
                        filters={filters}
                        onFilter={(newFilters) => {
                            setFilters(newFilters);
                            setPage(1);
                        }}
                    />

                    {history.error && <p className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">{history.error}</p>}

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        <div className="lg:col-span-7 border border-border-tint rounded-lg overflow-hidden">
                            <HistoryTable records={history.data?.data ?? []} selectedId={selected?.id} loading={history.loading} onSelect={setSelected} />
                            {history.data && <Pagination page={history.data} onPageChange={setPage} />}
                        </div>
                        <TelemetryCard record={selected} cityRecords={cityRecords} />
                    </div>

                    <p className="pt-2 border-t border-slate-100 text-[11px] text-slate-500">
                        Mais recentes primeiro · Histórico carregado: {history.data?.total ?? 0} registro(s)
                    </p>
                </section>
            </main>
        </div>
    );
}