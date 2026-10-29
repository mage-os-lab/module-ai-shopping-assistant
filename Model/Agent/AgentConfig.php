<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

class AgentConfig
{
    public readonly bool $enablePolicies;

    public function __construct(
        public readonly string $modelId = '',
        public readonly int $maxTokens = 2048,
        public readonly string $thinkingEffort = 'low',
        public readonly bool $progressUpdates = false,
        public readonly string $brandName = '',
        public readonly string $assistantName = 'the shopping assistant',
        public readonly string $brandVoice = 'warm, concise, and plain about trade-offs',
        public readonly string $greeting = 'Hi. Tell me what you are looking for and I will find it.',
        public readonly array $starters = [
            'Find something for a gift',
            'Compare two products',
            'What is your return policy?',
            'Where is my order?',
            'Show me what is new',
        ],
        public readonly string $domainSearchNotes = '',
        public readonly array $policyPages = [],
        public readonly array $allowedCategories = [],
        public readonly int $catalogMapDepth = 2,
        public readonly array $catalogMapRoots = [],
        public readonly int $catalogMapMaxChars = 6000,
        public readonly array $storeFacts = [],
        public readonly bool $includeCoreFacts = true,
        public readonly int $concurrentTurns = 4,
        public readonly int $turnsPerSession = 40,
        public readonly int $turnsPerSessionWindow = 12,
        public readonly int $turnsPerIpMinute = 10,
        public readonly int $maxToolIterations = 8,
        public readonly int $maxQuantityPerItem = 24,
        public readonly int $maxCartLines = 100,
        public readonly int $maxMessageLength = 2000,
        public readonly int $turnWallClock = 90,
        public readonly int $maxSearchResults = 8,
        public readonly int $maxFencedChars = 12000,
        public readonly int $compactAboveTokens = 100000,
        public readonly string $streaming = 'auto',
        public readonly int $firstByteThreshold = 4,
        public readonly int $heartbeatSeconds = 10,
        public readonly array $policyIntentTerms = [
            'return',
            'returns',
            'refund',
            'refunds',
            'exchange',
            'exchanges',
            'warranty',
            'guarantee',
            'cancel',
            'cancellation',
            'restocking',
            'fee',
            'fees',
            'shipping cost',
            'shipping costs',
            'delivery cost',
            'price match',
            'price lock',
            'membership',
            'subscription',
            'contract',
            'policy',
            'policies',
            'terms',
        ],
        public readonly array $orderIntentTerms = [
            'order',
            'orders',
            'delivery',
            'delivery address',
            'package',
            'parcel',
            'shipment',
            'tracking',
            'tracking number',
        ],
        public readonly int $retentionDays = 30,
        public readonly bool $showAiLabel = true,
        public readonly string $contactUrl = '',
        public readonly string $contactLabel = 'Contact us',
        public readonly bool $debugLog = false,
        public readonly bool $enabled = false,
        public readonly string $surfaceMode = 'overlay',
        public readonly bool $launcherEnabled = true,
        public readonly bool $keepOpen = true,
        public readonly bool $productBlockEnabled = true,
        public readonly string $headerIconView = 'cart',
        public readonly bool $enableCart = true,
        public readonly bool $enableOrders = true,
        ?bool $enablePolicies = null,
        public readonly bool $enableFulfillment = true,
        public readonly bool $closeOnPresentation = true,
        public readonly bool $enableMemory = false,
        public readonly array $productCard = [
            'image' => true,
            'price' => true,
            'description' => true,
            'stock' => true,
            'addToCart' => true,
            'reason' => true,
        ]
    ) {
        $this->enablePolicies = $enablePolicies ?? ($this->policyPages !== [] || $this->hasCmsSourcedFact());
    }

    public function promptBearingFields(): array
    {
        return [
            'brandName' => $this->brandName,
            'assistantName' => $this->assistantName,
            'brandVoice' => $this->brandVoice,
            'domainSearchNotes' => $this->domainSearchNotes,
            'enableCart' => $this->enableCart,
            'enableOrders' => $this->enableOrders,
            'enablePolicies' => $this->enablePolicies,
            'enableFulfillment' => $this->enableFulfillment,
            'enableMemory' => $this->enableMemory,
            'modelId' => $this->modelId,
            'catalogMapDepth' => $this->catalogMapDepth,
            'catalogMapRoots' => $this->catalogMapRoots,
            'catalogMapMaxChars' => $this->catalogMapMaxChars,
            'storeFacts' => $this->storeFacts,
            'includeCoreFacts' => $this->includeCoreFacts,
            'contactUrl' => $this->contactUrl,
            'contactLabel' => $this->contactLabel,
        ];
    }

    private function hasCmsSourcedFact(): bool
    {
        foreach ($this->storeFacts as $fact) {
            $source = is_array($fact) ? ($fact['source'] ?? null) : null;
            if ($source === 'cms_page' || $source === 'cms_block') {
                return true;
            }
        }
        return false;
    }
}
