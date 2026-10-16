<?php

declare(strict_types=1);

namespace Drupal\ishop_inbox;

use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Turns a raw email into the few things the inbox looks at (Phase 10 B).
 */
final class MessageReader {

  /**
   * Largest PDF kept (a label is a page or two).
   */
  public const MAX_PDF = 10 * 1024 * 1024;

  /**
   * Reads one RFC 822 message.
   *
   * @return array{from: string, subject: string, auth: string[], message_id: string, received: int, text: string, pdfs: array<int, array{name: string, data: string}>}
   *   The text is the plain part, or the HTML turned into text, followed by
   *   every link in the HTML (so listing links can be found).
   */
  public static function read(string $raw): array {
    $message = (new MailMimeParser())->parse($raw, FALSE);
    $header = fn (string $name) => trim((string) $message->getHeader($name)?->getRawValue());
    $html = (string) $message->getHtmlContent();
    $text = (string) $message->getTextContent();
    if ($text === '' && $html !== '') {
      $text = self::htmlToText($html);
    }
    if ($html !== '' && preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      $text .= "\n" . implode("\n", array_unique(array_map('html_entity_decode', $m[1])));
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $pdfs = [];
    foreach ($message->getAllAttachmentParts() as $part) {
      $name = (string) $part->getFilename();
      $type = strtolower($part->getContentType(''));
      if ($type !== 'application/pdf' && !str_ends_with(strtolower($name), '.pdf')) {
        continue;
      }
      $data = (string) $part->getBinaryContentStream()?->getContents();
      if ($data !== '' && strlen($data) <= self::MAX_PDF && str_starts_with($data, '%PDF')) {
        $pdfs[] = ['name' => $name !== '' ? $name : 'label.pdf', 'data' => $data];
      }
    }
    $date = $message->getHeader('Date');
    $received = $date && method_exists($date, 'getDateTime') && $date->getDateTime() ? $date->getDateTime()->getTimestamp() : time();
    return [
      'from' => $header('From'),
      'subject' => trim((string) $message->getSubject()),
      'auth' => array_map(fn ($h) => (string) $h->getRawValue(), $message->getAllHeadersByName('Authentication-Results')),
      'message_id' => trim($header('Message-ID'), '<> '),
      'received' => $received,
      'text' => $text,
      'pdfs' => $pdfs,
    ];
  }

  /**
   * Readable text from an HTML email: block tags become line breaks.
   */
  public static function htmlToText(string $html): string {
    $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
    $html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d|/td)\b[^>]*>#i', "\n", (string) $html);
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    return trim((string) preg_replace("/\n\s*\n+/", "\n", (string) $text));
  }

}
