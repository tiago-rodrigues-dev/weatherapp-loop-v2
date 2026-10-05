import { useId } from 'react';
import { formatDayMonthTime, formatTemperature } from '../utils/format';

const WIDTH = 260;
const X_START = 30;
const X_END = 230;
const Y_TOP = 28;
const Y_BOTTOM = 95;

/** SVG line chart, one point per record. `records` must be in chronological order. */
export function TemperatureChart({ records, selectedId }) {
    const gradientId = useId();

    if (records.length < 2) {
        return (
            <div className="h-36 bg-surface-canvas border border-border-subtle rounded-lg flex items-center justify-center px-6 text-center text-[11px] text-text-muted">
                É preciso ao menos duas consultas desta cidade para traçar a curva de variação.
            </div>
        );
    }

    const temperatures = records.map((record) => record.temperatura);
    const min = Math.min(...temperatures);
    const max = Math.max(...temperatures);

    const points = records.map((record, index) => ({
        record,
        x: X_START + (index * (X_END - X_START)) / (records.length - 1),
        y: max === min ? (Y_TOP + Y_BOTTOM) / 2 : Y_BOTTOM - ((record.temperatura - min) / (max - min)) * (Y_BOTTOM - Y_TOP),
    }));
    const line = points.map(({ x, y }, index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
    const peak = points.find((point) => point.record.temperatura === max);
    const peakLabelX = Math.min(Math.max(peak.x - 33, 0), WIDTH - 66);

    return (
        <div className="h-36 bg-surface-canvas border border-border-subtle rounded-lg p-2">
            <svg className="w-full h-full overflow-visible" viewBox={`0 0 ${WIDTH} 120`} preserveAspectRatio="none">
                <defs>
                    <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stopColor="#007BB3" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#007BB3" stopOpacity="0.02" />
                    </linearGradient>
                </defs>

                <path d={`${line} L ${X_END} 105 L ${X_START} 105 Z`} fill={`url(#${gradientId})`} />
                <path d={line} fill="none" stroke="#007BB3" strokeWidth={2.5} strokeLinejoin="round" />

                {points.map(({ record, x, y }) => {
                    const highlighted = record.id === selectedId || record === peak.record;

                    return (
                        <circle key={record.id} cx={x} cy={y} r={highlighted ? 4 : 3} fill={highlighted ? '#007BB3' : '#FFFFFF'} stroke={highlighted ? '#FFFFFF' : '#007BB3'} strokeWidth={2}>
                            <title>{`${formatDayMonthTime(record.consultado_em)} · ${formatTemperature(record.temperatura)}`}</title>
                        </circle>
                    );
                })}

                <rect x={peakLabelX} y={3} width={66} height={15} rx={3} fill="#00283C" />
                <text x={peakLabelX + 33} y={14} fill="#FFFFFF" fontSize={8} fontWeight="bold" textAnchor="middle">
                    Pico: {formatTemperature(max)}
                </text>

                <text x={X_START - 5} y={115} fill="#94A3B8" fontSize={7.5} fontFamily="monospace">
                    {formatDayMonthTime(records[0].consultado_em)}
                </text>
                <text x={X_END + 5} y={115} fill="#94A3B8" fontSize={7.5} fontFamily="monospace" textAnchor="end">
                    {formatDayMonthTime(records[records.length - 1].consultado_em)}
                </text>
            </svg>
        </div>
    );
}