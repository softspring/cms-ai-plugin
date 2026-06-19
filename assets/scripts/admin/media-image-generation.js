(function () {
    'use strict';

    function initPrompt(prompt) {
        if (prompt.dataset.aiMediaImageInitialized) {
            return;
        }

        prompt.dataset.aiMediaImageInitialized = '1';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary btn-sm mt-2';
        button.dataset.aiMediaImageGenerate = '';
        button.dataset.aiMediaImagePromptId = prompt.id;
        button.innerHTML = '<span class="bi bi-stars me-1" aria-hidden="true"></span>' + (prompt.dataset.aiMediaImageButtonLabel || 'Generate image');

        const status = document.createElement('div');
        status.className = 'small mt-2 d-none';
        status.dataset.aiMediaImageStatus = prompt.id;

        prompt.insertAdjacentElement('afterend', status);
        prompt.insertAdjacentElement('afterend', button);
    }

    function initPrompts(root) {
        root.querySelectorAll('[data-ai-media-image-prompt]').forEach(initPrompt);
    }

    function updateModelChoices(platformInput) {
        const modelInput = document.getElementById(platformInput.dataset.aiMediaImageModelInput);
        if (!modelInput) {
            return;
        }

        let modelsByPlatform = {};
        try {
            modelsByPlatform = JSON.parse(platformInput.dataset.aiMediaImageModels || '{}');
        } catch (e) {
            return;
        }

        const models = modelsByPlatform[platformInput.value] || {};
        const currentValue = modelInput.value;
        modelInput.innerHTML = '';

        Object.entries(models).forEach(function ([label, value]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            modelInput.appendChild(option);
        });

        if (models[currentValue]) {
            modelInput.value = currentValue;
        }
    }

    function setStatus(prompt, message, type) {
        const status = document.querySelector('[data-ai-media-image-status="' + prompt.id + '"]');
        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.classList.toggle('d-none', !message);
        status.classList.toggle('text-danger', 'error' === type);
        status.classList.toggle('text-muted', 'error' !== type);
    }

    function generatedFilename(response, fallback) {
        const header = response.headers.get('X-Generated-Filename');
        if (header) {
            return header;
        }

        return fallback || 'ai-generated-image.png';
    }

    async function errorMessage(response, fallback) {
        try {
            const data = await response.json();

            return data.error || fallback;
        } catch (e) {
            return fallback;
        }
    }

    async function generate(button) {
        const prompt = document.getElementById(button.dataset.aiMediaImagePromptId);
        if (!prompt) {
            return;
        }

        const value = prompt.value.trim();
        if (!value) {
            prompt.focus();
            return;
        }

        const targetInput = document.getElementById(prompt.dataset.aiMediaImageTargetInput);
        if (!targetInput) {
            setStatus(prompt, prompt.dataset.aiMediaImageErrorLabel || 'Image generation failed.', 'error');
            return;
        }

        const platformInput = document.getElementById(prompt.dataset.aiMediaImagePlatformInput);
        const modelInput = document.getElementById(prompt.dataset.aiMediaImageModelInput);
        const generatedPromptInput = document.getElementById(prompt.dataset.aiMediaImageGeneratedPromptInput);
        if (generatedPromptInput) {
            generatedPromptInput.value = '';
        }

        const previousHtml = button.innerHTML;
        button.disabled = true;
        prompt.disabled = true;
        if (platformInput) {
            platformInput.disabled = true;
        }
        if (modelInput) {
            modelInput.disabled = true;
        }
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + (prompt.dataset.aiMediaImageGeneratingLabel || 'Generating image...');
        setStatus(prompt, prompt.dataset.aiMediaImageGeneratingLabel || 'Generating image...', 'muted');

        try {
            const body = new FormData();
            body.append('prompt', value);
            if (platformInput) {
                body.append('platform', platformInput.value);
            }
            if (modelInput) {
                body.append('model', modelInput.value);
            }

            const response = await fetch(prompt.dataset.aiMediaImageGenerateUrl, {
                method: 'POST',
                body: body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(await errorMessage(response, prompt.dataset.aiMediaImageErrorLabel || 'Image generation failed.'));
            }

            const blob = await response.blob();
            const file = new File([blob], generatedFilename(response), {type: blob.type || 'image/png'});
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            targetInput.files = dataTransfer.files;
            targetInput.dispatchEvent(new Event('change', {bubbles: true}));

            if (generatedPromptInput) {
                generatedPromptInput.value = value;
            }

            setStatus(prompt, '', 'muted');
        } catch (e) {
            setStatus(prompt, e.message || prompt.dataset.aiMediaImageErrorLabel || 'Image generation failed.', 'error');
        } finally {
            button.disabled = false;
            prompt.disabled = false;
            if (platformInput) {
                platformInput.disabled = false;
            }
            if (modelInput) {
                modelInput.disabled = false;
            }
            button.innerHTML = previousHtml;
        }
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-ai-media-image-generate]');
        if (!button) {
            return;
        }

        event.preventDefault();
        generate(button);
    });

    document.addEventListener('change', function (event) {
        const platformInput = event.target.closest('[data-ai-media-image-models]');
        if (!platformInput) {
            return;
        }

        updateModelChoices(platformInput);
    });

    document.addEventListener('DOMContentLoaded', function () {
        initPrompts(document);
    });

    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) {
                    return;
                }

                initPrompts(node);
            });
        });
    }).observe(document.body, {childList: true, subtree: true});
}());
