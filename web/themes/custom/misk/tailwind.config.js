/** @type {import('tailwindcss').Config} */
const path = require('path');

module.exports = {
  // Resolve from this file so `npm run build` works regardless of cwd.
  content: [
    path.join(__dirname, 'templates/**/*.twig'),
    path.join(__dirname, 'js/**/*.js'),
    path.join(__dirname, 'src/**/*.css'),
    // From web/themes/custom/misk → web/modules/custom/court_booking
    path.join(__dirname, '../../../modules/custom/court_booking/templates/**/*.twig'),
    path.join(__dirname, '../../../modules/custom/court_booking/js/**/*.js'),
    // PHP may emit class names (field formatters, #markup); scan for completeness.
    path.join(__dirname, '../../../modules/custom/court_booking/**/*.php'),
    path.join(__dirname, '../../../modules/custom/court_booking/court_booking.module'),
  ],
  theme: {
    extend: {
      colors: {
        // Misk brand palette — mirrors CSS variables
        misk: {
          navy:          '#0D3B6E',
          brand:         '#1A3FA8',
          'brand-mid':   '#1565C0',
          'brand-light': '#E8EDF8',
          'brand-pale':  '#F0F4FD',
          orange:        '#E85D26',
          'orange-light':'#FEF0EA',
          green:         '#15803D',
          'green-light': '#DCFCE7',
          red:           '#B91C1C',
          'red-light':   '#FEE2E2',
          amber:         '#B45309',
          'amber-light': '#FEF3C7',
          'gray-bg':     '#F4F6FB',
        },
      },
      fontFamily: {
        sans: ['DM Sans', 'system-ui', 'sans-serif'],
        display: ['Outfit', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        'xl':  '12px',
        '2xl': '16px',
        '3xl': '20px',
      },
      spacing: {
        'safe': 'env(safe-area-inset-bottom)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
    require('@tailwindcss/line-clamp'),
  ],
};
