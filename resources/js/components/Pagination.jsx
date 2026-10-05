import { Icon } from './Icon';

const buttonClass =
    'inline-flex items-center gap-0.5 px-2 py-1 text-[11px] font-medium text-slate-700 bg-white border border-slate-200 rounded hover:bg-slate-50 cursor-pointer disabled:text-slate-400 disabled:cursor-not-allowed';

/** `page` is the Laravel paginator response (current_page, last_page, total, from, to). */
export function Pagination({ page, onPageChange }) {
    const { current_page: current, last_page: last, total, from, to } = page;
    const numbers = Array.from({ length: last }, (_, index) => index + 1).filter((number) => Math.abs(number - current) <= 2);

    return (
        <div className="flex items-center justify-between gap-2 px-3 py-2.5 bg-surface-canvas border-t border-border-tint text-[11px] text-slate-500">
            <span>{total === 0 ? 'Nenhum registro' : `Mostrando ${from} a ${to} de ${total} registros`}</span>

            <nav className="inline-flex items-center gap-1">
                <button type="button" disabled={current <= 1} onClick={() => onPageChange(current - 1)} className={buttonClass}>
                    <Icon name="chevron_left" className="text-[15px]" />
                    Anterior
                </button>
                {numbers.map((number) => (
                    <button
                        key={number}
                        type="button"
                        onClick={() => onPageChange(number)}
                        className={`w-6 h-6 rounded cursor-pointer ${number === current ? 'font-bold text-white bg-primary-strong' : 'text-slate-700 bg-white border border-slate-200 hover:bg-slate-50'}`}
                    >
                        {number}
                    </button>
                ))}
                <button type="button" disabled={current >= last} onClick={() => onPageChange(current + 1)} className={buttonClass}>
                    Próxima
                    <Icon name="chevron_right" className="text-[15px]" />
                </button>
            </nav>
        </div>
    );
}