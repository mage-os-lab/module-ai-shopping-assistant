<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Component;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\NameMatch;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\SkuMatch;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;

/**
 * Rebuilds the cards of a restored conversation: products and comparisons from the products the session
 * has seen, every other card through its own enricher, the same way the live turn built it.
 */
class TranscriptView
{
    public function render(array $rows, Registry $registry, EnrichmentContext $ctx): array
    {
        $messages = [];
        $count = count($rows);
        for ($index = 0; $index < $count; $index++) {
            $row = $rows[$index];
            $content = is_array($row['content'] ?? null) ? $row['content'] : [];
            if ((string)($row['role'] ?? '') === 'user') {
                $entry = $this->userEntry($content);
                if ($entry !== null) {
                    $messages[] = $entry;
                }
                continue;
            }
            if ((string)($row['role'] ?? '') !== 'assistant') {
                continue;
            }
            $nextContent = $index + 1 < $count && (string)($rows[$index + 1]['role'] ?? '') === 'user'
                ? (is_array($rows[$index + 1]['content'] ?? null) ? $rows[$index + 1]['content'] : [])
                : [];
            $entry = $this->assistantEntry($content, $nextContent, $registry, $ctx);
            if ($entry !== null) {
                $messages[] = $entry;
            }
        }
        return $messages;
    }

    private function userEntry(array $content): ?array
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                return null;
            }
        }
        $text = $this->textOf($this->visibleUserBlocks($content));
        return $text !== '' ? ['role' => 'user', 'text' => $text] : null;
    }

    private function visibleUserBlocks(array $content): array
    {
        return array_values(array_filter($content, static function ($block): bool {
            if (!is_array($block) || ($block['type'] ?? null) !== 'text') {
                return true;
            }
            $text = (string)($block['text'] ?? '');
            return !str_starts_with($text, PageNote::PREFIX)
                && !str_starts_with($text, NameMatch::PREFIX)
                && !str_starts_with($text, SkuMatch::PREFIX)
                && !str_starts_with($text, DynamicContext::PREFIX);
        }));
    }

    private function assistantEntry(array $content, array $nextContent, Registry $registry, EnrichmentContext $ctx): ?array
    {
        $text = $this->textOf($content);
        $cards = $this->cards($content, $nextContent, $registry, $ctx);
        if ($text === '' && $cards === []) {
            return null;
        }
        return ['role' => 'assistant', 'text' => $text, 'cards' => $cards];
    }

    private function textOf(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && trim((string)($block['text'] ?? '')) !== '') {
                $parts[] = trim((string)$block['text']);
            }
        }
        return implode("\n\n", $parts);
    }

    private function cards(array $content, array $nextContent, Registry $registry, EnrichmentContext $ctx): array
    {
        $results = $this->toolResultsById($nextContent);
        $components = $registry->components();
        $cards = [];
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                continue;
            }
            $name = (string)($block['name'] ?? '');
            if (!isset($components[$name])) {
                continue;
            }
            $id = (string)($block['id'] ?? '');
            $result = $results[$id] ?? null;
            if ($result === null || (bool)($result['is_error'] ?? false)) {
                continue;
            }
            $input = is_array($block['input'] ?? null) ? $block['input'] : [];
            $payload = $this->payload($components[$name], $input, $ctx);
            if ($payload === null) {
                continue;
            }
            $cards[] = ['component' => $components[$name]->component, 'payload' => $payload, 'id' => $id];
        }
        return $cards;
    }

    private function toolResultsById(array $content): array
    {
        $results = [];
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'tool_result') {
                continue;
            }
            $id = (string)($block['tool_use_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $results[$id] = $block;
        }
        return $results;
    }

    private function payload(Component $component, array $input, EnrichmentContext $ctx): ?array
    {
        if ($component->name === 'present_products') {
            return $this->productsPayload($input, $ctx->state);
        }
        if ($component->name === 'present_comparison') {
            return $this->comparisonPayload($input, $ctx->state);
        }
        try {
            return ($component->enricher)($input, $ctx);
        } catch (PresentationRefused) {
            return null;
        }
    }

    private function productsPayload(array $input, SessionState $state): ?array
    {
        $items = [];
        foreach ((is_array($input['picks'] ?? null) ? $input['picks'] : []) as $pick) {
            $productId = (string)($pick['product_id'] ?? '');
            $product = $state->seen($productId);
            if ($product === null) {
                continue;
            }
            $item = ['product' => $product];
            if (isset($pick['reason'])) {
                $item['reason'] = $pick['reason'];
            }
            $items[] = $item;
        }
        if ($items === []) {
            return null;
        }
        $payload = ['layout' => (string)($input['layout'] ?? 'carousel'), 'items' => $items];
        if (isset($input['title'])) {
            $payload = ['title' => $input['title']] + $payload;
        }
        return $payload;
    }

    private function comparisonPayload(array $input, SessionState $state): ?array
    {
        $entries = [];
        foreach ((is_array($input['entries'] ?? null) ? $input['entries'] : []) as $entry) {
            $productId = (string)($entry['product_id'] ?? '');
            $product = $state->seen($productId);
            if ($product === null) {
                continue;
            }
            $built = ['product_id' => $productId];
            if (!empty($entry['pros'])) {
                $built['pros'] = $entry['pros'];
            }
            if (!empty($entry['cons'])) {
                $built['cons'] = $entry['cons'];
            }
            if (isset($entry['best_for'])) {
                $built['best_for'] = $entry['best_for'];
            }
            $built['product'] = $product;
            $entries[] = $built;
        }
        if (count($entries) < 2) {
            return null;
        }
        $payload = [
            'entries' => $entries,
            'dimensions' => is_array($input['dimensions'] ?? null) ? $input['dimensions'] : [],
        ];
        if (isset($input['title'])) {
            $payload = ['title' => $input['title']] + $payload;
        }
        if (isset($input['recommended_product_id'])) {
            $payload['recommended_product_id'] = $input['recommended_product_id'];
        }
        $delta = $this->priceDelta($entries);
        if ($delta !== null) {
            $payload['price_delta'] = $delta;
        }
        return $payload;
    }

    private function priceDelta(array $entries): ?array
    {
        $currencies = [];
        foreach ($entries as $entry) {
            $currencies[(string)($entry['product']['currency'] ?? 'USD')] = true;
        }
        if (count($currencies) > 1) {
            return null;
        }
        $priced = [];
        foreach ($entries as $entry) {
            if (isset($entry['product']['price'])) {
                $priced[] = [(float)$entry['product']['price'], (string)$entry['product_id']];
            }
        }
        if (count($priced) < 2) {
            return null;
        }
        usort($priced, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        [$lowPrice, $lowId] = $priced[0];
        [$highPrice, $highId] = $priced[count($priced) - 1];
        $amount = round($highPrice - $lowPrice, 2);
        if ($amount <= 0) {
            return null;
        }
        return [
            'amount' => $amount,
            'low_product_id' => $lowId,
            'low_price' => $lowPrice,
            'high_product_id' => $highId,
            'high_price' => $highPrice,
        ];
    }
}
