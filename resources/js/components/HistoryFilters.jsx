import { useEffect, useState } from 'react';
import { Icon } from './Icon';

export const EMPTY_FILTERS = { city: '', from: '', to: '' };

const fieldClass = 'flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded px-2.5 py-1.5';
const inputClass = 'text-xs tabular-nums text-slate-800 outline-none bg-transparent';

/** Edits a draft; nothing is fetched until "Filtrar" is clicked. */
export function HistoryFilters({ cities, filters, onFilter }) {
    const [draft, setDraft] = useState(filters);

    useEffect(() => setDraft(filters), [filters]);

    const change = (field) => (event) => setDraft({ ...draft, [field]: event.target.value });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onFilter(draft);
            }}
            className="flex flex-wrap items-center gap-2.5 text-xs text-slate-700 py-1.5"
        >
            <label className={fieldClass}>
                <span className="font-medium text-slate-600">Cidade:</span>
                <select value={draft.city} onChange={change('city')} className="bg-transparent text-xs font-semibold text-slate-800 focus:outline-none cursor-pointer">
                    <option value="">Todas</option>
                    {cities.map((city) => (
                        <option key={city}>{city}</option>
                    ))}
                </select>
            </label>

            <label className={fieldClass}>
                <span className="font-medium text-slate-600">De:</span>
                <Icon name="calendar_today" className="text-slate-500 text-[14px]" />
                <input type="datetime-local" value={draft.from} onChange={change('from')} className={inputClass} />
            </label>

            <label className={fieldClass}>
                <span className="font-medium text-slate-600">Até:</span>
                <Icon name="schedule" className="text-slate-500 text-[14px]" />
                <input type="datetime-local" value={draft.to} min={draft.from} onChange={change('to')} className={inputClass} />
            </label>

            <div className="flex items-center gap-2 ml-auto">
                <button type="submit" className="inline-flex items-center gap-1.5 px-4 py-1.5 bg-primary-strong hover:bg-primary-hover text-white rounded text-xs font-semibold cursor-pointer">
                    <Icon name="filter_alt" className="text-[14px]" />
                    Filtrar
                </button>
                <button type="button" onClick={() => onFilter(EMPTY_FILTERS)} className="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded text-xs font-medium cursor-pointer">
                    Limpar
                </button>
            </div>
        </form>
    );
}