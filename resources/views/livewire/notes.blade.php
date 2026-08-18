<div class="mx-auto max-w-2xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900">Notes</h1>
        <p class="mt-1 text-sm text-zinc-500">
            A Livewire + Tailwind demo backed by Postgres (Neon) on Vercel.
        </p>
    </header>

    @if (session('status'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="mb-10 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
        <div class="space-y-4">
            <div>
                <label for="title" class="mb-1 block text-sm font-medium text-zinc-700">Title</label>
                <input
                    wire:model="title"
                    id="title"
                    type="text"
                    placeholder="What needs doing?"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                >
                @error('title')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="body" class="mb-1 block text-sm font-medium text-zinc-700">Details (optional)</label>
                <textarea
                    wire:model="body"
                    id="body"
                    rows="3"
                    placeholder="Add any details…"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                ></textarea>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Add note
                </button>
            </div>
        </div>
    </form>

    <ul class="space-y-3">
        @forelse ($notes as $note)
            <li class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                <button
                    wire:click="toggle({{ $note->id }})"
                    class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border border-zinc-300 transition hover:border-indigo-400"
                    aria-label="{{ $note->completed ? 'Mark as not done' : 'Mark as done' }}"
                >
                    @if ($note->completed)
                        <svg class="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                        </svg>
                    @endif
                </button>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium {{ $note->completed ? 'text-zinc-400 line-through' : 'text-zinc-900' }}">
                        {{ $note->title }}
                    </p>
                    @if ($note->body)
                        <p class="mt-1 text-sm text-zinc-500">{{ $note->body }}</p>
                    @endif
                    <p class="mt-1 text-xs text-zinc-400">
                        {{ $note->created_at->diffForHumans() }}
                    </p>
                </div>

                <button
                    wire:click="delete({{ $note->id }})"
                    wire:confirm="Delete this note?"
                    class="shrink-0 rounded-lg px-2 py-1 text-sm text-zinc-400 transition hover:bg-red-50 hover:text-red-600"
                    aria-label="Delete note"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </li>
        @empty
            <li class="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">
                No notes yet. Create your first one above — it's stored in Neon Postgres.
            </li>
        @endforelse
    </ul>
</div>
