<?php

declare(strict_types=1);

namespace Drupal\ishop_life\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ishop_life\Todos;

/**
 * Break room quick add: a to-do (with optional date) or a note.
 *
 * TodoForm and NoteForm each have their own form ID: two forms with one ID
 * on a page would process each other's submissions.
 */
abstract class QuickAddForm extends FormBase {

  /**
   * The content type this form adds: todo or note.
   */
  protected const TYPE = '';

  /**
   * Services are protected, not private readonly (forms are serialized).
   */
  public function __construct(
    protected Todos $todos,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ishop_life_quick_add_' . static::TYPE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $type = static::TYPE;
    $form['#attributes']['class'][] = 'rm-card';
    $form['#attributes']['class'][] = 'rm-log-form';
    $form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $type === 'todo' ? $this->t('Add a to-do') : $this->t('Add a note'),
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $type === 'todo' ? $this->t('What to do') : $this->t('Title'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    if ($type === 'todo') {
      $form['due'] = [
        '#type' => 'date',
        '#title' => $this->t('Date (optional)'),
        '#description' => $this->t('With a date it also shows on the Calendar wall, and in "Needs you now" on the day.'),
      ];
    }
    else {
      $form['text'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Note'),
        '#rows' => 4,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $type === 'todo' ? $this->t('Add the to-do') : $this->t('Save the note'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $type = static::TYPE;
    $node = $this->todos->add($type, trim((string) $form_state->getValue('title')), $form_state->getValue('due') ?: NULL, (string) $form_state->getValue('text'));
    $this->messenger()->addStatus($type === 'todo'
      ? $this->t('Added to the to-do list: @title.', ['@title' => $node->label()])
      : $this->t('Note saved: @title.', ['@title' => $node->label()]));
  }

}
