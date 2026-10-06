import { useEffect, useState } from 'react';

export type ThemePreference = 'system' | 'light' | 'dark';
export type Theme = 'light' | 'dark';

const KEY = 'arkon.theme';
const QUERY = '(prefers-color-scheme: dark)';

function stored(): ThemePreference {
    try {
        const value = localStorage.getItem(KEY);
        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

const systemTheme = (): Theme => (typeof matchMedia === 'function' && matchMedia(QUERY).matches ? 'dark' : 'light');

/** The admin colour theme: a per-browser preference, "system" following the OS live. */
export function useTheme(): { preference: ThemePreference; theme: Theme; setPreference(value: ThemePreference): void } {
    const [preference, setPreferenceState] = useState<ThemePreference>(stored);
    const [system, setSystem] = useState<Theme>(systemTheme);

    useEffect(() => {
        if (typeof matchMedia !== 'function') return;
        const media = matchMedia(QUERY);
        const update = () => setSystem(media.matches ? 'dark' : 'light');
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);

    const setPreference = (value: ThemePreference) => {
        setPreferenceState(value);
        try {
            if (value === 'system') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, value);
        } catch {
            // Storage unavailable (private mode): the choice lasts for this page only.
        }
    };

    return { preference, theme: preference === 'system' ? system : preference, setPreference };
}
