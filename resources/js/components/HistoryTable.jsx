import { capitalize, formatDate, formatTemperature, formatTime } from '../utils/format';
import { Icon } from './Icon';

const COLUMNS = ['CIDADE', 'CONSULTA', 'TEMP.', 'SENSAÇÃO', 'UMIDADE', 'DESCRIÇÃO'];
const RIGHT_ALIGNED = ['TEMP.', 'SENSAÇÃO', 'UMIDADE'];

export function HistoryTable({ records, selectedId, loading, onSelect }) {
    return (
        <div className={`overflow-x-auto ${loading ? 'opacity-60' : ''}`}>
            <table className="w-full text-xs">
                <thead>
                    <tr className="bg-surface-tint text-[#476077] border-b border-border-tint text-[11px] tracking-wider">
                        {COLUMNS.map((title) => (
                            <th key={title} className={`py-2 px-3 border-r last:border-r-0 border-border-tint ${RIGHT_ALIGNED.includes(title) ? 'text-right' : 'text-left'}`}>
                                {title}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EEF5]">
                    {records.length === 0 && (
                        <tr>
                            <td colSpan={COLUMNS.length} className="py-8 text-center text-text-muted">
                                {loading ? 'Carregando histórico…' : 'Nenhuma consulta encontrada para os filtros selecionados.'}
                            </td>
                        </tr>
                    )}

                    {records.map((record) => {
                        const selected = record.id === selectedId;
                        const muted = selected ? 'text-sky-100' : 'text-slate-600';

                        return (
                            <tr
                                key={record.id}
                                onClick={() => onSelect(record)}
                                className={`cursor-pointer whitespace-nowrap ${selected ? 'bg-primary-dark text-white' : 'bg-white hover:bg-slate-50 text-slate-800'}`}
                            >
                                <td className="py-2 px-3 font-semibold">
                                    <span className="flex items-center gap-1.5">
                                        {selected && <Icon name="radio_button_checked" className="text-[17px] text-[#7DD0FF]" />}
                                        {record.cidade}
                                    </span>
                                </td>
                                <td className={`py-2 px-3 tabular-nums text-[11px] ${muted}`}>
                                    {formatDate(record.consultado_em)}
                                    <br />
                                    {formatTime(record.consultado_em)}
                                </td>
                                <td className="py-2 px-3 text-right tabular-nums font-semibold">{formatTemperature(record.temperatura)}</td>
                                <td className={`py-2 px-3 text-right tabular-nums ${muted}`}>{formatTemperature(record.sensacao_termica)}</td>
                                <td className={`py-2 px-3 text-right tabular-nums ${muted}`}>{record.umidade}%</td>
                                <td className={`py-2 px-3 ${muted}`}>{capitalize(record.descricao)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}