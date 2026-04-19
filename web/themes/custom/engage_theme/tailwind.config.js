/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "templates/**/*.twig",
    "components/**/*.twig",
    "tw_tws.theme",
    "../../../modules/custom/**/templates/*.twig"
  ],
  safelist: [
    // existing safelist items
    'w-28', 'mt-16', 'mx-10', 'mt-9', 'form-select',
    'form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300',
    'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5',
    'checkbox', 'text-yellow-500', 'btn', 'btn-warning', 'hover:login-btn', 'active:login-btn',
    'text-white', 'capitalize', 'text-lg', 'hover:translate-y-0.5', 'submitBtn',
    'peer', 'peer-placeholder-shown:scale-100', 'peer-placeholder-shown:-translate-y-1/2',
    'peer-placeholder-shown:top-1/2', 'peer-focus:top-2', 'peer-focus:scale-75',
    'peer-focus:-translate-y-4', 'peer-focus:text-yellow-500', 'absolute', 'bg-white', 'text-sm',
    'text-gray-500', 'transition-all', 'duration-300', 'transform', 'z-10', 'origin-[0]',
    'top-2', 'left-1', 'focus:!border-yellow-500',

    // additional classes for checkbox
    'w-6', 'h-6', 'appearance-none', 'border', 'border-gray-400', 'rounded', 'cursor-pointer',
    'checked:bg-gray-900', // ✅ dark background when checked
    'checked:border-gray-900',
    'checked:[background-image:url(data:image/svg+xml,%3Csvg%20viewBox%3D%220%200%2016%2016%22%20fill%3D%22none%22%20stroke%3D%22white%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%224%208%207%2011%2012%205%22/%3E%3C/svg%3E)]',
    'checked:bg-center',
    'checked:bg-no-repeat',
    'transition-all', 'duration-200', 'ease-in-out', 'checked:animate-checkmark',
    'lg:h-14', 'lg:w-44', 's:h-10', 'xs:h-10',

    // layout and button styles
    'p-6', 'shadow-md', 'rounded-lg', 'max-w-md', 'mx-auto', 'mt-10', 'text-2xl',
    'font-semibold', 'mb-6', 'text-center', 'space-y-5', 'px-4', 'py-2', 'focus:outline-none',
    'focus:ring-2', 'focus:ring-blue-400', 'bg-blue-600', 'hover:bg-blue-700', 'transition',
    'bg-yellow-500', 'hover:bg-yellow-600', 'font-semibold', 'rounded', 'transition-all','text-green-600'
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ["Open Sans", "system-ui", "sans-serif"],
        nevis: ["Nevis", "sans-serif"],
        "nevis-bold": ["Nevis Bold", "sans-serif"],
      },
      colors: {
        gray83: "rgb(83 83 83)",
      },
      keyframes: {
        checkmark: {
          '0%': { transform: 'scale(0.8)', opacity: '0' },
          '50%': { transform: 'scale(1.05)', opacity: '1' },
          '100%': { transform: 'scale(1)', opacity: '1' },
        },
      },
      animation: {
        checkmark: 'checkmark var(--animation-input, 0.2s) ease-in-out',
      },
    },
  },
  plugins: [],
};
