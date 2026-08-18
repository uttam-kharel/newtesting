<?php

namespace Tests\Feature;

use App\Livewire\Notes;
use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_the_notes_component(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Notes');
    }

    public function test_user_can_create_a_note(): void
    {
        Livewire::test(Notes::class)
            ->set('title', 'Buy milk')
            ->set('body', 'and eggs')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->assertSet('body', '');

        $this->assertDatabaseHas('notes', [
            'title' => 'Buy milk',
            'body' => 'and eggs',
            'completed' => false,
        ]);
    }

    public function test_title_is_required_to_create_a_note(): void
    {
        Livewire::test(Notes::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_user_can_toggle_a_note_as_completed(): void
    {
        $note = Note::create(['title' => 'Walk the dog']);

        Livewire::test(Notes::class)
            ->call('toggle', $note->id);

        $this->assertTrue($note->fresh()->completed);

        Livewire::test(Notes::class)
            ->call('toggle', $note->id);

        $this->assertFalse($note->fresh()->completed);
    }

    public function test_user_can_delete_a_note(): void
    {
        $note = Note::create(['title' => 'Temporary note']);

        Livewire::test(Notes::class)
            ->call('delete', $note->id);

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }
}
