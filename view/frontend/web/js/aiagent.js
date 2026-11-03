const AI_AGENT_TURN_TIMEOUT_MS = 180000;

window.aiAgentReader = {
    async run(store, text) {
        const body = {
            session: store.sessionId,
            message: text,
            page: store.pagePayload(),
            stream: store.mode === 'stream' ? 1 : 0
        };
        const controller = new AbortController();
        store.turn.controller = controller;
        let firstByte = false;
        let firstByteMs = 0;
        let completed = false;
        let intentionalAbort = false;
        let timedOut = false;
        const startedAt = Date.now();
        const applyEvent = (type, data) => {
            if (type === 'turn_complete') {
                completed = true;
            }
            store.apply(type, data);
        };
        const watchdog = setTimeout(() => {
            if (!firstByte) {
                store.turn.status = store.config.i18n.stillWorking;
            }
        }, store.config.firstByteThreshold * 1000);
        const abortTimer = setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, AI_AGENT_TURN_TIMEOUT_MS);
        try {
            const response = await fetch(store.config.urls.turn, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Form-Key': hyva.getFormKey(),
                    'Accept': store.mode === 'stream' ? 'text/event-stream' : 'application/json'
                },
                body: JSON.stringify(body),
                signal: controller.signal
            });
            if (response.status === 403) {
                applyEvent('error', {message: store.config.i18n.reloadPage});
                return;
            }
            const contentType = response.headers.get('content-type') || '';
            if (store.mode === 'json' || contentType.includes('application/json')) {
                const payload = await response.json();
                const events = payload.events || [];
                events.forEach((event) => applyEvent(event.type, event.data));
                return;
            }
            if (!contentType.includes('text/event-stream')) {
                applyEvent('error', {message: store.config.i18n.interrupted});
                return;
            }
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let chunks = 0;
            for (;;) {
                const {value, done} = await reader.read();
                if (done) {
                    break;
                }
                if (!firstByte) {
                    firstByte = true;
                    clearTimeout(watchdog);
                    firstByteMs = Date.now() - startedAt;
                }
                chunks++;
                buffer += decoder.decode(value, {stream: true});
                let frameEnd = buffer.indexOf('\n\n');
                while (frameEnd !== -1) {
                    const frame = buffer.slice(0, frameEnd);
                    buffer = buffer.slice(frameEnd + 2);
                    if (!frame.startsWith(':')) {
                        const typeMatch = frame.match(/^event: (.+)$/m);
                        const dataMatch = frame.match(/^data: (.+)$/m);
                        if (typeMatch && dataMatch) {
                            applyEvent(typeMatch[1], JSON.parse(dataMatch[1]));
                        }
                    }
                    frameEnd = buffer.indexOf('\n\n');
                }
            }
            const buffered = chunks <= 2 && (Date.now() - startedAt) > 2000;
            const slowFirstByte = firstByteMs > store.config.firstByteThreshold * 1000;
            if (buffered || slowFirstByte) {
                store.setMode('json');
            }
        } catch (e) {
            if (controller.signal.aborted && !timedOut) {
                intentionalAbort = true;
                store.turn.running = false;
                store.turn.status = '';
                return;
            }
            applyEvent('error', {message: timedOut ? store.config.i18n.timedOut : store.config.i18n.interrupted});
        } finally {
            clearTimeout(watchdog);
            clearTimeout(abortTimer);
            if (!completed && !intentionalAbort) {
                store.apply('turn_complete', {usage: null});
            }
            if (store.turn.controller === controller) {
                store.turn.controller = null;
            }
        }
    }
};

function stripAiAgentTags(html) {
    return new DOMParser().parseFromString(html || '', 'text/html').body.textContent || '';
}

function releaseAiAgentFocusOnOpen(dialog) {
    if (!dialog || dialog.open) {
        return null;
    }
    const release = (event) => {
        event.target.blur();
    };
    dialog.addEventListener('focusin', release, {once: true});
    return release;
}

function initAiAgentCartLine() {
    return {
        label() {
            const cart = Alpine.store('aiAgent').cart;
            const config = Alpine.store('aiAgent').config;
            return cart.count
                ? hyva.str(config.i18n.cartItems, cart.count, stripAiAgentTags(cart.subtotal))
                : config.i18n.cartEmpty;
        },
        listeners: {}
    };
}

function initAiAgentCartStrip() {
    return {
        thumbs() {
            return (this.cartItems || []).slice(0, 4);
        },
        countLabel() {
            const count = this.itemsCount || 0;
            const config = Alpine.store('aiAgent').config;
            return count === 0
                ? config.i18n.cartEmpty
                : hyva.str(config.i18n.cartCount, count);
        },
        subtotalHtml() {
            return this.cart && this.cart.subtotal ? this.cart.subtotal : '';
        },
        expand() {
            this.showCart();
        }
    };
}

function initAiAgentDrawer() {
    return {
        view: 'chat',
        init() {
            const store = Alpine.store('aiAgent');
            const iconView = store.config.headerIconView;
            if (iconView === 'chat') {
                this.view = 'chat';
            } else if (iconView === 'last') {
                this.view = store.surface.view || 'cart';
            } else {
                this.view = 'cart';
            }
            this.$watch(() => Alpine.store('aiAgent').turn.id, () => {
                if (this.view === 'cart') {
                    this.showChat();
                }
            });
        },
        isChatView() {
            return this.view === 'chat';
        },
        isCartView() {
            return this.view === 'cart';
        },
        showStart() {
            return Alpine.store('aiAgent').transcript.length === 0;
        },
        isTranscriptView() {
            return !this.showStart();
        },
        hasTranscript() {
            return Alpine.store('aiAgent').transcript.length > 0;
        },
        newConversation() {
            Alpine.store('aiAgent').reset();
        },
        panelClasses() {
            return {
                'ai-agent-panel bg-surface': this.view === 'chat',
                'flex flex-col gap-2 border-b border-container pb-2 mb-2': this.view === 'cart'
            };
        },
        showChat() {
            this.view = 'chat';
            Alpine.store('aiAgent').surface.view = 'chat';
            this.ensureStarted();
            this.focusComposer();
        },
        showCart() {
            this.view = 'cart';
            Alpine.store('aiAgent').surface.view = 'cart';
        },
        close() {
            this.$dispatch('toggle-cart', { isOpen: false });
        },
        ensureStarted() {
            const store = Alpine.store('aiAgent');
            if (!store.started) {
                store.start();
            }
        },
        focusComposer() {
            this.$nextTick(() => {
                const input = this.$el.querySelector('textarea');
                if (input) {
                    input.focus();
                }
            });
        },
        lastLine() {
            const transcript = Alpine.store('aiAgent').transcript;
            const assistantMessages = transcript.filter((message) => message.role === 'assistant' && (!!message.text || !!message.after));
            const lastMessage = assistantMessages[assistantMessages.length - 1];
            return lastMessage ? (lastMessage.after || lastMessage.text).slice(0, 80) : '';
        },
        listeners: {
            ['@ai-agent:open.window'](event) {
                const store = Alpine.store('aiAgent');
                if (event.detail.page) {
                    store.page = Object.assign({}, store.page, event.detail.page);
                }
                this.$dispatch('toggle-cart', { isOpen: true });
                this.view = event.detail.view || 'chat';
                this.ensureStarted();
            },
            ['@toggle-cart.window'](event) {
                if (event.detail && event.detail.isOpen === true && !event.detail.fromAgent) {
                    this.init();
                }
            }
        }
    };
}

function initAiAgentOverlay() {
    return {
        open: false,
        focusRelease: null,
        show(focus) {
            const store = Alpine.store('aiAgent');
            const scrollY = window.scrollY;
            if (!focus) {
                this.focusRelease = releaseAiAgentFocusOnOpen(this.$root);
            }
            this.open = true;
            store.surface.open = true;
            store.rememberOpen();
            if (!store.started) {
                store.start();
            }
            this.$nextTick(() => {
                const textarea = this.$el.querySelector('textarea');
                if (focus && textarea) {
                    textarea.focus({preventScroll: true});
                }
                window.requestAnimationFrame(() => {
                    if (window.scrollY !== scrollY) {
                        window.scrollTo(0, scrollY);
                    }
                });
            });
        },
        close() {
            this.open = false;
            if (this.focusRelease) {
                this.$root.removeEventListener('focusin', this.focusRelease);
                this.focusRelease = null;
            }
            Alpine.store('aiAgent').surface.open = false;
            Alpine.store('aiAgent').forgetOpen();
            const launcher = document.getElementById('ai-agent-launcher');
            if (launcher) {
                window.setTimeout(() => launcher.focus(), 250);
            }
        },
        isClosed() {
            return !this.open;
        },
        hasTranscript() {
            return Alpine.store('aiAgent').transcript.length > 0;
        },
        newConversation() {
            Alpine.store('aiAgent').reset();
        },
        listeners: {
            ['@ai-agent:open.window'](event) {
                const store = Alpine.store('aiAgent');
                if (store.surface.resolved === 'side_cart' && document.getElementById('ai-agent-drawer-panel')) {
                    return;
                }
                if (event.detail.page) {
                    store.page = Object.assign({}, store.page, event.detail.page);
                }
                this.show(event.detail.focus !== false);
            },
            ['@keydown.escape.window']() {
                if (this.open) {
                    this.close();
                }
            }
        }
    };
}

function initAiAgentStart() {
    return {
        visible() {
            const store = Alpine.store('aiAgent');
            return store.transcript.length === 0 && !store.restoring;
        },
        loading() {
            const store = Alpine.store('aiAgent');
            return store.transcript.length === 0 && store.restoring;
        },
        greeting() {
            return Alpine.store('aiAgent').config.greeting;
        },
        starters() {
            return Alpine.store('aiAgent').config.starters;
        },
        isProductPage() {
            return Alpine.store('aiAgent').page.type === 'product';
        },
        productChips() {
            return Alpine.store('aiAgent').config.i18n.productChips;
        },
        pick() {
            Alpine.store('aiAgent').send(this.$el.dataset.text);
        }
    };
}

function aiAgentStoreHref(raw) {
    const origin = window.location ? window.location.origin : '';
    if (!origin) {
        return null;
    }
    let url;
    try {
        url = new URL(raw, origin);
    } catch (e) {
        return null;
    }
    return (url.protocol === 'https:' || url.protocol === 'http:') && url.origin === origin ? url.href : null;
}

function appendAiAgentLinkedText(parent, text) {
    const pattern = /\[([^\]\n]+)\]\(([^)\s]+)\)/g;
    let last = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) {
            parent.appendChild(document.createTextNode(text.slice(last, match.index)));
        }
        const href = aiAgentStoreHref(match[2]);
        if (href === null) {
            parent.appendChild(document.createTextNode(match[1]));
        } else {
            const link = document.createElement('a');
            link.href = href;
            link.className = 'underline';
            link.appendChild(document.createTextNode(match[1]));
            parent.appendChild(link);
        }
        last = pattern.lastIndex;
    }
    if (last < text.length) {
        parent.appendChild(document.createTextNode(text.slice(last)));
    }
}

function appendAiAgentInlineText(parent, text) {
    const parts = String(text).split('**');
    parts.forEach((part, index) => {
        if (part === '') {
            return;
        }
        if (index % 2 === 1) {
            const strong = document.createElement('strong');
            appendAiAgentLinkedText(strong, part);
            parent.appendChild(strong);
        } else {
            appendAiAgentLinkedText(parent, part);
        }
    });
}

function appendAiAgentLines(parent, lines) {
    lines.forEach((line, index) => {
        if (index > 0) {
            parent.appendChild(document.createElement('br'));
        }
        appendAiAgentInlineText(parent, line);
    });
}

function renderAiAgentMarkdown(container, text) {
    const bulletPattern = /^[-*]\s+/;
    const orderedPattern = /^\d+\.\s+/;
    const paragraphs = String(text || '').split(/\n{2,}/);
    paragraphs.forEach((paragraph) => {
        const lines = paragraph.split('\n');
        let index = 0;
        while (index < lines.length) {
            if (bulletPattern.test(lines[index])) {
                const list = document.createElement('ul');
                list.className = 'list-disc list-inside';
                while (index < lines.length && bulletPattern.test(lines[index])) {
                    const item = document.createElement('li');
                    appendAiAgentInlineText(item, lines[index].replace(bulletPattern, ''));
                    list.appendChild(item);
                    index++;
                }
                container.appendChild(list);
                continue;
            }
            if (orderedPattern.test(lines[index])) {
                const list = document.createElement('ol');
                list.className = 'list-decimal list-inside';
                while (index < lines.length && orderedPattern.test(lines[index])) {
                    const item = document.createElement('li');
                    appendAiAgentInlineText(item, lines[index].replace(orderedPattern, ''));
                    list.appendChild(item);
                    index++;
                }
                container.appendChild(list);
                continue;
            }
            const plainLines = [];
            while (
                index < lines.length
                && !bulletPattern.test(lines[index])
                && !orderedPattern.test(lines[index])
            ) {
                plainLines.push(lines[index]);
                index++;
            }
            const paragraphEl = document.createElement('p');
            appendAiAgentLines(paragraphEl, plainLines);
            container.appendChild(paragraphEl);
        }
    });
}

function initAiAgentBubble() {
    return {
        field: 'text',
        rafId: null,
        pendingText: null,
        init() {
            this.renderText(this.m[this.field] || '');
            this.$watch(() => this.m[this.field], (value) => {
                this.scheduleRender(value || '');
            });
            this.$watch('m.streaming', (streaming) => {
                if (!streaming) {
                    this.flushRender();
                }
            });
        },
        scheduleRender(text) {
            this.pendingText = text;
            if (this.m.streaming !== true) {
                this.flushRender();
                return;
            }
            if (this.rafId !== null) {
                return;
            }
            this.rafId = window.requestAnimationFrame(() => {
                this.rafId = null;
                this.renderText(this.pendingText);
            });
        },
        flushRender() {
            if (this.rafId !== null) {
                window.cancelAnimationFrame(this.rafId);
                this.rafId = null;
            }
            this.renderText(this.pendingText !== null ? this.pendingText : (this.m[this.field] || ''));
        },
        renderText(text) {
            while (this.$el.firstChild) {
                this.$el.removeChild(this.$el.firstChild);
            }
            renderAiAgentMarkdown(this.$el, text);
        }
    };
}

function initAiAgentAfterBubble() {
    return Object.assign(initAiAgentBubble(), {field: 'after'});
}

function initAiAgentAfterTyping() {
    return {
        isTyping() {
            return this.m.streaming === true && this.m.afterCards === true && !this.m.after;
        }
    };
}

function initAiAgentTranscript() {
    return {
        messages() {
            return Alpine.store('aiAgent').transcript;
        },
        status() {
            return Alpine.store('aiAgent').turn.status;
        },
        bubbleClasses() {
            return {
                'ai-agent-bubble-user bg-primary text-on-primary p-3': this.m.role === 'user',
                'ai-agent-bubble-assistant bg-container p-3': this.m.role === 'assistant'
            };
        },
        isStreaming() {
            return this.m.streaming === true;
        },
        isVisible() {
            return this.m.role !== 'assistant'
                || this.m.streaming === true
                || !!this.m.text
                || !!this.m.after
                || (Array.isArray(this.m.cards) && this.m.cards.length > 0)
                || !!this.m.notice;
        },
        isSessionCap() {
            return this.m.sessionCap === true;
        },
        newConversation() {
            Alpine.store('aiAgent').reset();
        },
        lastUserText() {
            const messages = this.messages();
            for (let index = this.i; index >= 0; index--) {
                if (messages[index].role === 'user') {
                    return messages[index].text;
                }
            }
            return '';
        },
        retryDisabled() {
            return Alpine.store('aiAgent').isLimited();
        },
        retry() {
            const text = this.lastUserText();
            if (!text || Alpine.store('aiAgent').isLimited()) {
                return;
            }
            this.m.notice = '';
            this.m.retryAfter = null;
            Alpine.store('aiAgent').send(text);
        },
        lastMessageActivity() {
            const messages = this.messages();
            const last = messages[messages.length - 1];
            if (!last) {
                return 0;
            }
            const textLength = (last.text ? last.text.length : 0) + (last.after ? last.after.length : 0);
            const cardCount = Array.isArray(last.cards) ? last.cards.length : 0;
            return textLength + cardCount;
        },
        scrollToLatest() {
            this.$refs.scroller.scrollTop = this.$refs.scroller.scrollHeight;
        },
        scrollToEnd() {
            this.$nextTick(() => {
                window.requestAnimationFrame(() => {
                    this.scrollToLatest();
                });
            });
        },
        init() {
            this.scrollToEnd();
            this.$watch(
                () => Alpine.store('aiAgent').transcript.length,
                () => {
                    this.scrollToEnd();
                }
            );
            this.$watch(
                () => this.lastMessageActivity(),
                () => {
                    this.scrollToEnd();
                }
            );
            this.$watch(
                () => Alpine.store('aiAgent').turn.running,
                (running) => {
                    if (!running) {
                        this.scrollToEnd();
                    }
                }
            );
        },
        listeners: {
            ['@ai-agent:transcript-restored.window']() {
                this.scrollToEnd();
            },
            ['@ai-agent:open.window']() {
                this.scrollToEnd();
            }
        }
    };
}

function initAiAgentTypingIndicator() {
    return {
        isTyping() {
            return this.m.streaming === true && this.m.text === '';
        }
    };
}

function initAiAgentCard() {
    return {
        init() {
            this.$nextTick(() => {
                const template = document.getElementById('ai-agent-card-' + this.card.component);
                if (!template) {
                    return;
                }
                this.$el.appendChild(template.content.cloneNode(true));
            });
            this.hold(this.waitsForText());
            this.$watch(() => this.waitsForText(), (waiting) => this.hold(waiting));
        },
        waitsForText() {
            return this.m.streaming === true && this.m.text === '';
        },
        hold(waiting) {
            this.$el.inert = waiting;
            this.$el.style.transition = 'opacity 150ms';
            this.$el.style.opacity = waiting ? '0.45' : '';
            if (waiting) {
                this.$el.setAttribute('aria-busy', 'true');
                return;
            }
            this.$el.removeAttribute('aria-busy');
        }
    };
}

function initAiAgentComposer() {
    return {
        text: '',
        init() {
            this.$watch(() => this.placeholder(), () => this.fit());
            this.fit();
        },
        fit() {
            this.$nextTick(() => {
                const textarea = this.$root.querySelector('textarea');
                if (!textarea) {
                    return;
                }
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 5 * 24) + 'px';
            });
        },
        setText() {
            this.text = this.$event.target.value;
            const el = this.$event.target;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 5 * 24) + 'px';
        },
        onEnter() {
            if (this.$event.shiftKey) {
                return;
            }
            this.$event.preventDefault();
            this.$event.target.style.height = 'auto';
            this.submit();
        },
        submit() {
            const value = this.text.trim();
            if (!value || this.disabled()) {
                return;
            }
            Alpine.store('aiAgent').send(value);
            this.text = '';
            const textarea = this.$root.querySelector('textarea');
            if (textarea) {
                textarea.style.height = 'auto';
            }
        },
        disabled() {
            return this.running() || Alpine.store('aiAgent').isLimited();
        },
        maxLength() {
            return Alpine.store('aiAgent').config.maxMessageLength;
        },
        running() {
            return Alpine.store('aiAgent').turn.running;
        },
        idle() {
            return !this.running();
        },
        cannotSend() {
            return this.disabled() || this.text.trim() === '';
        },
        hint() {
            const config = Alpine.store('aiAgent').config;
            const aiPart = config.showAiLabel ? config.i18n.aiAssistant : '';
            const contactPart = config.contact.label
                ? config.i18n.needPerson + config.contact.label
                : '';
            return aiPart + contactPart;
        },
        placeholder() {
            const store = Alpine.store('aiAgent');
            if (store.isLimited()) {
                return store.config.i18n.limited;
            }
            return store.transcript.length > 0 ? store.config.i18n.placeholderReply : store.config.i18n.placeholder;
        },
        chips() {
            return Alpine.store('aiAgent').suggestions;
        },
        hasChips() {
            return this.chips().length > 0;
        },
        pick() {
            Alpine.store('aiAgent').send(this.$el.dataset.text);
        }
    };
}

function initAiAgentCardProducts() {
    return {
        title() {
            return this.card.payload.title || Alpine.store('aiAgent').config.i18n.products;
        },
        layoutClasses() {
            return {};
        },
        itemsLayoutClasses() {
            const count = (this.card.payload.items || []).length;
            if (count === 1) {
                return {
                    'grid grid-cols-1 gap-4': true
                };
            }
            return {
                'flex flex-row gap-4 overflow-x-auto': this.card.payload.layout === 'carousel',
                'grid grid-cols-1 sm:grid-cols-2 gap-4': this.card.payload.layout !== 'carousel'
            };
        },
        isListLayout() {
            const count = (this.card.payload.items || []).length;
            return count > 4 || this.card.payload.layout === 'list';
        },
        isTilesLayout() {
            return !this.isListLayout();
        },
        priceOf() {
            return hyva.formatPrice(this.item.product.price);
        },
        optionValuesText() {
            const values = this.item.product.option_values;
            if (!values || typeof values !== 'object') {
                return '';
            }
            return Object.values(values).join(' / ');
        },
        productsConfig() {
            const cards = Alpine.store('aiAgent').config.cards || {};
            return cards.products || {};
        },
        showImage() {
            return this.productsConfig().image !== false;
        },
        showPrice() {
            return this.productsConfig().price !== false;
        },
        showDescription() {
            return this.productsConfig().description !== false;
        },
        showStock() {
            return this.productsConfig().stock !== false;
        },
        showAddToCart() {
            return this.productsConfig().addToCart !== false;
        },
        showReason() {
            return this.productsConfig().reason !== false;
        },
        hasDescription() {
            return this.showDescription() && this.item.product.short_description !== null
                && this.item.product.short_description !== undefined
                && this.item.product.short_description !== '';
        },
        reasonVisible() {
            return this.showReason() && this.item.reason !== null
                && this.item.reason !== undefined
                && this.item.reason !== '';
        },
        inStockVisible() {
            return this.showStock() && this.item.product.in_stock === true;
        },
        outOfStockVisible() {
            return this.showStock() && this.item.product.in_stock === false;
        },
        isConfigurable() {
            const options = this.item.product.options;
            return options !== null && typeof options === 'object' && Object.keys(options).length > 0;
        },
        needsPageChoice() {
            return this.item.product.needs_page_choices === true;
        },
        hasCustomOptions() {
            const options = this.item.product.custom_options;
            return Array.isArray(options) && options.length > 0;
        },
        customOptionLines() {
            const options = this.item.product.custom_options;
            if (!Array.isArray(options)) {
                return [];
            }
            const required = options.filter((option) => option.required === true);
            const lines = required.slice(0, 3).map((option) => {
                const values = Array.isArray(option.values) ? option.values : [];
                const shown = values.slice(0, 4).map((value) => value.title);
                const more = values.length > 4 ? ' +' + (values.length - 4) + ' more' : '';
                return option.title + ': ' + shown.join(', ') + more;
            });
            if (required.length > 3) {
                lines.push('+' + (required.length - 3) + ' more options');
            }
            return lines;
        },
        hasCustomOptionLines() {
            return !this.needsPageChoice() && this.customOptionLines().length > 0;
        },
        showViewProductLink() {
            return this.showAddToCart() && this.needsPageChoice();
        },
        showAskCustomOptionsButton() {
            return this.showAddToCart() && !this.needsPageChoice() && this.hasCustomOptions();
        },
        showChooseOptionsButton() {
            return this.showAddToCart() && !this.needsPageChoice() && !this.hasCustomOptions() && this.isConfigurable();
        },
        showAddToCartButton() {
            return this.showAddToCart() && !this.needsPageChoice() && !this.hasCustomOptions() && !this.isConfigurable();
        },
        add() {
            const title = this.item.product.title;
            const optionValues = this.optionValuesText();
            if (optionValues) {
                Alpine.store('aiAgent').send('Add ' + title + ' in ' + optionValues + ' to my cart');
                return;
            }
            Alpine.store('aiAgent').send('Add ' + title + ' to my cart');
        },
        askCustomOptions() {
            const title = this.item.product.title;
            Alpine.store('aiAgent').send('Which options does ' + title + ' have?');
        },
        chooseOptions() {
            const title = this.item.product.title;
            Alpine.store('aiAgent').send('Which options are available for ' + title + '?');
        }
    };
}

function initAiAgentCardComparison() {
    return {
        title() {
            return this.card.payload.title || Alpine.store('aiAgent').config.i18n.compare;
        },
        priceOf() {
            return hyva.formatPrice(this.entry.product.price);
        },
        isRecommended() {
            return this.entry.product_id === this.card.payload.recommended_product_id;
        },
        optionValuesText() {
            const values = this.entry.product.option_values;
            if (!values || typeof values !== 'object') {
                return '';
            }
            return Object.values(values).join(' / ');
        },
        prosOf() {
            return this.entry.pros || [];
        },
        consOf() {
            return this.entry.cons || [];
        },
        bestFor() {
            return this.entry.best_for || '';
        },
        hasBestFor() {
            return Boolean(this.entry.best_for);
        },
        priceDeltaLine() {
            const delta = this.card.payload.price_delta;
            if (!delta) {
                return '';
            }
            return hyva.str(
                Alpine.store('aiAgent').config.i18n.priceDifference,
                hyva.formatPrice(delta.amount)
            );
        }
    };
}

function initAiAgentCardOrderStatus() {
    return {
        statusClass() {
            const classes = {
                processing: 'bg-status-infolight text-status-info',
                shipped: 'bg-status-infolight text-status-infodark',
                delivered: 'bg-status-successlight text-status-success',
                delayed: 'bg-status-warninglight text-status-warning',
                return_initiated: 'bg-status-warninglight text-status-warning',
                cancelled: 'bg-status-errorlight text-status-error',
                refunded: 'bg-container-light text-fg-secondary',
                unknown: 'bg-container text-fg-secondary'
            };
            return classes[this.card.payload.order.status] || classes.unknown;
        },
        statusLabel() {
            const labels = Alpine.store('aiAgent').config.i18n.orderStatus;
            return labels[this.card.payload.order.status] || labels.unknown;
        }
    };
}

function initAiAgentCardCheckout() {
    return {
        lineTotalOf() {
            return hyva.formatPrice(this.line.line_total);
        },
        subtotalFormatted() {
            return hyva.formatPrice(this.card.payload.cart.subtotal);
        },
        fulfillmentLine() {
            return hyva.str(
                Alpine.store('aiAgent').config.i18n.fulfillment,
                this.card.payload.fulfillment_method
            );
        }
    };
}

function registerAiAgentComponents() {
    Alpine.data('initAiAgentCartLine', initAiAgentCartLine);
    Alpine.data('initAiAgentCartStrip', initAiAgentCartStrip);
    Alpine.data('initAiAgentDrawer', initAiAgentDrawer);
    Alpine.data('initAiAgentOverlay', initAiAgentOverlay);
    Alpine.data('initAiAgentStart', initAiAgentStart);
    Alpine.data('initAiAgentBubble', initAiAgentBubble);
    Alpine.data('initAiAgentAfterBubble', initAiAgentAfterBubble);
    Alpine.data('initAiAgentAfterTyping', initAiAgentAfterTyping);
    Alpine.data('initAiAgentTranscript', initAiAgentTranscript);
    Alpine.data('initAiAgentTypingIndicator', initAiAgentTypingIndicator);
    Alpine.data('initAiAgentCard', initAiAgentCard);
    Alpine.data('initAiAgentComposer', initAiAgentComposer);
    Alpine.data('initAiAgentCardProducts', initAiAgentCardProducts);
    Alpine.data('initAiAgentCardComparison', initAiAgentCardComparison);
    Alpine.data('initAiAgentCardOrderStatus', initAiAgentCardOrderStatus);
    Alpine.data('initAiAgentCardCheckout', initAiAgentCardCheckout);
}

if (window.Alpine) {
    registerAiAgentComponents();
} else {
    window.addEventListener('alpine:init', registerAiAgentComponents, {once: true});
}
