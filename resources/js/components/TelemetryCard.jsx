import { capitalize, formatDayMonthTime, formatNumber, formatTemperature, formatTime } from '../utils/format';
import { getWeatherVisual } from '../utils/weatherIcon';
import { Icon } from './Icon';
import { TemperatureChart } from './TemperatureChart';

function StatTile({ icon, label, record, value, detail, className = 'text-slate-800' }) {
    return (
        <div className="bg-surface-tint border border-border-tint rounded-lg p-2 flex flex-col items-center text-center">
            <span className={`flex items-center gap-0.5 text-[10px] font-bold ${className}`}>
                <Icon name={icon} className="text-[12px]" />
                {label}
            </span>
            <span className={`tabular-nums font-bold text-xs ${className}`}>{formatTemperature(value ?? record.temperatura)}</span>
            <span className="text-[9px] text-slate-500">{detail ?? `${formatTime(record.consultado_em)} h`}</span>
        </div>
    );
}

function InfoTile({ icon, label, value }) {
    return (
        <div className="bg-surface-canvas border border-border-subtle rounded-lg p-2 flex items-center gap-2">
            <Icon name={icon} className="text-primary text-lg" />
            <div className="flex flex-col">
                <span className="text-[9px] text-slate-500">{label}</span>
                <span className="text-xs font-bold text-slate-800 tabular-nums">{value}</span>
            </div>
        </div>
    );
}

/** `cityRecords`: records of the selected city as returned by the API (newest first). */
export function TelemetryCard({ record, cityRecords }) {
    if (!record) {
        return (
            <div className="lg:col-span-5 border border-border-tint rounded-xl p-6 bg-white shadow-sm text-center text-sm text-text-muted">
                Consulte uma cidade ou clique em uma linha do histórico para ver os detalhes.
            </div>
        );
    }

    const chronological = [...cityRecords].reverse();
    const temperatures = cityRecords.map((item) => item.temperatura);
    const min = cityRecords.find((item) => item.temperatura === Math.min(...temperatures));
    const max = cityRecords.find((item) => item.temperatura === Math.max(...temperatures));
    const average = temperatures.reduce((sum, value) => sum + value, 0) / temperatures.length;
    const isLatest = cityRecords[0]?.id === record.id;
    const condition = getWeatherVisual(record);

    return (
        <div className="lg:col-span-5 border border-border-tint rounded-xl p-4 bg-white shadow-sm flex flex-col gap-3.5">
            <div className="flex items-start justify-between gap-2">
                <h3 className="flex items-center gap-2 font-display text-base font-bold text-slate-900">
                    <Icon name="location_on" className="text-primary text-xl" />
                    Temperatura — {record.cidade}
                </h3>
                <span className={`px-2.5 py-1 rounded-full text-[11px] font-bold whitespace-nowrap ${isLatest ? 'bg-[#E1EEF8] text-primary-dark' : 'bg-slate-100 text-slate-600'}`}>
                    {isLatest ? '● Última leitura' : 'Leitura anterior'}
                </span>
            </div>

            <div className="bg-[#F3F8FC] border border-[#DEECF8] rounded-xl p-3 flex items-center justify-between gap-3">
                <div>
                    <p className="text-[10px] font-bold text-[#56738E] uppercase tracking-wider">Leitura em {formatDayMonthTime(record.consultado_em)}</p>
                    <p className="font-display text-3xl font-extrabold text-slate-900 tabular-nums">
                        {formatNumber(record.temperatura)} <span className="text-base text-primary">°C</span>
                    </p>
                    <p className="flex items-center gap-1 text-[11px] text-slate-600">
                        <Icon name="device_thermostat" className="text-primary text-sm" />
                        Sensação: <strong className="tabular-nums">{formatTemperature(record.sensacao_termica)}</strong>
                    </p>
                </div>
                <div className={`flex flex-col items-center p-2.5 rounded-lg border w-24 ${condition.background} ${condition.border}`}>
                    <Icon name={condition.icon} filled className={`text-3xl ${condition.color}`} />
                    <span className={`text-[11px] font-semibold text-center leading-tight ${condition.labelColor}`}>{capitalize(record.descricao)}</span>
                </div>
            </div>

            <div className="flex flex-col gap-1.5">
                <span className="flex items-center gap-1.5 font-bold text-slate-800 text-[11px] tracking-wide uppercase">
                    <Icon name="show_chart" className="text-primary text-base" />
                    Curva de variação
                </span>
                <TemperatureChart records={chronological} selectedId={record.id} />
            </div>

            {cityRecords.length > 0 && (
                <div className="grid grid-cols-3 gap-2">
                    <StatTile icon="arrow_downward" label="Mínima" record={min} className="text-primary" />
                    <StatTile icon="bar_chart" label="Média" value={average} detail={`${cityRecords.length} consulta(s)`} />
                    <StatTile icon="arrow_upward" label="Máxima" record={max} className="text-rose-600" />
                </div>
            )}

            <div className="grid grid-cols-2 gap-2">
                <InfoTile icon="water_drop" label="Umidade relativa" value={`${record.umidade}%`} />
                <InfoTile icon="air" label="Velocidade do vento" value={record.vento_kmh === null ? '—' : `${formatNumber(record.vento_kmh)} km/h`} />
            </div>
        </div>
    );
}