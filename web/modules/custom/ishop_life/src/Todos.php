<?php

declare(strict_types=1);

namespace Drupal\ishop_life;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Notes and to-dos in the Break room (Phase 5 step 4).
 *
 * To-dos with a date show on the Calendar wall, and in "Needs you now" on
 * the day and while overdue.
 */
final class Todos {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Adds a to-do or a note.
   */
  public function add(string $type, string $title, ?string $due, string $text): NodeInterface {
    $values = [
      'type' => $type,
      'title' => mb_substr($title, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'promote' => 0,
    ];
    if ($type === 'todo') {
      $values += ['field_todo_due' => $due ?: NULL, 'field_todo_done' => FALSE];
    }
    else {
      $values += ['field_log_note' => $text];
    }
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $node->setRevisionLogMessage('Added in the Break room.');
    $node->save();
    return $node;
  }

  /**
   * Open or done to-dos: open ones by date (undated last), done ones newest.
   *
   * @return \Drupal\node\NodeInterface[]
   *   To-do nodes.
   */
  public function todos(bool $done, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'todo')
      ->condition('field_todo_done', $done ? 1 : 0)
      ->range(0, $limit);
    $done ? $query->sort('changed', 'DESC') : $query->sort('nid');
    $todos = $storage->loadMultiple($query->execute());
    if (!$done) {
      // Dated first (soonest first), then undated in the order they were added.
      $key = fn ($todo) => [$todo->get('field_todo_due')->value ?? '9999', (int) $todo->id()];
      uasort($todos, fn ($a, $b) => $key($a) <=> $key($b));
    }
    return $todos;
  }

  /**
   * Open to-dos due today or earlier: for "Needs you now".
   *
   * @return \Drupal\node\NodeInterface[]
   *   To-do nodes.
   */
  public function dueNow(): array {
    $today = LogBook::today();
    return array_filter($this->todos(FALSE), fn ($todo) => ($due = $todo->get('field_todo_due')->value) && $due <= $today);
  }

  /**
   * Open dated to-dos between two dates, as Calendar rows.
   */
  public function calendarRows(string $from, string $to): array {
    $rows = [];
    $url = Url::fromRoute('ishop_life.break_room')->toString();
    foreach ($this->todos(FALSE) as $todo) {
      $due = (string) $todo->get('field_todo_due')->value;
      if ($due !== '' && $due >= $from && $due <= $to) {
        $rows[] = [
          'date' => $due,
          'time' => '',
          'title' => $todo->label(),
          'kind' => 'todo',
          'url' => $url,
          'edit' => NULL,
          'delete' => NULL,
        ];
      }
    }
    return $rows;
  }

  /**
   * Marks a to-do done (today) or not done.
   */
  public function setDone(NodeInterface $todo, bool $done): void {
    $todo->set('field_todo_done', $done);
    $todo->set('field_done_on', $done ? LogBook::today() : NULL);
    $todo->setNewRevision(TRUE);
    $todo->setRevisionUserId((int) $this->currentUser->id());
    $todo->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $todo->setRevisionLogMessage($done ? 'Done.' : 'Not done after all.');
    $todo->save();
  }

  /**
   * The latest notes, newest first.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Note nodes.
   */
  public function notes(int $limit = 30): array {
    $storage = $this->entityTypeManager->getStorage('node');
    return $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'note')
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute());
  }

  /**
   * The Done (or Undo) link for a to-do, with a CSRF token.
   */
  public static function toggleUrl(NodeInterface $todo, string $action, string $return): string {
    return Url::fromRoute('ishop_life.todo_toggle', ['node' => $todo->id(), 'action' => $action], ['query' => ['destination' => $return]])->toString();
  }

}
