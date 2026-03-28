@php
    $enteNome = \App\Models\Impostazione::get('ente_nome', 'ProntoPA');
    $u = auth()->user();
    $isAdmin   = $u && ($u->isAdmin() || $u->hasRole('admin'));
    $isGestore = $u && ($u->isGestore() || $u->hasRole('gestore'));
    $isSegnalatore = $u && $u->hasRole('segnalatore');
@endphp
<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo / Nome ente -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="font-bold text-blue-700 text-lg tracking-tight">
                        {{ $enteNome }}
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-6 sm:-my-px sm:ms-10 sm:flex">
                    @if($isAdmin || $isGestore)
                        <x-nav-link :href="route('gestione.dashboard')" :active="request()->routeIs('gestione.*')">
                            Gestione
                        </x-nav-link>
                        <x-nav-link :href="route('imprese.index')" :active="request()->routeIs('imprese.index') || request()->routeIs('imprese.create') || request()->routeIs('imprese.edit')">
                            Imprese
                        </x-nav-link>
                        <x-nav-link :href="route('appalti.index')" :active="request()->routeIs('appalti.*')">
                            Appalti
                        </x-nav-link>
                        <x-nav-link :href="route('statistiche.index')" :active="request()->routeIs('statistiche.*')">
                            Statistiche
                        </x-nav-link>
                    @endif

                    @if($isSegnalatore)
                        <x-nav-link :href="route('segnalatore.dashboard')" :active="request()->routeIs('segnalatore.*')">
                            Le mie segnalazioni
                        </x-nav-link>
                        <x-nav-link :href="route('segnalazioni.create')" :active="request()->routeIs('segnalazioni.create')">
                            Nuova segnalazione
                        </x-nav-link>
                    @endif

                    @if($isAdmin)
                        <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">
                            Admin
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- User Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @if($isAdmin)
                            <x-dropdown-link :href="route('admin.impostazioni.index')">
                                Impostazioni ente
                            </x-dropdown-link>
                            <div class="border-t border-gray-100"></div>
                        @endif
                        <x-dropdown-link :href="route('profile.edit')">
                            Profilo
                        </x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                Esci
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @if($isAdmin || $isGestore)
                <x-responsive-nav-link :href="route('gestione.dashboard')" :active="request()->routeIs('gestione.*')">Gestione</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('imprese.index')" :active="request()->routeIs('imprese.index')">Imprese</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('appalti.index')" :active="request()->routeIs('appalti.*')">Appalti</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('statistiche.index')" :active="request()->routeIs('statistiche.*')">Statistiche</x-responsive-nav-link>
            @endif
            @if($isSegnalatore)
                <x-responsive-nav-link :href="route('segnalatore.dashboard')" :active="request()->routeIs('segnalatore.*')">Le mie segnalazioni</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('segnalazioni.create')" :active="request()->routeIs('segnalazioni.create')">Nuova segnalazione</x-responsive-nav-link>
            @endif
            @if($isAdmin)
                <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">Admin</x-responsive-nav-link>
            @endif
        </div>

        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                @if($isAdmin)
                    <x-responsive-nav-link :href="route('admin.impostazioni.index')">Impostazioni ente</x-responsive-nav-link>
                @endif
                <x-responsive-nav-link :href="route('profile.edit')">Profilo</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Esci</x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
