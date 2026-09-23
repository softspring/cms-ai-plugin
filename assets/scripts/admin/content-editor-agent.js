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

    function readJson(root, selector, fallback) {
        const element = root.querySelector(selector);

        if (!element) {
            return fallback;
        }

        try {
            return JSON.parse(element.textContent || '');
        } catch (e) {
            return fallback;
        }
    }

    function htmlToText(value) {
        return String(value)
            .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, ' ')
            .replace(/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function extractFirstJsonValue(value) {
        const text = String(value);
        const start = text.search(/[\[{]/);

        if (start < 0) {
            return null;
        }

        const opener = text[start];
        const closer = opener === '{' ? '}' : ']';
        let depth = 0;
        let inString = false;
        let escaped = false;

        for (let i = start; i < text.length; i++) {
            const char = text[i];

            if (inString) {
                if (escaped) {
                    escaped = false;
                    continue;
                }

                if (char === '\\') {
                    escaped = true;
                    continue;
                }

                if (char === '"') {
                    inString = false;
                }

                continue;
            }

            if (char === '"') {
                inString = true;
                continue;
            }

            if (char === opener) {
                depth++;
                continue;
            }

            if (char !== closer) {
                continue;
            }

            depth--;
            if (depth === 0) {
                return text.slice(start, i + 1);
            }
        }

        return null;
    }

    async function readJsonResponse(response) {
        const contentType = response.headers.get('Content-Type') || '';
        const text = await response.text();
        const trimmed = text.trim();

        if (contentType.includes('application/json') || trimmed.startsWith('{') || trimmed.startsWith('[')) {
            try {
                return JSON.parse(trimmed || '{}');
            } catch (error) {
                const extracted = extractFirstJsonValue(trimmed);

                if (extracted) {
                    return JSON.parse(extracted);
                }

                throw new Error(`Invalid JSON response (${response.status}): ${error.message}`);
            }
        }

        const title = trimmed.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1]
            || trimmed.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i)?.[1]
            || trimmed;
        const message = htmlToText(title).slice(0, 240) || `Unexpected response (${response.status})`;

        throw new Error(`Unexpected non-JSON response (${response.status}): ${message}`);
    }

    function addMessage(thread, role, content, extraClass) {
        const message = document.createElement('div');
        message.className = `sfs-cms-ai-content-agent-message sfs-cms-ai-content-agent-message-${role}${extraClass ? ` ${extraClass}` : ''}`;

        const body = document.createElement('div');
        body.className = 'sfs-cms-ai-content-agent-message-body';
        body.innerHTML = escapeHtml(content).replace(/\n/g, '<br>');

        message.appendChild(body);
        thread.appendChild(message);
        thread.scrollTop = thread.scrollHeight;

        return message;
    }

    function setPending(root, enabled) {
        const status = root.querySelector('[data-cms-ai-content-editor-status]');
        const fields = root.querySelectorAll('textarea, select, button');

        root.dataset.pending = enabled ? '1' : '0';
        status && status.classList.toggle('d-none', !enabled);

        fields.forEach((field) => {
            field.disabled = enabled;
        });
    }

    function showBox(root, selector, message) {
        const box = root.querySelector(selector);
        if (!box) {
            return;
        }

        box.innerHTML = message;
        box.classList.toggle('d-none', !message);
    }

    function prettyJson(value) {
        if (value === undefined || value === null || value === '') {
            return '';
        }

        if (typeof value === 'string') {
            try {
                return JSON.stringify(JSON.parse(value), null, 2);
            } catch (error) {
                return value;
            }
        }

        return JSON.stringify(value, null, 2);
    }

    function setDebugValue(root, selector, value) {
        const element = root.querySelector(selector);
        if (!element) {
            return;
        }

        element.textContent = prettyJson(value);
    }

    function showDebug(root, payload) {
        const debug = root.querySelector('[data-cms-ai-content-editor-debug]');
        if (!debug) {
            return;
        }

        setDebugValue(root, '[data-cms-ai-content-editor-debug-payload]', payload.payload || {});
        setDebugValue(root, '[data-cms-ai-content-editor-debug-merged-payload]', payload.mergedPayload || {});
        setDebugValue(root, '[data-cms-ai-content-editor-debug-tool-calls]', payload.toolCalls || []);
        setDebugValue(root, '[data-cms-ai-content-editor-debug-raw-response]', payload.rawResponse || '');
        debug.classList.remove('d-none');
    }

    function clearMessages(root) {
        showBox(root, '[data-cms-ai-content-editor-error]', '');
        showBox(root, '[data-cms-ai-content-editor-validation]', '');
    }

    function parseName(name) {
        const parts = [];
        const regex = /([^[\]]+)|\[([^\]]*)\]/g;
        let match;

        while ((match = regex.exec(name)) !== null) {
            parts.push(match[1] !== undefined ? match[1] : match[2]);
        }

        return parts;
    }

    function setDeep(target, path, value) {
        let cursor = target;

        path.forEach((part, index) => {
            if (index === path.length - 1) {
                cursor[part] = value;
                return;
            }

            if (!cursor[part] || typeof cursor[part] !== 'object') {
                cursor[part] = {};
            }

            cursor = cursor[part];
        });
    }

    function normalizeArrays(value) {
        if (!value || typeof value !== 'object' || value instanceof Element) {
            return value;
        }

        if (Array.isArray(value)) {
            return value.map(normalizeArrays);
        }

        const keys = Object.keys(value);
        const normalized = {};

        keys.forEach((key) => {
            normalized[key] = normalizeArrays(value[key]);
        });

        if (keys.length && keys.every((key) => /^\d+$/.test(key))) {
            return keys
                .sort((a, b) => Number(a) - Number(b))
                .map((key) => normalized[key]);
        }

        return normalized;
    }

    function shouldSkipPath(path) {
        return !path.length
            || ['_token', '_ok', 'goto', 'module_prototypes_collection'].includes(path[0]);
    }

    function getControlValue(control) {
        if ('checkbox' === control.type) {
            return control.checked ? ('1' === control.value ? true : control.value) : false;
        }

        if ('radio' === control.type) {
            return control.checked ? control.value : undefined;
        }

        if ('SELECT' === control.tagName && control.multiple) {
            return Array.from(control.selectedOptions).map((option) => option.value);
        }

        return control.value;
    }

    function serializeForm(form, rootName) {
        const payload = {};
        const controls = Array.from(form.querySelectorAll('input, textarea, select'));

        controls.forEach((control) => {
            if (!control.name || control.disabled || 'file' === control.type) {
                return;
            }

            const path = parseName(control.name);
            if (path.shift() !== rootName || shouldSkipPath(path)) {
                return;
            }

            const value = getControlValue(control);
            if (value === undefined) {
                return;
            }

            setDeep(payload, path, value);
        });

        return normalizeArrays(payload);
    }

    function formNameFromPath(rootName, path) {
        return rootName + path.map((part) => `[${part}]`).join('');
    }

    function findCollection(rootName, path) {
        const fullName = formNameFromPath(rootName, path);

        return Array.from(document.querySelectorAll('[data-collection="collection"]'))
            .find((collection) => collection.dataset.fullName === fullName) || null;
    }

    function getCollectionNode(collection, index) {
        return Array.from(collection.querySelectorAll(':scope > [data-collection="node"]'))
            .find((node) => Number(node.dataset.collectionIndex) === Number(index)) || null;
    }

    function deleteNode(node) {
        const button = node.querySelector(':scope > .cms-module > .cms-module-header [data-collection-action="delete"], :scope [data-collection-action="delete"]');

        if (button) {
            button.click();
            return;
        }

        node.remove();
    }

    function clearCollection(collection) {
        Array.from(collection.querySelectorAll(':scope > [data-collection="node"]'))
            .reverse()
            .forEach(deleteNode);
    }

    function findModulePrototypeButton(moduleId) {
        return Array.from(document.querySelectorAll('#module_prototypes_collection_modal [data-collection-action="insert"][data-module-id]'))
            .find((button) => button.dataset.moduleId === moduleId) || null;
    }

    function insertModule(collection, index, moduleId) {
        if (!moduleId) {
            return null;
        }

        const allowed = (collection.dataset.modulesAllowed || '').split(',').filter(Boolean);
        if (allowed.length && !allowed.includes(moduleId)) {
            throw new Error(`Module "${moduleId}" is not allowed in this collection.`);
        }

        const button = findModulePrototypeButton(moduleId);
        if (!button) {
            throw new Error(`Module prototype "${moduleId}" was not found.`);
        }

        button.dataset.collectionTarget = collection.id;
        button.dataset.collectionInsertPosition = String(index);
        button.click();
        delete button.dataset.collectionTarget;
        delete button.dataset.collectionInsertPosition;

        return getCollectionNode(collection, index);
    }

    function ensureModuleNode(collection, index, moduleId) {
        let node = getCollectionNode(collection, index);

        if (node && moduleId && node.dataset.moduleId !== moduleId) {
            deleteNode(node);
            node = null;
        }

        return node || insertModule(collection, index, moduleId);
    }

    function setControlValue(control, value) {
        if ('checkbox' === control.type) {
            if (Array.isArray(value)) {
                control.checked = value.map(String).includes(String(control.value));
            } else if (typeof value === 'boolean') {
                control.checked = value;
            } else {
                control.checked = String(value) === String(control.value) || ['1', 'true', 'on'].includes(String(value).toLowerCase());
            }

            control.dispatchEvent(new Event('change', {bubbles: true}));
            return;
        }

        if ('radio' === control.type) {
            control.checked = String(control.value) === String(value);
            control.dispatchEvent(new Event('change', {bubbles: true}));
            return;
        }

        if ('SELECT' === control.tagName && control.multiple && Array.isArray(value)) {
            Array.from(control.options).forEach((option) => {
                option.selected = value.map(String).includes(String(option.value));
            });
            control.dispatchEvent(new Event('change', {bubbles: true}));
            return;
        }

        const stringValue = value === null || value === undefined ? '' : String(value);
        control.value = stringValue;
        control.setAttribute('value', stringValue);

        if (window.tinymce && control.id && window.tinymce.get(control.id)) {
            window.tinymce.get(control.id).setContent(stringValue);
        }

        control.dispatchEvent(new Event('input', {bubbles: true}));
        control.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function setFormField(rootName, path, value) {
        const name = formNameFromPath(rootName, path);
        const controls = Array.from(document.querySelectorAll('input, textarea, select'))
            .filter((control) => control.name === name);

        if (!controls.length) {
            return false;
        }

        controls.forEach((control) => setControlValue(control, value));

        return true;
    }

    function isObject(value) {
        return value && typeof value === 'object' && !Array.isArray(value);
    }

    function applyCollection(rootName, path, value, replaceCollections) {
        const collection = findCollection(rootName, path);

        if (!collection) {
            return false;
        }

        if (replaceCollections) {
            clearCollection(collection);
        }

        value.forEach((item, index) => {
            const moduleId = isObject(item) ? item._module : null;
            const node = ensureModuleNode(collection, index, moduleId);

            if (!node || !isObject(item)) {
                return;
            }

            Object.keys(item).forEach((key) => {
                applyValue(rootName, path.concat([index, key]), item[key], replaceCollections);
            });
        });

        if (replaceCollections) {
            Array.from(collection.querySelectorAll(':scope > [data-collection="node"]'))
                .filter((node) => Number(node.dataset.collectionIndex) >= value.length)
                .reverse()
                .forEach(deleteNode);
        }

        return true;
    }

    function applyValue(rootName, path, value, replaceCollections) {
        if ('layout' === path[0]) {
            return;
        }

        if (Array.isArray(value)) {
            if (applyCollection(rootName, path, value, replaceCollections || value.some(isObject))) {
                return;
            }

            setFormField(rootName, path, value);
            return;
        }

        if (isObject(value)) {
            Object.keys(value).forEach((key) => {
                applyValue(rootName, path.concat([key]), value[key], replaceCollections);
            });
            return;
        }

        setFormField(rootName, path, value);
    }

    function applyPayload(root, payload, replaceCollections) {
        const rootName = root.dataset.formName;

        Object.keys(payload || {}).forEach((key) => {
            applyValue(rootName, [key], payload[key], !!replaceCollections);
        });
    }

    function selectedValue(selector) {
        const field = selector ? document.querySelector(selector) : null;

        return field ? field.value : null;
    }

    function updateModelOptions(root) {
        const platform = root.querySelector('[data-cms-ai-content-editor-platform]');
        const model = root.querySelector('[data-cms-ai-content-editor-model]');
        const modelsByPlatform = readJson(root, '[data-cms-ai-content-editor-models]', {});

        if (!platform || !model) {
            return;
        }

        const currentValue = model.value;
        const models = modelsByPlatform[platform.value] || {};
        model.innerHTML = '';

        Object.keys(models).forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = models[value];
            option.selected = value === currentValue;
            model.appendChild(option);
        });

        if (!model.value && model.options.length) {
            model.options[0].selected = true;
        }
    }

    function validationMessage(errors) {
        if (!errors || !errors.length) {
            return '';
        }

        const items = errors.slice(0, 5).map((error) => {
            const path = error.path ? `<code>${escapeHtml(error.path)}</code>: ` : '';

            return `<li>${path}${escapeHtml(error.message || 'Invalid value')}</li>`;
        }).join('');

        return `<strong>Draft validation warning</strong><ul class="mb-0 mt-2">${items}</ul>`;
    }

    async function sendInstruction(root) {
        if (root.dataset.pending === '1') {
            return;
        }

        const form = document.querySelector(root.dataset.formSelector);
        const input = root.querySelector('[data-cms-ai-content-editor-instruction]');
        const thread = root.querySelector('[data-cms-ai-content-editor-thread]');
        const platform = root.querySelector('[data-cms-ai-content-editor-platform]');
        const model = root.querySelector('[data-cms-ai-content-editor-model]');
        const instruction = input ? input.value.trim() : '';

        if (!form || !instruction) {
            input && input.focus();
            return;
        }

        if (!platform?.value || !model?.value) {
            showBox(root, '[data-cms-ai-content-editor-error]', 'No AI platform or model is configured.');
            return;
        }

        clearMessages(root);
        addMessage(thread, 'user', instruction);
        const thinking = addMessage(thread, 'assistant', root.dataset.thinkingLabel || 'Thinking...', 'is-thinking');
        setPending(root, true);

        try {
            const currentPayload = serializeForm(form, root.dataset.formName);
            const response = await fetch(root.dataset.endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    instruction,
                    platform: platform.value,
                    model: model.value,
                    contentType: root.dataset.contentType,
                    contentId: root.dataset.contentId,
                    contentName: root.dataset.contentName,
                    layout: currentPayload.layout || root.dataset.layout,
                    baseVersionId: root.dataset.baseVersionId || null,
                    baseVersionNumber: root.dataset.baseVersionNumber || null,
                    selectedLocale: selectedValue(root.dataset.selectedLocaleSelector),
                    selectedSite: selectedValue(root.dataset.selectedSiteSelector),
                    locales: readJson(root, '[data-cms-ai-content-editor-locales]', []),
                    sites: readJson(root, '[data-cms-ai-content-editor-sites]', []),
                    currentPayload,
                }),
            });
            const payload = await readJsonResponse(response);

            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || `Unexpected response (${response.status})`);
            }

            applyPayload(root, payload.payload || {}, !!payload.replaceCollections);
            showDebug(root, payload);
            thinking.remove();
            addMessage(thread, 'assistant', payload.answer || 'Draft updated.');
            showBox(root, '[data-cms-ai-content-editor-validation]', payload.isValid === false ? validationMessage(payload.errors || []) : '');

            if (input) {
                input.value = '';
                input.focus();
            }
        } catch (error) {
            thinking.remove();
            const message = `${root.dataset.errorPrefix || 'Agent failed:'} ${error.message}`;
            showBox(root, '[data-cms-ai-content-editor-error]', escapeHtml(message));
            addMessage(thread, 'assistant', message, 'is-error');
        } finally {
            setPending(root, false);
        }
    }

    async function resetChat(root) {
        if (root.dataset.pending === '1') {
            return;
        }

        const input = root.querySelector('[data-cms-ai-content-editor-instruction]');
        const thread = root.querySelector('[data-cms-ai-content-editor-thread]');

        clearMessages(root);
        setPending(root, true);

        try {
            const response = await fetch(root.dataset.endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    reset: true,
                    layout: root.dataset.layout,
                    baseVersionId: root.dataset.baseVersionId || null,
                }),
            });
            const payload = await readJsonResponse(response);

            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || `Unexpected response (${response.status})`);
            }

            thread.innerHTML = '';
            addMessage(thread, 'assistant', 'Ready.');
            const debug = root.querySelector('[data-cms-ai-content-editor-debug]');
            debug && debug.classList.add('d-none');

            if (input) {
                input.value = '';
                input.focus();
            }
        } catch (error) {
            showBox(root, '[data-cms-ai-content-editor-error]', escapeHtml(error.message));
        } finally {
            setPending(root, false);
        }
    }

    function init(root) {
        const input = root.querySelector('[data-cms-ai-content-editor-instruction]');
        const submit = root.querySelector('[data-cms-ai-content-editor-submit]');
        const reset = root.querySelector('[data-cms-ai-content-editor-reset]');
        const platform = root.querySelector('[data-cms-ai-content-editor-platform]');
        const thread = root.querySelector('[data-cms-ai-content-editor-thread]');

        thread && (thread.scrollTop = thread.scrollHeight);
        updateModelOptions(root);

        platform && platform.addEventListener('change', () => updateModelOptions(root));
        submit && submit.addEventListener('click', () => sendInstruction(root));
        reset && reset.addEventListener('click', () => resetChat(root));

        input && input.addEventListener('keydown', (event) => {
            if ('Enter' !== event.key || event.shiftKey || event.altKey || event.metaKey || event.ctrlKey) {
                return;
            }

            event.preventDefault();
            sendInstruction(root);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-cms-ai-content-editor-agent]').forEach(init);
    });
}());
