const visual = (icon, color, background, border, labelColor = 'text-slate-700') => ({ icon, color, background, border, labelColor });

const THUNDERSTORM = visual('thunderstorm', 'text-indigo-600', 'bg-indigo-50', 'border-indigo-200');
const HEAVY_RAIN = visual('rainy_heavy', 'text-primary-dark', 'bg-blue-100', 'border-blue-200');
const RAIN = visual('rainy', 'text-blue-500', 'bg-blue-50', 'border-blue-200');
const SNOW = visual('weather_snowy', 'text-sky-500', 'bg-sky-50', 'border-sky-200');
const SLEET = visual('weather_mix', 'text-sky-600', 'bg-sky-50', 'border-sky-200');
const FOG = visual('foggy', 'text-slate-600', 'bg-slate-100', 'border-slate-200');
const WIND = visual('air', 'text-teal-600', 'bg-teal-50', 'border-teal-200');
const TORNADO = visual('tornado', 'text-teal-700', 'bg-teal-50', 'border-teal-200');
const CLEAR_DAY = visual('wb_sunny', 'text-amber-500', 'bg-amber-50', 'border-amber-200');
const CLEAR_NIGHT = visual('clear_night', 'text-amber-300', 'bg-slate-900', 'border-slate-800', 'text-white');
const FEW_CLOUDS = visual('filter_drama', 'text-amber-600', 'bg-amber-50', 'border-amber-200');
const PARTLY_CLOUDY = visual('partly_cloudy_day', 'text-sky-500', 'bg-sky-50', 'border-sky-200');
const CLOUDY_NIGHT = visual('partly_cloudy_night', 'text-amber-300', 'bg-slate-900', 'border-slate-800', 'text-white');
const CLOUDY = visual('cloud', 'text-slate-500', 'bg-slate-100', 'border-slate-200');
const UNKNOWN = visual('device_thermostat', 'text-primary', 'bg-white', 'border-border-tint');

/** OpenWeather condition codes: https://openweathermap.org/weather-conditions */
function fromCode(code, night) {
    if (code < 300) return THUNDERSTORM;
    if (code < 400) return RAIN; // drizzle
    if (code === 511) return SLEET; // freezing rain
    if ([502, 503, 504, 522].includes(code)) return HEAVY_RAIN;
    if (code < 600) return RAIN;
    if (code >= 611 && code <= 616) return SLEET;
    if (code < 700) return SNOW;
    if (code === 771) return WIND;
    if (code === 781) return TORNADO;
    if (code < 800) return FOG;
    if (code === 800) return night ? CLEAR_NIGHT : CLEAR_DAY;
    if (code === 801) return night ? CLOUDY_NIGHT : FEW_CLOUDS;
    if (code === 802) return night ? CLOUDY_NIGHT : PARTLY_CLOUDY;
    if (code <= 804) return CLOUDY;
    return UNKNOWN;
}

/** Fallback for old records without `condicao_id`. First matching rule wins, so order matters. */
const DESCRIPTION_RULES = [
    [['trovoada', 'tempestade'], THUNDERSTORM],
    [['neve', 'granizo'], SNOW],
    [['chuva forte', 'chuva muito forte', 'chuva extrema', 'intensidade pesada'], HEAVY_RAIN],
    [['chuva', 'garoa', 'chuvisco'], RAIN],
    [['nevoa', 'neblina', 'nevoeiro', 'fumaca', 'poeira', 'areia', 'cinza'], FOG],
    [['algumas nuvens'], FEW_CLOUDS],
    [['nuvens dispersas'], PARTLY_CLOUDY],
    [['nublado', 'nuvens'], CLOUDY],
    [['limpo'], CLEAR_DAY],
];

function fromDescription(description) {
    const text = description.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const rule = DESCRIPTION_RULES.find(([terms]) => terms.some((term) => text.includes(term)));

    return rule ? rule[1] : UNKNOWN;
}

export function getWeatherVisual(record) {
    if (record.condicao_id) return fromCode(record.condicao_id, record.icone?.endsWith('n'));

    return fromDescription(record.descricao);
}