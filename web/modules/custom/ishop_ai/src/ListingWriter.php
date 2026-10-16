<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;

/**
 * Photos to a draft listing, priced from web-searched sold comps (Phase 7).
 *
 * Ported from the old crosslister's app/ai_provider/claude.py: the prompt and
 * the listing fields word for word, up to 8 photos at 1568 px, web search
 * (max 5 searches), effort medium, pause_turn resumes, a photo-only retry if
 * the search request fails, and the rule that research fields are cleared
 * when no search really happened (a guess is never shown as market data).
 * Model: Claude Opus 5.5 with the server-side refusal fallback.
 */
final class ListingWriter {

  public const MODEL = 'claude-opus-5-5';

  public const MAX_PHOTOS = 8;

  /**
   * Search results and thinking share this with the listing itself.
   */
  public const MAX_TOKENS = 16000;

  public const MAX_CONTINUATIONS = 3;

  /**
   * Prices (USD), checked 2026-10-15 on Anthropic's pricing page.
   */
  public const PRICE_INPUT_PER_MTOK = 4.0;
  public const PRICE_OUTPUT_PER_MTOK = 20.0;
  public const PRICE_CACHE_WRITE_PER_MTOK = 5.0;
  public const PRICE_CACHE_READ_PER_MTOK = 0.20;
  public const PRICE_PER_SEARCH = 0.01;

  /**
   * Conditions the site uses (the condition vocabulary).
   */
  public const CONDITIONS = ['New with tags', 'New without tags', 'Like new', 'Good', 'Fair', 'Poor'];

  /**
   * The old app's prompt, word for word.
   *
   * EBay is named on purpose: eBay SOLD listings are the best public record
   * of what things sell for; reading them is not selling there.
   */
  public const PROMPT = <<<'PROMPT'
You are helping a reseller create an online marketplace listing.
All of the attached photos show ONE item for sale (different angles, tags, flaws).

Step 1 - Identify the item. Read every tag, label, logo and stamp you can see and work
out the exact brand, model or style name, and size. Only state what is actually visible
in the photos - never invent a brand, model or size that isn't there.

Step 2 - Research the price. USE THE web_search TOOL to look up what this exact item
actually sells for secondhand right now. eBay SOLD listings are the most reliable
source - they are completed sales, not asking prices - so check those first, then
Poshmark, Mercari, Depop, Grailed, Vinted and StockX. Search for the specific item you
identified, not a generic category.

Step 3 - Write the listing from what you found:
- Title: what a buyer would type into search - brand, item type, key attributes. No
  emoji, no ALL CAPS.
- Description: 2-4 short paragraphs. Honest and specific; mention material, measurements
  or size if visible, styling ideas, and any flaws.
- Condition notes: visible wear, stains, pilling, scratches. Empty string if it looks
  flawless.
- price_min / price_max / suggested_price: base these on the real sold prices you found,
  not on a guess from the photo. suggested_price is the single number the seller should
  list at, and must always be filled in.
- research_notes: one or two plain sentences on what the search actually turned up, e.g.
  "Similar Gap navy tees sold $12-18 on eBay in the last month."
- sources: the sites or URLs you actually used, one per line.

If the search fails or turns up nothing useful, still produce the listing: estimate the
price from the photos alone and leave research_notes and sources empty rather than
inventing market data.
PROMPT;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * The listing fields, as in the old app's ListingDraft (descriptions kept).
   */
  public static function schema(): array {
    $string = fn (string $description) => ['type' => 'string', 'description' => $description];
    $number = fn (string $description) => ['type' => 'number', 'description' => $description];
    $properties = [
      'title' => $string('Keyword-rich title a buyer would search for, under 80 characters'),
      'description' => $string('Honest, appealing description: what it is, material, fit, flaws'),
      'category' => $string('General category path, e.g. "Women\'s Clothing > Dresses"'),
      'condition' => $string('One of: "New with tags", "New without tags", "Like new", "Good", "Fair", "Poor"'),
      'condition_notes' => $string('Specific flaws visible in the photos; empty string if none'),
      'brand' => $string('Brand name only if readable on a tag or logo; empty string otherwise'),
      'size' => $string('Size only if readable on a tag; empty string otherwise'),
      'color' => $string('Main color or colors'),
      'price_min' => $number('Low end of a realistic USD resale price range'),
      'price_max' => $number('High end of a realistic USD resale price range'),
      'suggested_price' => $number('The single USD price to list at, based on real comps when they were found. Always fill this in - it is what the app auto-fills for the seller'),
      'research_notes' => $string('Short plain-English summary of what the price research found, e.g. "Similar Gap navy tees sold $12-18 on eBay in the last month". Empty string if no search was performed'),
      'sources' => $string('Newline-separated list of the URLs or sites the prices came from; empty string if no search was performed'),
    ];
    return [
      'type' => 'object',
      'properties' => $properties,
      'required' => array_keys($properties),
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * The item's photos as base64 JPEGs at up to 1568 px (first 8).
   *
   * @return string[]
   *   Base64 strings.
   */
  public function photos(NodeInterface $item): array {
    $style = ImageStyle::load('ai_input');
    $images = [];
    foreach ($item->get('field_photos') as $reference) {
      $file = $reference->entity?->get('field_media_image_1')->entity;
      if (!$file) {
        continue;
      }
      $uri = $file->getFileUri();
      $derivative = $style->buildUri($uri);
      if (!file_exists($derivative) && !$style->createDerivative($uri, $derivative)) {
        continue;
      }
      $images[] = base64_encode((string) file_get_contents($this->fileSystem->realpath($derivative)));
      if (count($images) >= self::MAX_PHOTOS) {
        break;
      }
    }
    return $images;
  }

  /**
   * Writes a draft from the item's photos.
   *
   * @return array{draft: array, usage: array, searched: bool}
   *   The draft (ListingDraft fields), usage (tokens, searches) and whether a
   *   search really ran.
   *
   * @throws \RuntimeException
   *   With a plain-English reason (no photos, refusal, unusable answer).
   * @throws \Anthropic\Core\Exceptions\APIException
   *   Refused key or no credit: the caller pauses AI.
   */
  public function write(NodeInterface $item, string $key): array {
    $images = $this->photos($item);
    if (!$images) {
      throw new \RuntimeException('This item has no photos yet, so there is nothing for the AI to look at.');
    }
    $content = [];
    foreach ($images as $data) {
      $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $data]];
    }
    $content[] = ['type' => 'text', 'text' => self::PROMPT];
    $client = new Client(apiKey: $key);

    $usage = [
      'input' => 0,
      'output' => 0,
      'cache_write' => 0,
      'cache_read' => 0,
      'searches' => 0,
      'model' => self::MODEL,
    ];
    try {
      [$response, $blocks] = $this->generate($client, $content, TRUE, $usage);
    }
    catch (AuthenticationException | PermissionDeniedException $e) {
      throw $e;
    }
    catch (APIStatusException $e) {
      if ($e->type?->value === 'billing_error') {
        throw $e;
      }
      // Research is a bonus, never a requirement: retry from the photos alone.
      [$response, $blocks] = $this->generate($client, $content, FALSE, $usage);
    }
    catch (\Throwable) {
      [$response, $blocks] = $this->generate($client, $content, FALSE, $usage);
    }

    // A refusal is an HTTP 200 with empty or partial content: check first.
    if ($response->stopReason === 'refusal') {
      throw new \RuntimeException('The AI declined to look at these photos. Try different photos.');
    }
    $draft = NULL;
    foreach ($response->content as $block) {
      if ($block->type === 'text') {
        $draft = json_decode($block->text, TRUE);
      }
    }
    if (!is_array($draft) || !isset($draft['title'])) {
      throw new \RuntimeException('The AI did not return a usable listing. Please try again.');
    }
    $searched = self::cleanResearch($draft, $blocks);
    return ['draft' => $draft, 'usage' => $usage, 'searched' => $searched];
  }

  /**
   * One generation, resuming through pause_turn stops.
   *
   * @return array{0: object, 1: array}
   *   The final response and every content block seen (as plain arrays).
   */
  private function generate(Client $client, array $content, bool $search, array &$usage): array {
    $messages = [['role' => 'user', 'content' => $content]];
    $blocks = [];
    $response = NULL;
    for ($i = 0; $i <= self::MAX_CONTINUATIONS; $i++) {
      $args = [
        'model' => self::MODEL,
        'maxTokens' => self::MAX_TOKENS,
        'messages' => $messages,
        // Research needs some deliberation; more mostly buys more searches.
        'outputConfig' => [
          'effort' => 'medium',
          'format' => ['type' => 'json_schema', 'schema' => self::schema()],
        ],
        // Server-side refusal fallback (recommended for Claude Opus 5.5).
        'betas' => ['server-side-fallback-2026-07-01'],
        'fallbacks' => 'default',
      ];
      if ($search) {
        $args['tools'] = [['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => 5]];
      }
      $response = $client->beta->messages->create(...$args);
      self::addUsage($usage, $response->usage);
      $plain = json_decode(json_encode($response->content), TRUE) ?: [];
      $blocks = array_merge($blocks, $plain);
      if ($response->stopReason !== 'pause_turn') {
        break;
      }
      $messages[] = ['role' => 'assistant', 'content' => $plain];
    }
    return [$response, $blocks];
  }

  /**
   * Adds one response's usage to the running totals.
   */
  private static function addUsage(array &$usage, object $u): void {
    $usage['input'] += (int) $u->inputTokens;
    $usage['output'] += (int) $u->outputTokens;
    $usage['cache_write'] += (int) ($u->cacheCreationInputTokens ?? 0);
    $usage['cache_read'] += (int) ($u->cacheReadInputTokens ?? 0);
    $usage['searches'] += (int) ($u->serverToolUse?->webSearchRequests ?? 0);
  }

  /**
   * The cost of a run in USD, from its usage.
   */
  public static function cost(array $usage): float {
    return round(
      $usage['input'] / 1e6 * self::PRICE_INPUT_PER_MTOK
      + $usage['output'] / 1e6 * self::PRICE_OUTPUT_PER_MTOK
      + ($usage['cache_write'] ?? 0) / 1e6 * self::PRICE_CACHE_WRITE_PER_MTOK
      + ($usage['cache_read'] ?? 0) / 1e6 * self::PRICE_CACHE_READ_PER_MTOK
      + ($usage['searches'] ?? 0) * self::PRICE_PER_SEARCH,
      4,
    );
  }

  /**
   * Keeps the research fields honest and guarantees a usable price.
   *
   * Ported from the old app: if no search really returned results, the
   * research notes and sources are cleared; missing sources are filled from
   * the result URLs; a missing suggested price is the middle of the range.
   *
   * @return bool
   *   Whether a search really returned results.
   */
  public static function cleanResearch(array &$draft, array $blocks): bool {
    $searched = FALSE;
    $urls = [];
    foreach ($blocks as $block) {
      if (($block['type'] ?? '') !== 'web_search_tool_result') {
        continue;
      }
      // A successful result holds a LIST; an error holds a single object.
      if (!is_array($block['content'] ?? NULL) || !array_is_list($block['content'])) {
        continue;
      }
      $searched = TRUE;
      foreach ($block['content'] as $result) {
        $url = (string) ($result['url'] ?? '');
        if ($url !== '' && !in_array($url, $urls, TRUE)) {
          $urls[] = $url;
        }
      }
    }
    if (!$searched) {
      $draft['research_notes'] = '';
      $draft['sources'] = '';
    }
    elseif (trim((string) ($draft['sources'] ?? '')) === '') {
      $draft['sources'] = implode("\n", $urls);
    }
    if ((float) ($draft['suggested_price'] ?? 0) <= 0) {
      $draft['suggested_price'] = round(((float) ($draft['price_min'] ?? 0) + (float) ($draft['price_max'] ?? 0)) / 2, 2);
    }
    return $searched;
  }

}
