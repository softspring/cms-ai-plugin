(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatJson(value) {
        if (typeof value === 'string') {
            try {
                return JSON.stringify(JSON.parse(value), null, 2);
            } catch (e) {
                return value;
            }
        }

        return JSON.stringify(value || {}, null, 2);
    }

    function renderInlineMarkdown(value) {
        return escapeHtml(value)
            .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (match, alt, url) => {
                if (!isSafeMarkdownUrl(url)) {
                    return match;
                }

                return `<img src="${url}" alt="${alt}" class="sfs-cms-ai-markdown-image">`;
            })
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (match, label, url) => {
                if (!isSafeMarkdownUrl(url)) {
                    return match;
                }

                return `<a href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>`;
            })
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>');
    }

    function isSafeMarkdownUrl(url) {
        return /^(https?:\/\/|\/)/.test(String(url || ''));
    }

    function renderMarkdown(markdown) {
        const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
        const html = [];
        let listType = null;

        function closeList() {
            if (!listType) {
                return;
            }

            html.push(`</${listType}>`);
            listType = null;
        }

        lines.forEach((line) => {
            const trimmed = line.trim();

            if (!trimmed) {
                closeList();
                return;
            }

            if (/^---+$/.test(trimmed)) {
                closeList();
                html.push('<hr>');
                return;
            }

            const heading = trimmed.match(/^(#{1,4})\s+(.+)$/);
            if (heading) {
                closeList();
                const level = Math.min(heading[1].length + 2, 6);
                html.push(`<h${level}>${renderInlineMarkdown(heading[2])}</h${level}>`);
                return;
            }

            const unordered = trimmed.match(/^[-*]\s+(.+)$/);
            if (unordered) {
                if ('ul' !== listType) {
                    closeList();
                    listType = 'ul';
                    html.push('<ul>');
                }
                html.push(`<li>${renderInlineMarkdown(unordered[1])}</li>`);
                return;
            }

            const ordered = trimmed.match(/^\d+\.\s+(.+)$/);
            if (ordered) {
                if ('ol' !== listType) {
                    closeList();
                    listType = 'ol';
                    html.push('<ol>');
                }
                html.push(`<li>${renderInlineMarkdown(ordered[1])}</li>`);
                return;
            }

            closeList();
            html.push(`<p>${renderInlineMarkdown(trimmed)}</p>`);
        });

        closeList();

        return html.join('');
    }

    function formatNumber(value) {
        return new Intl.NumberFormat().format(value);
    }

    function formatDuration(durationMs) {
        if (!Number.isFinite(durationMs)) {
            return null;
        }

        if (durationMs < 1000) {
            return `${formatNumber(Math.max(0, Math.round(durationMs)))} ms`;
        }

        const seconds = durationMs / 1000;

        return `${seconds.toFixed(seconds < 10 ? 2 : 1)} s`;
    }

    function formatTokenDetails(tokens) {
        if (!tokens || 'object' !== typeof tokens) {
            return null;
        }

        const details = [
            ['prompt', 'in'],
            ['completion', 'out'],
            ['thinking', 'reasoning'],
            ['tool', 'tools'],
            ['cached', 'cached'],
        ]
            .filter(([key]) => Number.isFinite(tokens[key]) && tokens[key] > 0)
            .map(([key, label]) => `${label} ${formatNumber(tokens[key])}`);

        return details.length ? details.join(', ') : null;
    }

    function formatMetrics(metrics) {
        if (!metrics || 'object' !== typeof metrics) {
            return null;
        }

        const parts = [];
        const duration = formatDuration(Number(metrics.durationMs));
        const tokens = metrics.tokens && 'object' === typeof metrics.tokens ? metrics.tokens : null;
        const tokenTotal = tokens && Number.isFinite(tokens.total) ? tokens.total : null;
        const tokenDetails = formatTokenDetails(tokens);

        if (duration) {
            parts.push(duration);
        }

        if (tokenTotal) {
            parts.push(`${formatNumber(tokenTotal)} tokens`);
        }

        if (!parts.length) {
            return null;
        }

        return {
            label: parts.join(' · '),
            title: tokenDetails,
        };
    }

    function addMessage(thread, role, content, extraClass, metrics) {
        const message = document.createElement('div');
        message.className = `sfs-cms-ai-message sfs-cms-ai-message-${role}${extraClass ? ` ${extraClass}` : ''}`;

        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'sfs-cms-ai-message-content';

        const body = document.createElement('div');
        body.className = 'sfs-cms-ai-message-body';
        if ('assistant' === role && !extraClass) {
            body.classList.add('sfs-cms-ai-markdown');
            body.innerHTML = renderMarkdown(content);
        } else {
            body.textContent = content;
        }

        contentWrapper.appendChild(body);

        if ('assistant' === role && !extraClass) {
            const formattedMetrics = formatMetrics(metrics);
            if (formattedMetrics) {
                const meta = document.createElement('div');
                meta.className = 'sfs-cms-ai-message-meta';
                meta.textContent = formattedMetrics.label;
                if (formattedMetrics.title) {
                    meta.title = formattedMetrics.title;
                }
                contentWrapper.appendChild(meta);
            }
        }

        message.appendChild(contentWrapper);
        thread.appendChild(message);
        thread.scrollTop = thread.scrollHeight;

        return message;
    }

    function setThinking(root, enabled) {
        const status = root.querySelector('[data-mcp-chatbot-status]');
        const submit = root.querySelector('[data-mcp-chatbot-submit]');
        const input = root.querySelector('[data-mcp-chatbot-input]');
        const fields = root.querySelectorAll('select, textarea, button');

        root.dataset.pending = enabled ? '1' : '0';
        status && status.classList.toggle('d-none', !enabled);

        fields.forEach((field) => {
            field.disabled = enabled;
        });

        if (!enabled) {
            input && input.focus();
        }

        if (submit) {
            submit.classList.toggle('disabled', enabled);
        }
    }

    function showError(root, message) {
        const error = root.querySelector('[data-mcp-chatbot-error]');
        if (!error) {
            return;
        }

        error.textContent = message || 'Unexpected chatbot error.';
        error.classList.remove('d-none');
    }

    function clearError(root) {
        const error = root.querySelector('[data-mcp-chatbot-error]');
        if (!error) {
            return;
        }

        error.textContent = '';
        error.classList.add('d-none');
    }

    function renderToolCalls(container, calls) {
        if (!container) {
            return;
        }

        container.innerHTML = '';

        if (!calls || !calls.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted mb-0';
            empty.textContent = 'No MCP tools were called.';
            container.appendChild(empty);
            return;
        }

        calls.forEach((call) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'border-top pt-3 mt-3';
            wrapper.innerHTML = `
                <p class="mb-2">
                    <code>${escapeHtml(call.name || 'unknown_tool')}</code>
                    ${call.error ? '<span class="badge bg-danger ms-2">error</span>' : ''}
                </p>
                <h3 class="h6">Arguments</h3>
                <pre class="small">${escapeHtml(formatJson(call.arguments))}</pre>
                <h3 class="h6">Result</h3>
                <pre class="small mb-0">${escapeHtml(formatJson(call.content))}</pre>
            `;

            container.appendChild(wrapper);
        });
    }

    function updateModelOptions(platformInput) {
        const modelInput = document.getElementById(platformInput.dataset.mcpChatbotModelInput);
        if (!modelInput) {
            return;
        }

        let modelsByPlatform = {};
        try {
            modelsByPlatform = JSON.parse(platformInput.dataset.mcpChatbotModels || '{}');
        } catch (error) {
            modelsByPlatform = {};
        }

        const currentValue = modelInput.value;
        const models = modelsByPlatform[platformInput.value] || {};
        modelInput.innerHTML = '';

        Object.entries(models).forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            modelInput.appendChild(option);
        });

        if (models[currentValue]) {
            modelInput.value = currentValue;
        } else if (modelInput.options.length) {
            modelInput.options[0].selected = true;
        }

        modelInput.disabled = 0 === modelInput.options.length;
    }

    async function submitChat(root, form) {
        if (root.dataset.pending === '1') {
            return;
        }

        const input = root.querySelector('[data-mcp-chatbot-input]');
        const thread = root.querySelector('[data-mcp-chatbot-thread]');
        const toolCallsTargetSelector = form.dataset.mcpChatbotToolCallsTarget;
        const toolCallsTarget = toolCallsTargetSelector ? document.querySelector(`${toolCallsTargetSelector} [data-mcp-chatbot-tool-calls]`) : null;
        const question = input ? input.value.trim() : '';

        if (!question) {
            input && input.focus();
            return;
        }

        const formData = new FormData(form);

        clearError(root);
        addMessage(thread, 'user', question);
        const thinkingMessage = addMessage(thread, 'assistant', root.dataset.thinkingLabel || 'Thinking...', 'is-thinking');
        setThinking(root, true);

        try {
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : null;

            if (!response.ok || !payload || !payload.ok) {
                throw new Error((payload && payload.error) || `Unexpected response (${response.status})`);
            }

            thinkingMessage.remove();
            addMessage(thread, 'assistant', payload.answer || '', null, payload.metrics || null);
            renderToolCalls(toolCallsTarget, payload.toolCalls || []);

            if (input) {
                input.value = '';
            }
        } catch (error) {
            thinkingMessage.remove();
            const prefix = root.dataset.errorPrefix || 'Chatbot failed:';
            const message = `${prefix} ${error.message}`;
            showError(root, message);
            addMessage(thread, 'assistant', message, 'is-error');
        } finally {
            setThinking(root, false);
        }
    }

    function init(root) {
        const form = root.querySelector('[data-mcp-chatbot-form]');
        const input = root.querySelector('[data-mcp-chatbot-input]');
        const thread = root.querySelector('[data-mcp-chatbot-thread]');

        if (!form || !input) {
            return;
        }

        if (thread) {
            thread.scrollTop = thread.scrollHeight;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitChat(root, form);
        });

        form.querySelectorAll('[data-mcp-chatbot-platform]').forEach((platformInput) => {
            platformInput.addEventListener('change', function () {
                updateModelOptions(platformInput);
            });
        });

        input.addEventListener('keydown', function (event) {
            if ('Enter' !== event.key || event.shiftKey || event.altKey || event.metaKey || event.ctrlKey) {
                return;
            }

            event.preventDefault();
            submitChat(root, form);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mcp-chatbot-markdown]').forEach((element) => {
            element.innerHTML = renderMarkdown(element.textContent || '');
            element.classList.add('sfs-cms-ai-markdown');
        });

        document.querySelectorAll('[data-mcp-chatbot]').forEach(init);
    });
}());
