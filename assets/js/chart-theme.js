/**
 * chart-theme.js
 *
 * Single source of truth for every color used by the dashboard's
 * Chart.js graphs (admin/dashboard.php + staff/dashboard.php).
 *
 * Colors are NOT hardcoded here — they are read live from the CSS
 * design tokens defined in assets/css/dashboard.css (:root and
 * [data-bs-theme="dark"]), so the charts automatically stay in sync
 * with the rest of the UI and with the light/dark theme toggle,
 * instead of drifting out of sync with a second, duplicate set of
 * hex values.
 *
 * Usage (see admin/dashboard.php / staff/dashboard.php):
 *   const palette = chartTheme.palette();
 *   backgroundColor: palette.success
 *   backgroundColor: chartTheme.shadeFamily(palette.primary, labels.length)
 */
(function (window) {
    'use strict';

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        v = (v || '').trim();
        return v || fallback;
    }

    /**
     * Reads the current --chart-* design tokens into a plain object.
     * Called fresh (not cached) so a theme toggle mid-session is
     * picked up correctly if the page re-renders its charts.
     */
    function palette() {
        return {
            primary:       cssVar('--chart-primary', '#5b7ef5'),
            primarySoft:   cssVar('--chart-primary-soft', '#93a9f5'),
            secondary:     cssVar('--chart-secondary', '#a996f0'),
            secondarySoft: cssVar('--chart-secondary-soft', '#8677e6'),
            success:       cssVar('--chart-success', '#4ab390'),
            warning:       cssVar('--chart-warning', '#d9ab5c'),
            danger:        cssVar('--chart-danger', '#dd8580'),
            info:          cssVar('--chart-info', '#6f9adf'),
            critical:      cssVar('--chart-critical', '#cf9257'),
            grid:          cssVar('--chart-grid', 'rgba(255,255,255,0.07)'),
            label:         cssVar('--chart-label', '#8896ab'),
            tooltipBg:     cssVar('--chart-tooltip-bg', '#232b38'),
            tooltipBorder: cssVar('--chart-tooltip-border', 'rgba(255,255,255,0.14)')
        };
    }

    // ---- small hex <-> HSL helpers, used only to derive shades ----

    function hexToHsl(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) {
            hex = hex.split('').map(function (c) { return c + c; }).join('');
        }
        var r = parseInt(hex.substr(0, 2), 16) / 255;
        var g = parseInt(hex.substr(2, 2), 16) / 255;
        var b = parseInt(hex.substr(4, 2), 16) / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h, s, l = (max + min) / 2;
        if (max === min) {
            h = s = 0;
        } else {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }
        return [h * 360, s * 100, l * 100];
    }

    function hslToHex(h, s, l) {
        h /= 360; s /= 100; l /= 100;
        function hue2rgb(p, q, t) {
            if (t < 0) t += 1;
            if (t > 1) t -= 1;
            if (t < 1 / 6) return p + (q - p) * 6 * t;
            if (t < 1 / 2) return q;
            if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
            return p;
        }
        var r, g, b;
        if (s === 0) {
            r = g = b = l;
        } else {
            var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            var p = 2 * l - q;
            r = hue2rgb(p, q, h + 1 / 3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1 / 3);
        }
        function toHex(v) {
            var h2 = Math.round(v * 255).toString(16);
            return h2.length === 1 ? '0' + h2 : h2;
        }
        return '#' + toHex(r) + toHex(g) + toHex(b);
    }

    /**
     * Returns `count` colors from the SAME family as `baseHex` by
     * walking its lightness up/down a tasteful range. Used for charts
     * with a variable number of bars (Low Stock, Product Statistics)
     * so every bar stays a shade of the brand palette rather than
     * pulling in unrelated rainbow colors.
     */
    function shadeFamily(baseHex, count) {
        count = Math.max(1, count | 0);
        if (count === 1) return [baseHex];
        var hsl = hexToHsl(baseHex);
        var lMin = Math.max(hsl[2] - 20, 32);
        var lMax = Math.min(hsl[2] + 20, 76);
        var colors = [];
        for (var i = 0; i < count; i++) {
            var t = i / (count - 1);
            var l = lMax - t * (lMax - lMin);
            colors.push(hslToHex(hsl[0], hsl[1], l));
        }
        return colors;
    }

    window.chartTheme = {
        palette: palette,
        shadeFamily: shadeFamily
    };
})(window);
