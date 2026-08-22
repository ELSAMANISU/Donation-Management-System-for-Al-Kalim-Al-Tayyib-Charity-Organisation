<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-5 py-2 border border-transparent rounded-lg bg-indigo-600 font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
