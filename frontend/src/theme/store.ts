import { create } from 'zustand';
import {
    type ThemeMode,
    applyThemeAttribute,
    clampSiderWidth,
    nextThemeMode,
    readStoredSiderWidth,
    readStoredThemeMode,
    writeStoredSiderWidth,
    writeStoredThemeMode,
} from './mode';

/**
 * The two display choices, held for the whole app (03-Sep-2026): light or
 * dark, and how wide the sidebar is. Seeded from `localStorage` at module
 * load so the first paint is already the person's own choice, and every
 * change writes back.
 */
interface DisplayState {
    mode: ThemeMode;
    siderWidth: number;
    setMode: (mode: ThemeMode) => void;
    toggleMode: () => void;
    setSiderWidth: (width: number) => void;
    resetSiderWidth: () => void;
}

const initialMode = readStoredThemeMode();
applyThemeAttribute(initialMode);

export const useDisplayStore = create<DisplayState>((set, get) => ({
    mode: initialMode,
    siderWidth: readStoredSiderWidth(),
    setMode: (mode) => {
        applyThemeAttribute(mode);
        writeStoredThemeMode(mode);
        set({ mode });
    },
    toggleMode: () => get().setMode(nextThemeMode(get().mode)),
    setSiderWidth: (width) => {
        const next = clampSiderWidth(width);
        writeStoredSiderWidth(next);
        set({ siderWidth: next });
    },
    /** Back to the shipped width — what the handle's double-click does. */
    resetSiderWidth: () => get().setSiderWidth(Number.NaN),
}));
