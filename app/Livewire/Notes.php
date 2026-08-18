<?php

namespace App\Livewire;

use App\Models\Note;
use Livewire\Component;

class Notes extends Component
{
    public string $title = '';

    public string $body = '';

    /**
     * Validation rules for creating a note.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        Note::create([
            'title' => $this->title,
            'body' => $this->body,
        ]);

        $this->reset('title', 'body');

        session()->flash('status', 'Note created.');
    }

    public function toggle(Note $note): void
    {
        $note->update([
            'completed' => ! $note->completed,
        ]);
    }

    public function delete(Note $note): void
    {
        $note->delete();

        session()->flash('status', 'Note deleted.');
    }

    public function render()
    {
        return view('livewire.notes', [
            'notes' => Note::latest()->get(),
        ]);
    }
}
