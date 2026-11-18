import { createTheme } from '@cscro8/ui/theme';

/**
 * Light / dark / follow-the-system appearance — the shared implementation in
 * @cscro8/ui, under IMIS's own storage key so existing choices carry over. The
 * inline script in app.blade.php applies it before first paint.
 */
export const useTheme = createTheme('imis-theme');
