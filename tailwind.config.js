import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * كل لون يمر عبر متغير CSS حتى يتبدّل الوضع الفاتح/الداكن دون تكرار أي كلاس.
 * الصيغة rgb(var(--x) / <alpha-value>) تحفظ دعم الشفافية في تيلويند.
 */
const token = (name) => `rgb(var(--${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"IBM Plex Sans Arabic"', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                brand: {
                    50:  token('brand-50'),
                    100: token('brand-100'),
                    200: token('brand-200'),
                    300: token('brand-300'),
                    400: token('brand-400'),
                    500: token('brand-500'),
                    600: token('brand-600'),
                    700: token('brand-700'),
                    800: token('brand-800'),
                    900: token('brand-900'),
                },

                // أسطح وخطوط
                app:      token('app'),
                card:     token('card'),
                muted:    token('muted'),
                sunken:   token('sunken'),
                scrim:    token('scrim'),
                line: {
                    DEFAULT: token('line'),
                    strong:  token('line-strong'),
                },

                // النص
                fg: {
                    DEFAULT: token('fg'),
                    muted:   token('fg-muted'),
                    subtle:  token('fg-subtle'),
                    inverse: token('fg-inverse'),
                },

                // دلالات الحالة
                success: { DEFAULT: token('success'), soft: token('success-soft'), fg: token('success-fg') },
                warning: { DEFAULT: token('warning'), soft: token('warning-soft'), fg: token('warning-fg') },
                danger:  { DEFAULT: token('danger'),  soft: token('danger-soft'),  fg: token('danger-fg') },
                info:    { DEFAULT: token('info'),    soft: token('info-soft'),    fg: token('info-fg') },
            },

            borderRadius: {
                lg: '0.625rem',
                xl: '0.875rem',
                '2xl': '1.125rem',
                '3xl': '1.5rem',
            },

            // ظلال بميل دافئ يطابق الحياد الدافئ للواجهة
            boxShadow: {
                xs:  '0 1px 2px 0 rgb(41 31 24 / 0.05)',
                sm:  '0 1px 3px 0 rgb(41 31 24 / 0.07), 0 1px 2px -1px rgb(41 31 24 / 0.05)',
                md:  '0 4px 12px -2px rgb(41 31 24 / 0.08), 0 2px 4px -2px rgb(41 31 24 / 0.05)',
                lg:  '0 12px 28px -8px rgb(41 31 24 / 0.14), 0 4px 10px -6px rgb(41 31 24 / 0.08)',
                xl:  '0 24px 48px -16px rgb(41 31 24 / 0.20), 0 8px 16px -12px rgb(41 31 24 / 0.10)',
                pop: '0 16px 40px -12px rgb(41 31 24 / 0.22)',
            },

            transitionTimingFunction: {
                out: 'cubic-bezier(0.16, 1, 0.3, 1)',
                spring: 'cubic-bezier(0.34, 1.32, 0.64, 1)',
            },

            keyframes: {
                'fade-up':   { from: { opacity: '0', transform: 'translateY(6px)' }, to: { opacity: '1', transform: 'none' } },
                'fade-in':   { from: { opacity: '0' }, to: { opacity: '1' } },
                'scale-in':  { from: { opacity: '0', transform: 'scale(.97)' }, to: { opacity: '1', transform: 'none' } },
                'slide-in':  { from: { transform: 'translateX(100%)' }, to: { transform: 'none' } },
                shimmer:     { '100%': { transform: 'translateX(-200%)' } },
                'pulse-dot': { '0%,100%': { opacity: '.35', transform: 'scale(.85)' }, '50%': { opacity: '1', transform: 'scale(1)' } },
            },

            animation: {
                'fade-up':  'fade-up .28s cubic-bezier(0.16,1,0.3,1) both',
                'fade-in':  'fade-in .2s ease-out both',
                'scale-in': 'scale-in .18s cubic-bezier(0.16,1,0.3,1) both',
                'slide-in': 'slide-in .26s cubic-bezier(0.16,1,0.3,1) both',
                shimmer:    'shimmer 1.6s infinite',
                'pulse-dot':'pulse-dot 1.2s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
