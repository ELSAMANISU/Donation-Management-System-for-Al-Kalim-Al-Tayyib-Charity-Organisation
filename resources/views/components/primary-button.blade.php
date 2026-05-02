<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-5 py-2 border border-transparent rounded-lg font-semibold text-sm text-white uppercase tracking-widest transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
