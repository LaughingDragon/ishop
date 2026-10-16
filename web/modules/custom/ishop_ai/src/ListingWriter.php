<?php

declare(strict_types=1);

namespace Drupal\ishop_ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;

/**
 * Photos to a draft listing, priced from web-searched sold comps (Phase 7).
 *
 * Ported from the old crosslister's app/ai_provider/claude.py: the prompt and
 * the listing fields, effort medium, pause_turn resumes, a photo-only retry
 * if the search request fails, and the rule that research fields are cleared
 * when no search really happened (a guess is never shown as market data).
 *
 * Changed 2026-10-16: the prompt says the items are pre-owned household
 * goods (redesign: Cody sells what is already in the house); and decision
 * 264 after the cost test: Claude Sonnet 5.5 with the server-side refusal
 * fallback, up to 8 photos at 1092 px, web search on resale sites only (max
 * 4 searches). About $0.02-0.09 an item in the test, against $0.30-0.64.
 *
 * Two-step (Liam: "build it", 2026-10-16, report 2026-10-16-7), on when
 * ishop_ai.settings:two_step is TRUE: step 1, Claude Haiku 5.5 writes the
 * whole draft from ONE photo at 1092 px with no search, plus a rough value.
 * Step 2 runs only when that value is at least research_threshold ($15): a
 * text-only Claude Sonnet 5.5 sold-price search (Haiku 5.5 can't use the
 * current web search tool). Research for a near-identical item (same
 * normalized title and category, within research_reuse_days) is reused
 * instead. If step 1 fails, the one-step run above is used.
 */
final class ListingWriter {

  public const MODEL = 'claude-sonnet-5-5';

  /**
   * Step 1 of the two-step run: identify and write from one photo.
   */
  public const IDENTIFY_MODEL = 'claude-haiku-5-5';

  /**
   * Where reused research is kept: key = normalized title + category.
   */
  public const RESEARCH_STORE = 'ishop_ai.research';

  public const MAX_PHOTOS = 8;

  /**
   * Search results and thinking share this with the listing itself.
   */
  public const MAX_TOKENS = 16000;

  public const MAX_CONTINUATIONS = 3;

  /**
   * Web search price (USD per search), checked 2026-10-15.
   */
  public const PRICE_PER_SEARCH = 0.01;

  /**
   * Per-model prices in USD per million tokens.
   *
   * Input, output, cache write, cache read. Opus checked 2026-10-15 on
   * Anthropic's pricing page; Claude Sonnet 5.5 added 2026-10-16 (cache write
   * taken as 1.25x input, as for Opus).
   */
  public const MODEL_PRICES = [
    'claude-opus-5-5' => [4.0, 20.0, 5.0, 0.20],
    'claude-sonnet-5-5' => [2.0, 10.0, 2.5, 0.20],
    // Claude Haiku 5.5, prompts up to 100K tokens (2026-10-16, Claude API
    // reference); cache prices assumed 1.25x / 0.1x input (no caching used).
    'claude-haiku-5-5' => [0.10, 0.50, 0.125, 0.01],
  ];

  /**
   * How a run is made.
   *
   * These defaults are what Cody's runs use; the cost test (2026-10-16)
   * passes others.
   */
  public const DEFAULTS = [
    'model' => self::MODEL,
    'style' => 'ai_input_small',
    'max_searches' => 4,
    'allowed_domains' => self::RESALE_DOMAINS,
    'sold_comps' => FALSE,
    // NULL = self::PROMPT.
    'prompt' => NULL,
  ];

  /**
   * Resale sites for the cost test's narrowed search.
   */
  public const RESALE_DOMAINS = [
    'ebay.com',
    'poshmark.com',
    'mercari.com',
    'depop.com',
    'grailed.com',
    'vinted.com',
    'stockx.com',
  ];

  /**
   * Extra instruction when sold_comps is on (cost test).
   */
  public const SOLD_COMPS_PROMPT = <<<'PROMPT'

Also fill sold_comps with every COMPLETED SALE you actually saw in the search results:
the sold price, the date it sold (as shown), the listing title and the exact URL of
the search result it came from. Asking prices and items still for sale do not count.
Leave sold_comps empty if you saw no completed sales.
PROMPT;

  /**
   * Conditions the site uses (the condition vocabulary).
   */
  public const CONDITIONS = ['New with tags', 'New without tags', 'Like new', 'Good', 'Fair', 'Poor'];

  /**
   * The old app's prompt, adapted to pre-owned household goods (2026-10-16).
   *
   * EBay is named on purpose: eBay SOLD listings are the best public record
   * of what things sell for; reading them is not selling there.
   */
  public const PROMPT = <<<'PROMPT'
You are helping someone sell household items they already own (pre-owned things
from their own home, often never used) on online resale marketplaces.
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
- Condition: the item is pre-owned. Use "New with tags" only if original tags or sealed
  packaging are visible, and "New without tags" only if it looks unused; otherwise
  judge the wear you can see.
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

  /**
   * Step 1: the old prompt without the search (two-step run).
   */
  public const IDENTIFY_PROMPT = <<<'PROMPT'
You are helping someone sell household items they already own (pre-owned things
from their own home, often never used) on online resale marketplaces.
The attached photo shows ONE item for sale.

Step 1 - Identify the item. Read every tag, label, logo and stamp you can see and work
out the exact brand, model or style name, and size. Only state what is actually visible
in the photo - never invent a brand, model or size that isn't there.

Step 2 - Write the listing:
- Title: what a buyer would type into search - brand, item type, key attributes. No
  emoji, no ALL CAPS.
- Description: 2-4 short paragraphs. Honest and specific; mention material, measurements
  or size if visible, and any flaws.
- Condition: the item is pre-owned. Use "New with tags" only if original tags or sealed
  packaging are visible, and "New without tags" only if it looks unused; otherwise
  judge the wear you can see.
- Condition notes: visible wear, stains, scratches. Empty string if it looks flawless.
- price_min / price_max / suggested_price: a rough secondhand value from what you know
  about items like this. There is no web search in this step: leave research_notes and
  sources as empty strings.
PROMPT;

  /**
   * Step 2: a text-only sold-price search for an identified item.
   */
  public const RESEARCH_PROMPT = <<<'PROMPT'
Find what this exact item actually sells for secondhand right now. USE THE web_search
TOOL. eBay SOLD listings are the most reliable source - they are completed sales, not
asking prices - so check those first, then Poshmark, Mercari, Depop, Grailed, Vinted
and StockX. Search for the specific item, not a generic category.

The item:
%s

Return price_min / price_max / suggested_price based on the real sold prices you found
(suggested_price is the single number to list at and must always be filled in),
research_notes (one or two plain sentences on what the search actually turned up) and
sources (the URLs you used, one per line). If the search turns up nothing useful, give
your best estimate and leave research_notes and sources empty.
PROMPT;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * The site's category paths ("Parent > Child"), for the schema's list.
   *
   * @return string[]
   *   Paths in the vocabulary's order.
   */
  public function categories(): array {
    $names = [];
    $paths = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('category') as $term) {
      $names[(int) $term->tid] = $term->name;
      $parent = (int) ($term->parents[0] ?? 0);
      $paths[(int) $term->tid] = ($parent && isset($paths[$parent]) ? $paths[$parent] . ' > ' : '') . $term->name;
    }
    return array_values($paths);
  }

  /**
   * The listing fields, as in the old app's ListingDraft (descriptions kept).
   *
   * Changed 2026-10-16 (after Liam's first real item): category must be
   * one of the site's own categories (closest match), with new_category_idea
   * when none fits well; size is for clothing and shoes only.
   *
   * @param string[] $categories
   *   The site's category paths; empty keeps the old free-text category.
   */
  public static function schema(array $categories = []): array {
    $string = fn (string $description) => ['type' => 'string', 'description' => $description];
    $number = fn (string $description) => ['type' => 'number', 'description' => $description];
    $properties = [
      'title' => $string('Keyword-rich title a buyer would search for, under 80 characters'),
      'description' => $string('Honest, appealing description: what it is, material, fit, flaws'),
      'category' => $categories
        ? [
          'type' => 'string',
          'enum' => array_values($categories),
          'description' => 'The closest of the seller\'s own categories. Pick "Other" only if nothing else is close',
        ]
        : $string('General category path, e.g. "Women\'s Clothing > Dresses"'),
      'new_category_idea' => $string('Empty string if one of the categories fits well (including the one you picked). If none fits well, a short name for a category the seller could add, e.g. "Home & Kitchen > Holiday & Seasonal"'),
      'condition' => $string('One of: "New with tags", "New without tags", "Like new", "Good", "Fair", "Poor"'),
      'condition_notes' => $string('Specific flaws visible in the photos; empty string if none'),
      'brand' => $string('Brand name only if readable on a tag or logo; empty string otherwise'),
      'size' => $string('Clothing or shoe size only, and only if readable on a tag. Empty string for anything that is not clothing or shoes (no capacities, dimensions or piece counts here)'),
      'color' => $string('Main color or colors'),
      'price_min' => $number('Low end of a realistic USD resale price range'),
      'price_max' => $number('High end of a realistic USD resale price range'),
      'suggested_price' => $number('The single USD price to list at, based on real comps when they were found. Always fill this in - it is what the app auto-fills for the seller'),
      'research_notes' => $string('Short plain-English summary of what the price research found, e.g. "Similar Gap navy tees sold $12-18 on eBay in the last month". Empty string if no search was performed'),
      'sources' => $string('Newline-separated list of the URLs or sites the prices came from; empty string if no search was performed'),
      // Check sold prices searches these (whole titles found nothing).
      'search_query' => $string('3 to 5 words a buyer would type into eBay search to find sold listings of this kind of item: brand, item type, model or line. No condition words, colors, sizes or counts. E.g. "Heye Birthday puzzle 1000"'),
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
  public function photos(NodeInterface $item, string $style_name = 'ai_input'): array {
    $style = ImageStyle::load($style_name);
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
  public function write(NodeInterface $item, string $key, array $options = []): array {
    $settings = $this->configFactory->get('ishop_ai.settings');
    // The cost test passes its own options; Cody's runs use the setting.
    if (!$options && $settings->get('two_step')) {
      try {
        return $this->writeTwoStep($item, $key, (float) $settings->get('research_threshold'), (int) $settings->get('research_reuse_days'));
      }
      catch (TwoStepFailed $e) {
        // Step 1 failed (declined photo, unusable answer): one-step run,
        // with step 1's tokens still counted.
        $result = $this->write($item, $key, ['two_step_failed' => TRUE]);
        $result['usage'] = self::combine([$e->usage, $result['usage']]);
        return $result;
      }
    }
    unset($options['two_step_failed']);
    $options += self::DEFAULTS;
    $images = $this->photos($item, $options['style']);
    if (!$images) {
      throw new \RuntimeException('This item has no photos yet, so there is nothing for the AI to look at.');
    }
    $content = [];
    foreach ($images as $data) {
      $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $data]];
    }
    $prompt = ($options['prompt'] ?? self::PROMPT) . ($options['sold_comps'] ? self::SOLD_COMPS_PROMPT : '');
    $content[] = ['type' => 'text', 'text' => $prompt];
    $schema = self::schema($this->categories());
    if ($options['sold_comps']) {
      $schema['properties']['sold_comps'] = [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'properties' => [
            'price' => ['type' => 'number'],
            'sold_date' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'url' => ['type' => 'string'],
          ],
          'required' => ['price', 'sold_date', 'title', 'url'],
          'additionalProperties' => FALSE,
        ],
      ];
      $schema['required'][] = 'sold_comps';
    }
    $client = new Client(apiKey: $key);

    $usage = [
      'input' => 0,
      'output' => 0,
      'cache_write' => 0,
      'cache_read' => 0,
      'searches' => 0,
      'model' => $options['model'],
    ];
    try {
      [$response, $blocks] = $this->generate($client, $content, TRUE, $usage, $options, $schema);
    }
    catch (AuthenticationException | PermissionDeniedException $e) {
      throw $e;
    }
    catch (APIStatusException $e) {
      if ($e->type?->value === 'billing_error') {
        throw $e;
      }
      // Research is a bonus, never a requirement: retry from the photos alone.
      [$response, $blocks] = $this->generate($client, $content, FALSE, $usage, $options, $schema);
    }
    catch (\Throwable) {
      [$response, $blocks] = $this->generate($client, $content, FALSE, $usage, $options, $schema);
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
    return ['draft' => $draft, 'usage' => $usage, 'searched' => $searched, 'blocks' => $blocks];
  }

  /**
   * The two-step run (see the class comment).
   *
   * @return array{draft: array, usage: array, searched: bool, blocks: array}
   *   As write(); the draft also says what happened to the research:
   *   research = skipped (under the threshold), reused, searched or none.
   *
   * @throws \Drupal\ishop_ai\TwoStepFailed
   *   Step 1 gave nothing usable: the caller falls back to one step.
   */
  public function writeTwoStep(NodeInterface $item, string $key, float $threshold, int $reuse_days): array {
    $images = $this->photos($item, 'ai_input_small');
    if (!$images) {
      throw new \RuntimeException('This item has no photos yet, so there is nothing for the AI to look at.');
    }
    $client = new Client(apiKey: $key);
    $categories = $this->categories();
    $content = [
      ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $images[0]]],
      ['type' => 'text', 'text' => self::IDENTIFY_PROMPT],
    ];
    $step1 = self::emptyUsage(self::IDENTIFY_MODEL);
    $options = ['model' => self::IDENTIFY_MODEL, 'effort' => 'low', 'fallbacks' => FALSE];
    try {
      [$response] = $this->generate($client, $content, FALSE, $step1, $options, self::schema($categories));
    }
    catch (AuthenticationException | PermissionDeniedException $e) {
      throw $e;
    }
    catch (APIStatusException $e) {
      if ($e->type?->value === 'billing_error') {
        throw $e;
      }
      throw new TwoStepFailed('Step 1 error: ' . $e->getMessage(), $step1);
    }
    $draft = self::decodeDraft($response);
    if ($draft === NULL) {
      throw new TwoStepFailed('Step 1 gave no usable listing.', $step1);
    }
    self::cleanResearch($draft, []);
    $steps = [$step1];
    $blocks = [];
    $searched = FALSE;
    $draft['research'] = 'skipped';
    $store = $this->keyValue->get(self::RESEARCH_STORE);
    $research_key = self::researchKey((string) $draft['title'], (string) $draft['category']);
    if ((float) $draft['suggested_price'] >= $threshold) {
      $saved = $store->get($research_key);
      if ($saved && $saved['at'] >= time() - $reuse_days * 86400 && (int) $saved['nid'] !== (int) $item->id()) {
        $draft = array_merge($draft, array_intersect_key($saved, array_flip(self::RESEARCH_FIELDS)));
        $draft['research'] = 'reused';
        $draft['research_from'] = (int) $saved['nid'];
        $searched = TRUE;
      }
      else {
        $step2 = self::emptyUsage(self::MODEL);
        $about = implode("\n", array_filter([
          'Title: ' . $draft['title'],
          $draft['brand'] !== '' ? 'Brand: ' . $draft['brand'] : '',
          'Category: ' . $draft['category'],
          'Condition: ' . $draft['condition'],
          $draft['size'] !== '' ? 'Size: ' . $draft['size'] : '',
          'Description: ' . mb_substr((string) $draft['description'], 0, 600),
        ]));
        $research = NULL;
        try {
          $ask = [['type' => 'text', 'text' => sprintf(self::RESEARCH_PROMPT, $about)]];
          [$response, $blocks] = $this->generate($client, $ask, TRUE, $step2, self::DEFAULTS, self::researchSchema());
          $research = self::decodeDraft($response, 'suggested_price');
        }
        catch (AuthenticationException | PermissionDeniedException $e) {
          throw $e;
        }
        catch (APIStatusException $e) {
          if ($e->type?->value === 'billing_error') {
            throw $e;
          }
        }
        $steps[] = $step2;
        $draft['research'] = 'none';
        if ($research) {
          $merged = array_merge($draft, array_intersect_key($research, array_flip(self::RESEARCH_FIELDS)));
          $searched = self::cleanResearch($merged, $blocks);
          if ($searched) {
            $draft = $merged;
            $draft['research'] = 'searched';
            $saved = array_intersect_key($draft, array_flip(self::RESEARCH_FIELDS));
            $store->set($research_key, $saved + ['nid' => (int) $item->id(), 'at' => time()]);
          }
        }
      }
    }
    $draft['research_threshold'] = $threshold;
    return ['draft' => $draft, 'usage' => self::combine($steps), 'searched' => $searched, 'blocks' => $blocks];
  }

  /**
   * The fields step 2 fills in (and that are reused).
   */
  public const RESEARCH_FIELDS = ['price_min', 'price_max', 'suggested_price', 'research_notes', 'sources'];

  /**
   * Step 2's answer: just the research fields.
   */
  public static function researchSchema(): array {
    $schema = self::schema();
    $schema['properties'] = array_intersect_key($schema['properties'], array_flip(self::RESEARCH_FIELDS));
    $schema['required'] = self::RESEARCH_FIELDS;
    return $schema;
  }

  /**
   * Near-identical items share research: normalized title + category.
   */
  public static function researchKey(string $title, string $category): string {
    $norm = fn (string $s) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    return sha1($norm($title) . '|' . $norm($category));
  }

  /**
   * The JSON answer of a response, or NULL (refusal, no JSON, field missing).
   */
  private static function decodeDraft(object $response, string $required = 'title'): ?array {
    if ($response->stopReason === 'refusal') {
      return NULL;
    }
    $draft = NULL;
    foreach ($response->content as $block) {
      if ($block->type === 'text') {
        $draft = json_decode($block->text, TRUE);
      }
    }
    return is_array($draft) && isset($draft[$required]) ? $draft : NULL;
  }

  /**
   * Zero usage for one model.
   */
  public static function emptyUsage(string $model): array {
    return ['input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'model' => $model];
  }

  /**
   * Several steps' usage as one: totals, plus the steps for cost().
   */
  public static function combine(array $steps): array {
    $flat = [];
    foreach ($steps as $step) {
      foreach ($step['steps'] ?? [$step] as $one) {
        $flat[] = $one;
      }
    }
    $total = self::emptyUsage('two-step');
    foreach ($flat as $one) {
      foreach (['input', 'output', 'cache_write', 'cache_read', 'searches'] as $k) {
        $total[$k] += (int) ($one[$k] ?? 0);
      }
    }
    $total['steps'] = $flat;
    return $total;
  }

  /**
   * One generation, resuming through pause_turn stops.
   *
   * @return array{0: object, 1: array}
   *   The final response and every content block seen (as plain arrays).
   */
  private function generate(Client $client, array $content, bool $search, array &$usage, array $options, array $schema): array {
    $messages = [['role' => 'user', 'content' => $content]];
    $blocks = [];
    $response = NULL;
    for ($i = 0; $i <= self::MAX_CONTINUATIONS; $i++) {
      $args = [
        'model' => $options['model'],
        'maxTokens' => self::MAX_TOKENS,
        'messages' => $messages,
        // Research needs some deliberation; more mostly buys more searches.
        'outputConfig' => [
          'effort' => $options['effort'] ?? 'medium',
          'format' => ['type' => 'json_schema', 'schema' => $schema],
        ],
      ];
      // Server-side refusal fallback (Claude Opus 5.5 / Sonnet 5.5; Claude
      // Haiku 5.5 has none).
      if ($options['fallbacks'] ?? TRUE) {
        $args['betas'] = ['server-side-fallback-2026-07-01'];
        $args['fallbacks'] = 'default';
      }
      if ($search) {
        $tool = ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => $options['max_searches']];
        if ($options['allowed_domains']) {
          $tool['allowedDomains'] = $options['allowed_domains'];
        }
        $args['tools'] = [$tool];
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
    if (!empty($usage['steps'])) {
      return round(array_sum(array_map([self::class, 'cost'], $usage['steps'])), 4);
    }
    [$in, $out, $write, $read] = self::MODEL_PRICES[$usage['model'] ?? self::MODEL] ?? self::MODEL_PRICES[self::MODEL];
    return round(
      $usage['input'] / 1e6 * $in
      + $usage['output'] / 1e6 * $out
      + ($usage['cache_write'] ?? 0) / 1e6 * $write
      + ($usage['cache_read'] ?? 0) / 1e6 * $read
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
