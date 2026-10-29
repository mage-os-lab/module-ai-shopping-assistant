<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

/**
 * The AI service itself is configured in Mage-OS AI Base; this class stores which row the assistant uses.
 */
class StoreConfig
{
    private const PATH_GENERAL_ENABLED = 'ai_integration/aiagent/general/enabled';
    private const PATH_GENERAL_SURFACE_MODE = 'ai_integration/aiagent/general/surface_mode';
    private const PATH_GENERAL_LAUNCHER_ENABLED = 'ai_integration/aiagent/general/launcher_enabled';
    private const PATH_GENERAL_KEEP_OPEN = 'ai_integration/aiagent/general/keep_open';
    private const PATH_GENERAL_PRODUCT_BLOCK_ENABLED = 'ai_integration/aiagent/general/product_block_enabled';
    private const PATH_GENERAL_HEADER_ICON_VIEW = 'ai_integration/aiagent/general/header_icon_view';
    private const PATH_MODEL_AI_SERVICE = 'ai_integration/aiagent/model/ai_service';
    private const PATH_MODEL_MODEL_ID = 'ai_integration/aiagent/model/model_id';
    private const PATH_MODEL_MAX_TOKENS = 'ai_integration/aiagent/model/max_tokens';
    private const PATH_MODEL_THINKING_EFFORT = 'ai_integration/aiagent/model/thinking_effort';
    private const PATH_MODEL_PROGRESS_UPDATES = 'ai_integration/aiagent/model/progress_updates';
    private const PATH_STORE_INFORMATION_NAME = 'general/store_information/name';
    private const PATH_VOICE_BRAND_NAME = 'ai_integration/aiagent/voice/brand_name';
    private const PATH_VOICE_ASSISTANT_NAME = 'ai_integration/aiagent/voice/assistant_name';
    private const PATH_VOICE_BRAND_VOICE = 'ai_integration/aiagent/voice/brand_voice';
    private const PATH_VOICE_GREETING = 'ai_integration/aiagent/voice/greeting';
    private const PATH_VOICE_STARTERS = 'ai_integration/aiagent/voice/starters';
    private const PATH_VOICE_DOMAIN_SEARCH_NOTES = 'ai_integration/aiagent/voice/domain_search_notes';
    private const PATH_CONTENT_POLICY_PAGES = 'ai_integration/aiagent/content/policy_pages';
    private const PATH_CONTENT_ALLOWED_CATEGORIES = 'ai_integration/aiagent/content/allowed_categories';
    private const PATH_CONTENT_CATALOG_MAP_DEPTH = 'ai_integration/aiagent/content/catalog_map_depth';
    private const PATH_CONTENT_CATALOG_MAP_ROOTS = 'ai_integration/aiagent/content/catalog_map_roots';
    private const PATH_CONTENT_CATALOG_MAP_MAX_CHARS = 'ai_integration/aiagent/content/catalog_map_max_chars';
    private const PATH_CONTENT_STORE_FACTS = 'ai_integration/aiagent/content/store_facts';
    private const PATH_CONTENT_INCLUDE_CORE_FACTS = 'ai_integration/aiagent/content/include_core_facts';
    private const PATH_CARDS_PRODUCTS_IMAGE = 'ai_integration/aiagent/cards/products_image';
    private const PATH_CARDS_PRODUCTS_PRICE = 'ai_integration/aiagent/cards/products_price';
    private const PATH_CARDS_PRODUCTS_DESCRIPTION = 'ai_integration/aiagent/cards/products_description';
    private const PATH_CARDS_PRODUCTS_STOCK = 'ai_integration/aiagent/cards/products_stock';
    private const PATH_CARDS_PRODUCTS_ADD_TO_CART = 'ai_integration/aiagent/cards/products_add_to_cart';
    private const PATH_CARDS_PRODUCTS_REASON = 'ai_integration/aiagent/cards/products_reason';
    private const PATH_LIMITS_CONCURRENT_TURNS = 'ai_integration/aiagent/limits/concurrent_turns';
    private const PATH_LIMITS_TURNS_PER_SESSION = 'ai_integration/aiagent/limits/turns_per_session';
    private const PATH_LIMITS_TURNS_PER_SESSION_WINDOW = 'ai_integration/aiagent/limits/turns_per_session_window';
    private const PATH_LIMITS_TURNS_PER_IP_MINUTE = 'ai_integration/aiagent/limits/turns_per_ip_minute';
    private const PATH_LIMITS_MAX_TOOL_ITERATIONS = 'ai_integration/aiagent/limits/max_tool_iterations';
    private const PATH_LIMITS_MAX_QUANTITY_PER_ITEM = 'ai_integration/aiagent/limits/max_quantity_per_item';
    private const PATH_LIMITS_MAX_CART_LINES = 'ai_integration/aiagent/limits/max_cart_lines';
    private const PATH_LIMITS_MAX_MESSAGE_LENGTH = 'ai_integration/aiagent/limits/max_message_length';
    private const PATH_LIMITS_TURN_WALL_CLOCK = 'ai_integration/aiagent/limits/turn_wall_clock';
    private const PATH_LIMITS_MAX_SEARCH_RESULTS = 'ai_integration/aiagent/limits/max_search_results';
    private const PATH_LIMITS_MAX_FENCED_CHARS = 'ai_integration/aiagent/limits/max_fenced_chars';
    private const PATH_LIMITS_COMPACT_ABOVE_TOKENS = 'ai_integration/aiagent/limits/compact_above_tokens';
    private const PATH_GENERAL_STREAMING = 'ai_integration/aiagent/general/streaming';
    private const PATH_RUNTIME_FIRST_BYTE_THRESHOLD = 'ai_integration/aiagent/runtime/first_byte_threshold';
    private const PATH_RUNTIME_HEARTBEAT_SECONDS = 'ai_integration/aiagent/runtime/heartbeat_seconds';
    private const PATH_LEXICON_POLICY_INTENT_TERMS = 'ai_integration/aiagent/lexicon/policy_intent_terms';
    private const PATH_LEXICON_ORDER_INTENT_TERMS = 'ai_integration/aiagent/lexicon/order_intent_terms';
    private const PATH_PRIVACY_RETENTION_DAYS = 'ai_integration/aiagent/privacy/retention_days';
    private const PATH_PRIVACY_SHOW_AI_LABEL = 'ai_integration/aiagent/privacy/show_ai_label';
    private const PATH_PRIVACY_CONTACT_URL = 'ai_integration/aiagent/privacy/contact_url';
    private const PATH_PRIVACY_CONTACT_LABEL = 'ai_integration/aiagent/privacy/contact_label';
    private const PATH_PRIVACY_DEBUG_LOG = 'ai_integration/aiagent/privacy/debug_log';

    private array $agentCache = [];

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_GENERAL_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function agent(int $storeId): AgentConfig
    {
        if (array_key_exists($storeId, $this->agentCache)) {
            return $this->agentCache[$storeId];
        }
        $defaults = new AgentConfig();
        $policyPages = $this->readMultiselect(self::PATH_CONTENT_POLICY_PAGES, $storeId);
        $config = new AgentConfig(
            modelId: $this->readString(self::PATH_MODEL_MODEL_ID, $storeId, $defaults->modelId),
            maxTokens: $this->readInt(self::PATH_MODEL_MAX_TOKENS, $storeId, $defaults->maxTokens),
            thinkingEffort: $this->readString(self::PATH_MODEL_THINKING_EFFORT, $storeId, $defaults->thinkingEffort),
            progressUpdates: $this->readBool(self::PATH_MODEL_PROGRESS_UPDATES, $storeId, $defaults->progressUpdates),
            brandName: $this->readBrandName($storeId, $defaults->brandName),
            assistantName: $this->readString(self::PATH_VOICE_ASSISTANT_NAME, $storeId, $defaults->assistantName),
            brandVoice: $this->readString(self::PATH_VOICE_BRAND_VOICE, $storeId, $defaults->brandVoice),
            greeting: $this->readString(self::PATH_VOICE_GREETING, $storeId, $defaults->greeting),
            starters: $this->readLines(self::PATH_VOICE_STARTERS, $storeId, $defaults->starters),
            domainSearchNotes: $this->readString(
                self::PATH_VOICE_DOMAIN_SEARCH_NOTES,
                $storeId,
                $defaults->domainSearchNotes
            ),
            policyPages: $policyPages,
            allowedCategories: array_map(
                'intval',
                $this->readMultiselect(self::PATH_CONTENT_ALLOWED_CATEGORIES, $storeId)
            ),
            catalogMapDepth: $this->readInt(
                self::PATH_CONTENT_CATALOG_MAP_DEPTH,
                $storeId,
                $defaults->catalogMapDepth
            ),
            catalogMapRoots: array_map(
                'intval',
                $this->readMultiselect(self::PATH_CONTENT_CATALOG_MAP_ROOTS, $storeId)
            ),
            catalogMapMaxChars: $this->readInt(
                self::PATH_CONTENT_CATALOG_MAP_MAX_CHARS,
                $storeId,
                $defaults->catalogMapMaxChars
            ),
            storeFacts: $this->readStoreFacts($storeId),
            includeCoreFacts: $this->readBool(
                self::PATH_CONTENT_INCLUDE_CORE_FACTS,
                $storeId,
                $defaults->includeCoreFacts
            ),
            productCard: [
                'image' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_IMAGE,
                    $storeId,
                    $defaults->productCard['image']
                ),
                'price' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_PRICE,
                    $storeId,
                    $defaults->productCard['price']
                ),
                'description' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_DESCRIPTION,
                    $storeId,
                    $defaults->productCard['description']
                ),
                'stock' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_STOCK,
                    $storeId,
                    $defaults->productCard['stock']
                ),
                'addToCart' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_ADD_TO_CART,
                    $storeId,
                    $defaults->productCard['addToCart']
                ),
                'reason' => $this->readBool(
                    self::PATH_CARDS_PRODUCTS_REASON,
                    $storeId,
                    $defaults->productCard['reason']
                ),
            ],
            concurrentTurns: $this->readInt(self::PATH_LIMITS_CONCURRENT_TURNS, $storeId, $defaults->concurrentTurns),
            turnsPerSession: $this->readInt(
                self::PATH_LIMITS_TURNS_PER_SESSION,
                $storeId,
                $defaults->turnsPerSession
            ),
            turnsPerSessionWindow: $this->readInt(
                self::PATH_LIMITS_TURNS_PER_SESSION_WINDOW,
                $storeId,
                $defaults->turnsPerSessionWindow
            ),
            turnsPerIpMinute: $this->readInt(
                self::PATH_LIMITS_TURNS_PER_IP_MINUTE,
                $storeId,
                $defaults->turnsPerIpMinute
            ),
            maxToolIterations: $this->readInt(
                self::PATH_LIMITS_MAX_TOOL_ITERATIONS,
                $storeId,
                $defaults->maxToolIterations
            ),
            maxQuantityPerItem: $this->readInt(
                self::PATH_LIMITS_MAX_QUANTITY_PER_ITEM,
                $storeId,
                $defaults->maxQuantityPerItem
            ),
            maxCartLines: $this->readInt(self::PATH_LIMITS_MAX_CART_LINES, $storeId, $defaults->maxCartLines),
            maxMessageLength: $this->readInt(
                self::PATH_LIMITS_MAX_MESSAGE_LENGTH,
                $storeId,
                $defaults->maxMessageLength
            ),
            turnWallClock: $this->readInt(self::PATH_LIMITS_TURN_WALL_CLOCK, $storeId, $defaults->turnWallClock),
            maxSearchResults: $this->readInt(
                self::PATH_LIMITS_MAX_SEARCH_RESULTS,
                $storeId,
                $defaults->maxSearchResults
            ),
            maxFencedChars: $this->readInt(self::PATH_LIMITS_MAX_FENCED_CHARS, $storeId, $defaults->maxFencedChars),
            compactAboveTokens: $this->readInt(
                self::PATH_LIMITS_COMPACT_ABOVE_TOKENS,
                $storeId,
                $defaults->compactAboveTokens
            ),
            streaming: $this->readString(self::PATH_GENERAL_STREAMING, $storeId, $defaults->streaming),
            firstByteThreshold: $this->readInt(
                self::PATH_RUNTIME_FIRST_BYTE_THRESHOLD,
                $storeId,
                $defaults->firstByteThreshold
            ),
            heartbeatSeconds: $this->readInt(
                self::PATH_RUNTIME_HEARTBEAT_SECONDS,
                $storeId,
                $defaults->heartbeatSeconds
            ),
            policyIntentTerms: $this->readLines(
                self::PATH_LEXICON_POLICY_INTENT_TERMS,
                $storeId,
                $defaults->policyIntentTerms
            ),
            orderIntentTerms: $this->readLines(
                self::PATH_LEXICON_ORDER_INTENT_TERMS,
                $storeId,
                $defaults->orderIntentTerms
            ),
            retentionDays: $this->readInt(self::PATH_PRIVACY_RETENTION_DAYS, $storeId, $defaults->retentionDays),
            showAiLabel: $this->readBool(self::PATH_PRIVACY_SHOW_AI_LABEL, $storeId, $defaults->showAiLabel),
            contactUrl: $this->readString(self::PATH_PRIVACY_CONTACT_URL, $storeId, $defaults->contactUrl),
            contactLabel: $this->readString(self::PATH_PRIVACY_CONTACT_LABEL, $storeId, $defaults->contactLabel),
            debugLog: $this->readBool(self::PATH_PRIVACY_DEBUG_LOG, $storeId, $defaults->debugLog),
            enabled: $this->readBool(self::PATH_GENERAL_ENABLED, $storeId, $defaults->enabled),
            surfaceMode: $this->readString(self::PATH_GENERAL_SURFACE_MODE, $storeId, $defaults->surfaceMode),
            launcherEnabled: $this->readBool(
                self::PATH_GENERAL_LAUNCHER_ENABLED,
                $storeId,
                $defaults->launcherEnabled
            ),
            keepOpen: $this->readBool(self::PATH_GENERAL_KEEP_OPEN, $storeId, $defaults->keepOpen),
            productBlockEnabled: $this->readBool(
                self::PATH_GENERAL_PRODUCT_BLOCK_ENABLED,
                $storeId,
                $defaults->productBlockEnabled
            ),
            headerIconView: $this->readString(
                self::PATH_GENERAL_HEADER_ICON_VIEW,
                $storeId,
                $defaults->headerIconView
            )
        );
        $this->agentCache[$storeId] = $config;
        return $config;
    }

    public function aiServiceId(int $storeId): string
    {
        return trim($this->readString(self::PATH_MODEL_AI_SERVICE, $storeId, ''));
    }

    private function readString(string $path, int $storeId, string $default): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || $value === '') {
            return $default;
        }
        return (string)$value;
    }

    private function readInt(string $path, int $storeId, int $default): int
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int)$value;
    }

    private function readBool(string $path, int $storeId, bool $default): bool
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || $value === '') {
            return $default;
        }
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function readLines(string $path, int $storeId, array $default): array
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || trim((string)$value) === '') {
            return $default;
        }
        $lines = explode("\n", str_replace("\r\n", "\n", (string)$value));
        $trimmed = array_map('trim', $lines);
        $filtered = array_filter($trimmed, static fn (string $line): bool => $line !== '');
        return array_values(array_unique($filtered));
    }

    private function readMultiselect(string $path, int $storeId): array
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || trim((string)$value) === '') {
            return [];
        }
        $items = explode(',', (string)$value);
        $trimmed = array_map('trim', $items);
        $filtered = array_filter($trimmed, static fn (string $item): bool => $item !== '');
        return array_values(array_unique($filtered));
    }

    private function readStoreFacts(int $storeId): array
    {
        $value = $this->scopeConfig->getValue(self::PATH_CONTENT_STORE_FACTS, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || trim((string)$value) === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $facts = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $topic = trim((string)($row['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $facts[] = [
                'topic' => $topic,
                'keywords' => $this->splitFactKeywords((string)($row['keywords'] ?? '')),
                'source' => $this->normalizeFactSource((string)($row['source'] ?? '')),
                'value' => trim((string)($row['value'] ?? '')),
            ];
        }
        return $facts;
    }

    private function splitFactKeywords(string $raw): array
    {
        $items = explode(',', $raw);
        $trimmed = array_map(static fn (string $item): string => mb_strtolower(trim($item)), $items);
        $filtered = array_filter($trimmed, static fn (string $item): bool => $item !== '');
        return array_values(array_unique($filtered));
    }

    private function normalizeFactSource(string $source): string
    {
        $source = trim($source);
        return in_array($source, ['text', 'cms_page', 'cms_block', 'not_offered'], true) ? $source : 'text';
    }

    private function readBrandName(int $storeId, string $default): string
    {
        $value = $this->readString(self::PATH_VOICE_BRAND_NAME, $storeId, '');
        if ($value !== '') {
            return $value;
        }
        $storeInformationName = $this->readString(self::PATH_STORE_INFORMATION_NAME, $storeId, '');
        if ($storeInformationName !== '') {
            return $storeInformationName;
        }
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException $exception) {
            return $default;
        }
        try {
            $website = $this->storeManager->getWebsite($store->getWebsiteId());
            $websiteName = $website !== null ? $website->getName() : null;
        } catch (NoSuchEntityException $exception) {
            $websiteName = null;
        }
        if ($websiteName !== null && $websiteName !== '') {
            return $websiteName;
        }
        $storeName = $store->getName();
        return $storeName !== null && $storeName !== '' ? $storeName : $default;
    }
}
