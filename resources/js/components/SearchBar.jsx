import { useState } from 'react';
import { MIN_SEARCH_LENGTH, useAutocomplete } from '../hooks/useAutocomplete';
import { Icon } from './Icon';

export function SearchBar({ searching, onSearch, onCancel }) {
    const [text, setText] = useState('');
    const [listOpen, setListOpen] = useState(false);
    const { suggestions, loading } = useAutocomplete(text, listOpen);

    // "Jales, SP" → "Jales": the API only receives the city name.
    const city = text.split(',')[0].trim();
    const showList = listOpen && text.trim().length >= MIN_SEARCH_LENGTH;

    function submit(event) {
        event.preventDefault();
        setListOpen(false);
        onSearch(city);
    }

    return (
        <form onSubmit={submit} className="bg-white rounded-lg border border-border-strong p-5 shadow-sm">
            <div className="flex flex-col md:flex-row items-center gap-3">
                <div className="relative flex-1 w-full">
                    <div className="flex items-center bg-surface-tint border border-border-tint rounded-lg px-3.5 py-2 shadow-inner focus-within:border-primary">
                        <Icon name="search" className="text-primary text-[18px] mr-2" />
                        <input
                            type="text"
                            value={text}
                            onChange={(event) => {
                                setText(event.target.value);
                                setListOpen(true);
                            }}
                            onBlur={() => setListOpen(false)}
                            placeholder="Digite a cidade, ex.: Jales, SP"
                            aria-label="Cidade"
                            autoComplete="off"
                            className="bg-transparent text-sm font-medium text-slate-800 focus:outline-none w-full placeholder:text-slate-400"
                        />
                        {text && (
                            <button type="button" onClick={() => setText('')} className="flex items-center gap-1 text-[11px] font-bold text-slate-500 hover:text-slate-700 uppercase tracking-wider ml-2 cursor-pointer">
                                <Icon name="cancel" className="text-[15px]" />
                                Limpar
                            </button>
                        )}
                    </div>

                    {showList && (
                        <ul className="absolute z-20 mt-1 w-full max-h-72 overflow-auto bg-white border border-border-strong rounded-lg shadow-lg py-1">
                            {suggestions.length === 0 && <li className="px-3.5 py-2 text-xs text-text-muted">{loading ? 'Buscando cidades…' : 'Nenhuma cidade encontrada.'}</li>}
                            {suggestions.map((municipality) => (
                                <li
                                    key={municipality.id}
                                    // onMouseDown (not onClick) runs before the input's onBlur closes the list.
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        setText(`${municipality.nome}, ${municipality.uf}`);
                                        setListOpen(false);
                                    }}
                                    className="flex items-center justify-between px-3.5 py-2 text-sm text-slate-700 cursor-pointer hover:bg-surface-tint"
                                >
                                    <span className="flex items-center gap-2">
                                        <Icon name="location_on" className="text-[16px] text-primary" />
                                        {municipality.nome}
                                    </span>
                                    <span className="px-1.5 py-0.5 bg-[#E1EEF8] text-primary-dark font-bold text-[10px] rounded">{municipality.uf}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex items-center gap-2 w-full md:w-auto">
                    <button
                        type="submit"
                        disabled={searching || city.length < MIN_SEARCH_LENGTH}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-strong hover:bg-primary-hover text-white text-sm font-semibold rounded-lg shadow-sm cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <Icon name={searching ? 'progress_activity' : 'my_location'} className={`text-[18px] ${searching ? 'animate-spin' : ''}`} />
                        {searching ? 'Consultando…' : 'Consultar'}
                    </button>
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={!searching}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-lg cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Icon name="disabled_by_default" className="text-[17px] text-slate-500" />
                        Cancelar
                    </button>
                </div>
            </div>
        </form>
    );
}