<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ishop_life\Form\NoteForm;
use Drupal\ishop_life\Form\TodoForm;
use Drupal\ishop_life\LogBook;
use Drupal\ishop_life\Todos;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The Break room: to-dos and notes (Phase 5 step 4).
 */
final class BreakRoomController extends ControllerBase {

  public function __construct(
    private readonly Todos $todos,
  ) {}

  /**
   * The page: open to-dos, add forms, notes, recently done.
   */
  public function page(): array {
    $return = Url::fromRoute('ishop_life.break_room')->toString();
    $today = LogBook::today();
    $open = [];
    foreach ($this->todos->todos(FALSE) as $todo) {
      $due = (string) $todo->get('field_todo_due')->value;
      $open[] = [
        'title' => $todo->label(),
        'due' => $due === '' ? '' : ($due < $today ? $this->t('overdue (@date)', ['@date' => $due]) : ($due === $today ? $this->t('today') : (new \DateTimeImmutable($due))->format('D M j'))),
        'late' => $due !== '' && $due <= $today,
        'done' => Todos::toggleUrl($todo, 'done', $return),
        'edit' => $todo->toUrl('edit-form', ['query' => ['destination' => $return]])->toString(),
      ];
    }
    $done = [];
    foreach ($this->todos->todos(TRUE, 10) as $todo) {
      $done[] = ['title' => $todo->label(), 'undo' => Todos::toggleUrl($todo, 'undo', $return)];
    }
    $notes = [];
    foreach ($this->todos->notes() as $note) {
      $notes[] = [
        'title' => $note->label(),
        'text' => (string) $note->get('field_log_note')->value,
        'edit' => $note->toUrl('edit-form', ['query' => ['destination' => $return]])->toString(),
        'delete' => $note->toUrl('delete-form', ['query' => ['destination' => $return]])->toString(),
      ];
    }
    return [
      'todos' => [
        '#type' => 'inline_template',
        '#template' => '<section class="rm-todos" aria-labelledby="rm-todos-title"><h2 id="rm-todos-title">{{ todo_title }}</h2>{% if open %}<ul class="rm-todos__list">{% for t in open %}<li><a class="rm-todos__done" href="{{ t.done }}" aria-label="{{ done_label }}: {{ t.title }}"><span aria-hidden="true">&#10003;</span></a><span class="rm-todos__text"><strong>{{ t.title }}</strong>{% if t.due %}<span class="rm-todos__due{{ t.late ? \' is-late\' }}">{{ t.due }}</span>{% endif %}</span><a class="rm-todos__edit" href="{{ t.edit }}">{{ edit }}</a></li>{% endfor %}</ul>{% else %}<p>{{ nothing }}</p>{% endif %}</section>',
        '#context' => [
          'todo_title' => $this->t('To-do'),
          'open' => $open,
          'done_label' => $this->t('Done'),
          'edit' => $this->t('Edit'),
          'nothing' => $this->t('Nothing on the list. Nice.'),
        ],
      ],
      'forms' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['rm-log__forms']],
        'todo' => $this->formBuilder()->getForm(TodoForm::class),
        'note' => $this->formBuilder()->getForm(NoteForm::class),
      ],
      'rest' => [
        '#type' => 'inline_template',
        '#template' => '<section class="rm-notes" aria-labelledby="rm-notes-title"><h2 id="rm-notes-title">{{ notes_title }}</h2>{% for n in notes %}<article class="rm-card rm-notes__note"><h3>{{ n.title }}</h3>{% if n.text %}<p>{{ n.text|nl2br }}</p>{% endif %}<p class="rm-log__links"><a href="{{ n.edit }}">{{ edit }}</a> <a href="{{ n.delete }}">{{ delete }}</a></p></article>{% else %}<p>{{ no_notes }}</p>{% endfor %}</section>
{% if done %}<section class="rm-todos rm-todos--done" aria-labelledby="rm-done-title"><h2 id="rm-done-title">{{ done_title }}</h2><ul class="rm-todos__list">{% for t in done %}<li><span class="rm-todos__text"><s>{{ t.title }}</s></span><a class="rm-todos__edit" href="{{ t.undo }}">{{ undo }}</a></li>{% endfor %}</ul></section>{% endif %}',
        '#context' => [
          'notes_title' => $this->t('Notes'),
          'notes' => $notes,
          'no_notes' => $this->t('No notes yet.'),
          'edit' => $this->t('Edit'),
          'delete' => $this->t('Delete'),
          'done_title' => $this->t('Recently done'),
          'done' => $done,
          'undo' => $this->t('Undo'),
        ],
      ],
      '#attached' => ['library' => ['ishop_life/log']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Done or Undo, then back where Cody was.
   */
  public function toggle(NodeInterface $node, string $action): RedirectResponse {
    $this->todos->setDone($node, $action === 'done');
    $this->messenger()->addStatus($action === 'done'
      ? $this->t('Done: @title.', ['@title' => $node->label()])
      : $this->t('Back on the list: @title.', ['@title' => $node->label()]));
    return $this->redirect('ishop_life.break_room');
  }

}
