export class ApiError extends Error {}

export function isAbortError(error) {
    return error?.name === 'AbortError';
}

async function errorMessage(response) {
    try {
        const body = await response.json();

        if (body.erro) return body.erro;
        if (body.errors) return Object.values(body.errors)[0][0];
        if (body.message) return body.message;
    } catch {
        // Response body is not JSON: use the generic message below.
    }

    return `Erro inesperado no servidor (HTTP ${response.status}).`;
}

async function request(method, path, params = {}, signal) {
    const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
    let response;

    try {
        response = await fetch(`/api${path}?${query}`, { method, headers: { Accept: 'application/json' }, signal });
    } catch (error) {
        if (isAbortError(error)) throw error;
        throw new ApiError('Não foi possível conectar ao servidor. Verifique sua conexão.');
    }

    if (!response.ok) throw new ApiError(await errorMessage(response));

    return response.json();
}

export async function searchMunicipalities(term, signal) {
    return (await request('GET', '/municipios', { busca: term }, signal)).data;
}

export function registerWeatherQuery(city, signal) {
    return request('POST', '/clima', { cidade: city }, signal);
}

/** `from` and `to` must be ISO 8601 strings (see toIso in utils/format.js). */
export function fetchHistory({ city, from, to, page, perPage }, signal) {
    return request('GET', '/clima/historico', { cidade: city, de: from, ate: to, page, per_page: perPage }, signal);
}

export async function fetchQueriedCities(signal) {
    return (await request('GET', '/clima/cidades', {}, signal)).data;
}