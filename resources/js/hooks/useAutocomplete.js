import { useEffect, useState } from 'react';
import { searchMunicipalities } from '../api';
import { useRequest } from './useRequest';

export const MIN_SEARCH_LENGTH = 2;

export function useAutocomplete(text, enabled) {
    const [term, setTerm] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => setTerm(text.trim()), 300);
        return () => clearTimeout(timer);
    }, [text]);

    const shouldSearch = enabled && term.length >= MIN_SEARCH_LENGTH;
    const { data, loading } = useRequest(shouldSearch ? (signal) => searchMunicipalities(term, signal) : null, [term, shouldSearch]);

    return { suggestions: shouldSearch ? (data ?? []) : [], loading: shouldSearch && loading };
}