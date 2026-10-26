@props(['name', 'title', 'sottotitolo' => null, 'aperto' => false])

{{-- Pannello laterale: si apre con $dispatch('apri-pannello', '{{ $name }}'). --}}
<div x-data="{ aperto: @js((bool) $aperto) }"
     x-on:apri-pannello.window="aperto = ($event.detail === @js($name))"
     x-on:keydown.escape.window="aperto = false"
     x-cloak>
    <template x-if="aperto">
        <div>
            <div class="pa-drawer-overlay" x-on:click="aperto = false" aria-hidden="true"></div>
            <section class="pa-drawer" role="dialog" aria-modal="true" aria-labelledby="pannello-{{ $name }}">
                <header class="flex items-start justify-between gap-3 border-b border-[var(--slate-200)] px-6 py-5">
                    <div class="min-w-0">
                        <h2 id="pannello-{{ $name }}" class="m-0 text-xl font-bold">{{ $title }}</h2>
                        @if($sottotitolo)<p class="m-0 mt-1 truncate text-sm text-[var(--slate-600)]">{{ $sottotitolo }}</p>@endif
                    </div>
                    <button type="button" x-on:click="aperto = false" aria-label="Chiudi pannello"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-[var(--slate-600)] hover:bg-[var(--slate-100)]">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </header>
                <div class="flex flex-1 flex-col overflow-y-auto">
                    {{ $slot }}
                </div>
            </section>
        </div>
    </template>
</div>
