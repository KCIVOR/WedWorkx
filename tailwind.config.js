/**
 * WedWorks tokens, mapped one to one from docs/WedWorks_Chatbot_Design_System.html (:root).
 * Colors point at the CSS variables in resources/css/app.css, which hold the literal values.
 */
const v = (name) => `var(--${name})`;

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx}',
    ],
    theme: {
        extend: {
            colors: {
                blue: {
                    50: v('blue-50'),
                    100: v('blue-100'),
                    200: v('blue-200'),
                    500: v('blue-500'),
                    600: v('blue-600'),
                    700: v('blue-700'),
                },
                navy: { 900: v('navy-900') },
                slate: {
                    50: v('slate-50'),
                    100: v('slate-100'),
                    200: v('slate-200'),
                    300: v('slate-300'),
                    400: v('slate-400'),
                    500: v('slate-500'),
                    700: v('slate-700'),
                },
                green: { 50: v('green-50'), 200: v('green-200'), 700: v('green-700') },
                amber: { 50: v('amber-50'), 200: v('amber-200'), 700: v('amber-700') },
                red: { 50: v('red-50'), 200: v('red-200'), 600: v('red-600') },
                white: v('white'),
                'wb-accent': v('wb-accent'),
                'wb-accent-hover': v('wb-accent-hover'),
                'wb-accent-soft': v('wb-accent-soft'),
                'wb-accent-shadow': v('wb-accent-shadow'),
                'wb-canvas': v('wb-canvas'),
                'wb-surface': v('wb-surface'),
                'wb-text': v('wb-text'),
                'wb-heading': v('wb-heading'),
                'wb-muted': v('wb-muted'),
                'wb-line': v('wb-line'),
                'wb-ai-bg': v('wb-ai-bg'),
                'wb-ai-line': v('wb-ai-line'),
                'wb-ai-text': v('wb-ai-text'),
                'wb-user-bg': v('wb-user-bg'),
                'wb-user-text': v('wb-user-text'),
                'wb-placeholder': v('wb-placeholder'),
                'wb-online': v('wb-online'),
                'wb-online-ring': v('wb-online-ring'),
            },
            fontFamily: {
                display: [v('font-display')],
                body: [v('font-body')],
                mono: [v('font-mono')],
            },
            borderRadius: {
                'r-sm': v('r-sm'),
                'r-field': v('r-field'),
                'r-md': v('r-md'),
                'r-card': v('r-card'),
                'r-shell': v('r-shell'),
                'r-bubble': v('r-bubble'),
                'r-tail': v('r-tail'),
                'r-pill': v('r-pill'),
            },
            spacing: {
                's-1': v('s-1'),
                's-2': v('s-2'),
                's-3': v('s-3'),
                's-4': v('s-4'),
                's-5': v('s-5'),
                's-6': v('s-6'),
                's-8': v('s-8'),
                's-10': v('s-10'),
                's-12': v('s-12'),
                's-16': v('s-16'),
            },
            boxShadow: {
                'sh-sm': v('sh-sm'),
                'sh-md': v('sh-md'),
                'sh-lg': v('sh-lg'),
                'sh-blue': v('sh-blue'),
                'sh-user': v('sh-user'),
            },
            transitionTimingFunction: { out: v('ease-out') },
            transitionDuration: {
                fast: v('dur-fast'),
                base: v('dur-base'),
                slow: v('dur-slow'),
            },
            keyframes: {
                wbIn: { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'none' } },
                wbPulse: {
                    '0%,80%,100%': { opacity: '.25', transform: 'translateY(0)' },
                    '40%': { opacity: '1', transform: 'translateY(-3px)' },
                },
                wbBlink: { '50%': { opacity: '0' } },
            },
            animation: {
                'wb-in': 'wbIn var(--dur-base) var(--ease-out)',
                'wb-pulse': 'wbPulse 1.2s infinite ease-in-out',
                'wb-blink': 'wbBlink 1s steps(2) infinite',
            },
        },
    },
    plugins: [],
};
