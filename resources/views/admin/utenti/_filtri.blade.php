{{-- Ricerca e "mostra disattivati" comuni alle tre liste. $rotta = nome della rotta della lista. --}}
<form method="GET" action="{{ route($rotta) }}" class="mb-4 flex flex-wrap items-center gap-3 text-sm">
    <label for="q" class="sr-only">Cerca</label>
    <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Cerca per nome, username, codice fiscale o email"
           class="w-full sm:w-96 rounded-md border-gray-300 shadow-sm text-sm">
    <label for="mostra_disattivati" class="inline-flex items-center gap-2 cursor-pointer whitespace-nowrap text-xs text-gray-600">
        <input id="mostra_disattivati" type="checkbox" name="mostra_disattivati" value="1"
               class="rounded border-gray-300 text-blue-600 shadow-sm"
               {{ request()->boolean('mostra_disattivati') ? 'checked' : '' }}
               onchange="this.form.submit()">
        Mostra disattivati
    </label>
    <button type="submit" class="px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-xs font-medium hover:bg-gray-200">Cerca</button>
</form>
