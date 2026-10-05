const decimal = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const formatNumber = (value) => decimal.format(value);
export const formatTemperature = (value) => `${formatNumber(value)} °C`;
export const formatDate = (iso) => new Date(iso).toLocaleDateString('pt-BR');
export const formatTime = (iso) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
export const formatDayMonthTime = (iso) => `${new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })} ${formatTime(iso)}`;
export const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);

/** Converts an <input type="datetime-local"> value (browser local time) to ISO 8601 in UTC. */
export const toIso = (value) => (value ? new Date(value).toISOString() : undefined);